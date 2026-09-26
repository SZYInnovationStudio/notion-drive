<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Schema;
use NotionDrive\Security;

if (is_file(__DIR__ . '/storage/installed.lock')) {
    header('Location: index.php');
    exit;
}

$error = '';
$values = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'notion_drive',
    'db_user' => '',
    'admin_username' => 'admin',
    'page_id' => '',
    'plan' => 'free',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $key) {
        $values[$key] = trim((string) ($_POST[$key] ?? $values[$key]));
    }

    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $token = trim((string) ($_POST['notion_token'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $values['db_name'])) {
            throw new RuntimeException('数据库名称只能包含字母、数字和下划线。');
        }
        if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $values['admin_username'])) {
            throw new RuntimeException('管理员用户名需为 3–64 个字符。');
        }
        if (mb_strlen($adminPassword) < 10) {
            throw new RuntimeException('管理员密码至少需要 10 个字符。');
        }
        if ($token === '') {
            throw new RuntimeException('请填写 Notion 集成 Token。');
        }
        $pageId = preg_replace('/[^a-fA-F0-9-]/', '', $values['page_id']);
        if (!in_array(strlen((string) $pageId), [32, 36], true)) {
            throw new RuntimeException('请填写有效的 Notion 页面 ID。');
        }
        if (!in_array($values['plan'], ['free', 'paid'], true)) {
            throw new RuntimeException('Notion 套餐选项无效。');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], (int) $values['db_port']);
        $pdo = new PDO($dsn, $values['db_user'], $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $quotedDatabase = '`' . str_replace('`', '``', $values['db_name']) . '`';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE {$quotedDatabase}");

        // 目标库已存在用户数据说明不是全新安装：拒绝继续，避免安装锁文件被误删后
        // 有人重跑安装程序、用 ON DUPLICATE KEY UPDATE 接管管理员账号
        $existingUsers = 0;
        try {
            $existingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (Throwable) {
            // users 表尚不存在，属于全新数据库
        }
        if ($existingUsers > 0) {
            throw new RuntimeException('该数据库中已存在用户数据，安装程序拒绝覆盖。若确需重新安装，请先清空该数据库或改用其他数据库。');
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                quota_bytes BIGINT NULL DEFAULT NULL,
                notion_page_id VARCHAR(64) NULL DEFAULT NULL,
                notion_token_encrypted VARCHAR(512) NULL DEFAULT NULL,
                notion_parent_page_id VARCHAR(64) NULL DEFAULT NULL,
                notion_account_source VARCHAR(20) NOT NULL DEFAULT \'own\',
                premium_expires_at DATETIME NULL DEFAULT NULL,
                premium_reminder_sent_at DATETIME NULL DEFAULT NULL,
                group_id BIGINT UNSIGNED NULL DEFAULT NULL,
                email VARCHAR(191) NULL DEFAULT NULL,
                email_verified_at TIMESTAMP NULL DEFAULT NULL,
                avatar_path VARCHAR(191) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_users_email (email),
                INDEX idx_premium_expires (premium_expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS config (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                config_key VARCHAR(191) NOT NULL UNIQUE,
                config_value MEDIUMTEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS uploads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                filename VARCHAR(900) NOT NULL,
                display_name VARCHAR(900) NULL,
                storage_name VARCHAR(900) NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                mime_type VARCHAR(191) NOT NULL,
                notion_file_upload_id VARCHAR(64) NOT NULL,
                notion_block_id VARCHAR(64) NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_uploads_user_created (user_id, created_at),
                CONSTRAINT fk_uploads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Schema::createGroupsTable($pdo);
        Schema::createVerificationTable($pdo);
        Schema::createLoginAttemptsTable($pdo);
        Schema::createOfficialAccountsTable($pdo);
        Schema::createUserOfficialAccountsTable($pdo);
        Schema::createUploadReplicasTable($pdo);
        Schema::createOrdersTable($pdo);
        Schema::createAdminAlertsTable($pdo);

        $appKey = bin2hex(random_bytes(32));
        $statement = $pdo->prepare(
            'INSERT INTO config (config_key, config_value) VALUES (:config_key, :config_value)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        foreach ([
            'notion_token' => Security::encrypt($token, $appKey),
            'default_page_id' => (string) $pageId,
            'notion_plan' => $values['plan'],
            'schema_version' => (string) Schema::CURRENT_VERSION,
        ] as $key => $value) {
            $statement->execute(['config_key' => $key, 'config_value' => $value]);
        }

        // 安装时填写的 Token 同时作为官方账户池的第一个账户，供购买高级套餐的用户使用
        $poolStatement = $pdo->prepare(
            'INSERT INTO official_accounts (label, token_encrypted, root_page_id, is_enabled, priority)
              SELECT :label, :token_encrypted, :root_page_id, 1, 1 FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM official_accounts)'
        );
        $poolStatement->execute([
            'label' => '默认官方账户',
            'token_encrypted' => Security::encrypt($token, $appKey),
            'root_page_id' => (string) $pageId,
        ]);

        $adminGroupStatement = $pdo->prepare('SELECT id FROM `groups` WHERE name = :name LIMIT 1');
        $adminGroupStatement->execute(['name' => Schema::BUILTIN_GROUPS[0]['name']]);
        $adminGroupId = (int) $adminGroupStatement->fetchColumn();

        // 安装时填写的 Token / 页面同时绑定为管理员的自有账户，使安装后管理员可立即上传
        $adminStatement = $pdo->prepare(
            'INSERT INTO users (username, password_hash, group_id, notion_token_encrypted, notion_parent_page_id, notion_account_source)
             VALUES (:username, :password_hash, :group_id, :notion_token_encrypted, :notion_parent_page_id, :notion_account_source)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), group_id = VALUES(group_id),
                 notion_token_encrypted = VALUES(notion_token_encrypted),
                 notion_parent_page_id = VALUES(notion_parent_page_id),
                 notion_account_source = VALUES(notion_account_source)'
        );
        $adminStatement->execute([
            'username' => $values['admin_username'],
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'group_id' => $adminGroupId,
            'notion_token_encrypted' => Security::encrypt($token, $appKey),
            'notion_parent_page_id' => normalizePageId((string) $pageId),
            'notion_account_source' => 'own',
        ]);

        $config = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'host' => $values['db_host'],
            'port' => (int) $values['db_port'],
            'name' => $values['db_name'],
            'user' => $values['db_user'],
            'password' => $dbPassword,
            'app_key' => $appKey,
        ], true) . ";\n";

        if (file_put_contents(__DIR__ . '/config/database.php', $config, LOCK_EX) === false) {
            throw new RuntimeException('无法写入数据库配置，请检查 config 目录权限。');
        }
        if (file_put_contents(__DIR__ . '/storage/installed.lock', date(DATE_ATOM), LOCK_EX) === false) {
            throw new RuntimeException('无法写入安装锁，请检查 storage 目录权限。');
        }

        header('Location: index.php?installed=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException
            ? '数据库安装失败，请检查连接信息、权限和用户名是否已存在。'
            : $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>安装 · Notion Drive</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/app.css?v=23">
</head>
<body class="install-shell">
    <div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
    <main class="install-layout">
        <section class="install-intro">
            <a class="brand" href="#" aria-label="Notion Drive">
                <span class="brand-mark">N</span><span>Notion Drive</span>
            </a>
            <div>
                <span class="eyebrow">首次配置</span>
                <h1>连接你的<br><em>Notion 空间</em></h1>
                <p>一次配置，即可为团队提供安全、清晰的文件上传入口。</p>
            </div>
            <ol class="setup-steps" aria-label="安装步骤">
                <li class="active"><span>01</span>数据库</li>
                <li class="active"><span>02</span>Notion</li>
                <li class="active"><span>03</span>管理员</li>
            </ol>
        </section>

        <section class="glass-card install-card">
            <div class="card-heading">
                <div><span class="status-pill"><i></i> 安全安装</span><h2>初始化应用</h2></div>
                <span class="step-count">01—03</span>
            </div>
            <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="install-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>">
                <fieldset>
                    <legend>数据库连接</legend>
                    <div class="field-grid three">
                        <label><span>主机</span><input name="db_host" value="<?= e($values['db_host']) ?>" required></label>
                        <label><span>端口</span><input name="db_port" inputmode="numeric" value="<?= e($values['db_port']) ?>" required></label>
                        <label><span>数据库名</span><input name="db_name" value="<?= e($values['db_name']) ?>" required></label>
                    </div>
                    <div class="field-grid two">
                        <label><span>数据库用户</span><input name="db_user" value="<?= e($values['db_user']) ?>" required></label>
                        <label><span>数据库密码</span><input type="password" name="db_password" required></label>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>Notion 工作区</legend>
                    <label><span>集成 Token</span><input type="password" name="notion_token" placeholder="ntn_••••••••••••" required><small>Token 会加密后保存，所有用户共用。</small></label>
                    <div class="field-grid two">
                        <label><span>默认页面 ID</span><input name="page_id" value="<?= e($values['page_id']) ?>" placeholder="页面链接末尾的 32 位 ID" required></label>
                        <label><span>Notion 套餐</span><select name="plan"><option value="free" <?= $values['plan'] === 'free' ? 'selected' : '' ?>>免费版 · 5 MB</option><option value="paid" <?= $values['plan'] === 'paid' ? 'selected' : '' ?>>付费版 · 5 GB</option></select></label>
                    </div>
                </fieldset>
                <fieldset>
                    <legend>管理员账号</legend>
                    <div class="field-grid two">
                        <label><span>用户名</span><input name="admin_username" value="<?= e($values['admin_username']) ?>" minlength="3" required></label>
                        <label><span>密码</span><input type="password" name="admin_password" minlength="10" required><small>至少 10 个字符</small></label>
                    </div>
                </fieldset>
                <button class="primary-button" type="submit"><span>完成安装</span><b>→</b></button>
            </form>
        </section>
    </main>
</body>
</html>
