<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Security;

$user = Auth::requireLogin();
// 到期前 3 天的邮件提醒：无 cron，靠页面访问懒触发，同一到期周期只发一次
maybeSendPremiumReminder($user);
$singleLimit = userSingleFileLimit($user);
$usedBytes = userUsedBytes((int) $user['id']);
$quotaBytes = userQuotaBytes($user);
// 剩余容量：null 表示不限总容量。前端据此把「单文件超限」与「剩余空间不足」分开提示
$remainingBytes = $quotaBytes === null ? null : max(0, $quotaBytes - $usedBytes);
$needsEmail = !mayUpload($user);
$uploadBlocked = uploadBlockedReason($user);
$notionReady = notionSourceUsable($user);
$usagePercent = $quotaBytes && $quotaBytes > 0 ? min(100, (int) round($usedBytes / $quotaBytes * 100)) : 0;
// 文件列表分页：文件多了以后不能一次全查出来
$perPage = 50;
$countStatement = Database::connection()->prepare(
    "SELECT COUNT(*) FROM uploads WHERE user_id = :user_id AND status = 'active'"
);
$countStatement->execute(['user_id' => $user['id']]);
$totalFiles = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiles / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;
// LIMIT/OFFSET 用内联整数：原生预处理把占位符当字符串绑定，会触发 SQL 语法错误
$statement = Database::connection()->prepare(
    "SELECT id, filename, display_name, file_size, mime_type, created_at, updated_at
     FROM uploads WHERE user_id = :user_id AND status = 'active' ORDER BY id DESC LIMIT $perPage OFFSET $offset"
);
$statement->execute(['user_id' => $user['id']]);
$files = $statement->fetchAll();
$replicaSources = uploadReplicaSources(array_column($files, 'id'));
$planLabel = premiumShortStatus($user);
$premiumNotice = premiumNotice($user);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>我的文件 · Notion Drive</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/app.css?v=20">
</head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>" data-limit="<?= $singleLimit ?>" data-remaining="<?= $remainingBytes === null ? '' : $remainingBytes ?>">
    <aside class="drive-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">N</span><span>Notion Drive</span></a>
        <nav class="drive-nav" aria-label="主导航">
            <a class="active" href="dashboard.php"><span>▣</span>我的文件</a>
            <?php if (isAdmin($user)): ?><a href="admin.php"><span>⚙</span>管理后台</a><a href="admin_orders.php"><span>¥</span>订单管理</a><a href="logs.php"><span>☰</span>运行日志</a><?php endif; ?>
            <a href="account.php"><span>◍</span>账户管理</a>
            <?php if (!isAdmin($user)): ?><a href="pay.php"><span>★</span>会员套餐</a><?php endif; ?>
        </nav>
        <div class="sidebar-storage">
            <div><span>已用空间</span><strong><?= e(formatBytes($usedBytes)) ?></strong></div>
            <?php if ($quotaBytes !== null): ?><progress max="100" value="<?= $usagePercent ?>"></progress><small>共 <?= e(formatBytes($quotaBytes)) ?></small><?php else: ?><small>不限总容量</small><?php endif; ?>
            <?php if (!isAdmin($user)): ?>
            <div><span>会员套餐</span><strong><?= e($planLabel) ?></strong></div>
            <small><a href="pay.php"><?= isPremiumUser($user) ? '续费 / 延长' : '升级高级用户' ?></a></small>
            <?php endif; ?>
        </div>
        <div class="account-menu">
            <?= avatarHtml($user) ?>
            <div><strong><?= e($user['username']) ?></strong><small><?= isAdmin($user) ? '管理员' : e((string) ($user['group_name'] ?? '用户')) ?></small></div>
            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button title="退出登录">退出</button></form>
        </div>
    </aside>

    <main class="drive-main">
        <header class="drive-header">
            <div><p>个人文件空间</p><h1>我的文件</h1></div>
            <label class="search-box"><span>⌕</span><input id="fileSearch" type="search" placeholder="搜索文件" aria-label="搜索文件"></label>
        </header>

        <?php if ($needsEmail): ?>
        <div class="alert error">管理员已开启「强制绑定邮箱」，请先到 <a href="account.php">账户管理</a> 绑定并验证邮箱后才能上传文件。</div>
        <?php endif; ?>
        <?php if ($uploadBlocked !== ''): ?>
        <div class="alert error"><?= e($uploadBlocked) ?> <a href="pay.php">立即续费</a> · <a href="account.php">切换账户来源</a></div>
        <?php elseif ($premiumNotice !== ''): ?>
        <div class="alert error"><?= e($premiumNotice) ?> <a href="pay.php">立即续费</a></div>
        <?php endif; ?>
        <?php if (!$notionReady && $uploadBlocked === ''): ?>
        <div class="alert error">当前 Notion 账户尚未就绪，暂时无法上传。请到 <a href="account.php">账户管理</a> 完成账户绑定或切换到可用来源。</div>
        <?php endif; ?>

        <section class="upload-card">
            <div class="upload-copy">
                <h2>上传文件</h2>
                <p>把文件拖到虚线区域，或从电脑中选择文件。</p>
                <ul>
                    <li>当前单文件上限：<strong><?= e(formatBytes($singleLimit)) ?></strong></li>
                    <li>个人剩余空间：<strong><?= $quotaBytes === null ? '不限' : e(formatBytes(max(0, $quotaBytes - $usedBytes))) ?></strong></li>
                    <li>文件名最长 900 字节</li>
                </ul>
                <details class="type-details"><summary>查看支持的文件类型</summary><p>文档：PDF、Word、Excel、PPT、TXT、Markdown、CSV、JSON、压缩包；图片：JPG、PNG、GIF、SVG、WebP；音视频：MP3、WAV、MP4、MOV、WebM；以及 Notion 支持的 CAD/3D 文件。</p></details>
            </div>
            <div id="dropZone" class="drop-zone" tabindex="0" role="button" aria-label="选择上传文件">
                <input id="fileInput" type="file" multiple hidden>
                <span class="upload-icon">↑</span><strong>拖放文件到这里</strong><small>或点击选择文件</small>
                <button type="button" id="browseButton">选择文件</button>
            </div>
            <div id="queue" class="upload-queue" aria-live="polite"></div>
        </section>

        <section class="file-section">
            <div class="file-section-head"><div><h2>全部文件</h2><span><?= $totalFiles ?> 个文件<?= $totalPages > 1 ? ' · 第 ' . $page . '/' . $totalPages . ' 页' : '' ?></span></div><span class="sync-state">文件已同步到 Notion 空间</span></div>
            <?php if (!$files): ?>
                <div class="empty-state" id="emptyState"><span>□</span><h3>这里还没有文件</h3><p>上传第一个文件后，它会显示在这里。</p></div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table" id="fileTable">
                    <thead><tr><th>名称</th><th>类型</th><th>大小</th><th>更新时间</th><th><span class="sr-only">操作</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($files as $file): $name = (string) ($file['display_name'] ?: $file['filename']); ?>
                    <?php
                        // 来源标记：文件实际存放在自有账户还是官方账户，以及是否完成了双写
                        $sources = $replicaSources[(int) $file['id']] ?? [];
                        $origin = '自有账户';
                        foreach ($sources as $source) {
                            if ($source['type'] !== 'own') {
                                $origin = '官方账户';
                                break;
                            }
                        }
                        $storageLabel = $sources === [] ? '已安全存储' : sprintf('%s · %d 份副本', $origin, count($sources));
                    ?>
                        <tr data-file-id="<?= (int) $file['id'] ?>" data-file-name="<?= e(mb_strtolower($name)) ?>">
                            <td><div class="file-name"><span class="file-kind"><?= e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) ?></span><div><strong class="display-name"><?= e($name) ?></strong><small><?= e($storageLabel) ?></small></div></div></td>
                            <td data-label="类型"><?= e(explode('/', (string) $file['mime_type'])[0]) ?></td>
                            <td data-label="大小"><?= e(formatBytes((int) $file['file_size'])) ?></td>
                            <td data-label="更新时间"><?= e(date('Y-m-d H:i', strtotime((string) $file['updated_at']))) ?></td>
                            <td><div class="file-actions"><a href="api/file.php?id=<?= (int) $file['id'] ?>" title="下载">下载</a><button data-action="rename">重命名</button><button class="danger" data-action="delete">删除</button></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="log-quick">
                <?php if ($page > 1): ?><a class="secondary-button" href="dashboard.php?page=<?= $page - 1 ?>">上一页</a><?php endif; ?>
                <span class="log-meta">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?><a class="secondary-button" href="dashboard.php?page=<?= $page + 1 ?>">下一页</a><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=13" defer></script>
</body>
</html>
