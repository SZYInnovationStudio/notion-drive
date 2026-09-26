<?php

declare(strict_types=1);

// 接口返回 JSON、下载转发原始字节，响应体里多一个字符都会损坏内容，因此不把 PHP 警告输出到页面（仍会写入错误日志）
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}

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
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
