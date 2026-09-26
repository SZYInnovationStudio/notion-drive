<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;

// 作为图片资源接口必须返回 JSON 而非 302 跳转，否则未登录时 <img> 会拿到登录页 HTML
$currentUser = Auth::requireLogin(true);

$targetId = (int) ($_GET['u'] ?? 0);
if ($targetId <= 0) {
    http_response_code(404);
    exit;
}

// 头像属于个人资料：仅本人与管理员可查看，避免凭 ?u= 递增枚举全站用户头像
if ($targetId !== (int) $currentUser['id'] && !isAdmin($currentUser)) {
    http_response_code(403);
    exit;
}

$statement = Database::connection()->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $targetId]);
$avatarPath = (string) ($statement->fetchColumn() ?: '');

// 只允许读取 storage/avatars 目录内的文件，避免路径穿越
$base = realpath(__DIR__ . '/storage/avatars');
$file = $avatarPath === '' ? false : realpath(__DIR__ . '/' . ltrim($avatarPath, '/\\'));
if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit;
}

$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!str_starts_with($mime, 'image/')) {
    http_response_code(404);
    exit;
}

header_remove('Cache-Control');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
readfile($file);
