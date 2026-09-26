<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;

$currentUser = Auth::requireAdmin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $order = findOrderByNo(trim((string) ($_POST['order_no'] ?? '')));
        if ($order === null) {
            throw new RuntimeException('该订单不存在或已被删除。');
        }
        $orderNo = (string) $order['order_no'];

        if ($action === 'mark_paid') {
            if ((string) $order['status'] === 'paid') {
                flash('success', '该订单已完成支付，无需重复开通。');
            } else {
                // 人工开通时用 MANUAL-管理员ID 作为交易号，便于事后区分「线上支付」与「人工处理」
                $settled = settleOrderPayment($order, 'MANUAL-' . (int) $currentUser['id'], [
                    'manual' => true,
                    'operator' => (string) $currentUser['username'],
                ]);
                Logger::info('payment', '管理员手动将订单标记为已支付。', [
                    'order_no' => $orderNo,
                    'operator' => $currentUser['username'],
                    'settled' => $settled,
                ]);
                flash($settled ? 'success' : 'error', $settled
                    ? '已标记为已支付，并开通/顺延该用户的高级套餐。'
                    : '该订单已完成支付，无需重复开通。');
            }
        } elseif ($action === 'close_order') {
            if ((string) $order['status'] !== 'pending') {
                throw new RuntimeException('只有待支付的订单可以关闭。');
            }
            Database::connection()->prepare("UPDATE orders SET status = 'closed' WHERE id = :id")
                ->execute(['id' => (int) $order['id']]);
            Logger::info('payment', '管理员关闭未支付订单。', [
                'order_no' => $orderNo,
                'operator' => $currentUser['username'],
            ]);
            flash('success', '订单已关闭。');
        } elseif ($action === 'sync_order') {
            if ((string) $order['status'] === 'paid') {
                flash('success', '该订单已完成支付。');
            } else {
                $result = reconcileOrder($order);
                flash($result['paid'] ? 'success' : 'error', $result['message']);
            }
        } else {
            throw new RuntimeException('未知操作。');
        }

        header('Location: admin_orders.php');
        exit;
    } catch (Throwable $exception) {
        // 只回显可安全展示的提示：数据库原始报错可能包含 SQL 语句与服务器路径
        $error = match (true) {
            $exception instanceof PDOException => '数据库操作失败，请稍后重试。',
            $exception instanceof RuntimeException => $exception->getMessage(),
            default => '服务器处理请求时发生未知错误，请稍后重试，或凭参考号在「运行日志」中查询详情。',
        };
    }
}

$status = strtolower(trim((string) ($_GET['status'] ?? '')));
if ($status !== '' && !in_array($status, ['pending', 'paid', 'closed'], true)) {
    $status = '';
}
$keyword = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'o.status = :status';
    $params['status'] = $status;
}
if ($keyword !== '') {
    // 原生预处理不允许同名占位符复用，关键字匹配拆成三个不同的参数
    $where[] = '(o.order_no LIKE :kw_order_no OR o.trade_no LIKE :kw_trade_no OR u.username LIKE :kw_username)';
    $like = '%' . $keyword . '%';
    $params['kw_order_no'] = $like;
    $params['kw_trade_no'] = $like;
    $params['kw_username'] = $like;
}

$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

// 分页：先按同样的筛选条件取总数，再取当前页，避免订单量增长后一次拉全表
$perPage = 50;
$countStatement = Database::connection()->prepare(
    'SELECT COUNT(*) AS total FROM orders o LEFT JOIN users u ON u.id = o.user_id' . $whereSql
);
$countStatement->execute($params);
$totalOrders = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalOrders / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

// LIMIT/OFFSET 用内联整数：原生预处理把占位符当字符串绑定，会触发 SQL 语法错误
$sql = 'SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id' . $whereSql
    . ' ORDER BY o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

$statement = Database::connection()->prepare($sql);
$statement->execute($params);
$orders = $statement->fetchAll();

// 分页链接需保留筛选条件
$filterQuery = array_filter(['status' => $status, 'q' => $keyword], static fn ($value): bool => $value !== '');
$pageUrl = static function (int $target) use ($filterQuery): string {
    return 'admin_orders.php?' . http_build_query($filterQuery + ['page' => $target]);
};

$statusCounts = [];
foreach (Database::connection()->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll() as $row) {
    $statusCounts[(string) $row['status']] = (int) $row['total'];
}

$flash = pullFlash();
$statusLabels = ['' => '全部状态', 'pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭'];
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>订单管理 · Notion Drive</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/app.css?v=19"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">N</span><span>Notion Drive</span></a>
        <nav class="drive-nav"><a href="dashboard.php"><span>▣</span>我的文件</a><a href="admin.php"><span>⚙</span>管理后台</a><a class="active" href="admin_orders.php"><span>¥</span>订单管理</a><a href="logs.php"><span>☰</span>运行日志</a><a href="account.php"><span>◍</span>账户管理</a></nav>
        <div class="account-menu"><?= avatarHtml($currentUser) ?><div><strong><?= e($currentUser['username']) ?></strong><small>管理员</small></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button>退出</button></form></div>
    </aside>
    <main class="drive-main admin-main">
        <header class="drive-header"><div><p>付费系统</p><h1>订单管理</h1></div><a class="secondary-button" href="admin.php">返回管理后台</a></header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>订单列表</h2><p>共 <?= array_sum($statusCounts) ?> 笔订单 · 待支付 <?= (int) ($statusCounts['pending'] ?? 0) ?> · 已支付 <?= (int) ($statusCounts['paid'] ?? 0) ?> · 已关闭 <?= (int) ($statusCounts['closed'] ?? 0) ?></p></div>
                <span><?= $totalOrders ?> 条结果<?= $totalPages > 1 ? ' · 第 ' . $page . '/' . $totalPages . ' 页' : '' ?></span>
            </div>
            <form method="get" class="log-filters">
                <label><span>状态</span><select name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label><span>关键字</span><input name="q" value="<?= e($keyword) ?>" placeholder="订单号、交易号或用户名"></label>
                <button class="primary-button compact">筛选</button>
                <?php if ($status !== '' || $keyword !== ''): ?><a class="text-action" href="admin_orders.php">清除筛选</a><?php endif; ?>
            </form>
            <p class="log-meta">按时间倒序分页展示，每页 <?= $perPage ?> 笔。支付依赖网关异步通知，通知丢失时可对单笔订单点击「刷新状态」主动查单；确认已收到款项但订单仍未开通时，可用「标记已支付」人工开通。</p>
            <?php if (!$orders): ?>
                <div class="empty-state compact-empty">没有匹配的订单记录。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>订单号</th><th>用户</th><th>套餐</th><th>渠道</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): $orderStatus = (string) $order['status']; ?>
                    <tr>
                        <td>
                            <div class="file-name"><div><strong class="display-name"><?= e((string) $order['order_no']) ?></strong><small><?= (string) $order['trade_no'] !== '' && $order['trade_no'] !== null ? '交易号 ' . e((string) $order['trade_no']) : '—' ?></small></div></div>
                        </td>
                        <td data-label="用户"><?= e((string) ($order['username'] ?? '已删除用户')) ?></td>
                        <td data-label="套餐"><?= (int) $order['months'] ?> 个月</td>
                        <td data-label="渠道"><?= e(payTypeLabel((string) $order['pay_type'])) ?></td>
                        <td data-label="金额">¥<?= e(number_format((float) $order['amount'], 2)) ?></td>
                        <td data-label="状态"><span class="status-badge <?= e($orderStatus) ?>"><?= e(orderStatusLabel($orderStatus)) ?></span></td>
                        <td data-label="创建时间"><span><?= e(date('Y-m-d H:i', (int) strtotime((string) $order['created_at']))) ?><?= $orderStatus === 'paid' && $order['paid_at'] !== null ? '<br><small>付款 ' . e(date('Y-m-d H:i', (int) strtotime((string) $order['paid_at']))) . '</small>' : '' ?></span></td>
                        <td>
                            <div class="file-actions">
                                <a href="admin.php?user_id=<?= (int) $order['user_id'] ?>">用户</a>
                                <?php if ($orderStatus !== 'paid'): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="sync_order"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>"><button>刷新状态</button></form>
                                <?php endif; ?>
                                <?php if ($orderStatus !== 'paid'): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>"><button data-confirm="确认已收到该订单款项，并立即开通高级用户？">标记已支付</button></form>
                                <?php endif; ?>
                                <?php if ($orderStatus === 'pending'): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="close_order"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>"><button class="danger" data-confirm="确认关闭该订单？关闭后用户需要重新下单。">关闭</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
            <div class="log-quick">
                <?php if ($page > 1): ?><a class="secondary-button" href="<?= e($pageUrl($page - 1)) ?>">上一页</a><?php endif; ?>
                <span class="log-meta">第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?><a class="secondary-button" href="<?= e($pageUrl($page + 1)) ?>">下一页</a><?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=13" defer></script><script src="assets/admin.js?v=5" defer></script>
</body>
</html>