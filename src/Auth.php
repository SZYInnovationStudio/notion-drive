<?php

declare(strict_types=1);

namespace NotionDrive;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT id, is_admin, username, quota_bytes, notion_page_id,
                    notion_token_encrypted, notion_parent_page_id, notion_account_source,
                    email, email_verified_at, avatar_path
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
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
