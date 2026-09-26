<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    Security::verifyCsrf($_POST['csrf_token'] ?? null);
} catch (Throwable $exception) {
    // CSRF 校验失败不做登出（防止第三方页面构造请求强制踢出登录），仅提示后回到首页
    flash('error', '页面已过期，请刷新后重试。');
    header('Location: index.php');
    exit;
}
Auth::logout();
header('Location: index.php');
exit;
