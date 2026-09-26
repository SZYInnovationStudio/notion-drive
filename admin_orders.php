<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;

$currentUser = Auth::requireAdmin();
$error = '';

/** 解析后台提交的套餐到期日期（date 输入）；留空表示永久有效。 */
function parsePremiumExpiry(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    // createFromFormat 会容忍 "2026-1-1" 这类宽松写法，用回格式化后的字符串严格比对
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('到期日期格式无效，请使用日期选择器。');
    }

    // 管理员按天设置，当天仍然有效
    return $date->format('Y-m-d') . ' 23:59:59';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        // 作废订阅：只作废这一笔订单，并把这笔订单当初贡献的会员时长从该用户身上回滚
        if ($action === 'revoke_subscription') {
            $order = findOrderByNo(trim((string) ($_POST['order_no'] ?? '')));
            if ($order === null) {
                throw new RuntimeException('该订单不存在或已被删除。');
            }
            if ((string) $order['status'] === 'void') {
                throw new RuntimeException('该订单已作废，无需重复操作。');
            }
            if ((string) $order['status'] !== 'paid') {
                throw new RuntimeException('只有已支付的订单才能作废订阅。');
            }
            $target = findUserById((int) $order['user_id']);
            if ($target === null) {
                throw new RuntimeException('该用户不存在或已被删除。');
            }
            if (isAdmin($target)) {
                throw new RuntimeException('管理员账号无需调整高级套餐。');
            }
            // 作废这一笔并回滚它当初贡献的时长：扣完才降级，仍有剩余则保持高级用户
            $result = revokePremium((int) $target['id'], (int) $order['id']);
            Logger::info('payment', '管理员作废订单并回滚其贡献的会员时长。', [
                'order_no' => (string) $order['order_no'],
                'user_id' => (int) $target['id'],
                'months' => (int) $result['months'],
                'still_premium' => (bool) $result['still_premium'],
                'operator' => $currentUser['username'],
            ]);
            if ($result['expires_at'] === null) {
                flash('success', sprintf(
                    '已作废订单 %s；%s 为永久有效会员，会员时长不受影响。',
                    (string) $order['order_no'],
                    (string) $target['username']
                ));
            } elseif ($result['still_premium']) {
                flash('success', sprintf(
                    '已作废订单 %s，已回滚这笔订单为 %s 贡献的 %d 个月会员时长，剩余有效期至 %s。',
                    (string) $order['order_no'],
                    (string) $target['username'],
                    (int) $result['months'],
                    date('Y-m-d H:i', (int) strtotime((string) $result['expires_at']))
                ));
            } else {
                flash('success', sprintf(
                    '已作废订单 %s，%s 的高级套餐已失效并降回普通用户组。',
                    (string) $order['order_no'],
                    (string) $target['username']
                ));
            }
            header('Location: admin_orders.php');
            exit;
        }

        // 设置到期时间：直接指定某个用户的套餐到期日期（留空 = 永久有效），并同步调整权限组
        if ($action === 'set_premium_expiry') {
            $username = trim((string) ($_POST['username'] ?? ''));
            if ($username === '') {
                throw new RuntimeException('请填写要设置到期时间的用户名。');
            }
            $target = findUserByUsername($username);
            if ($target === null) {
                throw new RuntimeException('没有找到用户名为「' . $username . '」的用户。');
            }
            $userId = (int) $target['id'];
            $expiresAt = parsePremiumExpiry((string) ($_POST['premium_expires_at'] ?? ''));

            // 留空代表永久有效；已过期则与访问时的懒检查保持一致，改为普通组。管理员不调整，避免被降出管理组
            $groupId = 0;
            $grantsPremium = $expiresAt === null || strtotime($expiresAt) > time();
            if (!isAdmin($target)) {
                $groupId = $grantsPremium ? premiumGroupId() : defaultGroupId();
            }

            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                // 同时清空到期提醒标记，让新设定的到期周期可以重新提醒
                $pdo->prepare('UPDATE users SET premium_expires_at = :expires_at, premium_reminder_sent_at = NULL WHERE id = :id')
                    ->execute(['expires_at' => $expiresAt, 'id' => $userId]);
                if ($groupId > 0) {
                    $pdo->prepare('UPDATE users SET group_id = :group_id WHERE id = :id')
                        ->execute(['group_id' => $groupId, 'id' => $userId]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            // 与开通订阅一致：改为使用官方账户的高级用户必须具备可用的官方账户
            if ($grantsPremium && userNotionSource($target) === 'official' && userOfficialAccounts($userId) === []) {
                autoAssignOfficialAccounts($userId);
            }

            Logger::info('payment', '管理员调整用户高级套餐到期时间。', [
                'user_id' => $userId,
                'operator' => $currentUser['username'],
                'expires_at' => $expiresAt,
            ]);
            flash('success', $expiresAt === null
                ? sprintf('%s 的套餐已设为永久有效。', (string) $target['username'])
                : sprintf('%s 的套餐到期时间已更新为 %s。', (string) $target['username'], date('Y-m-d H:i', (int) strtotime($expiresAt))));
            header('Location: admin_orders.php');
            exit;
        }

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
if ($status !== '' && !in_array($status, ['pending', 'paid', 'closed', 'void'], true)) {
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
// 一并取出下单用户名与管理员标记，行内「作废订阅」按钮据此显示
$sql = 'SELECT o.*, u.username, g.is_admin AS group_is_admin'
    . ' FROM orders o LEFT JOIN users u ON u.id = o.user_id LEFT JOIN `groups` g ON g.id = u.group_id'
    . $whereSql . ' ORDER BY o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

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
$statusLabels = ['' => '全部状态', 'pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'void' => '已作废'];
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>订单管理 · Notion Drive</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/app.css?v=23"></head>
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
            <div class="panel-title"><div><h2>设置套餐到期时间</h2><p>按用户名直接指定套餐到期日期，用于补录或纠正到期时间；留空表示永久有效，保存后会同步调整权限组（留空或未来时间进入高级用户组，已过去的时间恢复普通用户组）。本操作不产生订单记录。</p></div></div>
            <form method="post" class="log-filters">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="set_premium_expiry">
                <label><span>用户名</span><input name="username" required placeholder="精确匹配用户名"></label>
                <label><span>到期日期</span><input type="date" name="premium_expires_at"></label>
                <button class="primary-button compact" data-confirm="确认保存该用户的套餐到期时间？留空将设为永久有效。">保存到期时间</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>订单列表</h2><p>共 <?= array_sum($statusCounts) ?> 笔订单 · 待支付 <?= (int) ($statusCounts['pending'] ?? 0) ?> · 已支付 <?= (int) ($statusCounts['paid'] ?? 0) ?> · 已关闭 <?= (int) ($statusCounts['closed'] ?? 0) ?> · 已作废 <?= (int) ($statusCounts['void'] ?? 0) ?></p></div>
                <span><?= $totalOrders ?> 条结果<?= $totalPages > 1 ? ' · 第 ' . $page . '/' . $totalPages . ' 页' : '' ?></span>
            </div>
            <form method="get" class="log-filters">
                <label><span>状态</span><select name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label><span>关键字</span><input name="q" value="<?= e($keyword) ?>" placeholder="订单号、交易号或用户名"></label>
                <button class="primary-button compact">筛选</button>
                <?php if ($status !== '' || $keyword !== ''): ?><a class="text-action" href="admin_orders.php">清除筛选</a><?php endif; ?>
            </form>
            <p class="log-meta">按时间倒序分页展示，每页 <?= $perPage ?> 笔。支付依赖网关异步通知，通知丢失时可对单笔订单点击「刷新状态」主动查单；确认已收到款项但订单仍未开通时，可用「标记已支付」人工开通。交易号带 MANUAL- 前缀的订单是管理员人工开通，状态显示为「管理员授权」；「作废订阅」只作废当前这一笔订单，并按该订单开通前后的到期时间，把它贡献的会员时长从用户身上精确回滚（购买可叠加，不会误伤同一用户的其它订单），扣完才降回普通用户组，已作废的订单不能重复作废。</p>
            <?php if (!$orders): ?>
                <div class="empty-state compact-empty">没有匹配的订单记录。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>订单号</th><th>用户</th><th>套餐</th><th>渠道</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order): $orderStatus = (string) $order['status']; $canSettle = $orderStatus === 'pending' || $orderStatus === 'closed'; ?>
                    <tr>
                        <td>
                            <div class="file-name"><div><strong class="display-name"><?= e((string) $order['order_no']) ?></strong><small><?= (string) $order['trade_no'] !== '' && $order['trade_no'] !== null ? '交易号 ' . e((string) $order['trade_no']) : '—' ?></small></div></div>
                        </td>
                        <td data-label="用户"><?= e((string) ($order['username'] ?? '已删除用户')) ?></td>
                        <td data-label="套餐"><?= (int) $order['months'] ?> 个月</td>
                        <td data-label="渠道"><?= e(payTypeLabel((string) $order['pay_type'])) ?></td>
                        <td data-label="金额">¥<?= e(number_format((float) $order['amount'], 2)) ?></td>
                        <td data-label="状态"><span class="status-badge <?= e(orderDisplayClass($order)) ?>"><?= e(orderDisplayLabel($order)) ?></span></td>
                        <td data-label="创建时间"><span><?= e(date('Y-m-d H:i', (int) strtotime((string) $order['created_at']))) ?><?= $orderStatus === 'paid' && $order['paid_at'] !== null ? '<br><small>付款 ' . e(date('Y-m-d H:i', (int) strtotime((string) $order['paid_at']))) . '</small>' : '' ?><?= $orderStatus === 'void' && ($order['voided_at'] ?? null) !== null ? '<br><small>作废 ' . e(date('Y-m-d H:i', (int) strtotime((string) $order['voided_at']))) . '</small>' : '' ?></span></td>
                        <td>
                            <div class="file-actions">
                                <a href="admin.php?tab=content&user_id=<?= (int) $order['user_id'] ?>">用户</a>
                                <?php if ((int) $order['user_id'] > 0 && !isAdmin($order) && $orderStatus === 'paid'): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="revoke_subscription"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>"><button class="danger" data-confirm="确认作废这一笔订单？将把该订单当初贡献的会员时长从该用户身上回滚，扣完才会降回普通用户组；仅这一笔订单会被标记为「已作废」。">作废订阅</button></form>
                                <?php endif; ?>
                                <?php if ($canSettle): ?>
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="sync_order"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>"><button>刷新状态</button></form>
                                <?php endif; ?>
                                <?php if ($canSettle): ?>
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
    <script src="assets/app.js?v=14" defer></script><script src="assets/admin.js?v=9" defer></script>
</body>
</html>