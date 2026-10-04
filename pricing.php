<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Share\ShareService;

$user = Auth::requireLogin();
$userId = (int) $user['id'];
// 管理员天然拥有高级用户与直链权限（额度不限），不再购买；整页只展示、不产生订单
$isAdmin = isAdmin($user);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        if ($isAdmin) {
            throw new RuntimeException('管理员账号无需购买。');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'create_order') {
            throw new RuntimeException('未知操作。');
        }

        $payType = trim((string) ($_POST['pay_type'] ?? ''));
        if ($payType === '' || !isset(epayPayTypes()[$payType])) {
            throw new RuntimeException('请选择有效的支付方式。');
        }
        if (Epay::fromConfig() === null) {
            throw new RuntimeException('支付功能尚未配置，请联系管理员。');
        }

        $productType = (string) ($_POST['product_type'] ?? 'premium');
        if ($productType === 'share_pack') {
            $links = (int) ($_POST['links'] ?? 0);
            $pack = ShareService::packByCount($links);
            if ($pack === null) {
                throw new RuntimeException('该直链包不存在或已下架。');
            }
            // 未定价的档位不产生订单，避免出现 ¥0 订单
            if ((float) $pack['price'] <= 0) {
                throw new RuntimeException('该直链包暂未定价，无法购买。');
            }
            $order = createOrder(
                $userId,
                0,
                (float) $pack['price'],
                $payType,
                'share_pack',
                (string) json_encode(['links' => $links], JSON_UNESCAPED_UNICODE)
            );
            Logger::info('payment', '用户创建直链包订单，准备跳转支付网关。', [
                'user_id' => $userId,
                'order_no' => (string) $order['order_no'],
                'links' => $links,
                'amount' => (string) $order['amount'],
                'pay_type' => $payType,
            ]);
        } else {
            $plan = premiumPlan((int) ($_POST['months'] ?? 0));
            if ($plan === null) {
                throw new RuntimeException('请选择有效的套餐。');
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
        }

        // CSP form-action='self' 会拦截「表单 POST → 跨站重定向」，故改为同源 302 到收银台过渡页，由页面脚本导航前往网关
        header('Location: pay.php?order=' . rawurlencode((string) $order['order_no']) . '&checkout=1');
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

$flash = pullFlash();
$plans = premiumPlans();
$payTypes = epayPayTypes();
$payConfigured = Epay::configured() && $payTypes !== [];

$isPremium = isPremiumUser($user);
$quotaBytes = userQuotaBytes($user);
$fileLimit = userSingleFileLimit($user);
$statusText = premiumStatusText($user);

// 直链包：仅展示「上架且已定价」的档位可购买；其余档位灰显提示暂不出售
$shareEnabled = ShareService::enabled();
$sharePacks = ShareService::packs();
$sharePurchasable = array_values(array_filter(
    $sharePacks,
    static fn (array $pack): bool => $pack['enabled'] && $pack['price'] !== '' && (float) $pack['price'] > 0
));
$shareRemaining = ShareService::remaining($user);
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('会员套餐') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=67"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav" aria-label="主导航">
            <a href="dashboard.php"><?= navIcon('files') ?>我的文件</a>
            <a href="tickets.php"><?= navIcon('tickets') ?><?= isAdmin($user) ? '工单管理' : '我的工单' ?></a>
            <?php if ($isAdmin): ?><a href="admin.php"><?= navIcon('admin') ?>管理后台</a><a href="admin_orders.php"><?= navIcon('orders') ?>订单管理</a><a href="bans.php"><?= navIcon('bans') ?>封禁管理</a><a href="logs.php"><?= navIcon('logs') ?>运行日志</a><?php endif; ?>
            <a href="account.php"><?= navIcon('account') ?>账户管理</a>
            <a class="active" href="pricing.php"><?= navIcon('premium') ?>会员套餐</a>
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

    <main class="drive-main">
        <?= banNoticeHtml($user) ?>
        <header class="drive-header">
            <div><p>高级用户</p><h1>会员套餐</h1></div>
            <a class="secondary-button" href="dashboard.php">返回我的文件</a>
        </header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <?php if ($isAdmin): ?>
        <div class="alert success">管理员账号默认拥有高级用户与直链权限（直链额度不限），无需购买任何套餐。</div>
        <?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>当前套餐</h2><p>高级用户不限总容量，官方账户来源的单个文件最大 5 GB，自有账户来源受 Notion 官方限制（5 MB）。</p></div>
                <span class="<?= $isPremium ? 'status-badge' : 'sync-state' ?>"><?= e($statusText) ?></span>
            </div>
            <div class="plan-summary">
                <div><span>权限组</span><strong><?= $isAdmin ? '管理员' : e((string) ($user['group_name'] ?? '用户')) ?></strong></div>
                <div><span>总容量</span><strong><?= e(quotaLabel($quotaBytes)) ?></strong></div>
                <div><span>单个文件上限</span><strong><?= e(formatBytes($fileLimit)) ?></strong></div>
                <div><span>直链剩余条数</span><strong><?= $shareRemaining === null ? '不限' : (int) $shareRemaining . ' 条' ?></strong></div>
            </div>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>高级会员</h2><p>支付成功后自动升级为高级用户；已是高级用户时，购买时长将在原到期时间基础上顺延。</p></div></div>
            <?php if ($isAdmin): ?>
                <p class="compact-empty">管理员账号无需购买高级会员。</p>
            <?php elseif (!$payConfigured): ?>
                <p class="compact-empty">管理员尚未开通在线支付，暂时无法购买，请联系管理员。</p>
            <?php else: ?>
            <form method="post" class="subscribe-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="create_order"><input type="hidden" name="product_type" value="premium">
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
                    <p class="field-hint">支付完成后会跳回本站并自动开通；若未自动开通，可到<a href="pay.php">订单记录</a>中点击「刷新状态」。</p>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>直链包</h2><p>直链是免登录的公开下载 / 预览地址，可在「我的文件」中为文件生成。直链仅高级用户可用；购买后额度长期有效，链接被撤销或过期后自动释放条数。</p></div>
                <?php if ($isAdmin): ?><span class="status-badge">管理员额度不限</span><?php endif; ?></div>
            <?php if (!$shareEnabled && !$isAdmin): ?>
                <p class="compact-empty">直链分享功能当前未开放，请稍后再试。</p>
            <?php elseif (!$isAdmin && !$payConfigured): ?>
                <p class="compact-empty">管理员尚未开通在线支付，暂时无法购买，请联系管理员。</p>
            <?php elseif ($sharePurchasable === [] && !$isAdmin): ?>
                <p class="compact-empty">暂无可购买的直链包，请稍后再试。</p>
            <?php elseif (!$isAdmin): ?>
            <form method="post" class="subscribe-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="create_order"><input type="hidden" name="product_type" value="share_pack">
                <div class="plan-grid">
                    <?php foreach ($sharePacks as $pack): $packCount = (int) $pack['count']; $buyable = in_array($pack, $sharePurchasable, true); ?>
                    <label class="plan-option<?= $buyable ? '' : ' disabled' ?>">
                        <input type="radio" name="links" value="<?= $packCount ?>" <?= $buyable ? '' : 'disabled' ?> <?= $buyable && $packCount === (int) ($sharePurchasable[0]['count'] ?? 0) ? 'checked' : '' ?>>
                        <span class="plan-name"><?= $packCount ?> 条</span>
                        <span class="plan-price"><?= $buyable ? '¥' . e(number_format((float) $pack['price'], 2)) : '暂不出售' ?></span>
                        <span class="plan-unit"><?= $buyable ? e(number_format((float) $pack['price'] / $packCount, 2)) . ' 元 / 条' : '未上架或未定价' ?></span>
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
                    <p class="field-hint">直链额度不设有效期，购买后可与会员到期时间无关地长期使用。</p>
                </div>
            </form>
            <?php else: ?>
            <div class="plan-grid">
                <?php foreach ($sharePacks as $pack): $packCount = (int) $pack['count']; $buyable = $pack['enabled'] && $pack['price'] !== '' && (float) $pack['price'] > 0; ?>
                <div class="plan-option">
                    <span class="plan-name"><?= $packCount ?> 条</span>
                    <span class="plan-price"><?= $buyable ? '¥' . e(number_format((float) $pack['price'], 2)) : '暂不出售' ?></span>
                    <span class="plan-unit"><?= $buyable ? e(number_format((float) $pack['price'] / $packCount, 2)) . ' 元 / 条' : '未上架或未定价' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>
    <?= siteCreditHtml() ?>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=40" defer></script>
</body>
</html>
