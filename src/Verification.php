<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;

/** 邮箱验证码：6 位数字、10 分钟有效、同邮箱 60 秒冷却、最多错 5 次。 */
final class Verification
{
    public const TTL_SECONDS = 600;
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;
    /** 同一 IP 在 IP_WINDOW_SECONDS 内最多请求的验证码条数。 */
    public const MAX_SENDS_PER_IP = 10;
    public const IP_WINDOW_SECONDS = 600;

    /** 签发验证码并返回明文（由调用方负责发送邮件）。 */
    public static function issue(string $email, string $purpose): string
    {
        $pdo = Database::connection();
        $email = strtolower(trim($email));

        // 按来源 IP 限流：单邮箱冷却挡不住轮换收件地址的骚扰式发信，因此再加一层 IP 维度的上限
        $ip = clientIp();
        $ipStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM email_verifications
             WHERE ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::IP_WINDOW_SECONDS . ' SECOND)'
        );
        $ipStatement->execute(['ip' => $ip]);
        if ((int) $ipStatement->fetchColumn() >= self::MAX_SENDS_PER_IP) {
            throw new RuntimeException('验证码请求过于频繁，请稍后再试。');
        }

        $statement = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM email_verifications
             WHERE email = :email AND purpose = :purpose AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['email' => $email, 'purpose' => $purpose]);
        $elapsed = $statement->fetchColumn();
        if ($elapsed !== false && (int) $elapsed < self::RESEND_COOLDOWN_SECONDS) {
            throw new RuntimeException(
                '验证码已发送，请 ' . (self::RESEND_COOLDOWN_SECONDS - (int) $elapsed) . ' 秒后再试。'
            );
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO email_verifications (email, ip, purpose, code_hash, expires_at)
             VALUES (:email, :ip, :purpose, :code_hash, DATE_ADD(NOW(), INTERVAL ' . self::TTL_SECONDS . ' SECOND))'
        )->execute([
            'email' => $email,
            'ip' => $ip,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
        ]);

        return $code;
    }

    /**
     * 回滚本次签发的验证码：邮件发送失败时调用，避免用户被 60 秒冷却挡住无法立即重试。
     * 只删最新一条未消费记录（即本次签发的那条），不动此前已发出、用户可能仍在使用的验证码。
     */
    public static function discard(string $email, string $purpose): void
    {
        Database::connection()->prepare(
            'DELETE FROM email_verifications
             WHERE email = :email AND purpose = :purpose AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        )->execute(['email' => strtolower(trim($email)), 'purpose' => $purpose]);
    }

    /** 校验验证码；成功时消费该验证码并返回 null，失败返回错误信息。 */
    public static function check(string $email, string $purpose, string $code): ?string
    {
        $pdo = Database::connection();
        $email = strtolower(trim($email));
        $code = trim($code);

        $statement = $pdo->prepare(
            'SELECT id, code_hash, attempts, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_left
             FROM email_verifications
             WHERE email = :email AND purpose = :purpose AND consumed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['email' => $email, 'purpose' => $purpose]);
        $record = $statement->fetch();

        if (!is_array($record)) {
            return '请先获取邮箱验证码。';
        }
        if ((int) $record['seconds_left'] <= 0) {
            return '验证码已过期，请重新获取。';
        }
        if ((int) $record['attempts'] >= self::MAX_ATTEMPTS) {
            return '验证码错误次数过多，请重新获取。';
        }
        if (!hash_equals((string) $record['code_hash'], hash('sha256', $code))) {
            $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id')
                ->execute(['id' => (int) $record['id']]);

            return '验证码不正确。';
        }

        $pdo->prepare('UPDATE email_verifications SET consumed_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $record['id']]);

        return null;
    }
}
