<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Security;

$user = Auth::requireLogin();
// 管理员无需购买套餐，整页对含 POST 的所有请求直接挡回，避免权限组/到期时间被改乱
if (isAdmin($user)) {
    flash('error', '管理员账号无需购买套餐。');
    header('Location: dashboard.php');
    exit;
}
// 到期前 3 天的邮件提醒：无 cron，靠页面访问懒触发，同一到期周期只发一次
maybeSendPremiumReminder($user);
$userId = (int) $user['id'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_order') {
            $plan = premiumPlan((int) ($_POST['months'] ?? 0));
            if ($plan === null) {
                throw new RuntimeException('请选择有效的套餐。');
            }
            $payType = trim((string) ($_POST['pay_type'] ?? ''));
            if ($payType === '' || !isset(epayPayTypes()[$payType])) {
                throw new RuntimeException('请选择有效的支付方式。');
            }

            $epay = Epay::fromConfig();
            if ($epay === null) {
                throw new RuntimeException('支付功能尚未配置，请联系管理员。');
            }

            // 金额只取服务端配置，忽略客户端提交的任何价格
            $order = createOrder($userId, (int) $plan['months'], (float) $plan['price'], $payType);
            Logger::info('payment', '用户创建订单，准备跳转支付网关。', [
                'user_id' => $userId,
                'order_no' => (string) $order['order_no'],
                'months' => (int) $plan['months'],
                'amount' => (string) $order['amount'],
                'pay_type' => $payType,
            ]);

            // CSP form-action='self' 会拦截「表单 POST → 跨站重定向」，故改为同源 302 到收银台过渡页，由页面脚本导航前往网关
            header('Location: pay.php?order=' . rawurlencode((string) $order['order_no']) . '&checkout=1');
            exit;
        }

        if ($action === 'sync_order') {
            $order = findOrderByNo(trim((string) ($_POST['order_no'] ?? '')));
            if ($order === null || (int) $order['user_id'] !== $userId) {
                throw new RuntimeException('订单不存在。');
            }

            if ((string) $order['status'] === 'paid') {
                flash('success', '该订单已完成支付。');
            } else {
                $result = reconcileOrder($order);
                flash($result['paid'] ? 'success' : 'error', $result['message']);
            }

            header('Location: pay.php?order=' . rawurlencode((string) $order['order_no']));
            exit;
        }

        throw new RuntimeException('未知操作。');
    } catch (Throwable $exception) {
        // 只回显可安全展示的提示：数据库原始报错可能包含 SQL 语句与服务器路径
        $error = match (true) {
            $exception instanceof PDOException => '数据库操作失败，请稍后重试。',
            $exception instanceof RuntimeException => $exception->getMessage(),
            default => '服务器处理请求时发生未知错误，请稍后重试，或凭参考号在「运行日志」中查询详情。',
        };
    }
}

$flash = pullFlash();
$plans = premiumPlans();
$payTypes = epayPayTypes();
$payConfigured = Epay::configured();

$isPremium = isPremiumUser($user);
$quotaBytes = userQuotaBytes($user);
$usedBytes = userUsedBytes($userId);
$fileLimit = userSingleFileLimit($user);

$focusOrderNo = trim((string) ($_GET['order'] ?? ''));

$orderStatement = Database::connection()->prepare(
    'SELECT * FROM orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 20'
);
$orderStatement->execute(['user_id' => $userId]);
$orders = $orderStatement->fetchAll();

$statusText = premiumStatusText($user);

// 收银台过渡页：按订单记录重拼提单地址交给页面脚本跳转（订单号来自 URL，但只认本人在待支付状态下的订单）
$checkoutUrl = '';
$checkoutOrder = null;
if ($focusOrderNo !== '' && isset($_GET['checkout'])) {
    $candidate = findOrderByNo($focusOrderNo);
    $epay = Epay::fromConfig();
    if ($candidate !== null
        && (int) $candidate['user_id'] === $userId
        && (string) $candidate['status'] === 'pending'
        && $epay !== null
    ) {
        $checkoutOrder = $candidate;
        $checkoutUrl = $epay->submitUrl([
            'type' => (string) $candidate['pay_type'],
            'out_trade_no' => (string) $candidate['order_no'],
            'notify_url' => epayNotifyUrl(),
            'return_url' => epayReturnUrl(),
            'name' => 'Notion Drive 高级用户' . (int) $candidate['months'] . '个月',
            'money' => (string) $candidate['amount'],
        ]);
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('会员套餐') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=61"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>"<?= $checkoutUrl !== '' ? ' data-checkout-url="' . e($checkoutUrl) . '"' : '' ?>>
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav" aria-label="主导航">
            <a href="dashboard.php"><?= navIcon('files') ?>我的文件</a>
            <a href="tickets.php"><?= navIcon('tickets') ?><?= isAdmin($user) ? '工单管理' : '我的工单' ?></a>
            <?php if (isAdmin($user)): ?><a href="admin.php"><?= navIcon('admin') ?>管理后台</a><a href="admin_orders.php"><?= navIcon('orders') ?>订单管理</a><a href="bans.php"><?= navIcon('bans') ?>封禁管理</a><a href="logs.php"><?= navIcon('logs') ?>运行日志</a><?php endif; ?>
            <a href="account.php"><?= navIcon('account') ?>账户管理</a>
            <a class="active" href="pay.php"><?= navIcon('premium') ?>会员套餐</a>
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
            <div><p>高级用户</p><h1>会员套餐</h1></div>
            <a class="secondary-button" href="dashboard.php">返回我的文件</a>
        </header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php if ($checkoutUrl !== ''): ?>
        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>订单已创建，正在前往收银台</h2><p>订单号 <?= e((string) $checkoutOrder['order_no']) ?> · <?= e(payTypeLabel((string) $checkoutOrder['pay_type'])) ?> · ¥<?= e(number_format((float) $checkoutOrder['amount'], 2)) ?></p></div>
            </div>
            <div class="subscribe-actions">
                <a class="primary-button" href="<?= e($checkoutUrl) ?>">前往收银台支付</a>
                <p class="field-hint">若浏览器未自动跳转，请点击上方按钮继续支付。支付完成后会跳回本站并自动开通。</p>
            </div>
        </section>
        <?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>当前套餐</h2><p>高级用户不限总容量，官方账户来源的单个文件最大 5 GB，自有账户来源受 Notion 官方限制（5 MB）。</p></div>
                <span class="<?= $isPremium ? 'status-badge' : 'sync-state' ?>"><?= e($statusText) ?></span>
            </div>
            <div class="plan-summary">
                <div><span>权限组</span><strong><?= isAdmin($user) ? '管理员' : e((string) ($user['group_name'] ?? '用户')) ?></strong></div>
                <div><span>总容量</span><strong><?= e(quotaLabel($quotaBytes)) ?></strong></div>
                <div><span>单个文件上限</span><strong><?= e(formatBytes($fileLimit)) ?></strong></div>
                <div><span>账户来源</span><strong><?= userNotionSource($user) === 'own' ? '自有账户' : '官方账户池' ?></strong></div>
            </div>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>购买 / 续费</h2><p>支付成功后自动升级为高级用户；已是高级用户时，购买时长将在原到期时间基础上顺延。</p></div></div>
            <?php if (!$payConfigured): ?>
                <p class="compact-empty">管理员尚未配置易支付接口，暂时无法购买套餐，请联系管理员。</p>
            <?php elseif ($payTypes === []): ?>
                <p class="compact-empty">管理员尚未开放任何支付渠道，暂时无法购买套餐，请联系管理员。</p>
            <?php else: ?>
            <form method="post" class="subscribe-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="create_order">
                <div class="plan-grid">
                    <?php foreach ($plans as $months => $price): ?>
                    <label class="plan-option">
                        <input type="radio" name="months" value="<?= (int) $months ?>" <?= (int) $months === 1 ? 'checked' : '' ?>>
                        <span class="plan-name"><?= (int) $months ?> 个月</span>
                        <span class="plan-price">¥<?= e(number_format((float) $price, 2)) ?></span>
                        <span class="plan-unit"><?= e(number_format((float) $price / (int) $months, 2)) ?> 元 / 月</span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="pay-type-row" role="radiogroup" aria-label="支付方式">
                    <?php foreach ($payTypes as $type => $label): ?>
                    <label class="pay-type-option">
                        <input type="radio" name="pay_type" value="<?= e($type) ?>" <?= $type === array_key_first($payTypes) ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="subscribe-actions">
                    <button class="primary-button">立即支付</button>
                    <p class="field-hint">支付完成后会跳回本站并自动开通；若未自动开通，可在下方订单记录中点击「刷新状态」。</p>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>订单记录</h2><p>最近 20 笔订单。不支持退款，如需帮助请联系管理员。管理员人工开通的订单显示为「管理员授权」，被作废的订单显示为「已作废」。</p></div></div>
            <?php if ($orders === []): ?>
                <p class="compact-empty">还没有订单记录。</p>
            <?php else: ?>
            <div class="order-list">
                <?php foreach ($orders as $order): ?>
                <?php $status = (string) $order['status']; ?>
                <div class="order-row<?= $focusOrderNo !== '' && $focusOrderNo === (string) $order['order_no'] ? ' focused' : '' ?>">
                    <div class="order-main">
                        <span class="order-no"><?= e((string) $order['order_no']) ?></span>
                        <small><?= e(date('Y-m-d H:i', (int) strtotime((string) $order['created_at']))) ?> · <?= (int) $order['months'] ?> 个月 · <?= e(payTypeLabel((string) $order['pay_type'])) ?></small>
                    </div>
                    <div class="order-amount">¥<?= e(number_format((float) $order['amount'], 2)) ?></div>
                    <span class="status-badge <?= e(orderDisplayClass($order)) ?>"><?= e(orderDisplayLabel($order)) ?></span>
                    <div class="order-actions">
                        <?php if (in_array($status, ['pending', 'closed'], true)): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="sync_order"><input type="hidden" name="order_no" value="<?= e((string) $order['order_no']) ?>">
                            <button class="secondary-button">刷新状态</button>
                        </form>
                        <?php elseif ($status === 'paid'): ?>
                        <small class="order-paid-at"><?= e((string) $order['paid_at'] !== '' ? date('Y-m-d H:i', (int) strtotime((string) $order['paid_at'])) : '') ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
    <?= siteCreditHtml() ?>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=31" defer></script>
</body>
</html>