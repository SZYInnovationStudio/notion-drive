<?php

declare(strict_types=1);

namespace NotionDrive\Files;

use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Space\SpacePatrol;
use NotionDrive\Uploads\NotionWriteQueue;

/**
 * 把一份 uploads 记录的内容流式转发给客户端。
 *
 * 登录态下载（api/file.php）统一走这份实现，保证 Range 透传、预览白名单、
 * 安全 Content-Type、多副本回退与中断告警行为一致。
 * 调用方负责鉴权与状态校验（数据丢失等），本类只负责「把内容安全地发出去」。
 */
final class FileStreamer
{
    /**
     * 并发拉取的分块大小。
     * 每连接被跨境限速（实测约 15 KB/s），分块必须足够小，否则「按序输出」会一直等首块收完，
     * 客户端在首块到达前拿不到任何数据，表现为和单连接一样慢。64 KiB 约 4 秒首块、8 路并发约 7 倍提速。
     */
    private const PARALLEL_CHUNK = 65536;
    /** 低于该体积不值得并行（省去探测与多连接的额外开销） */
    private const PARALLEL_MIN_SIZE = 1048576;

    /**
     * @param array $file            uploads 记录（含 display_name/filename/mime_type/status 等）
     * @param array $owner           文件所属用户（下载要用他的 Token 访问 Notion）
     * @param bool  $inlineRequested 是否请求内联预览（仅白名单类型 + 后台开关开启才真正内联）
     * @param array $context         ['viewer_id' => int|null, 'via' => 'file']，仅用于日志
     */
    public static function stream(array $file, array $owner, bool $inlineRequested, array $context = []): never
    {
        $id = (int) ($file['id'] ?? 0);
        $viewerId = $context['viewer_id'] ?? null;
        $via = (string) ($context['via'] ?? 'file');

        // 预览：仅当请求带 inline=1、文件类型在预览白名单内、且后台已开启对应开关时，才内联输出；
        // 否则退化为普通下载（避免被当作任意文件的内联挂载点）
        $previewMode = false;
        if ($inlineRequested) {
            if (previewKind($file) === '' || !filePreviewable($file)) {
                http_response_code(404);
                exit('该类型不支持预览。');
            }
            // 预览必须能用与预览大类一致的安全 Content-Type 输出，否则拒绝内联
            // （防止「文件名 a.png、内容为 HTML」被以 text/html + inline 渲染成同源 HTML 注入）
            if (previewContentType($file, previewKind($file)) === null) {
                http_response_code(404);
                exit('该类型不支持预览。');
            }
            $previewMode = true;
        }

        $filename = (string) ($file['display_name'] ?: $file['filename']);
        // ASCII 兜底文件名：老浏览器与下载工具不认 filename*=，需剔除非 ASCII 字符并保证保留扩展名
        $asciiName = trim(preg_replace('/[^\x20-\x7E]+/', '', str_replace(['\\', '/', '"'], '', $filename)) ?? '');
        if ($asciiName === '' || $asciiName === '.') {
            $extension = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($filename, PATHINFO_EXTENSION)) ?? '';
            $asciiName = 'download' . ($extension !== '' ? '.' . $extension : '');
        }
        $responseType = $previewMode
            ? (previewContentType($file, previewKind($file)) ?: 'application/octet-stream')
            : (string) $file['mime_type'];
        header('Content-Type: ' . ($responseType !== '' ? $responseType : 'application/octet-stream'));
        if ($previewMode) {
            // 内联预览：不触发下载；nosniff 防止浏览器把内容猜成可执行类型
            header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
        } else {
            header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        }
        header('Accept-Ranges: bytes');
        header('X-Accel-Buffering: no');

        // 尚未写入存储（正在写入 / 写入失败）：这类文件没有 Notion 副本，直接读服务器上的暂存文件，
        // 用户不必等后台把 Notion 副本写完。后台刚好写完并删掉暂存文件时会落空，此时继续走下面的 Notion 副本分支
        $stagingPending = in_array((string) $file['status'], [NotionWriteQueue::STATUS_WRITING, NotionWriteQueue::STATUS_FAILED], true);
        $stagingPath = NotionWriteQueue::stagingPath((int) $file['user_id'], $id);
        if ($stagingPending && is_file($stagingPath)) {
            self::streamLocalFile($stagingPath);
        }

        // 按副本顺序逐个尝试：某个账户不可用时自动回退到下一份
        $targets = uploadReplicaTargets($file, $owner);
        if ($targets === []) {
            throw new \RuntimeException('该文件当前没有可用的存储副本。');
        }

        $lastError = '';
        $fellBack = false;
        // 统计有多少副本是「块已不存在（404）」：全部都 404 才判定数据丢失，网络错误/Token 失效不计入
        $missingBlocks = 0;
        foreach ($targets as $target) {
            try {
                $url = (new NotionClient((string) $target['token']))->downloadUrl((string) $target['block_id']);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                $fellBack = true;
                if ($exception instanceof NotionApiException && $exception->status() === 404) {
                    $missingBlocks++;
                }
                Logger::warning('download', '取得副本下载地址失败，尝试下一份副本。', [
                    'file_id' => $id,
                    'replica_id' => $target['replica_id'],
                    'account_type' => $target['type'],
                    'account_id' => $target['account_id'],
                    'via' => $via,
                    'reason' => $lastError,
                ]);
                continue;
            }

            // 跨境直连 S3 单连接会被限速（实测每连接约 12–19 KB/s）。改走多连接并行分段拉取、
            // 按偏移顺序拼回后转发，可成倍提速。任一步骤在「尚未输出任何字节」时失败都会返回 0，
            // 交由下面的单连接路径安全回退；一旦已经开始输出则返回已写字节数并由本方法内部收尾。
            $parallelWritten = self::relayRangeParallel(
                (string) $url,
                (string) ($_SERVER['HTTP_RANGE'] ?? ''),
                $file,
                $target,
                $via,
                $viewerId
            );
            if ($parallelWritten > 0) {
                exit;
            }

            // 远端响应头先暂存，等第一个数据块到达再发出；中途失败则一个字节都没写出去，可以安全回退
            $pendingHeaders = [];
            $status = 0;
            $started = false;
            $written = 0;
            // 远端声明的总长度：下载结束后据此校验是否收全，避免把截断的响应当成完整文件
            $expectedLength = null;

            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                // 仅允许跟随 https 跳转，避免被重定向到 http 或内网地址
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RETURNTRANSFER => false,
                // 不设总时长上限（大文件可能很久），仅以「长时间无数据」判定断开，避免连接挂死
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 120,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$pendingHeaders, &$status, &$expectedLength): int {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                        $status = (int) $match[1];
                        // 发生跳转时丢弃上一跳的响应头，只保留最终响应的
                        $pendingHeaders = [];
                        $expectedLength = null;
                    } elseif (preg_match('/^(Content-Length|Content-Range|Accept-Ranges):/i', $header)) {
                        $pendingHeaders[] = trim($header);
                        if (stripos($header, 'Content-Length:') === 0) {
                            $expectedLength = (int) trim(substr($header, strlen('Content-Length:')));
                        }
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$pendingHeaders, &$status, &$started, &$written): int {
                    if (!$started) {
                        // 跟随跳转后的最终地址必须仍是 Notion 可信文件域名，否则不输出任何内容并回退
                        if (!NotionClient::isTrustedDownloadUrl((string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL))) {
                            return 0;
                        }
                        // 远端返回错误状态：不输出任何内容，交由外层回退到下一份副本
                        if ($status >= 400) {
                            return 0;
                        }
                        if ($status > 0) {
                            http_response_code($status);
                        }
                        foreach ($pendingHeaders as $header) {
                            header($header, true);
                        }
                        $started = true;
                    }
                    echo $data;
                    flush();
                    $written += strlen($data);
                    return strlen($data);
                },
            ]);
            if (!empty($_SERVER['HTTP_RANGE'])) {
                curl_setopt($handle, CURLOPT_HTTPHEADER, ['Range: ' . $_SERVER['HTTP_RANGE']]);
            }
            $ok = curl_exec($handle);
            $error = curl_error($handle);
            $truncated = $ok === false || ($expectedLength !== null && $written !== $expectedLength);

            if ($written > 0) {
                if ($truncated) {
                    // 用户主动取消下载不属于账户故障，不告警
                    if (!connection_aborted()) {
                        // 响应已开始输出，无法回退也无法改写状态码，只能记录并告警；已声明 Content-Length，客户端会判定下载失败
                        $reason = $ok === false
                            ? $error
                            : sprintf('已接收 %d 字节，远端声明 %d 字节', $written, (int) $expectedLength);
                        Logger::error('download', '下载中途中断，输出的数据可能不完整。', [
                            'file_id' => $id,
                            'user_id' => $viewerId,
                            'replica_id' => $target['replica_id'],
                            'account_type' => $target['type'],
                            'account_id' => $target['account_id'],
                            'via' => $via,
                            'reason' => $reason,
                        ]);
                        raiseAdminAlert('error', sprintf('账户「%s」下载文件 #%d 时中断，该副本可能不可用。', (string) $target['label'], $id), [
                            'stage' => 'download',
                            'file_id' => $id,
                            'replica_id' => $target['replica_id'],
                            'account_type' => $target['type'],
                            'account_id' => $target['account_id'],
                            'reason' => $reason,
                        ]);
                    }
                    exit;
                }
                if ($fellBack) {
                    Logger::info('download', '已回退到可用副本完成下载。', [
                        'file_id' => $id,
                        'user_id' => $viewerId,
                        'replica_id' => $target['replica_id'],
                        'account_type' => $target['type'],
                        'account_id' => $target['account_id'],
                        'via' => $via,
                    ]);
                }
                exit;
            }

            // 取地址成功但实际拉取流返回 404（极少见：拿到签名 URL 后对象已被删）同样计为块丢失
            if ($status === 404) {
                $missingBlocks++;
            }
            $fellBack = true;
            $lastError = $ok === false ? $error : sprintf('远端返回 HTTP %d', $status);
            Logger::warning('download', '副本下载失败，尝试下一份副本。', [
                'file_id' => $id,
                'replica_id' => $target['replica_id'],
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
                'via' => $via,
                'reason' => $lastError,
            ]);
        }

        // 所有副本的文件块都返回 404：内容确实已从 Notion 消失，标记 data_lost 并提示重新上传
        if ($missingBlocks >= count($targets)) {
            SpacePatrol::markLost($id, '下载时探测到全部副本块均返回 404。');
            http_response_code(410);
            header('Content-Type: text/plain; charset=utf-8');
            exit(SpacePatrol::LOST_MESSAGE);
        }

        throw new \RuntimeException('全部副本均不可用：' . $lastError);
    }

    /**
     * 读取并发连接数配置，返回 <2 表示关闭并行（回退单连接透传）。
     * 配置缺失时默认 8 条连接，钳制在 2–16。
     */
    private static function parallelConnections(): int
    {
        $value = (int) Database::config('transfer_connections', '8');
        if ($value <= 1) {
            return 0;
        }

        return max(2, min(16, $value));
    }

    /**
     * 用「Range: bytes=0-0」探测远端文件总大小。
     * 需返回 206 且带 Content-Range 才算成功（预签名 URL 不允许 HEAD），失败返回 null。
     */
    private static function probeTotalSize(string $url): ?int
    {
        $status = 0;
        $total = null;
        $seenRange = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => ['Range: bytes=0-0'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$status, &$total, &$seenRange): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                    $status = (int) $match[1];
                    $total = null;
                    $seenRange = false;
                } elseif (preg_match('#^Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $header, $match)) {
                    $total = (int) $match[1];
                    $seenRange = true;
                }

                return strlen($header);
            },
            // 头部已足够，收到第一个数据块立即中止，丢弃 body
            CURLOPT_WRITEFUNCTION => static function (): int {
                return 0;
            },
        ]);
        curl_exec($handle);

        if (!$seenRange || $total === null || $total <= 0 || $status !== 206) {
            return null;
        }
        if (!NotionClient::isTrustedDownloadUrl((string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL))) {
            return null;
        }

        return $total;
    }

    /**
     * 多连接并行分段拉取远端内容，并按偏移顺序边收边转发给客户端。
     *
     * 返回已写给客户端的字节数：0 表示「一个字节都没输出，调用方可安全回退单连接」；
     * 任何输出前或输出后发生的失败都在本方法内记录并收尾，不向调用方抛出异常。
     *
     * @param string     $rangeHeader 客户端原始 Range 头（'' 表示整文件请求）
     * @param array      $file        uploads 记录（仅用于日志）
     * @param array      $target      当前副本信息（仅用于日志）
     * @param int|null   $viewerId    下载者用户 ID（仅用于日志）
     */
    private static function relayRangeParallel(
        string $url,
        string $rangeHeader,
        array $file,
        array $target,
        string $via,
        ?int $viewerId
    ): int {
        $connections = self::parallelConnections();
        if ($connections < 2) {
            return 0;
        }

        $total = self::probeTotalSize($url);
        if ($total === null || $total < self::PARALLEL_MIN_SIZE) {
            return 0;
        }

        // 解析客户端 Range：仅支持单段；多段/非法时回退单连接原样透传
        $rangeHeader = trim($rangeHeader);
        $isPartial = $rangeHeader !== '';
        $start = 0;
        $end = $total - 1;
        if ($isPartial) {
            if (preg_match('/^bytes=(\d*)-(\d*)$/i', $rangeHeader, $match) !== 1) {
                return 0;
            }
            if ($match[1] === '' && $match[2] === '') {
                return 0;
            }
            if ($match[1] === '') {
                $suffix = (int) $match[2];
                if ($suffix <= 0) {
                    return 0;
                }
                $start = max(0, $total - $suffix);
            } else {
                $start = (int) $match[1];
                if ($match[2] !== '') {
                    $end = (int) $match[2];
                }
            }
            if ($start > $end || $start >= $total) {
                return 0;
            }
            $end = min($end, $total - 1);
        }

        $length = $end - $start + 1;
        // 小范围（音视频拖动产生的尾段等）不值得并行
        if ($length < self::PARALLEL_MIN_SIZE) {
            return 0;
        }

        $chunkSize = self::PARALLEL_CHUNK;
        $chunkCount = intdiv($length + $chunkSize - 1, $chunkSize);
        $chunks = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkStart = $start + $i * $chunkSize;
            $chunkEnd = min($chunkStart + $chunkSize - 1, $end);
            $chunks[$i] = [
                'start' => $chunkStart,
                'end' => $chunkEnd,
                'len' => $chunkEnd - $chunkStart + 1,
                'buf' => '',
                'emitted' => 0,
                'status' => 0,
                'done' => false,
                'bad' => false,
                'handle' => null,
            ];
        }

        $multi = curl_multi_init();
        $active = [];
        $nextIndex = 0;
        $sendPos = $start;
        $writtenTotal = 0;
        $lastActivity = microtime(true);
        $failed = false;
        $failReason = '';
        $headersSent = false;

        // 发出响应头：延迟到第一个数据块真正输出前，未输出任何字节时可让调用方干净回退
        $sendHeaders = static function () use (&$headersSent, $isPartial, $start, $end, $total, $length): void {
            if ($headersSent) {
                return;
            }
            $headersSent = true;
            if ($isPartial) {
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
            } else {
                http_response_code(200);
            }
            header('Content-Length: ' . $length);
        };

        $launch = static function (int $index) use (&$chunks, &$active, $multi, $url): void {
            $chunk = &$chunks[$index];
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 20,
                // 仅以「长时间无数据」判定断开，避免大分块被误杀
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 60,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_BUFFERSIZE => 262144,
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_HTTPHEADER => ['Range: bytes=' . $chunk['start'] . '-' . $chunk['end']],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$chunks, $index): int {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                        $chunks[$index]['status'] = (int) $match[1];
                    }

                    return strlen($header);
                },
                // 只缓存不输出：必须按偏移顺序拼回，否则大文件会错位
                CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$chunks, $index): int {
                    $chunks[$index]['buf'] .= $data;

                    return strlen($data);
                },
            ]);
            $chunk['handle'] = $handle;
            $active[spl_object_id($handle)] = $index;
            curl_multi_add_handle($multi, $handle);
            unset($chunk);
        };

        while (true) {
            while ($nextIndex < $chunkCount && count($active) < $connections) {
                $launch($nextIndex);
                $nextIndex++;
                $lastActivity = microtime(true);
            }

            curl_multi_exec($multi, $running);

            while (($info = curl_multi_info_read($multi)) !== false) {
                if (($info['msg'] ?? 0) !== CURLMSG_DONE) {
                    continue;
                }
                $handle = $info['handle'];
                $key = spl_object_id($handle);
                $index = $active[$key] ?? null;
                curl_multi_remove_handle($multi, $handle);
                unset($active[$key]);
                if ($index === null || !isset($chunks[$index])) {
                    continue;
                }
                $chunk = &$chunks[$index];
                $chunk['done'] = true;
                // 分块必须收到 206 且字节数与请求的窗口一致，否则视为失败
                if ($chunk['status'] !== 206 || $info['result'] !== CURLE_OK || strlen($chunk['buf']) !== $chunk['len']) {
                    $chunk['bad'] = true;
                }
                unset($chunk);
                $lastActivity = microtime(true);
            }

            // 按偏移顺序输出：只有排在队首的块能连续吐出，保证不错位
            while ($sendPos <= $end) {
                $index = intdiv($sendPos - $start, $chunkSize);
                if (!isset($chunks[$index])) {
                    break;
                }
                $chunk = &$chunks[$index];
                if ($chunk['start'] + $chunk['emitted'] !== $sendPos) {
                    unset($chunk);
                    break;
                }
                $buffered = strlen($chunk['buf']);
                if ($chunk['emitted'] < $buffered) {
                    $sendHeaders();
                    $piece = substr($chunk['buf'], $chunk['emitted']);
                    echo $piece;
                    flush();
                    $chunk['emitted'] += strlen($piece);
                    $sendPos += strlen($piece);
                    $writtenTotal += strlen($piece);
                    $lastActivity = microtime(true);
                }
                if ($chunk['emitted'] >= $buffered) {
                    if ($chunk['bad']) {
                        $failed = true;
                        $failReason = sprintf(
                            '分块 %d-%d 传输失败（HTTP %d，已收 %d/%d 字节）',
                            $chunk['start'],
                            $chunk['end'],
                            $chunk['status'],
                            $buffered,
                            $chunk['len']
                        );
                        unset($chunk);
                        break 2;
                    }
                    if ($chunk['done'] && $chunk['emitted'] >= $chunk['len']) {
                        unset($chunks[$index]);
                        unset($chunk);
                        continue;
                    }
                    unset($chunk);
                    break;
                }
                unset($chunk);
                break;
            }

            if ($sendPos > $end) {
                break;
            }

            // 客户端已断开：无需继续拉取，也不应告警
            if (connection_aborted()) {
                break;
            }

            if ($nextIndex >= $chunkCount && $active === []) {
                if (!$failed) {
                    $failed = true;
                    $failReason = '全部连接已结束但数据未收全';
                }
                break;
            }

            if ($running > 0) {
                if (curl_multi_select($multi, 0.05) === -1) {
                    usleep(50000);
                }
            } else {
                usleep(10000);
            }

            if (microtime(true) - $lastActivity > 60) {
                $failed = true;
                $failReason = '并行分块拉取超过 60 秒无新数据';
                break;
            }
        }

        // 清理未结束的连接（PHP 8.5 起不再调用 curl_close/curl_multi_close，交由 GC 回收）
        foreach ($active as $index) {
            if (isset($chunks[$index]) && $chunks[$index]['handle'] instanceof \CurlHandle) {
                curl_multi_remove_handle($multi, $chunks[$index]['handle']);
            }
        }
        $active = [];

        if ($failed && $writtenTotal > 0) {
            // 已开始输出、无法回退也无法改写状态码：记录并告警，客户端据 Content-Length 判定失败
            if (!connection_aborted()) {
                Logger::error('download', '并行分块下载中途失败，输出可能不完整。', [
                    'file_id' => (int) ($file['id'] ?? 0),
                    'user_id' => $viewerId,
                    'replica_id' => $target['replica_id'] ?? null,
                    'account_type' => $target['type'] ?? null,
                    'account_id' => $target['account_id'] ?? null,
                    'via' => $via,
                    'written' => $writtenTotal,
                    'reason' => $failReason,
                ]);
                raiseAdminAlert('error', sprintf('账户「%s」并行下载文件 #%d 时中断，该副本可能不可用。', (string) ($target['label'] ?? ''), (int) ($file['id'] ?? 0)), [
                    'stage' => 'download',
                    'file_id' => (int) ($file['id'] ?? 0),
                    'replica_id' => $target['replica_id'] ?? null,
                    'account_type' => $target['type'] ?? null,
                    'account_id' => $target['account_id'] ?? null,
                    'reason' => $failReason,
                ]);
            }
        } elseif ($failed) {
            Logger::warning('download', '并行分块拉取未输出任何数据，回退单连接。', [
                'file_id' => (int) ($file['id'] ?? 0),
                'replica_id' => $target['replica_id'] ?? null,
                'via' => $via,
                'reason' => $failReason,
            ]);
        }

        return $writtenTotal;
    }

    /**
     * 本地暂存文件的流式输出（支持 Range，便于音视频预览拖动进度）。
     * 暂存文件在后台写入完成后即被删除，因此读不到时由调用方回退到 Notion 副本分支。
     */
    private static function streamLocalFile(string $path): never
    {
        $size = (int) (filesize($path) ?: 0);
        $start = 0;
        $end = max(0, $size - 1);

        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $match) === 1) {
            if ($match[1] !== '') {
                $start = (int) $match[1];
            }
            if ($match[2] !== '') {
                $end = (int) $match[2];
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $end = min($end, $size - 1);
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        header('Content-Length: ' . max(0, $end - $start + 1));

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('无法读取该文件的服务器暂存副本。');
        }
        try {
            if ($start > 0) {
                fseek($handle, $start);
            }
            for ($remaining = $end - $start + 1; $remaining > 0; ) {
                $chunk = fread($handle, (int) min(262144, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        exit;
    }
}