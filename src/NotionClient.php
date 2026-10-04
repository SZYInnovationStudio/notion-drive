<?php

declare(strict_types=1);

namespace NotionDrive;

use CURLFile;
use RuntimeException;

final class NotionClient
{
    private const API_BASE = 'https://api.notion.com/v1';
    private const API_VERSION = '2026-03-11';
    /** 分片大小：Notion 要求每片 5~20 MB，取上限 20 MB 以减少请求数（末片可小于 5 MB）。 */
    private const PART_SIZE = 20 * 1024 * 1024;
    /** 分片并发窗口：Notion 官方允许分片并发、乱序上传，但并发过高会触发 429，取 4 折中。 */
    private const UPLOAD_CONCURRENCY = 4;
    /** 单个分片的最大尝试次数（首次 + 2 次重试）。 */
    private const MAX_UPLOAD_ATTEMPTS = 3;

    /**
     * 网络层瞬时错误：分片重发只覆盖自身、不会产生多余数据，可安全重试。
     * 用方法而非类常量，避免未安装 curl 扩展时在类声明阶段就因常量未定义而致命报错。
     */
    private static function transientCurlErrors(): array
    {
        return [
            CURLE_PARTIAL_FILE,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ];
    }

    /** 可信下载域名：Notion 官方域名及其文件托管的 S3 域名。 */
    private const TRUSTED_DOWNLOAD_DOMAINS = ['notion.com', 'notion.so', 'notion-static.com', 'amazonaws.com'];

    public function __construct(private readonly string $token)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('服务器未启用 PHP cURL 扩展。');
        }
    }

    /**
     * 上传文件并挂到指定页面下。
     *
     * $onProgress 可选，签名 (int $completed, int $total)：每成功上传一个分片回调一次，
     * 供上层换算成整体的「正在写入存储」进度（单分片文件上传完成后回调一次）。
     */
    public function uploadAndAttach(string $path, string $filename, string $mime, int $size, string $pageId, ?callable $onProgress = null): array
    {
        $multiPart = $size > 20 * 1024 * 1024;
        $parts = $multiPart ? (int) ceil($size / self::PART_SIZE) : 1;

        $session = $this->initStreamSession($filename, $mime, $multiPart, $parts);
        $uploadId = $session['id'];

        if ($multiPart) {
            $this->sendParts($session['upload_url'], $path, $filename, $mime, $parts, $onProgress);
            $this->completeStreamSession($session['complete_url']);
        } else {
            $this->sendStreamPart($session['upload_url'], $path, $filename, $mime);
            if ($onProgress !== null) {
                $onProgress(1, 1);
            }
        }

        $attached = $this->attachFileUpload($uploadId, $pageId, $mime);

        return ['file_upload_id' => $uploadId, 'block' => $attached];
    }

    /**
     * 建立 Notion 文件上传会话（「在线上传」直传复用）：只拿 upload_url / complete_url，不实际传输内容。
     * $multiPart 为 true 时按 $parts 声明分片总数（Notion 要求每片 5~20 MB、末片可小、part_number ≤ 1000）。
     *
     * @return array{id:string, upload_url:string, complete_url:string, multi_part:bool}
     */
    public function initStreamSession(string $filename, string $mime, bool $multiPart, int $parts): array
    {
        $payload = [
            'mode' => $multiPart ? 'multi_part' : 'single_part',
            'filename' => $filename,
            'content_type' => $mime,
        ];
        if ($multiPart) {
            $payload['number_of_parts'] = max(1, $parts);
        }

        $session = $this->jsonRequest('POST', self::API_BASE . '/file_uploads', $payload);
        $uploadId = (string) ($session['id'] ?? '');
        $uploadUrl = (string) ($session['upload_url'] ?? '');
        if ($uploadId === '' || !$this->isTrustedUploadUrl($uploadUrl)) {
            throw new NotionApiException('Notion 未返回有效的上传会话。');
        }
        $completeUrl = (string) ($session['complete_url'] ?? self::API_BASE . '/file_uploads/' . $uploadId . '/complete');
        if (!$this->isTrustedUploadUrl($completeUrl)) {
            throw new NotionApiException('Notion 未返回有效的完成地址。');
        }

        return ['id' => $uploadId, 'upload_url' => $uploadUrl, 'complete_url' => $completeUrl, 'multi_part' => $multiPart];
    }

    /**
     * 把一个服务器上的分片（或整份小文件）转发给 Notion 直传会话；$partNumber 为 null 表示单分片模式。
     * 重发同一分片只会覆盖该分片、不产生多余数据，因此 execute() 允许幂等重试。
     */
    public function sendStreamPart(string $uploadUrl, string $filePath, string $filename, string $mime, ?int $partNumber = null): void
    {
        if (!$this->isTrustedUploadUrl($uploadUrl)) {
            throw new NotionApiException('Notion 未返回有效的上传地址。');
        }

        $fields = ['file' => new CURLFile($filePath, $mime, $filename)];
        if ($partNumber !== null) {
            $fields['part_number'] = (string) $partNumber;
        }

        $handle = curl_init($uploadUrl);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Notion-Version: ' . self::API_VERSION,
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        $this->execute($handle, true);
    }

    /** 完成直传会话：通知 Notion 所有分片已就位。complete 为 POST、非幂等，故不做盲目重试。 */
    public function completeStreamSession(string $completeUrl): void
    {
        if (!$this->isTrustedUploadUrl($completeUrl)) {
            throw new NotionApiException('Notion 未返回有效的完成地址。');
        }

        $this->jsonRequest('POST', $completeUrl, null);
    }

    /** 把一个已完成的 file_upload 挂到指定页面下，返回 Notion 返回的块对象。 */
    public function attachFileUpload(string $fileUploadId, string $pageId, string $mime): array
    {
        $blockType = $this->blockType($mime);

        return $this->jsonRequest(
            'PATCH',
            self::API_BASE . '/blocks/' . rawurlencode($pageId) . '/children',
            [
                'children' => [[
                    'object' => 'block',
                    'type' => $blockType,
                    $blockType => [
                        'type' => 'file_upload',
                        'file_upload' => ['id' => $fileUploadId],
                    ],
                ]],
            ]
        );
    }

    public function createUserSpace(string $parentPageId, string $username): string
    {
        $page = $this->jsonRequest('POST', self::API_BASE . '/pages', [
            'parent' => ['type' => 'page_id', 'page_id' => $parentPageId],
            'properties' => [
                'title' => [
                    'type' => 'title',
                    'title' => [[
                        'type' => 'text',
                        'text' => ['content' => 'Notion Drive · ' . $username],
                    ]],
                ],
            ],
        ]);
        $pageId = (string) ($page['id'] ?? '');
        if ($pageId === '') {
            throw new NotionApiException('无法为用户创建独立 Notion 文件空间。');
        }

        return $pageId;
    }

    public function downloadUrl(string $blockId): string
    {
        $block = $this->jsonRequest('GET', self::API_BASE . '/blocks/' . rawurlencode($blockId), null);
        $type = (string) ($block['type'] ?? '');
        $fileObject = $block[$type] ?? null;
        $url = is_array($fileObject) ? (string) ($fileObject['file']['url'] ?? '') : '';
        if ($url === '' || !self::isTrustedDownloadUrl($url)) {
            throw new NotionApiException('Notion 未返回有效的文件下载地址。');
        }

        return $url;
    }

    /**
     * 把指定文件块的真实内容下载到本地路径（用于在账户之间补副本：先取幸存副本字节，再重新上传到另一账户）。
     * 目标地址来自 Notion 返回的可信文件域名，写入失败或 HTTP 非 2xx 时抛异常。
     */
    public function downloadBlockToFile(string $blockId, string $destinationPath): void
    {
        $url = $this->downloadUrl($blockId);

        $handle = fopen($destinationPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法写入临时文件以下载副本。');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // 仅允许跟随 https 跳转，避免被重定向到 http 或内网地址
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            // 跟随跳转后的最终地址：必须仍落在 Notion 可信文件域名内，否则可能已经被引导到任意地址
            $effective = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
            $error = curl_error($curl);
        } finally {
            fclose($handle);
            // 不调用 curl_close()：该函数自 PHP 8.0 起即为空操作（CurlHandle 是对象，由 GC 回收），
            // PHP 8.5 起调用会产生弃用警告
        }

        if ($effective !== '' && !self::isTrustedDownloadUrl($effective)) {
            @unlink($destinationPath);
            throw new NotionApiException('下载地址被重定向到不可信域名，已中止。');
        }

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($destinationPath);
            throw new NotionApiException('下载 Notion 文件内容失败（HTTP ' . $status . '）' . ($error !== '' ? '：' . $error : ''), $status);
        }
    }

    public function archiveBlock(string $blockId): void
    {
        $this->jsonRequest('DELETE', self::API_BASE . '/blocks/' . rawurlencode($blockId), null);
    }

    public function archivePage(string $pageId): void
    {
        $this->jsonRequest('PATCH', self::API_BASE . '/pages/' . rawurlencode($pageId), ['in_trash' => true]);
    }

    /** 把误入回收站的页面移出回收站，用于空间页面被归档后的自愈。 */
    public function restorePage(string $pageId): void
    {
        $this->jsonRequest('PATCH', self::API_BASE . '/pages/' . rawurlencode($pageId), ['in_trash' => false]);
    }

    /**
     * 读取页面对象，用于校验 Token 是否有权访问该页面；不可读时抛 NotionApiException。
     *
     * @return array<string,mixed>
     */
    public function retrievePage(string $pageId): array
    {
        return $this->jsonRequest('GET', self::API_BASE . '/pages/' . rawurlencode($pageId), null);
    }

    /** 判断页面是否直接位于给定页面之下；查询失败（例如页面已被删除）时返回 false。 */
    public function pageIsUnder(string $pageId, string $parentPageId): bool
    {
        try {
            $page = $this->jsonRequest('GET', self::API_BASE . '/pages/' . rawurlencode($pageId), null);
        } catch (NotionApiException) {
            return false;
        }

        $parent = $page['parent'] ?? null;

        return is_array($parent)
            && \normalizePageId((string) ($parent['page_id'] ?? '')) === \normalizePageId($parentPageId);
    }

    private function jsonRequest(string $method, string $url, ?array $payload): array
    {
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Notion-Version: ' . self::API_VERSION,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($handle, $options);

        // GET / DELETE 天然幂等，可以安全重试
        return $this->execute($handle, $method === 'GET' || $method === 'DELETE');
    }

    /**
     * 并发发送全部文件分片：Notion 允许分片并行、乱序上传（只要 complete 之前把 1..N 发完即可），
     * 逐片串行会把每一次往返延迟累加，大文件因此非常慢。这里用 curl_multi 维持固定并发窗口，
     * 并让所有分片句柄共享连接缓存，省掉每片重复的 TCP / TLS 握手；
     * 429 / 5xx / 网络瞬时错误按与 execute() 一致的退避策略重发（重发同一分片只覆盖自身）。
     */
    private function sendParts(string $url, string $path, string $filename, string $mime, int $parts, ?callable $onProgress = null): void
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException('无法读取上传文件。');
        }

        $multi = curl_multi_init();
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);

        $active = [];
        // 已切好、等待并发槽的分片（含失败后待重发的）：优先于新分片发送，
        // 这样源文件流始终按分片号顺序前进，不会因重试读错字节
        $waiting = [];
        $remaining = range(1, $parts);
        // 已成功上传的分片数：用于向上层汇报写入进度（重发同一分片不会重复计数）
        $completed = 0;

        try {
            while ($remaining !== [] || $waiting !== [] || $active !== []) {
                while (count($active) < self::UPLOAD_CONCURRENCY && ($waiting !== [] || $remaining !== [])) {
                    if ($waiting === []) {
                        $part = array_shift($remaining);
                        $temporary = $this->spoolPart($source);
                        if ($temporary === null) {
                            throw new RuntimeException('无法创建分片临时文件。');
                        }
                        $waiting[] = ['part' => $part, 'file' => $temporary, 'attempts' => 0];
                    }

                    $entry = array_shift($waiting);
                    $entry['attempts']++;
                    $entry['state'] = new \stdClass();
                    $entry['state']->retryAfter = null;
                    $entry['handle'] = $this->initPartHandle($url, $entry['file'], $filename, $mime, (int) $entry['part'], $share, $entry['state']);
                    curl_multi_add_handle($multi, $entry['handle']);
                    $active[spl_object_id($entry['handle'])] = $entry;
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                    // 无可等待的描述符（极端情况）：短暂让出 CPU，避免空转
                    usleep(1000);
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $key = spl_object_id($handle);
                    if (!isset($active[$key])) {
                        continue;
                    }

                    $entry = $active[$key];
                    unset($active[$key]);
                    curl_multi_remove_handle($multi, $handle);

                    $outcome = $this->partOutcome($handle, $entry['state'], $entry['attempts']);

                    if ($outcome['ok']) {
                        @unlink($entry['file']);
                        $completed++;
                        if ($onProgress !== null) {
                            $onProgress($completed, $parts);
                        }
                        continue;
                    }

                    // 重试必须复用同一份分片文件：源文件流已前进，无法再切出同一段字节；
                    // 仅在放弃该分片时才删除临时文件
                    if (!$outcome['retryable'] || $entry['attempts'] >= self::MAX_UPLOAD_ATTEMPTS) {
                        @unlink($entry['file']);
                        throw new NotionApiException($outcome['message']);
                    }

                    $waiting[] = $entry;
                    usleep($outcome['delay']);
                }
            }
        } finally {
            // 句柄不再显式关闭（curl_close()/curl_share_close() 自 PHP 8.0 起为空操作、8.5 起弃用）：
            // $active / $share 离开作用域后由 GC 回收，$multi 仍保留 curl_multi_close()
            foreach ($active as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                @unlink($entry['file']);
            }
            foreach ($waiting as $entry) {
                @unlink($entry['file']);
            }
            curl_multi_close($multi);
            fclose($source);
        }
    }

    /**
     * 把源文件流的下一段切到独立临时文件：CURLFile 从磁盘流式读取，不会把分片读进内存；
     * 并发窗口内最多同时存在「并发数」份临时文件。
     */
    private function spoolPart($source): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'notion-drive-part-');
        if ($temporary === false) {
            return null;
        }

        $target = fopen($temporary, 'wb');
        if ($target === false) {
            @unlink($temporary);

            return null;
        }

        $copied = stream_copy_to_stream($source, $target, self::PART_SIZE);
        fclose($target);
        if ($copied === false) {
            @unlink($temporary);

            return null;
        }

        return $temporary;
    }

    /** 初始化一个分片上传句柄：鉴权头与超时同 sendStreamPart()，另加共享连接缓存与每句柄独立的 Retry-After 记录。 */
    private function initPartHandle(string $url, string $temporary, string $filename, string $mime, int $part, $share, object $state)
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($temporary, $mime, $filename),
                'part_number' => (string) $part,
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Notion-Version: ' . self::API_VERSION,
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SHARE => $share,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use ($state): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $state->retryAfter = trim(substr($header, strlen('Retry-After:')));
                }

                return strlen($header);
            },
        ]);

        return $handle;
    }

    /**
     * 汇总一个已完成分片句柄的结果：成功 / 可重试（含等待时间）/ 不可重试。
     *
     * @return array{ok: bool, retryable: bool, delay: int, message: string}
     */
    private function partOutcome($handle, object $state, int $attempts): array
    {
        $body = curl_multi_getcontent($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($body === false) {
            return [
                'ok' => false,
                'retryable' => in_array(curl_errno($handle), self::transientCurlErrors(), true),
                'delay' => self::retryDelay(null, $attempts),
                'message' => '连接 Notion 失败：' . curl_error($handle),
            ];
        }

        if ($status === 429 || $status >= 500) {
            // 429 在执行前就被拒绝、5xx 重发同一分片只覆盖自身，两者重试都安全
            return [
                'ok' => false,
                'retryable' => true,
                'delay' => self::retryDelay($state->retryAfter, $attempts),
                'message' => 'Notion 接口返回 HTTP ' . $status,
            ];
        }

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode((string) $body, true);

            return [
                'ok' => false,
                'retryable' => false,
                'delay' => 0,
                'message' => is_array($decoded) && isset($decoded['message'])
                    ? (string) $decoded['message']
                    : 'Notion 接口返回 HTTP ' . $status,
            ];
        }

        return ['ok' => true, 'retryable' => false, 'delay' => 0, 'message' => ''];
    }

    private function execute($handle, bool $idempotent): array
    {
        $attempt = 0;

        // 记录响应头里的 Retry-After，用于 429/5xx 时按服务端要求退避
        $retryAfter = null;
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($handle, string $header) use (&$retryAfter): int {
            if (stripos($header, 'Retry-After:') === 0) {
                $retryAfter = trim(substr($header, strlen('Retry-After:')));
            }

            return strlen($header);
        });

        do {
            $retryAfter = null;
            $body = curl_exec($handle);
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            // 网络层瞬时错误，以及 Notion 限流（429）与服务端错误（5xx）都值得重试
            $retryable = $body === false
                ? in_array($errorNumber, self::transientCurlErrors(), true)
                : ($status === 429 || $status >= 500);
            // 429 在执行前就被拒绝，重试安全；5xx 与网络中断无法确定是否已处理，只有幂等请求才可重试，否则会在 Notion 留下重复页面/文件块
            $safeToRetry = $status === 429 || $idempotent;
            if (!$retryable || !$safeToRetry || $attempt >= 2) {
                break;
            }

            usleep(self::retryDelay($retryAfter, $attempt));
            $attempt++;
        } while (true);

        if ($body === false) {
            throw new NotionApiException('连接 Notion 失败：' . $error);
        }

        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : 'Notion 接口返回 HTTP ' . $status;
            throw new NotionApiException($message, $status);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** 计算重试前的等待时间（微秒）：优先遵循 Retry-After，否则按 250ms/500ms 退避。 */
    private static function retryDelay(?string $retryAfter, int $attempt): int
    {
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            // 上限 30 秒，避免服务端给出过大的等待时间拖住用户请求
            return max(1, min(30, (int) $retryAfter)) * 1000000;
        }

        return 250000 * (2 ** $attempt);
    }

    private function isTrustedUploadUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'api.notion.com';
    }

    /**
     * 校验地址以 https 访问且主机位于 Notion 可信文件域名之下，避免把服务端当作任意地址的下载代理。
     * 下载（含跟随跳转后的最终地址）与预览直出都复用此判定。
     */
    public static function isTrustedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::TRUSTED_DOWNLOAD_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function blockType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            $mime === 'application/pdf' => 'pdf',
            default => 'file',
        };
    }
}
