<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Moderation\ModerationService;
use NotionDrive\Security;
use NotionDrive\Tickets\TicketService;

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
$userId = (int) $user['id'];

// 工单到期清扫：客服回复后用户长期未回应、或关闭超过重开窗口的工单自动置为「已过期」
try {
    TicketService::create()->expireDueTickets();
} catch (Throwable $exception) {
    // 清扫属后台维护动作，失败不阻断文件页
}

// 内容风控提示：被判定违规而暂停使用的文件（含管理员已放行重传的），风控未启用或查询失败时静默跳过
try {
    $moderationNotices = ModerationService::create()->userNotices($userId);
} catch (Throwable $exception) {
    $moderationNotices = [];
}

// 申诉工单线程：在文件页直接展示用户与管理员的往来对话
$moderationThreads = [];
if ($moderationNotices !== []) {
    try {
        $moderationThreads = TicketService::create()->appealThreadsByRecords(array_column($moderationNotices, 'id'));
    } catch (Throwable $exception) {
        $moderationThreads = [];
    }
}

// 申诉提交：快捷提一张申诉工单并落一条留言，管理员在后台工单里回复
$appealError = '';
$appealSubmitted = (string) ($_GET['appeal'] ?? '') === 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'submit_appeal') {
    try {
        Security::verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
        ModerationService::create()->submitAppeal(
            (int) ($_POST['record_id'] ?? 0),
            $userId,
            (string) ($_POST['appeal_text'] ?? '')
        );
        header('Location: dashboard.php?appeal=ok');
        exit;
    } catch (Throwable $exception) {
        $appealError = $exception->getMessage() !== '' ? $exception->getMessage() : '提交失败，请稍后再试。';
    }
}

// 清除记录：用户把某条未通过审核的提示从本页隐藏（仅隐藏提示，不影响文件状态与申诉记录）
$noticeError = '';
$noticeCleared = (string) ($_GET['cleared'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'dismiss_notice') {
    try {
        Security::verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
        ModerationService::create()->dismissNotice((int) ($_POST['record_id'] ?? 0), $userId);
        header('Location: dashboard.php?cleared=ok');
        exit;
    } catch (Throwable $exception) {
        $noticeError = $exception->getMessage() !== '' ? $exception->getMessage() : '清除失败，请稍后再试。';
    }
}

// 一键删除：把本页全部未通过审核提示一次性隐藏（同单条清除口径，仅用户自己不可见，不动风控与违规计数）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'dismiss_all_notices') {
    try {
        Security::verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
        $clearedCount = ModerationService::create()->dismissAllNotices($userId);
        header('Location: dashboard.php?cleared=all&count=' . $clearedCount);
        exit;
    } catch (Throwable $exception) {
        $noticeError = $exception->getMessage() !== '' ? $exception->getMessage() : '清除失败，请稍后再试。';
    }
}

// 当前所在文件夹：?folder= 来自用户输入，必须校验归属，否则退回根目录
$currentFolderId = max(0, (int) ($_GET['folder'] ?? 0));
if ($currentFolderId > 0 && folderRow($userId, $currentFolderId) === null) {
    header('Location: dashboard.php');
    exit;
}
// 根目录用 NULL 匹配：folder_id <=> NULL 是 NULL 安全等值，能命中未归类的文件
$folderParam = $currentFolderId > 0 ? $currentFolderId : null;
$folderCrumbs = $currentFolderId > 0 ? folderAncestors($userId, $currentFolderId) : [];
$folders = folderChildren($userId, $currentFolderId);
$folderTree = folderTree($userId);
$folderFileCounts = folderFileCountMap($userId);
$folderQuery = $currentFolderId > 0 ? '&folder=' . $currentFolderId : '';

$perPage = 50;
$countStatement = Database::connection()->prepare(
    "SELECT COUNT(*) FROM uploads WHERE user_id = :user_id AND scope = 'drive' AND status = 'active' AND folder_id <=> :folder_id"
);
$countStatement->execute(['user_id' => $user['id'], 'folder_id' => $folderParam]);
$totalFiles = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiles / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;
// LIMIT/OFFSET 用内联整数：原生预处理把占位符当字符串绑定，会触发 SQL 语法错误
$statement = Database::connection()->prepare(
    "SELECT id, filename, display_name, file_size, mime_type, data_lost, created_at, updated_at
     FROM uploads WHERE user_id = :user_id AND scope = 'drive' AND status = 'active' AND folder_id <=> :folder_id
     ORDER BY id DESC LIMIT $perPage OFFSET $offset"
);
$statement->execute(['user_id' => $user['id'], 'folder_id' => $folderParam]);
$files = $statement->fetchAll();
$replicaSources = uploadReplicaSources(array_column($files, 'id'));
$premiumNotice = premiumNotice($user);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?= siteTitleTag('我的文件') ?>
    <?= siteFaviconTag() ?>
    <link rel="stylesheet" href="assets/app.css?v=54">
</head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>" data-limit="<?= $singleLimit ?>" data-remaining="<?= $remainingBytes === null ? '' : $remainingBytes ?>" data-folder="<?= $currentFolderId ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav" aria-label="主导航">
            <a class="active" href="dashboard.php"><span>▣</span>我的文件</a>
            <a href="tickets.php"><span>✉</span>我的工单</a>
            <?php if (isAdmin($user)): ?><a href="admin.php"><span>⚙</span>管理后台</a><a href="admin_orders.php"><span>¥</span>订单管理</a><a href="bans.php"><span>⛔</span>封禁管理</a><a href="logs.php"><span>☰</span>运行日志</a><?php endif; ?>
            <a href="account.php"><span>◍</span>账户管理</a>
            <?php if (!isAdmin($user)): ?><a href="pay.php"><span>★</span>会员套餐</a><?php endif; ?>
        </nav>
        <?= sidebarStorageHtml($user) ?>
        <?= sidebarIcpHtml() ?>
        <?= sidebarPoliceHtml() ?>
        <div class="account-menu">
            <?= avatarHtml($user) ?>
            <div><strong><?= e($user['username']) ?></strong><small><?= isAdmin($user) ? '管理员' : e((string) ($user['group_name'] ?? '用户')) ?></small></div>
            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button title="退出登录">退出</button></form>
        </div>
    </aside>

    <main class="drive-main">
        <?= banNoticeHtml($user) ?>
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

        <?php if ($appealSubmitted): ?>
        <div class="alert success">申诉已提交，已自动生成工单，管理员会尽快处理并回复。</div>
        <?php elseif ($appealError !== ''): ?>
        <div class="alert error"><?= e($appealError) ?></div>
        <?php endif; ?>

        <?php if ($noticeCleared === 'all'): ?>
        <div class="alert success">已清除全部 <?= max(0, (int) ($_GET['count'] ?? 0)) ?> 条审核提示。文件仍保持不可用；管理员重新处理这些记录时，提示会再次出现。</div>
        <?php elseif ($noticeCleared === 'ok'): ?>
        <div class="alert success">已清除该条审核提示。文件仍保持不可用；管理员重新处理该记录时，提示会再次出现。</div>
        <?php elseif ($noticeError !== ''): ?>
        <div class="alert error"><?= e($noticeError) ?></div>
        <?php endif; ?>

        <?php if ($moderationNotices !== []): ?>
        <div class="alert warning">
            <div class="alert-head">
                <strong>有 <?= count($moderationNotices) ?> 个文件未通过内容审核</strong>
                <span>如认为误判，可直接提交申诉（自动生成工单），管理员会在工单里与你对话。</span>
            </div>
            <form method="post" class="notice-bulk">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <input type="hidden" name="action" value="dismiss_all_notices">
                <label class="notice-check-all"><input type="checkbox" data-check-all="notice-check"> 全选</label>
                <button type="submit" class="text-action" data-confirm="确认删除全部 <?= count($moderationNotices) ?> 条审核提示？删除后本页不再显示这些提示（文件仍不可用），且无法再对其提交申诉。">一键删除</button>
            </form>
            <details class="alert-fold">
                <summary>点此展开 / 收起文件清单（<?= count($moderationNotices) ?> 个）</summary>
            <ul class="alert-list">
                <?php foreach ($moderationNotices as $notice): ?>
                <?php
                    $recordId = (int) $notice['id'];
                    $thread = $moderationThreads[$recordId] ?? null;
                    $threadMessages = $thread['messages'] ?? [];
                ?>
                <li>
                    <label class="notice-check"><input type="checkbox" name="notice-check" value="<?= $recordId ?>"></label>
                    <span>
                        <?= e((string) $notice['filename']) ?>
                        <?php if ((string) $notice['reason'] !== ''): ?><small><?= e((string) $notice['reason']) ?></small><?php endif; ?>
                    </span>
                    <span>
                        申诉号 <strong><?= e((string) $notice['appeal_no']) ?></strong>
                        <small>
                            <?= (string) $notice['status'] === ModerationService::STATUS_RESUBMIT
                                ? '管理员已放行重传，请重新上传后等待复核'
                                : ((string) $notice['stage'] === ModerationService::STAGE_START ? '上传被拒绝' : '已暂停使用') . ' · 等待管理员复核' ?>
                            · <?= e((string) $notice['created_at']) ?>
                        </small>
                        <?php if ($threadMessages !== []): ?>
                        <div class="ticket-thread">
                            <?php foreach ($threadMessages as $message): ?>
                            <?php $fromAdmin = (string) $message['author_role'] === TicketService::ROLE_ADMIN; ?>
                            <div class="ticket-msg <?= $fromAdmin ? 'from-admin' : 'from-user' ?>">
                                <div class="ticket-msg-meta"><?= $fromAdmin ? '管理员' : '我' ?> · <?= e((string) $message['created_at']) ?></div>
                                <div class="ticket-msg-body"><?= nl2br(e((string) $message['body'])) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <details class="appeal-box">
                            <summary><?= $threadMessages !== [] ? '继续申诉 / 补充说明' : '填写申诉理由' ?></summary>
                            <form method="post" class="appeal-form">
                                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                                <input type="hidden" name="action" value="submit_appeal">
                                <input type="hidden" name="record_id" value="<?= $recordId ?>">
                                <textarea name="appeal_text" rows="3" maxlength="1000" required placeholder="说明情况，例如文件用途、误判理由等（最多 1000 字）"></textarea>
                                <button type="submit" class="save-action">提交申诉</button>
                            </form>
                        </details>
                        <?php if ($thread !== null): ?>
                        <a class="ticket-more" href="tickets.php?id=<?= (int) $thread['ticket']['id'] ?>">查看完整对话 →</a>
                        <?php endif; ?>
                        <form method="post" class="notice-dismiss">
                            <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                            <input type="hidden" name="action" value="dismiss_notice">
                            <input type="hidden" name="record_id" value="<?= $recordId ?>">
                            <button type="submit" class="text-action" data-confirm="确认清除这条记录？清除后本页不再显示该提示（文件仍不可用），且无法再对其提交申诉。">清除记录</button>
                        </form>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            </details>
        </div>
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
            <?php if ($folderCrumbs): ?>
            <nav class="folder-breadcrumb" aria-label="文件夹路径">
                <a href="dashboard.php">全部文件</a>
                <?php foreach ($folderCrumbs as $crumb): ?>
                    <span class="crumb-sep">/</span>
                    <?php if ((int) $crumb['id'] === $currentFolderId): ?>
                        <strong><?= e((string) $crumb['name']) ?></strong>
                    <?php else: ?>
                        <a href="dashboard.php?folder=<?= (int) $crumb['id'] ?>"><?= e((string) $crumb['name']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <div class="file-section-head"><div><h2>全部文件</h2><span><?= count($folders) ?> 个文件夹 · <?= $totalFiles ?> 个文件<?= $totalPages > 1 ? ' · 第 ' . $page . '/' . $totalPages . ' 页' : '' ?></span></div><div class="file-section-tools"><button type="button" id="newFolderButton" class="secondary-button" data-parent="<?= $currentFolderId ?>">新建文件夹</button><span class="sync-state">文件已同步到 Notion 空间</span></div></div>
            <?php if (!$files && !$folders): ?>
                <div class="empty-state" id="emptyState"><span>□</span><h3>这里还没有文件</h3><p>上传第一个文件后，它会显示在这里。</p></div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table" id="fileTable">
                    <thead><tr><th>名称</th><th>类型</th><th>大小</th><th>更新时间</th><th><span class="sr-only">操作</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($folders as $folder): $folderInside = (int) ($folderFileCounts[(int) $folder['id']] ?? 0); ?>
                        <tr class="folder-row" data-folder-id="<?= (int) $folder['id'] ?>" data-file-name="<?= e(mb_strtolower((string) $folder['name'])) ?>" data-folder-inside="<?= $folderInside ?>">
                            <td><div class="file-name"><span class="file-kind folder-kind">DIR</span><div><a class="display-name folder-link" href="dashboard.php?folder=<?= (int) $folder['id'] ?>"><?= e((string) $folder['name']) ?></a><small>含 <?= $folderInside ?> 个文件</small></div></div></td>
                            <td data-label="类型">文件夹</td>
                            <td data-label="大小">—</td>
                            <td data-label="更新时间"><?= e(date('Y-m-d H:i', strtotime((string) $folder['updated_at']))) ?></td>
                            <td><div class="file-actions"><button data-action="folder-rename">重命名</button><button class="danger" data-action="folder-delete">删除</button></div></td>
                        </tr>
                    <?php endforeach; ?>
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
                        $isLost = (int) ($file['data_lost'] ?? 0) === 1;
                        if ($isLost) {
                            $storageLabel = '数据已丢失，请重新上传';
                        }
                    ?>
                        <tr data-file-id="<?= (int) $file['id'] ?>" data-file-name="<?= e(mb_strtolower($name)) ?>"<?= $isLost ? ' class="file-lost"' : '' ?>>
                            <td><div class="file-name"><span class="file-kind"><?= e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) ?></span><div><strong class="display-name"><?= e($name) ?></strong><small><?= e($storageLabel) ?></small></div><?php if ($isLost): ?><span class="lost-badge">数据已丢失</span><?php endif; ?></div></td>
                            <td data-label="类型"><?= e(explode('/', (string) $file['mime_type'])[0]) ?></td>
                            <td data-label="大小"><?= e(formatBytes((int) $file['file_size'])) ?></td>
                            <td data-label="更新时间"><?= e(date('Y-m-d H:i', strtotime((string) $file['updated_at']))) ?></td>
                            <td><div class="file-actions"><?php if (!$isLost && filePreviewable($file)): ?><button data-action="preview" data-preview-kind="<?= e(previewKind($file)) ?>" data-preview-mime="<?= e((string) $file['mime_type']) ?>" data-preview-size="<?= (int) $file['file_size'] ?>" data-preview-name="<?= e($name) ?>">预览</button><?php endif; ?><?php if (!$isLost): ?><a href="api/file.php?id=<?= (int) $file['id'] ?>" title="下载">下载</a><button data-action="rename">重命名</button><button data-action="move">移动</button><?php endif; ?><button class="danger" data-action="delete">删除</button></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="log-quick">
                <?php if ($page > 1): ?><a class="secondary-button" href="dashboard.php?page=<?= $page - 1 ?><?= $folderQuery ?>">上一页</a><?php endif; ?>
                <span class="log-meta">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?><a class="secondary-button" href="dashboard.php?page=<?= $page + 1 ?><?= $folderQuery ?>">下一页</a><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <template id="folderOptions"><option value="0">根目录（不放入文件夹）</option><?php foreach ($folderTree as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e(str_repeat('　', (int) $item['depth']) . (string) $item['name']) ?></option><?php endforeach; ?></template>
        </section>
    </main>
    <?php $announcements = announcementList(); ?>
    <?php if ($announcements): ?>
    <div id="announceModal" class="announce-overlay" hidden>
        <div class="announce-dialog" role="dialog" aria-modal="true" aria-labelledby="announceHeading">
            <h2 id="announceHeading">站点公告</h2>
            <div class="announce-list">
                <?php foreach ($announcements as $item): ?>
                <article class="announce-item" data-announce-id="<?= (int) $item['id'] ?>" data-announce-sig="<?= e($item['sig']) ?>" hidden>
                    <h3 class="announce-title"><?= e($item['title']) ?></h3>
                    <div class="announce-body"><?= renderMarkdown($item['body']) ?></div>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="announce-actions">
                <label class="announce-remind">提醒
                    <select id="announceReminder">
                        <option value="again">每次进入都提醒</option>
                        <option value="0">不再提醒（永久）</option>
                        <option value="3">3 天内不再提醒</option>
                        <option value="7">7 天内不再提醒</option>
                        <option value="15">15 天内不再提醒</option>
                        <option value="30">30 天内不再提醒</option>
                        <option value="custom">自定义天数</option>
                    </select>
                </label>
                <input id="announceCustomDays" class="announce-custom" type="number" min="1" max="3650" value="7" hidden>
                <button type="button" id="announceAck" class="primary-button">我知道了</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?= siteCreditHtml() ?>
    <div id="previewModal" class="preview-overlay" hidden data-warn-bytes="<?= (int) previewWarnBytes() ?>">
        <div class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="previewHeading">
            <div class="preview-head">
                <h2 id="previewHeading" class="preview-title">文件预览</h2>
                <div class="preview-head-actions">
                    <a id="previewDownload" class="secondary-button compact" href="#" download>下载</a>
                    <button type="button" id="previewClose" class="secondary-button compact">关闭</button>
                </div>
            </div>
            <div class="preview-body" id="previewBody"></div>
        </div>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=28" defer></script>
</body>
</html>
