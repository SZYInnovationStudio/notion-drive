<?php

declare(strict_types=1);

// 接口返回 JSON、下载转发原始字节，响应体里多一个字符都会损坏内容，因此不把 PHP 警告输出到页面（仍会写入错误日志）
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}

// 全站时间统一使用东八区（UTC+8）：PHP 与 MySQL 会话时区（见 Database::connection()）都以此为准
date_default_timezone_set('Asia/Shanghai');

if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'NotionDrive\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';

session_name('notion_drive_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => requestIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (is_file(dirname(__DIR__) . '/storage/installed.lock')) {
    // 结构升级失败不能让整站白屏：记录日志后继续运行，避免每次请求都因同一个错误中断
    try {
        NotionDrive\Schema::migrate();
    } catch (Throwable $exception) {
        NotionDrive\Logger::error('schema', '数据库结构升级失败：' . $exception->getMessage(), [
            'exception' => $exception::class,
        ]);
    }

    // R13 每日 12:00 后由「已登录用户访问 HTML 页面」懒触发一次空间巡检（当天只跑一次）。
    // 刻意避开 api/* 与二进制资源接口，避免给上传/下载等关键路径增加额外 Notion 往返延迟。
    if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $entryScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $patrolPages = ['dashboard.php', 'admin.php', 'admin_orders.php', 'account.php', 'tickets.php', 'bans.php', 'logs.php', 'pay.php'];
        if (in_array($entryScript, $patrolPages, true)) {
            try {
                if (NotionDrive\Auth::user() !== null) {
                    NotionDrive\Space\SpacePatrol::runIfDue();
                }
            } catch (Throwable $exception) {
                NotionDrive\Logger::error('patrol', '空间巡检懒触发调度失败：' . $exception->getMessage(), [
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self'; frame-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
