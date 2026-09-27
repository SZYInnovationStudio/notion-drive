<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Database;

// 站点 logo / favicon / 公告图片都是公开资源，因此不做登录校验；
// storage 目录被 .htaccess 禁止直读，这里在服务端校验路径后再输出。
$kind = (string) ($_GET['k'] ?? '');

if ($kind === 'logo' || $kind === 'favicon') {
    $path = (string) Database::config($kind === 'favicon' ? 'site_favicon' : 'site_logo', '');

    // 只允许读取 storage/site 目录内的文件，避免路径穿越
    $base = realpath(__DIR__ . '/storage/site');
    $file = $path === '' ? false : realpath(__DIR__ . '/' . ltrim($path, '/\\'));
} elseif ($kind === 'media') {
    // 公告正文图片：仅允许 storage/announcements 下的普通文件名
    $filename = (string) ($_GET['f'] ?? '');
    if (preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) {
        http_response_code(404);
        exit;
    }
    $base = realpath(__DIR__ . '/storage/announcements');
    $file = realpath(__DIR__ . '/storage/announcements/' . $filename);
} else {
    http_response_code(404);
    exit;
}

if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit;
}

$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file);

header_remove('Cache-Control');
header('Cache-Control: public, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
readfile($file);