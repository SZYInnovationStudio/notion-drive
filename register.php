<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Verification;

if (!registrationEnabled()) {
    header('Location: index.php');
    exit;
}

if (Auth::user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$emailRequired = emailRequired();
$username = '';
$email = '';
$notionParentPage = '';
$notionSource = 'own';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        if (!verifyCaptcha($_POST['captcha'] ?? null)) {
            throw new RuntimeException('验证码不正确，请重新输入。');
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $code = trim((string) ($_POST['code'] ?? ''));
        $notionToken = trim((string) ($_POST['notion_token'] ?? ''));
        $notionParentPage = trim((string) ($_POST['notion_parent_page'] ?? ''));
        $notionSource = (string) ($_POST['notion_source'] ?? 'own');

        if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
            throw new RuntimeException('用户名需为 3–64 个字符，仅使用文字、数字、点、横线或下划线。');
        }
        if (mb_strlen($password) < 10) {
            throw new RuntimeException('密码至少需要 10 个字符。');
        }
        if ($password !== $passwordConfirm) {
            throw new RuntimeException('两次输入的密码不一致。');
        }

        $pdo = Database::connection();
        $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
        $taken->execute(['username' => $username]);
        if ($taken->fetch()) {
            throw new RuntimeException('该用户名已被占用，请换一个。');
        }

        if ($emailRequired) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
                throw new RuntimeException('请填写有效的邮箱地址。');
            }
            $occupied = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $occupied->execute(['email' => $email]);
            if ($occupied->fetch()) {
                throw new RuntimeException('该邮箱已被其他账号使用。');
            }
            $verificationError = Verification::check($email, 'register', $code);
            if ($verificationError !== null) {
                throw new RuntimeException($verificationError);
            }
        } else {
            $email = '';
        }

        if (!in_array($notionSource, ['own', 'official'], true)) {
            $notionSource = 'own';
        }

        // 选择自有账户时注册即绑定：Token 必须能读到该父页面，否则不建号；
        // 选择官方账户时暂不绑定，购买高级用户套餐后由系统自动分配官方账户
        if ($notionSource === 'own') {
            $binding = assertNotionBinding($notionToken, $notionParentPage);
            $notionTokenEncrypted = Security::encrypt((string) $binding['token'], (string) appConfig()['app_key']);
            $notionParentPageId = (string) $binding['page_id'];
        } else {
            $notionToken = '';
            $notionParentPage = '';
            $notionTokenEncrypted = null;
            $notionParentPageId = null;
        }

        $group = findGroup(defaultGroupId());
        if ($group === null) {
            throw new RuntimeException('系统尚未创建默认权限组，请联系管理员。');
        }

        // 验证时间的取值只有 NOW() 与 NULL 两种固定字面量，用数据库时间以保证时区一致
        $verifiedAt = $emailRequired ? 'NOW()' : 'NULL';
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, group_id, email, email_verified_at,
                                notion_token_encrypted, notion_parent_page_id, notion_account_source)
             VALUES (:username, :password_hash, :group_id, :email, ' . $verifiedAt . ',
                     :notion_token_encrypted, :notion_parent_page_id, :notion_account_source)'
        )->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'group_id' => (int) $group['id'],
            'email' => $email === '' ? null : $email,
            'notion_token_encrypted' => $notionTokenEncrypted,
            'notion_parent_page_id' => $notionParentPageId,
            'notion_account_source' => $notionSource,
        ]);

        Logger::info('auth', '新用户注册成功。', ['username' => $username, 'notion_source' => $notionSource]);
        Auth::attempt($username, $password);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $exception) {
        // 数据库原始报错可能包含 SQL 与服务器路径，不直接回显；NotionApiException 继承 RuntimeException，
        // 绑定校验的提示需要继续展示给用户
        $error = match (true) {
            $exception instanceof PDOException => (int) $exception->getCode() === 23000
                ? '用户名或邮箱已被占用，请更换后重试。'
                : '数据库操作失败，请稍后重试。',
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
    <title>注册 · Notion Drive</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/app.css?v=24">
</head>
<body class="login-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <main class="login-layout">
        <section class="login-copy">
            <a class="brand" href="index.php"><span class="brand-mark">N</span><span>Notion Drive</span></a>
            <div><span class="eyebrow">Workspace gateway</span><h1>创建账号，<br><em>开始上传</em></h1><p>注册后即可拥有独立的个人文件空间。</p></div>
        </section>
        <section class="glass-card login-card">
            <div class="card-heading"><div><span class="status-pill"><i></i> 开放注册</span><h2>注册新账号</h2><p>填写下面的信息即可开始使用</p></div></div>
            <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <label><span>用户名</span><input name="username" value="<?= e($username) ?>" autocomplete="username" minlength="3" maxlength="64" autofocus required placeholder="your.name"></label>
                <label><span>密码</span><input type="password" name="password" autocomplete="new-password" minlength="10" required placeholder="至少 10 个字符"></label>
                <label><span>确认密码</span><input type="password" name="password_confirm" autocomplete="new-password" minlength="10" required placeholder="再次输入密码"></label>
                <fieldset class="source-fieldset">
                    <legend>Notion 账户来源</legend>
                    <div class="source-choice">
                        <label class="source-option"><input type="radio" name="notion_source" value="own" <?= $notionSource === 'own' ? 'checked' : '' ?>>自有 Notion 账户</label>
                        <label class="source-option"><input type="radio" name="notion_source" value="official" <?= $notionSource === 'official' ? 'checked' : '' ?>>使用官方账户</label>
                    </div>
                </fieldset>
                <div class="source-own">
                    <label><span>Notion 集成 Token</span><input type="password" name="notion_token" autocomplete="off" maxlength="200" placeholder="ntn_••••••••••••"><small>在 Notion 中创建内部集成后复制 Token；Token 会加密保存，不会回显。</small></label>
                    <label><span>Notion 父页面</span><input name="notion_parent_page" value="<?= e($notionParentPage) ?>" maxlength="500" placeholder="https://www.notion.so/..."><small>把该页面共享给上面的集成（页面右上角 ··· → 连接），你的文件将保存在它下面。</small></label>
                </div>
                <div class="source-official">
                    <p class="code-hint">选择官方账户无需填写 Token；注册后购买高级用户套餐，系统会自动为你分配官方账户（不限总容量，单个文件最大 5 GB）。</p>
                </div>
                <?php if ($emailRequired): ?>
                <label><span>邮箱</span><input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" maxlength="191" required placeholder="you@example.com"><small>管理员已开启强制绑定邮箱，注册后需验证邮箱才能上传文件。</small></label>
                <label><span>邮箱验证码</span>
                    <div class="code-row"><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required placeholder="6 位数字"><button type="button" class="secondary-button" data-send-code data-purpose="register">获取验证码</button></div>
                </label>
                <p class="code-hint" data-code-hint></p>
                <?php endif; ?>
                <?= captchaField() ?>
                <button class="primary-button" type="submit"><span>创建账号</span><b>→</b></button>
            </form>
            <p class="login-note"><span>◆</span> 已有账号？<a href="index.php">返回登录</a></p>
        </section>
    </main>
    <script src="assets/app.js?v=14" defer></script>
    <?php if ($emailRequired): ?><script src="assets/verify.js?v=1" defer></script><?php endif; ?>
</body>
</html>
