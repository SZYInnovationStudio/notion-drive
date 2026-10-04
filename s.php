<?php

declare(strict_types=1);

// 公开直链入口：**不需要登录**，凭 URL 里的 token 访问。
// 鉴权与状态校验全部交给 ShareService::resolve()，取内容复用 FileStreamer（与站内下载同一实现）。
require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Files\FileStreamer;
use NotionDrive\Logger;
use NotionDrive\Share\ShareService;

$token = trim((string) ($_GET['t'] ?? ''));

/** 直链失败页：不泄露文件信息，也不回显 token。 */
$fail = static function (int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . siteTitleTag('链接不可用') . siteFaviconTag()
        . '<link rel="stylesheet" href="assets/app.css?v=67"></head>'
        . '<body><div class="confirm-overlay"><div class="confirm-dialog">'
        . '<h2>链接不可用</h2><p>' . e($message) . '</p>'
        . '</div></div></body></html>';
    exit;
};

$resolved = ShareService::resolve($token);
if (!$resolved['ok']) {
    $fail((int) $resolved['http'], (string) $resolved['message']);
}

$file = $resolved['file'];
$share = $resolved['share'];

// 直链默认按「预览」打开：只有真正可预览的类型才请求内联输出，其余类型自动退化为下载
// （FileStreamer 在 inline 请求命中非预览白名单时会直接 404，因此判断必须放在这里）
$download = (string) ($_GET['dl'] ?? '') === '1';
$inlineRequested = false;
if (!$download) {
    $kind = previewKind($file);
    $inlineRequested = $kind !== '' && filePreviewable($file) && previewContentType($file, $kind) !== null;
}

try {
    // 先记账再输出：即便传输中途断开，也说明这条链接确实被访问过
    ShareService::touch($share, empty($_SERVER['HTTP_RANGE']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    FileStreamer::stream($file, $resolved['owner'], $inlineRequested, [
        'viewer_id' => null,
        'via' => 'share',
        'share_id' => (int) $share['id'],
    ]);
} catch (Throwable $exception) {
    Logger::error('share', '直链取回内容失败：' . $exception->getMessage(), [
        'share_id' => (int) $share['id'],
        'file_id' => (int) $file['id'],
        'exception' => $exception::class,
    ]);
    // 已经开始输出内容时无法再改写响应，只能截断（与站内下载的处理口径一致）
    if (headers_sent()) {
        exit;
    }
    $fail(502, '暂时无法取回文件，请稍后再试。');
}