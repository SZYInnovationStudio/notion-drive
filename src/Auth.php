<?php

declare(strict_types=1);

namespace NotionDrive;

final class Auth
{
    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT u.id, u.username, u.quota_bytes, u.notion_page_id, u.group_id,
                    u.notion_token_encrypted, u.notion_parent_page_id, u.notion_account_source,
                    u.premium_expires_at, u.premium_reminder_sent_at,
                    u.email, u.email_verified_at, u.avatar_path,
                    g.name AS group_name, g.quota_bytes AS group_quota_bytes,
                    g.single_file_limit_bytes AS group_single_file_limit_bytes, g.is_admin AS group_is_admin
             FROM users u LEFT JOIN `groups` g ON g.id = u.group_id
             WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();

        if (!$user) {
            return null;
        }

        // 套餐到期的懒检查：访问任意页面时把已到期的付费用户降级回普通用户组
        return \downgradeExpiredPremium($user);
    }

    public static function attempt(string $username, string $password): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT id, password_hash FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        return true;
    }

    public static function requireLogin(bool $json = false): array
    {
        $user = self::user();
        if ($user !== null) {
            return $user;
        }

        if ($json) {
            jsonResponse(['ok' => false, 'message' => '登录已过期，请重新登录。'], 401);
        }

        header('Location: index.php');
        exit;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!\isAdmin($user)) {
            http_response_code(403);
            exit('无权访问此页面。');
        }

        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], true);
        }
        session_destroy();
    }
}
