<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Bans\BanService;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Verification;

$user = Auth::requireLogin();
// 到期前 3 天的邮件提醒：无 cron，靠页面访问懒触发，同一到期周期只发一次
maybeSendPremiumReminder($user);

/** 把上传的图片中心裁剪为 256×256 的方形头像并保存到 storage/avatars。 */
function storeAvatar(array $file): string
{
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('头像上传失败，请重新选择图片后重试。');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('头像文件不能超过 5 MB。');
    }

    $info = @getimagesize($temporaryPath);
    $mime = is_array($info) ? (string) $info['mime'] : '';
    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($temporaryPath),
        'image/png' => @imagecreatefrompng($temporaryPath),
        'image/gif' => @imagecreatefromgif($temporaryPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($temporaryPath) : false,
        default => false,
    };
    if ($source === false) {
        throw new RuntimeException('仅支持 JPG、PNG、GIF 或 WebP 格式的图片。');
    }

    $edge = min(imagesx($source), imagesy($source));
    $offsetX = (int) ((imagesx($source) - $edge) / 2);
    $offsetY = (int) ((imagesy($source) - $edge) / 2);
    $size = 256;
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefilledrectangle($target, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagecopyresampled($target, $source, 0, 0, $offsetX, $offsetY, $size, $size, $edge, $edge);

    $directory = __DIR__ . '/storage/avatars';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建头像目录，请检查 storage 目录权限。');
    }

    $filename = bin2hex(random_bytes(16)) . '.png';
    $saved = imagepng($target, $directory . '/' . $filename, 6);
    if (!$saved) {
        throw new RuntimeException('头像保存失败，请稍后重试。');
    }

    return 'storage/avatars/' . $filename;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $pdo = Database::connection();
        $userId = (int) $user['id'];

        if ($action === 'update_profile') {
            $username = trim((string) ($_POST['username'] ?? ''));
            if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
                throw new RuntimeException('用户名需为 3–64 个字符，仅使用文字、数字、点、横线或下划线。');
            }
            if ($username !== (string) $user['username']) {
                $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = :username AND id <> :id LIMIT 1');
                $taken->execute(['username' => $username, 'id' => $userId]);
                if ($taken->fetch()) {
                    throw new RuntimeException('该用户名已被占用，请换一个。');
                }
                $pdo->prepare('UPDATE users SET username = :username WHERE id = :id')
                    ->execute(['username' => $username, 'id' => $userId]);
                Logger::info('account', '用户修改了用户名。', ['user' => $user['username'], 'new' => $username]);
            }
            flash('success', '用户名已保存。');
        } elseif ($action === 'update_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $password = (string) ($_POST['new_password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            $hashStatement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $hashStatement->execute(['id' => $userId]);
            if (!password_verify($current, (string) $hashStatement->fetchColumn())) {
                throw new RuntimeException('当前密码不正确。');
            }
            if (mb_strlen($password) < 10) {
                throw new RuntimeException('新密码至少需要 10 个字符。');
            }
            if ($password !== $passwordConfirm) {
                throw new RuntimeException('两次输入的新密码不一致。');
            }
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
            // 改密后轮换会话 ID，使泄露的会话标识失效（保留登录状态，仅轮换 ID）
            session_regenerate_id(true);
            Logger::info('account', '用户修改了登录密码。', ['user' => $user['username']]);
            flash('success', '登录密码已更新。');
        } elseif ($action === 'bind_email') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $code = trim((string) ($_POST['code'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
                throw new RuntimeException('请填写有效的邮箱地址。');
            }
            if (emailBound($user) && strcasecmp((string) $user['email'], $email) === 0) {
                throw new RuntimeException('该邮箱已绑定到当前账号。');
            }
            $occupied = $pdo->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $occupied->execute(['email' => $email, 'id' => $userId]);
            if ($occupied->fetch()) {
                throw new RuntimeException('该邮箱已被其他账号使用。');
            }
            $verificationError = Verification::check($email, 'bind', $code);
            if ($verificationError !== null) {
                throw new RuntimeException($verificationError);
            }
            $pdo->prepare('UPDATE users SET email = :email, email_verified_at = NOW() WHERE id = :id')
                ->execute(['email' => $email, 'id' => $userId]);
            Logger::info('account', '用户绑定了邮箱。', ['user' => $user['username'], 'email' => $email]);
            flash('success', '邮箱绑定成功。');
        } elseif ($action === 'bind_notion_own') {
            $notionToken = trim((string) ($_POST['notion_token'] ?? ''));
            // Token 留空表示沿用已保存的 Token，仅校验新的父页面
            if ($notionToken === '') {
                $notionToken = userNotionToken($user);
            }
            bindOwnNotionAccount($userId, $user, $notionToken, trim((string) ($_POST['notion_parent_page'] ?? '')));
            flash('success', '自有 Notion 账户已保存。');
        } elseif ($action === 'switch_notion_source') {
            switchNotionSource($userId, $user, (string) ($_POST['source'] ?? ''));
            flash('success', 'Notion 账户来源已更新，后续上传将使用新来源。');
        } elseif ($action === 'avatar_upload') {
            $avatarPath = storeAvatar((array) ($_FILES['avatar'] ?? []));
            removeAvatarFile((string) ($user['avatar_path'] ?? ''));
            $pdo->prepare('UPDATE users SET avatar_path = :avatar WHERE id = :id')
                ->execute(['avatar' => $avatarPath, 'id' => $userId]);
            Logger::info('account', '用户更新了头像。', ['user' => $user['username']]);
            flash('success', '头像已更新。');
        } elseif ($action === 'avatar_delete') {
            removeAvatarFile((string) ($user['avatar_path'] ?? ''));
            $pdo->prepare('UPDATE users SET avatar_path = NULL WHERE id = :id')->execute(['id' => $userId]);
            flash('success', '头像已移除。');
        } else {
            throw new RuntimeException('未知操作。');
        }

        header('Location: account.php');
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
$hasAvatar = (string) ($user['avatar_path'] ?? '') !== '';
$emailBound = emailBound($user);
$canBindEmail = smtpConfigured();
$quotaBytes = userQuotaBytes($user);
$usedBytes = userUsedBytes((int) $user['id']);
$isPremium = isPremiumUser($user);
$premiumStatus = premiumStatusText($user);
$premiumNotice = premiumNotice($user);
$fileLimit = userSingleFileLimit($user);
$notionSource = userNotionSource($user);
$ownNotionBound = hasOwnNotionAccount($user);
$notionSourceUsable = notionSourceUsable($user);
$officialAccountsUsable = canUseOfficialSource($user);
$assignedOfficialAccounts = $notionSource === 'official' ? userOfficialAccounts((int) $user['id']) : [];
// R10 可信度（违规次数）：普通用户展示；管理员账户不受违规分约束，无需展示
$trust = isAdmin($user) ? null : BanService::create()->trustSummary((int) $user['id']);
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('账户管理') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=51"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav" aria-label="主导航">
            <a href="dashboard.php"><span>▣</span>我的文件</a>
            <a href="tickets.php"><span>✉</span><?= isAdmin($user) ? '工单管理' : '我的工单' ?></a>
            <?php if (isAdmin($user)): ?><a href="admin.php"><span>⚙</span>管理后台</a><a href="admin_orders.php"><span>¥</span>订单管理</a><a href="bans.php"><span>⛔</span>封禁管理</a><a href="logs.php"><span>☰</span>运行日志</a><?php endif; ?>
            <a class="active" href="account.php"><span>◍</span>账户管理</a>
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
            <div><p>个人设置</p><h1>账户管理</h1></div>
            <a class="secondary-button" href="dashboard.php">返回我的文件</a>
        </header>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>账号资料</h2><p>用户名用于登录，修改后请使用新用户名登录。</p></div>
                <span class="sync-state"><?= e((string) ($user['group_name'] ?? '未分组')) ?> · 容量 <?= e(quotaLabel($quotaBytes)) ?> · 已用 <?= e(formatBytes($usedBytes)) ?></span></div>
            <form method="post" class="account-form profile">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="update_profile">
                <label><span>用户名</span><input name="username" value="<?= e($user['username']) ?>" minlength="3" maxlength="64" required></label>
                <button class="primary-button compact">保存用户名</button>
            </form>
        </section>

        <?php if ($trust !== null): ?>
        <section class="admin-panel">
            <?php $trustPoints = (int) $trust['points']; $trustMax = max(1, (int) $trust['threshold']); ?>
            <div class="panel-title">
                <div><h2>可信度</h2><p>违规次数累计到 <?= $trustMax ?> 次会自动封禁（禁止上传、下载与文件操作，工单申诉入口保留），封禁 30 天后自动解封并清零。</p></div>
                <span class="status-badge <?= $trust['tone'] === 'good' ? 'granted' : ($trust['tone'] === 'warn' ? 'pending' : 'void') ?>"><?= e((string) $trust['label']) ?></span>
            </div>
            <div class="trust-card tone-<?= e((string) $trust['tone']) ?>">
                <div class="trust-score">
                    <strong><?= $trustPoints ?><span>/<?= $trustMax ?></span></strong>
                    <small><?= $trustPoints === 0 ? '无违规记录，请继续保持' : '当前违规次数，等级：' . e((string) $trust['label']) ?></small>
                </div>
                <div class="trust-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $trustMax ?>" aria-valuenow="<?= $trustPoints ?>" aria-label="违规次数">
                    <?php for ($slot = 1; $slot <= $trustMax; $slot++): ?><i class="<?= $slot <= $trustPoints ? 'filled' : '' ?>"></i><?php endfor; ?>
                </div>
            </div>
            <?php if ((bool) $trust['banned']): ?>
            <div class="alert error">你的账户当前处于封禁状态<?= (string) $trust['ban_until'] === '' ? '。' : '，预计 ' . e(date('Y-m-d H:i', (int) strtotime((string) $trust['ban_until']))) . ' 自动解封。' ?>如有疑问请 <a href="tickets.php?appeal=1">提交申诉</a>。</div>
            <?php endif; ?>
            <?php
            // 去重：打回记录已在上方按文件展示，这里只列管理员手动加计的留痕
            $manualHistory = array_values(array_filter($trust['history'], static fn (array $row): bool => (string) ($row['source'] ?? '') === 'manual'));
            ?>
            <?php if ($trust['recent'] || $manualHistory): ?>
            <details class="trust-history">
                <summary>历史违规记录（<?= count($trust['recent']) + count($manualHistory) ?> 条）</summary>
                <ul>
                    <?php foreach ($trust['recent'] as $violation): ?>
                    <li><span class="status-badge void">违规文件</span><span><?= e((string) $violation['filename']) ?></span><small><?= e((string) ($violation['reason'] ?? '') !== '' ? (string) $violation['reason'] : '未通过内容审核') ?><?= (string) ($violation['appeal_no'] ?? '') === '' ? '' : ' · 申诉号 ' . e((string) $violation['appeal_no']) ?> · <?= e(date('Y-m-d H:i', (int) strtotime((string) $violation['occurred_at']))) ?></small></li>
                    <?php endforeach; ?>
                    <?php foreach ($manualHistory as $violation): ?>
                    <li><span class="status-badge pending">管理员加计</span><span><?= (int) $violation['points'] > 0 ? '+' . (int) $violation['points'] . ' 次' : '留痕' ?></span><small><?= e((string) ($violation['reason'] ?? '') !== '' ? (string) $violation['reason'] : '未说明') ?> · <?= e(date('Y-m-d H:i', (int) strtotime((string) $violation['created_at']))) ?></small></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!isAdmin($user)): ?>
        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>会员套餐</h2><p>高级用户不限总容量；官方账户来源的单个文件最大 5 GB，自有账户来源受 Notion 官方限制（5 MB）。</p></div>
                <span class="<?= $isPremium ? 'status-badge' : 'sync-state' ?>"><?= e($premiumStatus) ?></span>
            </div>
            <?php if ($premiumNotice !== ''): ?><div class="alert error"><?= e($premiumNotice) ?></div><?php endif; ?>
            <div class="subscribe-form">
                <div class="plan-summary">
                    <div><span>权限组</span><strong><?= e((string) ($user['group_name'] ?? '用户')) ?></strong></div>
                    <div><span>总容量</span><strong><?= e(quotaLabel($quotaBytes)) ?></strong></div>
                    <div><span>单个文件上限</span><strong><?= e(formatBytes($fileLimit)) ?></strong></div>
                    <div><span>账户来源</span><strong><?= userNotionSource($user) === 'own' ? '自有账户' : '官方账户池' ?></strong></div>
                </div>
                <div class="subscribe-actions">
                    <a class="primary-button compact" href="pay.php"><?= $isPremium ? '续费 / 延长' : '升级高级用户' ?></a>
                    <p class="field-hint">前往「会员套餐」页选择时长与支付方式，支付成功后自动开通；已是高级用户时，购买时长在原到期时间基础上顺延。</p>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>Notion 账户</h2><p>上传的文件会存放在下方选定的 Notion 账户中；切换来源只影响之后上传的文件，已上传文件仍留在原账户且可正常下载。</p></div>
                <span class="<?= $notionSourceUsable ? 'status-badge' : 'sync-state' ?>"><?= $notionSourceUsable ? '当前来源可用' : '当前来源不可用' ?></span>
            </div>
            <?php if (!$notionSourceUsable): ?>
            <?php if ($notionSource === 'own'): ?>
            <div class="alert error">自有账户尚未绑定或不可用，上传会失败。请在下方填写并验证 Notion 集成 Token 与父页面。</div>
            <?php elseif (!$officialAccountsUsable): ?>
            <div class="alert error">官方账户仅对有效期内的付费用户开放。请先 <a href="pay.php">升级高级用户</a>，或在下方绑定自有账户后切换到自有账户。</div>
            <?php else: ?>
            <div class="alert error">官方账户池暂时没有可用账户，请联系管理员检查账户池状态。</div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="source-toggle">
                <div class="source-status"><span>当前来源</span><strong><?= $notionSource === 'own' ? '自有账户' : '官方账户池' ?></strong></div>
                <?php if ($notionSource === 'own'): ?>
                    <?php if ($officialAccountsUsable): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="switch_notion_source"><input type="hidden" name="source" value="official"><button class="secondary-button" data-confirm="切换为官方账户后，新上传的文件将存入官方账户池，单文件最大 5 GB。已上传文件不受影响。确认切换？">切换为官方账户</button></form>
                    <?php else: ?>
                    <p class="source-note">购买高级用户套餐后即可切换到官方账户池（不限总容量、单文件最大 5 GB）。</p>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="switch_notion_source"><input type="hidden" name="source" value="own"><button class="secondary-button" <?= $ownNotionBound ? '' : 'disabled' ?>>切换为自有账户</button></form>
                    <p class="source-note">官方账户池为全体付费用户共享：优先使用这里分配的 <?= count($assignedOfficialAccounts) ?> 个账户，池内其他启用账户在需要时自动顺延（最多主备两个）。<?= $ownNotionBound ? '' : '需先绑定自有账户才能切回。' ?></p>
                <?php endif; ?>
            </div>

            <div class="source-block">
                <div class="source-status"><span>自有账户</span><strong class="<?= $ownNotionBound ? 'status-badge' : 'sync-state' ?>"><?= $ownNotionBound ? '已绑定' : '未绑定' ?></strong></div>
                <form method="post" class="account-form notion">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="bind_notion_own">
                    <label><span>Notion 集成 Token</span><input type="password" name="notion_token" autocomplete="off" maxlength="200" placeholder="<?= $ownNotionBound ? '留空表示沿用已保存的 Token' : 'ntn_••••••••••••' ?>"></label>
                    <label><span>Notion 父页面</span><input name="notion_parent_page" value="<?= e((string) ($user['notion_parent_page_id'] ?? '')) ?>" maxlength="500" required placeholder="https://www.notion.so/..."></label>
                    <button class="primary-button compact"><?= $ownNotionBound ? '更新自有账户' : '绑定自有账户' ?></button>
                    <p class="code-hint">在 Notion 中创建内部集成后复制 Token，并把该父页面共享给集成（页面右上角 ··· → 连接）。Token 会加密保存且不会回显；更换 Token 或父页面后，若旧 Token 失效，此前上传的文件可能无法下载。</p>
                </form>
            </div>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>头像</h2><p>图片会由服务器裁剪压缩为 256×256 的方形头像，保存在服务器本地。</p></div></div>
            <div class="avatar-editor">
                <?= avatarHtml($user, 'avatar large') ?>
                <div class="avatar-editor-body">
                    <form method="post" enctype="multipart/form-data" class="account-form">
                        <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="avatar_upload">
                        <label><span>选择图片</span><input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
                        <button class="primary-button compact">上传头像</button>
                    </form>
                    <?php if ($hasAvatar): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="avatar_delete"><button class="secondary-button" data-confirm="确认移除当前头像？">移除头像</button></form>
                    <?php endif; ?>
                    <p class="field-hint">支持 JPG、PNG、GIF、WebP，单个文件不超过 5 MB。</p>
                </div>
            </div>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>登录密码</h2><p>修改密码需要先验证当前密码，新密码至少 10 个字符。</p></div></div>
            <form method="post" class="account-form password">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="update_password">
                <label><span>当前密码</span><input type="password" name="current_password" autocomplete="current-password" required placeholder="••••••••••"></label>
                <label><span>新密码</span><input type="password" name="new_password" autocomplete="new-password" minlength="10" required placeholder="至少 10 个字符"></label>
                <label><span>确认新密码</span><input type="password" name="password_confirm" autocomplete="new-password" minlength="10" required placeholder="再次输入新密码"></label>
                <button class="primary-button compact">更新密码</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>邮箱绑定</h2><p>绑定邮箱需要邮件验证码；管理员开启「强制绑定邮箱」后，未验证邮箱将无法上传文件。</p></div><span class="<?= $emailBound ? 'status-badge' : 'sync-state' ?>"><?= $emailBound ? '已绑定 ' . e((string) $user['email']) : '未绑定邮箱' ?></span></div>
            <?php if (!mayUpload($user)): ?><div class="alert error">管理员已开启「强制绑定邮箱」，请先绑定并验证邮箱后才能上传文件。</div><?php endif; ?>
            <?php if (!$canBindEmail): ?>
                <p class="compact-empty">管理员尚未配置 SMTP 邮件服务，暂时无法发送验证码，请联系管理员。</p>
            <?php else: ?>
            <form method="post" class="account-form email">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="bind_email">
                <label><span>邮箱地址</span><input type="email" name="email" value="<?= $emailBound ? e((string) $user['email']) : '' ?>" maxlength="191" required placeholder="you@example.com"></label>
                <label><span>邮箱验证码</span>
                    <div class="code-row"><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="6 位数字"><button type="button" class="secondary-button" data-send-code data-purpose="bind">获取验证码</button></div>
                </label>
                <button class="primary-button compact"><?= $emailBound ? '更换邮箱' : '绑定邮箱' ?></button>
                <p class="code-hint" data-code-hint></p>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <?= siteCreditHtml() ?>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js?v=26" defer></script>
    <script src="assets/admin.js?v=9" defer></script>
    <?php if ($canBindEmail): ?><script src="assets/verify.js?v=1" defer></script><?php endif; ?>
</body>
</html>
