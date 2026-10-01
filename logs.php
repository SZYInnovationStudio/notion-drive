<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Logger;
use NotionDrive\Security;

$currentUser = Auth::requireAdmin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $target = (string) ($_POST['file'] ?? '');
        if (!Logger::isValidName($target)) {
            throw new RuntimeException('日志文件名无效。');
        }
        if ($action === 'clear') {
            Logger::clear($target) ? flash('success', '日志内容已清空。') : flash('error', '清空失败，请检查 storage/logs 目录权限。');
        } elseif ($action === 'delete') {
            Logger::remove($target) ? flash('success', '日志文件已删除。') : flash('error', '删除失败，请检查 storage/logs 目录权限。');
        } else {
            throw new RuntimeException('未知操作。');
        }
        header('Location: logs.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$files = Logger::files();
$selected = (string) ($_GET['file'] ?? '');
if ($selected === '' && $files !== []) {
    $selected = (string) $files[0]['name'];
}
if ($selected !== '' && !Logger::isValidName($selected)) {
    $selected = $files === [] ? '' : (string) $files[0]['name'];
}

$lineOptions = [100, 200, 500, 1000];
$lineLimit = (int) ($_GET['lines'] ?? 200);
if (!in_array($lineLimit, $lineOptions, true)) {
    $lineLimit = 200;
}
$level = strtoupper((string) ($_GET['level'] ?? 'ALL'));
if ($level !== 'ALL' && !in_array($level, Logger::LEVELS, true)) {
    $level = 'ALL';
}
$keyword = trim((string) ($_GET['q'] ?? ''));

$result = $selected === ''
    ? ['lines' => [], 'total' => 0, 'matched' => 0, 'truncated' => false]
    : Logger::read($selected, $lineLimit, $level, $keyword);

$flash = pullFlash();
$levelLabels = ['ALL' => '全部级别', 'ERROR' => '仅 ERROR', 'WARNING' => '仅 WARNING', 'INFO' => '仅 INFO'];
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('运行日志') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=60"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav"><a href="dashboard.php"><?= navIcon('files') ?>我的文件</a><a href="tickets.php"><?= navIcon('tickets') ?>工单管理</a><a href="admin.php"><?= navIcon('admin') ?>管理后台</a><a href="admin_orders.php"><?= navIcon('orders') ?>订单管理</a><a href="bans.php"><?= navIcon('bans') ?>封禁管理</a><a class="active" href="logs.php"><?= navIcon('logs') ?>运行日志</a><a href="account.php"><?= navIcon('account') ?>账户管理</a></nav>
        <?= sidebarStorageHtml($currentUser) ?>
        <?= sidebarIcpHtml() ?>
        <?= sidebarPoliceHtml() ?>
        <div class="account-menu"><?= avatarHtml($currentUser) ?><div><strong><?= e($currentUser['username']) ?></strong><small>管理员</small></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button>退出</button></form></div>
    </aside>
    <main class="drive-main admin-main">
        <?= banNoticeHtml($currentUser) ?>
        <header class="drive-header"><div><p>故障排查</p><h1>运行日志</h1></div><a class="secondary-button" href="admin.php">返回管理后台</a></header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>日志文件</h2><p>记录上传、下载、重命名、删除等操作的失败详情；文件保存在 storage/logs，已通过 storage/.htaccess 禁止直接访问。</p></div><span><?= count($files) ?> 个文件</span></div>
            <?php if (!$files): ?>
                <div class="empty-state compact-empty">暂无日志文件。发生失败操作后，这里会自动生成 app-日期.log。</div>
            <?php else: ?>
                <div class="log-file-list">
                    <?php foreach ($files as $file): ?>
                    <a class="log-file-chip<?= $file['name'] === $selected ? ' active' : '' ?>" href="logs.php?file=<?= e(rawurlencode($file['name'])) ?>">
                        <strong><?= e($file['name']) ?></strong><small><?= e(formatBytes($file['size'])) ?> · <?= e(date('m-d H:i', $file['modified'])) ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($selected !== ''): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>日志内容</h2><p><?= e($selected) ?> · 共 <?= (int) $result['total'] ?> 行，匹配 <?= (int) $result['matched'] ?> 行</p></div>
                <div class="log-quick">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="file" value="<?= e($selected) ?>"><input type="hidden" name="action" value="clear"><button class="text-action" data-confirm="确认清空该日志文件的内容？">清空</button></form>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="file" value="<?= e($selected) ?>"><input type="hidden" name="action" value="delete"><button class="delete-action" data-confirm="确认删除该日志文件？">删除文件</button></form>
                </div>
            </div>
            <form method="get" class="log-filters">
                <input type="hidden" name="file" value="<?= e($selected) ?>">
                <label><span>级别</span><select name="level"><?php foreach ($levelLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $level === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label><span>关键字</span><input name="q" value="<?= e($keyword) ?>" placeholder="文件名、用户、错误码"></label>
                <label><span>显示行数</span><select name="lines"><?php foreach ($lineOptions as $option): ?><option value="<?= $option ?>" <?= $lineLimit === $option ? 'selected' : '' ?>>最近 <?= $option ?> 行</option><?php endforeach; ?></select></label>
                <button class="primary-button compact">筛选</button>
            </form>
            <p class="log-meta">显示最近 <?= count($result['lines']) ?> 行<?= $result['truncated'] ? '（已按行数上限截断，可缩小筛选范围）' : '' ?></p>
            <?php if (!$result['lines']): ?>
                <div class="empty-state compact-empty">没有匹配的日志记录。</div>
            <?php else: ?>
                <ol class="log-view">
                    <?php foreach ($result['lines'] as $line): ?>
                    <li class="log-line log-<?= e(strtolower($line['level'])) ?>"><?= e($line['text']) ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
    <?= siteCreditHtml() ?>
    <div id="toast" class="toast"></div><script src="assets/app.js?v=29" defer></script><script src="assets/admin.js?v=9" defer></script>
</body></html>
