<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Verification;

if (Auth::user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$done = false;
$email = '';
// 未配置 SMTP 时无法自助收发验证码：直接引导联系管理员，避免用户白填表单
$mailReady = smtpConfigured();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mailReady) {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        if (!verifyCaptcha($_POST['captcha'] ?? null)) {
            throw new RuntimeException('验证码不正确，请重新输入。');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $code = trim((string) ($_POST['code'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            throw new RuntimeException('请输入有效的邮箱地址。');
        }
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('密码至少需要 6 个字符。');
        }
        if ($password !== $passwordConfirm) {
            throw new RuntimeException('两次输入的密码不一致。');
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $account = $statement->fetch();

        // 账号不存在与验证码错误统一回同一提示，避免本页沦为邮箱枚举入口
        if (!is_array($account) || Verification::check($email, 'reset', $code) !== null) {
            throw new RuntimeException('邮箱或验证码不正确，请重新获取验证码后再试。');
        }

        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $account['id']]);

        Logger::info('auth', '用户通过邮箱验证码重置了密码。', ['user_id' => (int) $account['id']]);
        $done = true;
    } catch (Throwable $exception) {
        // 数据库原始报错可能含 SQL 与服务器路径，不直接回显；RuntimeException 的提示需继续展示给用户
        $error = match (true) {
            $exception instanceof PDOException => '数据库操作失败，请稍后重试。',
            $exception instanceof RuntimeException => $exception->getMessage(),
            default => '服务器处理请求时发生未知错误，请稍后重试。',
        };
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?= siteTitleTag('找回密码') ?>
    <?= siteFaviconTag() ?>
    <link rel="stylesheet" href="assets/app.css?v=61">
</head>
<body class="login-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
    <main class="login-layout">
        <section class="login-copy">
            <?= brandHtml('index.php') ?>
            <div class="orbital-art" aria-hidden="true"><span></span><i></i><b>ND</b></div>
            <div><span class="eyebrow">Workspace gateway</span><h1>忘记密码，<br><em>重置访问</em></h1><p>通过绑定邮箱验证身份后设置新密码。</p></div>
        </section>
        <section class="glass-card login-card">
            <div class="card-heading"><div><span class="status-pill"><i></i> 账号安全</span><h2>找回密码</h2><p>使用注册时绑定的邮箱接收验证码</p></div></div>
            <?php if (!$mailReady): ?>
                <div class="alert error" role="alert">系统尚未配置邮件服务，无法自助找回密码，请联系管理员协助重置。</div>
                <p class="login-note"><span>◆</span> <a href="index.php">返回登录</a></p>
            <?php elseif ($done): ?>
                <div class="alert success" role="alert">密码已重置，请使用新密码登录。</div>
                <p class="login-note"><span>◆</span> <a href="index.php">返回登录</a></p>
            <?php else: ?>
                <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
                <form method="post" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                    <label><span>邮箱</span><input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" maxlength="191" autofocus required placeholder="you@example.com"></label>
                    <label><span>邮箱验证码</span>
                        <div class="code-row"><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="6 位数字"><button type="button" class="secondary-button" data-send-code data-purpose="reset">获取验证码</button></div>
                    </label>
                    <p class="code-hint" data-code-hint></p>
                    <label><span>新密码</span><input type="password" name="password" autocomplete="new-password" minlength="6" required placeholder="至少 6 个字符"></label>
                    <label><span>确认新密码</span><input type="password" name="password_confirm" autocomplete="new-password" minlength="6" required placeholder="再次输入新密码"></label>
                    <?= captchaField() ?>
                    <button class="primary-button" type="submit"><span>重置密码</span><b>→</b></button>
                </form>
                <p class="login-note"><span>◆</span> 想起密码了？<a href="index.php">返回登录</a></p>
            <?php endif; ?>
        </section>
    </main>
    <?= siteCreditHtml() ?>
    <script src="assets/app.js?v=31" defer></script>
    <?php if ($mailReady && !$done): ?><script src="assets/verify.js?v=1" defer></script><?php endif; ?>
</body>
</html>
