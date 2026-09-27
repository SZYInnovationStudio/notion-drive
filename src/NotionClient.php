<?php

declare(strict_types=1);

namespace NotionDrive;

use CURLFile;
use RuntimeException;

final class NotionClient
{
    private const API_BASE = 'https://api.notion.com/v1';
    private const API_VERSION = '2026-03-11';
    private const PART_SIZE = 10 * 1024 * 1024;

    /** 可信下载域名：Notion 官方域名及其文件托管的 S3 域名。 */
    private const TRUSTED_DOWNLOAD_DOMAINS = ['notion.com', 'notion.so', 'notion-static.com', 'amazonaws.com'];

    public function __construct(private readonly string $token)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('服务器未启用 PHP cURL 扩展。');
        }
    }

    public function uploadAndAttach(string $path, string $filename, string $mime, int $size, string $pageId): array
    {
        $multiPart = $size > 20 * 1024 * 1024;
        $parts = $multiPart ? (int) ceil($size / self::PART_SIZE) : 1;

        $payload = [
            'mode' => $multiPart ? 'multi_part' : 'single_part',
            'filename' => $filename,
            'content_type' => $mime,
        ];
        if ($multiPart) {
            $payload['number_of_parts'] = $parts;
        }

        $session = $this->jsonRequest('POST', self::API_BASE . '/file_uploads', $payload);
        $uploadId = (string) ($session['id'] ?? '');
        $uploadUrl = (string) ($session['upload_url'] ?? '');
        if ($uploadId === '' || !$this->isTrustedUploadUrl($uploadUrl)) {
            throw new NotionApiException('Notion 未返回有效的上传会话。');
        }

        if ($multiPart) {
            $this->sendParts($uploadUrl, $path, $filename, $mime, $parts);
            $completeUrl = (string) ($session['complete_url'] ?? self::API_BASE . '/file_uploads/' . $uploadId . '/complete');
            if (!$this->isTrustedUploadUrl($completeUrl)) {
                throw new NotionApiException('Notion 未返回有效的完成地址。');
            }
            $this->jsonRequest('POST', $completeUrl, null);
        } else {
            $this->sendFile($uploadUrl, $path, $filename, $mime);
        }

        $blockType = $this->blockType($mime);
        $attached = $this->jsonRequest(
            'PATCH',
            self::API_BASE . '/blocks/' . rawurlencode($pageId) . '/children',
            [
                'children' => [[
                    'object' => 'block',
                    'type' => $blockType,
                    $blockType => [
                        'type' => 'file_upload',
                        'file_upload' => ['id' => $uploadId],
                    ],
                ]],
            ]
        );

        return ['file_upload_id' => $uploadId, 'block' => $attached];
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
        if ($url === '' || !$this->isTrustedUrl($url, self::TRUSTED_DOWNLOAD_DOMAINS)) {
            throw new NotionApiException('Notion 未返回有效的文件下载地址。');
        }

        return $url;
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

    private function sendFile(string $url, string $path, string $filename, string $mime, ?int $part = null): void
    {
        $fields = ['file' => new CURLFile($path, $mime, $filename)];
        if ($part !== null) {
            $fields['part_number'] = (string) $part;
        }

        $handle = curl_init($url);
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
        // 同一分片重复上传只会覆盖该分片，不会产生额外数据，因此允许重试
        $this->execute($handle, true);
    }

    private function sendParts(string $url, string $path, string $filename, string $mime, int $parts): void
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException('无法读取上传文件。');
        }

        try {
            for ($part = 1; $part <= $parts; $part++) {
                $temporary = tempnam(sys_get_temp_dir(), 'notion-drive-part-');
                if ($temporary === false) {
                    throw new RuntimeException('无法创建分片临时文件。');
                }

                $target = fopen($temporary, 'wb');
                if ($target === false) {
                    @unlink($temporary);
                    throw new RuntimeException('无法写入分片临时文件。');
                }

                stream_copy_to_stream($source, $target, self::PART_SIZE);
                fclose($target);

                try {
                    $this->sendFile($url, $temporary, $filename, $mime, $part);
                } finally {
                    @unlink($temporary);
                }
            }
        } finally {
            fclose($source);
        }
    }

    private function execute($handle, bool $idempotent): array
    {
        $attempt = 0;
        $transientErrors = [
            CURLE_PARTIAL_FILE,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ];

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
                ? in_array($errorNumber, $transientErrors, true)
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
     * 校验地址以 https 访问且主机位于给定域名之下，避免把服务端当作任意地址的下载/上传代理。
     *
     * @param list<string> $domains
     */
    private function isTrustedUrl(string $url, array $domains): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach ($domains as $domain) {
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
