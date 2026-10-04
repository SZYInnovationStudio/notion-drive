<?php

declare(strict_types=1);

namespace NotionDrive\Files;

use NotionDrive\Logger;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Space\SpacePatrol;
use NotionDrive\Uploads\NotionWriteQueue;

/**
 * 把一份 uploads 记录的内容流式转发给客户端。
 *
 * 登录态下载（api/file.php）与公开直链（s.php）共用这份实现，保证两者的
 * Range 透传、预览白名单、安全 Content-Type、多副本回退与中断告警行为完全一致。
 * 调用方负责鉴权与状态校验（封禁、数据丢失、直链有效期等），本类只负责「把内容安全地发出去」。
 *
 * 本类代码由 api/file.php 原样搬移而来，行为未做任何调整。
 */
final class FileStreamer
{
    /**
     * @param array $file            uploads 记录（含 display_name/filename/mime_type/status 等）
     * @param array $owner           文件所属用户（下载要用他的 Token 访问 Notion）
     * @param bool  $inlineRequested 是否请求内联预览（仅白名单类型 + 后台开关开启才真正内联）
     * @param array $context         ['viewer_id' => int|null, 'via' => 'file'|'share', 'share_id' => int|null]，仅用于日志
     */
    public static function stream(array $file, array $owner, bool $inlineRequested, array $context = []): never
    {
        $id = (int) ($file['id'] ?? 0);
        $viewerId = $context['viewer_id'] ?? null;
        $via = (string) ($context['via'] ?? 'file');
        $shareId = $context['share_id'] ?? null;

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
                            'share_id' => $shareId,
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