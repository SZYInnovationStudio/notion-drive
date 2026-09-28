<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Mailer;
use NotionDrive\Security;
use NotionDrive\Verification;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'message' => '请求方法不受支持。'], 405);
}

try {
    Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);
} catch (Throwable $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 419);
}

$purpose = (string) ($_POST['purpose'] ?? '');
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

try {
    if (!in_array($purpose, ['register', 'bind', 'reset'], true)) {
        throw new RuntimeException('验证码用途无效。');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        throw new RuntimeException('请输入有效的邮箱地址。');
    }

    $user = Auth::user();
    // 命中「邮箱已被占用」时不发信也不报错，只回统一成功文案，避免本接口沦为邮箱枚举入口
    $shouldSend = true;
    if ($purpose === 'register') {
        if (!registrationEnabled()) {
            throw new RuntimeException('当前未开放新账号注册。');
        }
        if (!emailRequired()) {
            throw new RuntimeException('当前注册无需填写邮箱，请直接提交注册。');
        }
        $occupied = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $occupied->execute(['email' => $email]);
        $shouldSend = $occupied->fetch() === false;
    } elseif ($purpose === 'reset') {
        // 找回密码：未登录也可请求。账号不存在时同样回统一成功文案，避免本接口沦为邮箱枚举入口
        if (!smtpConfigured()) {
            throw new RuntimeException('系统未配置邮件服务，无法发送验证码。');
        }
        $exists = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);
        $shouldSend = $exists->fetch() !== false;
    } else {
        if ($user === null) {
            jsonResponse(['ok' => false, 'message' => '登录已过期，请重新登录。'], 401);
        }
        if (emailBound($user) && strcasecmp((string) $user['email'], $email) === 0) {
            throw new RuntimeException('该邮箱已绑定到当前账号。');
        }
        $occupied = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $occupied->execute(['email' => $email, 'id' => (int) $user['id']]);
        if ($occupied->fetch()) {
            throw new RuntimeException('该邮箱已被其他账号使用。');
        }
    }

    if ($shouldSend) {
        // 发送频次由 Verification::issue() 在数据库层按「邮箱冷却 + 来源 IP 上限」控制
        $code = Verification::issue($email, $purpose);
        try {
            Mailer::fromConfig()->send(
                $email,
                'Notion Drive 邮箱验证码',
                "你的验证码是：{$code}\n\n验证码 10 分钟内有效，请勿转发或告知他人。"
            );
        } catch (Throwable $exception) {
            // 邮件没发出去用户就拿不到验证码：必须回滚刚签发的记录，否则 60 秒冷却会把用户挡在门外
            Verification::discard($email, $purpose);
            throw $exception;
        }

        Logger::info('mail', '已发送邮箱验证码。', ['purpose' => $purpose, 'email' => $email]);
    }

    jsonResponse(['ok' => true, 'message' => '验证码已发送，请查收邮件（10 分钟内有效）。']);
} catch (Throwable $exception) {
    // 数据库原始报错可能包含 SQL 与服务器路径，不直接回显
    $message = match (true) {
        $exception instanceof PDOException => '服务器暂时无法处理该请求，请稍后重试。',
        $exception instanceof RuntimeException => $exception->getMessage(),
        default => '发送验证码时发生未知错误，请稍后重试。',
    };
    jsonResponse(['ok' => false, 'message' => $message], 422);
}
