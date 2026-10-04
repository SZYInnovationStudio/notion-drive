<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Security;
use NotionDrive\Tickets\TicketBanService;
use NotionDrive\Tickets\TicketService;

$user = Auth::requireLogin();
$isAdmin = isAdmin($user);
$userId = (int) $user['id'];
// 归档是 viewer 维度的软隐藏：用户归档自己的视角，管理员各自归档后台视角
$viewerKind = $isAdmin ? TicketService::VIEWER_ADMIN : TicketService::VIEWER_USER;

$service = TicketService::create();
$ticketBanService = TicketBanService::create();
$categories = TicketService::categories();
$statuses = TicketService::statuses();

// 申诉模式：由封禁提示条的「提交申诉」等入口带 ?appeal=1 进入，预填「申诉」类工单与标题，用户补充理由即可提交
$appealMode = !$isAdmin && (string) ($_GET['appeal'] ?? '') === '1';

$error = '';
// 提交处理：校验 CSRF 后分发动作，完成后统一重定向（避免刷新重复提交）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);

        if ($action === 'create_ticket') {
            // 工单是用户向管理员提交的诉求，管理员只负责回复与关闭，不能自行提交工单
            if ($isAdmin) {
                throw new RuntimeException('管理员账号不能提交工单，请直接在用户工单里回复。');
            }
            // 申诉模式下（?appeal=1）允许提交「申诉」类工单；其余入口仍只收普通工单
            $category = (string) ($_POST['category'] ?? TicketService::CATEGORY_GENERAL);
            if ($category === TicketService::CATEGORY_APPEAL && (string) ($_POST['appeal_mode'] ?? '') !== '1') {
                $category = TicketService::CATEGORY_GENERAL;
            }
            $newId = $service->openTicket(
                $userId,
                (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['body'] ?? ''),
                $category
            );
            flash('success', '工单已提交，我们会尽快处理。');
            header('Location: tickets.php?id=' . $newId);
            exit;
        }

        if ($action === 'reply_ticket') {
            if ($isAdmin) {
                $service->replyAsAdmin($ticketId, $userId, (string) ($_POST['body'] ?? ''));
            } else {
                $service->replyAsUser($ticketId, $userId, (string) ($_POST['body'] ?? ''));
            }
            flash('success', '回复已发送。');
            header('Location: tickets.php?id=' . $ticketId);
            exit;
        }

        if ($action === 'close_ticket') {
            // 已关闭是终态，管理员可关闭任意工单，普通用户只能关闭自己的工单
            $closed = $service->closeTicket($ticketId, $isAdmin ? null : $userId);
            flash($closed ? 'success' : 'warning', $closed ? '工单已关闭。' : '该工单已处于结束状态，无需重复关闭。');
            header('Location: tickets.php?id=' . $ticketId);
            exit;
        }

        if ($action === 'reopen_ticket') {
            // 重开：双方都可发起；普通用户只能重开自己的工单，管理员可重开任意工单。过期工单不可重开
            $service->reopenTicket($ticketId, $isAdmin ? null : $userId);
            flash('success', '工单已重新开启。');
            header('Location: tickets.php?id=' . $ticketId);
            exit;
        }

        if ($action === 'submit_review') {
            // 评价只面向工单归属人且仅结束态可提交；重复提交即覆盖上次评分（Q24/Q26）
            if ($isAdmin) {
                throw new RuntimeException('管理员不能提交评价，评价由提交工单的用户给出。');
            }
            $service->submitReview(
                $ticketId,
                $userId,
                (float) ($_POST['score'] ?? 0),
                (string) ($_POST['is_anonymous'] ?? '') === '1',
                (string) ($_POST['comment'] ?? '')
            );
            flash('success', '感谢你的评价，已记录（可再次提交覆盖修改）。');
            header('Location: tickets.php?id=' . $ticketId);
            exit;
        }

        // 批量动作：勾选框 name="ids[]"，行内单条动作则带 ticket_id，两者合并处理
        $bulkIds = $_POST['ids'] ?? [];
        if (!is_array($bulkIds)) {
            $bulkIds = [$bulkIds];
        }
        if ($ticketId > 0) {
            $bulkIds[] = $ticketId;
        }
        // 操作后回到当前视图（归档页 / 列表页）
        $listTarget = 'tickets.php' . ((string) ($_POST['view'] ?? '') === 'archived' ? '?view=archived' : '');

        if ($action === 'batch_status') {
            if (!$isAdmin) {
                throw new RuntimeException('无权操作。');
            }
            $changed = $service->setStatuses($bulkIds, (string) ($_POST['bulk_status'] ?? ''));
            flash($changed > 0 ? 'success' : 'warning', $changed > 0 ? "已更新 {$changed} 张工单的状态。" : '没有工单需要更新状态。');
            header('Location: ' . $listTarget);
            exit;
        }

        if ($action === 'batch_close') {
            if ($isAdmin) {
                throw new RuntimeException('管理员请使用批量改状态。');
            }
            $closed = 0;
            foreach ($bulkIds as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $row = $service->findForUser($id, $userId);
                if ($row === null || TicketService::isEnded((string) $row['status'])) {
                    continue;
                }
                $service->closeTicket($id, $userId);
                $closed++;
            }
            flash($closed > 0 ? 'success' : 'warning', $closed > 0 ? "已关闭 {$closed} 张工单。" : '没有可关闭的工单。');
            header('Location: ' . $listTarget);
            exit;
        }

        // 批量归档：管理员是整理动作，不限状态；普通用户只能归档自己已结束的工单
        if ($action === 'batch_archive') {
            if (!$isAdmin) {
                throw new RuntimeException('无权操作。');
            }
            $moved = $service->archiveTickets($bulkIds, TicketService::VIEWER_ADMIN, $userId, false);
            flash($moved > 0 ? 'success' : 'warning', $moved > 0 ? "已归档 {$moved} 张工单，可在归档页恢复。" : '没有工单需要归档。');
            header('Location: ' . $listTarget);
            exit;
        }

        if ($action === 'archive_tickets') {
            $moved = $service->archiveTickets($bulkIds, $viewerKind, $userId);
            flash($moved > 0 ? 'success' : 'warning', $moved > 0 ? "已移除 {$moved} 张工单，可在归档页恢复。" : '没有可移除的工单。');
            header('Location: ' . $listTarget);
            exit;
        }

        if ($action === 'archive_all') {
            if (!$isAdmin) {
                throw new RuntimeException('无权操作。');
            }
            $moved = $service->archiveAllTickets(TicketService::VIEWER_ADMIN, $userId);
            flash($moved > 0 ? 'success' : 'warning', $moved > 0 ? "已把 {$moved} 张工单移入归档，可在归档页恢复。" : '没有工单需要归档。');
            header('Location: ' . $listTarget);
            exit;
        }

        if ($action === 'restore_tickets') {
            $restored = $service->restoreTickets($bulkIds, $viewerKind, $userId);
            flash($restored > 0 ? 'success' : 'warning', $restored > 0 ? "已恢复 {$restored} 张工单。" : '没有工单需要恢复。');
            header('Location: ' . $listTarget);
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage() !== '' ? $exception->getMessage() : '操作失败，请稍后再试。';
    }
}

$flash = pullFlash();

// 工单到期清扫：客服回复后用户长期未回应、或关闭超过重开窗口的工单自动置为「已过期」
$service->expireDueTickets();

// 禁止提交工单：只影响「新建工单」入口，已有工单的查看与回复不受影响
$ticketBan = $isAdmin ? null : $ticketBanService->activeTicketBan($userId);

$ticketId = max(0, (int) ($_GET['id'] ?? 0));
$ticket = null;
$messages = [];
$attachments = [];
$review = null;
$tickets = [];
$filterStatus = (string) ($_GET['status'] ?? '');
$filterCategory = (string) ($_GET['category'] ?? '');
// 归档视图：仅列出当前 viewer 已归档的工单（软隐藏，可恢复）
$archived = (string) ($_GET['view'] ?? '') === 'archived';

if ($ticketId > 0) {
    $ticket = $isAdmin ? $service->find($ticketId) : $service->findForUser($ticketId, $userId);
    if ($ticket === null) {
        flash('error', '工单不存在或无权访问。');
        header('Location: tickets.php');
        exit;
    }
    $messages = $service->messages($ticketId);
    $attachments = $service->attachments($ticketId);
    $review = $service->reviewFor($ticketId);
} elseif ($archived) {
    $tickets = $service->archivedTickets($viewerKind, $userId, $filterStatus, $filterCategory);
} elseif ($isAdmin) {
    $tickets = $service->allTickets($filterStatus, $filterCategory, 200, $userId);
} else {
    $tickets = $service->ticketsForUser($userId);
}
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag($ticket === null ? ($isAdmin ? '工单管理' : '我的工单') : '工单详情') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=62"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav" aria-label="主导航">
            <a href="dashboard.php"><?= navIcon('files') ?>我的文件</a>
            <a class="active" href="tickets.php"><?= navIcon('tickets') ?><?= $isAdmin ? '工单管理' : '我的工单' ?></a>
            <?php if ($isAdmin): ?><a href="admin.php"><?= navIcon('admin') ?>管理后台</a><a href="admin_orders.php"><?= navIcon('orders') ?>订单管理</a><a href="bans.php"><?= navIcon('bans') ?>封禁管理</a><a href="logs.php"><?= navIcon('logs') ?>运行日志</a><?php endif; ?>
            <a href="account.php"><?= navIcon('account') ?>账户管理</a>
            <?php if (!$isAdmin): ?><a href="pay.php"><?= navIcon('premium') ?>会员套餐</a><?php endif; ?>
        </nav>
        <?= sidebarStorageHtml($user) ?>
        <?= sidebarIcpHtml() ?>
        <?= sidebarPoliceHtml() ?>
        <div class="account-menu">
            <?= avatarHtml($user) ?>
            <div><strong><?= e($user['username']) ?></strong><small><?= $isAdmin ? '管理员' : e((string) ($user['group_name'] ?? '用户')) ?></small></div>
            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button title="退出登录">退出</button></form>
        </div>
    </aside>

    <main class="drive-main admin-main">
        <?= banNoticeHtml($user) ?>
        <header class="drive-header">
            <div><p><?= $isAdmin ? '客户支持' : '帮助与支持' ?></p><h1><?= $ticket === null ? ($isAdmin ? '工单管理' : '我的工单') : e((string) $ticket['subject']) ?></h1></div>
            <?php if ($ticket !== null): ?><a class="secondary-button" href="tickets.php">返回工单列表</a><?php else: ?><a class="secondary-button" href="dashboard.php">返回我的文件</a><?php endif; ?>
        </header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php if ($ticket === null): ?>
        <?php if (!$isAdmin): ?>
        <?php if ($ticketBan !== null): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>提交新工单</h2><p>你已被禁止提交工单，请联系管理员。</p></div></div>
            <div class="alert error" role="alert">
                你已被禁止提交工单，请联系管理员。
                <?php if ((string) ($ticketBan['reason'] ?? '') !== ''): ?><br>原因：<?= e((string) $ticketBan['reason']) ?><?php endif; ?>
                <?php if ($ticketBan['until'] !== null): ?><br>禁止期限至 <?= e(date('Y-m-d H:i', (int) strtotime((string) $ticketBan['until']))) ?>，到期后自动解除。<?php else: ?><br>当前为永久禁止。<?php endif; ?>
            </div>
            <p class="log-meta">你仍可查看与回复已有工单，上传、下载、重命名与新建文件夹均不受影响。</p>
        </section>
        <?php else: ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2><?= $appealMode ? '提交申诉' : '提交新工单' ?></h2><p><?= $appealMode ? '请补充申诉理由，管理员会在这里回复你；提交后自动归入「申诉」类别。' : '描述你遇到的问题或需求，管理员会在这里回复你。文件被内容审核拦截时，可直接在「我的文件」页一键提交申诉工单。' ?></p></div></div>
            <form method="post" class="ticket-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <input type="hidden" name="action" value="create_ticket">
                <?php if ($appealMode): ?>
                <input type="hidden" name="appeal_mode" value="1">
                <input type="hidden" name="category" value="<?= TicketService::CATEGORY_APPEAL ?>">
                <label><span>工单类别</span><input value="<?= e($categories[TicketService::CATEGORY_APPEAL]) ?>" readonly tabindex="-1"><small>已自动选择「申诉」，请补充下方理由后提交。</small></label>
                <label><span>工单标题</span><input name="subject" maxlength="200" required value="账户封禁申诉"></label>
                <label><span>申诉理由</span><textarea name="body" rows="5" maxlength="2000" required placeholder="请说明情况，例如账号用途、误封理由等（最多 2000 字）"></textarea></label>
                <button class="primary-button compact">提交申诉</button>
                <?php else: ?>
                <label><span>工单类别</span>
                    <select name="category">
                        <?php foreach ($categories as $value => $label): ?>
                        <?php if ($value === TicketService::CATEGORY_APPEAL) { continue; } ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>工单标题</span><input name="subject" maxlength="200" required placeholder="一句话说明问题，例如「无法上传大于 5MB 的文件」"></label>
                <label><span>详细描述</span><textarea name="body" rows="5" maxlength="2000" required placeholder="补充出现问题的操作步骤、报错提示等（最多 2000 字）"></textarea></label>
                <button class="primary-button compact">提交工单</button>
                <?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div>
                    <h2><?= $archived ? '归档工单' : '工单列表' ?></h2>
                    <p>
                        <?php if ($archived): ?>
                        共 <?= count($tickets) ?> 条 · 归档只是从你的列表里隐藏，工单与留言都保留，可随时恢复。
                        <?php else: ?>
                        共 <?= count($tickets) ?> 条<?= $isAdmin ? ' · 待处理与处理中的工单排在前面' : ' · 已结束的工单排在最后' ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a class="secondary-button compact" href="tickets.php<?= $archived ? '' : '?view=archived' ?>"><?= $archived ? '返回工单列表' : '查看归档' ?></a>
            </div>
            <?php if ($isAdmin): ?>
            <form method="get" class="log-filters">
                <?php if ($archived): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
                <label><span>状态</span>
                    <select name="status">
                        <option value="">全部状态</option>
                        <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filterStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>类别</span>
                    <select name="category">
                        <option value="">全部类别</option>
                        <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filterCategory === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="primary-button compact">筛选</button>
                <?php if ($filterStatus !== '' || $filterCategory !== ''): ?><a class="text-action" href="tickets.php<?= $archived ? '?view=archived' : '' ?>">清除筛选</a><?php endif; ?>
            </form>
            <?php endif; ?>
            <?php if (!$tickets): ?>
            <div class="empty-state compact-empty"><?= $archived ? '归档里还没有工单。' : '还没有工单记录。' ?></div>
            <?php else: ?>
            <?php
                // 批量表单放在表格外：勾选框用 HTML5 form 属性关联过来，避免 form 嵌套进 table
            ?>
            <form method="post" id="ticketBulkForm" class="ticket-bulk-form" data-bulk-total="<?= count($tickets) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <?php if ($archived): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
                <div class="ticket-bulk-bar">
                    <label class="ticket-bulk-all"><input type="checkbox" data-check-all> <span>全选（已选 <b data-check-count>0</b> / <?= count($tickets) ?>）</span></label>
                    <div class="ticket-bulk-actions">
                    <?php if ($archived): ?>
                        <button class="secondary-button compact" type="submit" name="action" value="restore_tickets" data-confirm="确认恢复选中的工单？恢复后会重新出现在你的列表里。">恢复选中</button>
                    <?php elseif ($isAdmin): ?>
                        <select name="bulk_status" aria-label="目标状态">
                            <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="secondary-button compact" type="submit" name="action" value="batch_status" data-confirm="确认把选中的工单改为目标状态？置为结束态（已关闭 / 已过期）会删除工单内附件且不可恢复。">批量改状态</button>
                        <button class="secondary-button compact" type="submit" name="action" value="batch_archive" data-confirm="确认归档选中的工单？归档后不再显示在当前列表，可在归档页恢复（不会删除工单本身）。">批量归档</button>
                        <button class="text-action danger-text" type="submit" name="action" value="archive_all" data-confirm="确认清除全部工单？这会把所有工单移入你的归档（不是全站删除），可在归档页恢复。">清除全部工单</button>
                    <?php else: ?>
                        <button class="secondary-button compact" type="submit" name="action" value="batch_close" data-confirm="确认关闭选中的工单？关闭后无法继续回复，工单内附件会被删除且不可恢复。">批量关闭</button>
                        <button class="secondary-button compact" type="submit" name="action" value="archive_tickets" data-confirm="确认移除选中的工单？仅从你的列表隐藏，可在归档页恢复。">移除选中</button>
                    <?php endif; ?>
                    </div>
                </div>
            </form>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th class="ticket-check-col"><input type="checkbox" data-check-all aria-label="全选"></th><th>工单号 / 标题</th><?php if ($isAdmin): ?><th>提交人</th><?php endif; ?><th>类别</th><th>状态</th><th>留言</th><th>最后更新</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($tickets as $row): $rowStatus = (string) $row['status']; $rowCategory = (string) $row['category']; ?>
                    <?php
                        // 行内「移除」：管理员仅已过期可归档，用户仅自己已结束的工单可归档；归档页则显示「恢复」
                        $canRemove = $isAdmin ? $rowStatus === TicketService::STATUS_EXPIRED : TicketService::isEnded($rowStatus);
                    ?>
                    <tr>
                        <td class="ticket-check-col"><input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" form="ticketBulkForm" data-check-item aria-label="选择该工单"></td>
                        <td class="ticket-title-col">
                            <div class="file-name"><div><strong class="display-name"><?= e((string) $row['subject']) ?></strong><small><?= e((string) $row['ticket_no']) ?></small></div></div>
                        </td>
                        <?php if ($isAdmin): ?><td data-label="提交人"><?= e((string) ($row['username'] ?? '已删除用户')) ?></td><?php endif; ?>
                        <td data-label="类别"><?= e($categories[$rowCategory] ?? $rowCategory) ?></td>
                        <td data-label="状态"><span class="status-badge ticket-status-<?= e($rowStatus) ?>"><?= e($statuses[$rowStatus] ?? $rowStatus) ?></span></td>
                        <td data-label="留言"><?= (int) $row['message_count'] ?> 条</td>
                        <td data-label="最后更新"><span><?= e(date('Y-m-d H:i', (int) strtotime((string) ($row['last_message_at'] ?? $row['created_at'])))) ?></span></td>
                        <td><div class="file-actions">
                            <a href="tickets.php?id=<?= (int) $row['id'] ?>">查看</a>
                            <?php if ($archived): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                                <input type="hidden" name="action" value="restore_tickets">
                                <input type="hidden" name="view" value="archived">
                                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                                <button class="danger" type="submit" data-confirm="确认恢复该工单？恢复后会重新出现在你的列表里。">恢复</button>
                            </form>
                            <?php elseif ($canRemove): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                                <input type="hidden" name="action" value="archive_tickets">
                                <input type="hidden" name="ticket_id" value="<?= (int) $row['id'] ?>">
                                <button class="danger" type="submit" data-confirm="确认移除该工单？仅从你的列表隐藏，可在归档页恢复。">移除</button>
                            </form>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <?php
            $ticketStatus = (string) $ticket['status'];
            $ticketCategory = (string) $ticket['category'];
            $canReply = $isAdmin || !TicketService::isEnded($ticketStatus);
            // 仅「已关闭」的工单可重开（过期不可重开）：判断是否仍在重开窗口内、次数是否未用尽
            $canReopen = false;
            $reopenReason = '';
            $reopenInfo = ['remaining' => TicketService::MAX_REOPEN, 'deadline' => ''];
            if ($ticketStatus === TicketService::STATUS_CLOSED) {
                [$canReopen, $reopenReason] = $service->reopenState($ticket);
                $reopenInfo = $service->reopenInfo($ticket);
            }
            // 工单评价：结束态才开放，重复提交即覆盖；站内评价总开关关闭时整块不渲染，调研入口随评价一并隐藏
            $reviewEnabled = TicketService::reviewEnabled();
            $canReview = $reviewEnabled && !$isAdmin && TicketService::isEnded($ticketStatus);
            $reviewScore = $review === null ? 0.0 : (float) $review['score'];
            $reviewComment = $review === null ? '' : (string) $review['comment'];
            $reviewAnonymous = $review !== null && (int) $review['is_anonymous'] === 1;
            $surveyUrl = $reviewEnabled ? TicketService::surveyUrl() : '';
        ?>
        <section class="admin-panel">
            <div class="panel-title">
                <div>
                    <h2>工单对话</h2>
                    <p>
                        工单号 <?= e((string) $ticket['ticket_no']) ?>
                        · <?= e($categories[$ticketCategory] ?? $ticketCategory) ?>
                        · 创建 <?= e(date('Y-m-d H:i', (int) strtotime((string) $ticket['created_at']))) ?>
                        <?php if ($isAdmin): ?>· 提交人 <?= e((string) ($ticket['username'] ?? '已删除用户')) ?><?php endif; ?>
                    </p>
                </div>
                <span class="status-badge ticket-status-<?= e($ticketStatus) ?>"><?= e($statuses[$ticketStatus] ?? $ticketStatus) ?></span>
            </div>

            <div class="ticket-thread">
                <?php foreach ($messages as $message): ?>
                <?php
                    $fromAdmin = (string) $message['author_role'] === TicketService::ROLE_ADMIN;
                    // 署名统一为「身份 · 用户名 · 时间」：管理员消息此前只显示「管理员」，看不出具体是哪位
                    $roleLabel = $fromAdmin ? '管理员' : '用户';
                    $authorName = (string) ($message['username'] ?? '');
                ?>
                <div class="ticket-msg <?= $fromAdmin ? 'from-admin' : 'from-user' ?>">
                    <div class="ticket-msg-meta"><?= $roleLabel ?> · <?= e($authorName !== '' ? $authorName : $roleLabel) ?> · <?= e(date('Y-m-d H:i', (int) strtotime((string) $message['created_at']))) ?></div>
                    <div class="ticket-msg-body"><?= nl2br(e((string) $message['body'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($canReply): ?>
            <form method="post" class="ticket-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <input type="hidden" name="action" value="reply_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                <label><span>回复内容</span><textarea name="body" rows="4" maxlength="2000" required placeholder="补充说明或回应对方（最多 2000 字）"></textarea></label>
                <button class="primary-button compact">发送回复</button>
            </form>
            <?php else: ?>
            <?php if ($ticketStatus === TicketService::STATUS_EXPIRED): ?>
            <div class="alert warning ticket-panel-notice">该工单已过期，无法继续回复，也不可重开；下方对话记录仍可查看。</div>
            <?php else: ?>
            <div class="alert warning ticket-panel-notice">该工单已关闭，无法继续回复；如需继续咨询可在下方重新开启，或重新提交一张工单。</div>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>工单附件</h2><p>附件只在工单内有效，不会出现在「我的文件」，也不占用你的个人空间。工单结束后附件会从 Notion 一并删除。</p></div>
            </div>
            <?php if (!$attachments): ?>
            <div class="empty-state compact-empty">该工单还没有附件。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>文件名</th><th>大小</th><th>上传者</th><th>上传时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($attachments as $attachment): ?>
                    <tr>
                        <td>
                            <div class="file-name"><span class="file-kind"><?= e(strtoupper((string) (pathinfo((string) $attachment['filename'], PATHINFO_EXTENSION) ?: 'FILE'))) ?></span><div><strong class="display-name"><?= e((string) ($attachment['display_name'] ?? $attachment['filename'])) ?></strong></div></div>
                        </td>
                        <td data-label="大小"><?= e(formatBytes((int) $attachment['file_size'])) ?></td>
                        <td data-label="上传者"><?= e((string) ($attachment['username'] ?? '已删除用户')) ?></td>
                        <td data-label="上传时间"><span><?= e(date('Y-m-d H:i', (int) strtotime((string) $attachment['created_at']))) ?></span></td>
                        <td><div class="file-actions"><a href="api/file.php?id=<?= (int) $attachment['id'] ?>" data-ticket-download>下载</a></div></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($canReply): ?>
            <div id="ticketAttachments" data-ticket="<?= (int) $ticket['id'] ?>">
                <div id="ticketDropZone" class="drop-zone" tabindex="0" role="button" aria-label="选择工单附件">
                    <input id="ticketFileInput" type="file" multiple hidden>
                    <span class="upload-icon">↑</span><strong>拖放附件到这里</strong><small>或点击选择文件，附件只在工单内有效</small>
                    <button type="button" id="ticketBrowseButton">选择文件</button>
                </div>
                <div id="ticketQueue" class="upload-queue" aria-live="polite"></div>
            </div>
            <?php else: ?>
            <div class="alert warning ticket-panel-notice">该工单已结束，无法继续上传附件。</div>
            <?php endif; ?>
        </section>

        <?php if (!TicketService::isEnded($ticketStatus)): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>结束工单</h2><p>工单状态由系统自动判定：「待处理」表示已提交但还没有管理员回复；「处理中」表示已有管理员回复。结束（关闭 / 过期）后工单进入终态，用户与管理员都无法继续回复。</p></div></div>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <input type="hidden" name="action" value="close_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                <button class="secondary-button compact" data-confirm="确认关闭该工单？关闭后无法继续回复，工单内的附件会从 Notion 一并删除且不可恢复。">关闭工单</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($ticketStatus === TicketService::STATUS_CLOSED): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>重新开启工单</h2><p>关闭后 <?= TicketService::REOPEN_WINDOW_DAYS ?> 天内、且重开次数未用尽时可重新开启，双方都可发起；重开后回到「待处理」，会占用 1 次重开机会。已过期的工单不可重开。剩余重开次数 <?= (int) $reopenInfo['remaining'] ?> / <?= TicketService::MAX_REOPEN ?> 次<?= $reopenInfo['deadline'] !== '' ? '，重开截止 ' . e($reopenInfo['deadline']) : '' ?>。</p></div></div>
            <?php if ($canReopen): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <input type="hidden" name="action" value="reopen_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                <button class="secondary-button compact" data-confirm="确认重新开启该工单？重开后回到「待处理」，将占用 1 次重开机会（剩余 <?= (int) $reopenInfo['remaining'] ?> 次）。">重新开启工单</button>
            </form>
            <?php else: ?>
            <div class="alert warning ticket-panel-notice"><?= e($reopenReason) ?></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($reviewEnabled && TicketService::isEnded($ticketStatus)): ?>
        <section class="admin-panel">
            <div class="panel-title">
                <div>
                    <h2>工单评价</h2>
                    <p>工单结束后可对本次服务打分（0.5 ~ 5.0，共 10 档）。再次提交会覆盖上一次的评分与留言。评价仅用于本站统计，不会共享到外部渠道。</p>
                </div>
                <?php if ($review !== null): ?><span class="status-badge">已评价 <?= e(number_format((float) $review['score'], 1)) ?> 分</span><?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <?php if ($review === null): ?>
                <div class="empty-state compact-empty">该工单还没有收到评价。</div>
                <?php else: ?>
                <div class="review-readonly">
                    <div class="review-score-line"><?= e(number_format((float) $review['score'], 1)) ?> 分<?= (int) $review['is_anonymous'] === 1 ? ' · 匿名评价' : '' ?></div>
                    <div class="review-comment"><?= (string) $review['comment'] === '' ? '（未填写留言）' : nl2br(e((string) $review['comment'])) ?></div>
                    <small>提交时间 <?= e(date('Y-m-d H:i', (int) strtotime((string) $review['created_at']))) ?><?= (string) ($review['updated_at'] ?? '') !== '' && (string) $review['updated_at'] !== (string) $review['created_at'] ? ' · 修改于 ' . e(date('Y-m-d H:i', (int) strtotime((string) $review['updated_at']))) : '' ?></small>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" class="ticket-form review-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                    <input type="hidden" name="action" value="submit_review">
                    <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                    <label><span>你的评分</span>
                        <div class="star-rating" role="radiogroup" aria-label="服务评分" data-style-pct="<?= e(number_format($reviewScore / TicketService::MAX_SCORE * 100, 2)) ?>%">
                            <div class="star-rating-stars" aria-hidden="true"><span class="star-rating-fg">★★★★★</span>★★★★★</div>
                            <div class="star-rating-inputs">
                                <?php foreach (TicketService::scoreOptions() as $option): ?>
                                <label><input type="radio" name="score" value="<?= e(number_format($option, 1)) ?>" <?= abs($reviewScore - $option) < 0.001 ? 'checked' : '' ?>><span class="sr-only"><?= e(number_format($option, 1)) ?> 分</span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small>0.5 ~ 5.0，点击星星左右半边可选择半颗星；不选则按最左档计。</small>
                    </label>
                    <label><span>留言（可选）</span><textarea name="comment" rows="3" maxlength="500" placeholder="说说你的体验与建议（最多 500 字）"><?= e($reviewComment) ?></textarea></label>
                    <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="is_anonymous" value="1" <?= $reviewAnonymous ? 'checked' : '' ?>>匿名提交（后台统计与明细中不展示你的用户名）</label></div>
                    <button class="primary-button compact"><?= $review === null ? '提交评价' : '更新评价' ?></button>
                </form>
            <?php endif; ?>

            <?php if ($surveyUrl !== ''): ?>
            <div class="survey-invite">
                <span>为了提升我们的服务，邀请您参加调研 → <a href="<?= e($surveyUrl) ?>" target="_blank" rel="noopener noreferrer">点我参加</a></span>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=34" defer></script>
</body>
</html>