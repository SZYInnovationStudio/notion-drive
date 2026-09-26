<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;

final class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): void
    {
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            throw new RuntimeException('页面已过期，请刷新后重试。');
        }
    }

    public static function encrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('敏感配置加密失败。');
        }

        return 'enc:' . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload, string $key): string
    {
        if (!str_starts_with($payload, 'enc:')) {
            return $payload;
        }

        $decoded = base64_decode(substr($payload, 4), true);
        if ($decoded === false || strlen($decoded) < 29) {
            throw new RuntimeException('敏感配置格式无效。');
        }

        $plaintext = openssl_decrypt(
            substr($decoded, 28),
            'aes-256-gcm',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16)
        );

        if ($plaintext === false) {
            throw new RuntimeException('无法解密敏感配置。');
        }

        return $plaintext;
    }
}
