<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Security;

if (Auth::user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$usernameInput = '';
$throttle = ['locked' => false, 'failures' => 0, 'ip_failures' => 0, 'captcha' => false, 'retry_after' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = trim((string) ($_POST['username'] ?? ''));
    $throttle = loginThrottle($usernameInput);
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        if ($throttle['locked']) {
            throw new RuntimeException(sprintf(
                '登录尝试次数过多，请 %d 分钟后再试。',
                max(1, (int) ceil($throttle['retry_after'] / 60))
            ));
        }
        // 连续失败后开始要求图形验证码，避免自动化撞库
        if ($throttle['captcha'] && !verifyCaptcha($_POST['captcha'] ?? null)) {
            throw new RuntimeException('验证码不正确，请重新输入。');
        }
        if (!Auth::attempt($usernameInput, (string) ($_POST['password'] ?? ''))) {
            recordLoginFailure($usernameInput);
            // 失败越多等得越久（上限 2 秒），抬高自动化尝试的成本
            usleep(min(2000000, 350000 * max(1, $throttle['failures'] + 1)));
            throw new RuntimeException('账号或密码不正确。');
        }
        clearLoginFailures($usernameInput);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $throttle = loginThrottle($usernameInput);
    }
}

$captchaRequired = $throttle['captcha'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?= siteTitleTag('登录') ?>
    <?= siteFaviconTag() ?>
    <link rel="stylesheet" href="assets/app.css?v=52">
</head>
<body class="login-shell">
    <div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
    <main class="login-layout">
        <section class="login-copy">
            <?= brandHtml('index.php') ?>
            <div class="orbital-art" aria-hidden="true"><span></span><i></i><b>ND</b></div>
            <div><span class="eyebrow">Workspace gateway</span><h1>文件，从这里<br><em>流向 Notion</em></h1><p>一个专注、安全的团队上传空间。</p></div>
        </section>
        <section class="glass-card login-card">
            <div class="card-heading"><div><span class="status-pill"><i></i> 服务在线</span><h2>欢迎回来</h2><p>使用管理员分配的账号登录</p></div></div>
            <?php if (isset($_GET['installed'])): ?><div class="alert success">安装完成，现在可以登录。</div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <label><span>用户名</span><input name="username" value="<?= e($usernameInput) ?>" autocomplete="username" autofocus required placeholder="your.name"></label>
                <label><span>密码</span><input type="password" name="password" autocomplete="current-password" required placeholder="••••••••••"></label>
                <?php if ($captchaRequired): ?><?= captchaField() ?><?php endif; ?>
                <button class="primary-button" type="submit"><span>进入工作区</span><b>→</b></button>
            </form>
            <p class="login-note"><span>◆</span> 使用管理员分配的账号登录</p>
            <?php if (registrationEnabled()): ?><p class="login-note"><span>◆</span> 还没有账号？<a href="register.php">注册新账号</a></p><?php endif; ?>
        </section>
    </main>
    <?= siteCreditHtml() ?>
    <script src="assets/app.js?v=27" defer></script>
</body>
</html>
