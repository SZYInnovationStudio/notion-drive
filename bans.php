<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Bans\BanService;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;

$currentUser = Auth::requireAdmin();
$error = '';
$service = BanService::create();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        // 自动封禁阈值：违规（被打回的审核记录）累计达到该次数即自动封号
        if ($action === 'save_threshold') {
            $threshold = (int) ($_POST['ban_threshold'] ?? 0);
            if ($threshold < 1 || $threshold > 100) {
                throw new RuntimeException('自动封禁阈值需为 1–100 之间的整数。');
            }
            $service->setThreshold($threshold);
            Logger::info('ban', '管理员更新自动封禁阈值。', [
                'threshold' => $threshold,
                'admin_id'  => (int) $currentUser['id'],
            ]);
            flash('success', '自动封禁阈值已更新为 ' . $threshold . ' 次。');
            header('Location: bans.php');
            exit;
        }

        // 顶端封禁提示的展示样式：plain / link / detail
        if ($action === 'save_notice_mode') {
            $mode = (string) ($_POST['ban_notice_mode'] ?? '');
            if (!in_array($mode, ['plain', 'link', 'detail'], true)) {
                throw new RuntimeException('封禁提示样式取值非法。');
            }
            Database::setConfig('ban_notice_mode', $mode);
            Logger::info('ban', '管理员更新封禁提示样式。', [
                'mode'     => $mode,
                'admin_id' => (int) $currentUser['id'],
            ]);
            flash('success', '封禁提示样式已更新。');
            header('Location: bans.php');
            exit;
        }

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'ban_user') {
            if ($userId === (int) $currentUser['id']) {
                throw new RuntimeException('不能封禁当前登录的账号。');
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $service->ban($userId, $reason !== '' ? $reason : '管理员手动封禁。', (int) $currentUser['id']);
            Logger::warning('ban', '管理员手动封禁用户。', [
                'user_id'  => $userId,
                'admin_id' => (int) $currentUser['id'],
            ]);
            flash('success', '用户已封禁：禁止上传、下载、修改与重命名，仅保留工单申诉入口。');
            header('Location: bans.php');
            exit;
        }

        if ($action === 'unban_user') {
            $service->unban($userId, (int) $currentUser['id']);
            Logger::info('ban', '管理员解除用户封禁。', [
                'user_id'  => $userId,
                'admin_id' => (int) $currentUser['id'],
            ]);
            flash('success', '已解除该用户的封禁，上传与下载恢复可用。');
            header('Location: bans.php');
            exit;
        }

        // R10 可信度：管理员手动给用户增加违规次数（累计到阈值即自动封禁）
        if ($action === 'add_violation') {
            $points = (int) ($_POST['violation_points'] ?? 1);
            $reason = trim((string) ($_POST['violation_reason'] ?? ''));
            $service->addViolation($userId, $points, $reason, (int) $currentUser['id']);
            Logger::warning('ban', '管理员手动增加用户违规次数。', [
                'user_id'  => $userId,
                'points'   => $points,
                'admin_id' => (int) $currentUser['id'],
            ]);
            flash('success', '已为该用户增加 ' . $points . ' 次违规，累计达到阈值时系统自动封禁。');
            header('Location: bans.php');
            exit;
        }

        throw new RuntimeException('未知操作。');
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : '操作失败，请稍后重试。';
        Logger::error('ban', $exception->getMessage(), ['admin_id' => (int) $currentUser['id']]);
    }
}

$threshold = $service->threshold();
// 懒结算违规自动封禁的到期解封（无 cron，靠页面访问触发），保证下方列表状态真实
$service->expireDue();
$noticeMode = (string) Database::config('ban_notice_mode', 'link');
$users = $service->bannedUsers();
$bannedCount = 0;
foreach ($users as $row) {
    if ($row['ban_id'] !== null) {
        $bannedCount++;
    }
}
$flash = pullFlash();
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('封禁管理') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=54"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav"><a href="dashboard.php"><span>▣</span>我的文件</a><a href="tickets.php"><span>✉</span>工单管理</a><a href="admin.php"><span>⚙</span>管理后台</a><a href="admin_orders.php"><span>¥</span>订单管理</a><a class="active" href="bans.php"><span>⛔</span>封禁管理</a><a href="logs.php"><span>☰</span>运行日志</a><a href="account.php"><span>◍</span>账户管理</a></nav>
        <?= sidebarStorageHtml($currentUser) ?>
        <?= sidebarIcpHtml() ?>
        <?= sidebarPoliceHtml() ?>
        <div class="account-menu"><?= avatarHtml($currentUser) ?><div><strong><?= e($currentUser['username']) ?></strong><small>管理员</small></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button>退出</button></form></div>
    </aside>
    <main class="drive-main admin-main">
        <?= banNoticeHtml($currentUser) ?>
        <header class="drive-header"><div><p>违规处置</p><h1>封禁管理</h1></div><a class="secondary-button" href="admin.php">返回管理后台</a></header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>自动封禁阈值</h2><p>被管理员打回（判定违规）的文件累计达到该次数时，系统自动封禁该用户。AI 掉线或无法识别时先转人工审核，人工确认违规才计数，避免误封。</p></div></div>
            <form method="post" class="log-filters">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_threshold">
                <label><span>违规次数阈值</span><input type="number" name="ban_threshold" min="1" max="100" value="<?= $threshold ?>" required></label>
                <button class="primary-button compact" data-confirm="确认更新自动封禁阈值？仅影响之后的自动判定，不会追溯已有记录。">保存阈值</button>
            </form>
            <p class="log-meta">封禁后该用户：禁止上传、禁止下载、禁止修改与重命名文件及文件夹；工单（申诉）入口保持可用，管理员可在工单里与他沟通后解封。</p>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>封禁提示样式</h2><p>被封禁的用户在每个页面顶端都会看到该提示条，替代以往只在上传/下载时报错的方式。上传、下载接口的拦截仍然生效，作为兜底。</p></div></div>
            <form method="post" class="log-filters">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_notice_mode">
                <label><span>展示内容</span><select name="ban_notice_mode">
                    <option value="plain" <?= $noticeMode === 'plain' ? 'selected' : '' ?>>只显示一句「您的账户已被封禁」</option>
                    <option value="link" <?= $noticeMode === 'link' ? 'selected' : '' ?>>文案 + 工单申诉入口</option>
                    <option value="detail" <?= $noticeMode === 'detail' ? 'selected' : '' ?>>文案 + 封禁原因与时间 + 工单申诉入口</option>
                </select></label>
                <button class="primary-button compact">保存样式</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>用户违规与封禁状态</h2><p>共 <?= count($users) ?> 位用户 · 封禁中 <?= $bannedCount ?> 位 · 阈值 <?= $threshold ?> 次</p></div>
            </div>
            <?php if (!$users): ?>
                <div class="empty-state compact-empty">暂无用户。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>用户</th><th>邮箱</th><th>违规次数</th><th>状态</th><th>封禁时间</th><th>原因</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                    <?php
                    $isBannedRow = $row['ban_id'] !== null;
                    $violations = (int) $row['violations'];
                    $isAdminRow = (int) ($row['group_is_admin'] ?? 0) === 1;
                    $isSelf = (int) $row['id'] === (int) $currentUser['id'];
                    // 违规自动封禁的到期时间：到点后本页访问会自动解封并把违规次数清零
                    $autoBanUntil = (string) ($row['violation_ban_until'] ?? '');
                    ?>
                    <tr>
                        <td>
                            <div class="file-name"><div><strong class="display-name"><?= e((string) $row['username']) ?></strong><small>ID <?= (int) $row['id'] ?><?= (int) $row['moderation_exempt'] === 1 ? ' · 免审核' : '' ?><?= $isAdminRow ? ' · 管理员' : '' ?></small></div></div>
                        </td>
                        <td data-label="邮箱"><?= e((string) ($row['email'] ?? '') !== '' ? (string) $row['email'] : '—') ?></td>
                        <td data-label="违规次数"><?= $violations ?> / <?= $threshold ?><?= $violations >= $threshold ? ' <span class="status-badge void">已达阈值</span>' : '' ?></td>
                        <td data-label="状态"><span class="status-badge <?= $isBannedRow ? 'void' : '' ?>"><?= $isBannedRow ? '已封禁' : '正常' ?></span></td>
                        <td data-label="封禁时间"><span><?= $isBannedRow && $row['banned_at'] !== null ? e(date('Y-m-d H:i', (int) strtotime((string) $row['banned_at']))) : '—' ?></span><?= $isBannedRow && $autoBanUntil !== '' ? '<small class="log-meta">' . e(date('Y-m-d H:i', (int) strtotime($autoBanUntil))) . ' 自动解封</small>' : '' ?></td>
                        <td data-label="原因"><?= e((string) ($row['reason'] ?? '') !== '' ? (string) $row['reason'] : '—') ?></td>
                        <td>
                            <div class="file-actions">
                                <?php if ($isBannedRow): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="unban_user"><input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>"><button data-confirm="确认解除该用户的封禁？解封后其上传、下载、修改与重命名将立即恢复。">解封</button></form>
                                <?php elseif (!$isAdminRow && !$isSelf): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="ban_user"><input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>"><input name="reason" placeholder="封禁原因（可留空）" maxlength="500"><button class="danger" data-confirm="确认封禁该用户？封禁后其上传、下载、修改、重命名均被禁止，仅保留工单申诉入口。">封禁</button></form>
                                <?php else: ?>
                                <span class="log-meta">—</span>
                                <?php endif; ?>
                                <?php if (!$isAdminRow && !$isSelf): ?>
                                <form method="post" class="inline-form violation-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="add_violation"><input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>"><input type="number" name="violation_points" min="1" max="100" value="1" aria-label="增加违规次数"><input name="violation_reason" placeholder="违规原因（可留空）" maxlength="500"><button class="text-action" data-confirm="确认增加该用户的违规次数？累计达到阈值（<?= $threshold ?> 次）时系统会自动封禁，30 天后自动解封并清零。">加违规</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>