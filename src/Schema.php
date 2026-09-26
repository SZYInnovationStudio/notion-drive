<?php

declare(strict_types=1);

namespace NotionDrive;

use PDO;

final class Schema
{
    public const CURRENT_VERSION = 9;

    /** 内置权限组：名称、组容量、单文件上限、是否管理员、等级。首个必须保持为管理员组。 */
    public const BUILTIN_GROUPS = [
        ['name' => '管理员', 'quota_bytes' => null, 'single_file_limit_bytes' => null, 'is_admin' => 1, 'level' => 100],
        ['name' => '高级用户', 'quota_bytes' => null, 'single_file_limit_bytes' => 5 * 1024 * 1024 * 1024, 'is_admin' => 0, 'level' => 50],
        ['name' => '普通用户', 'quota_bytes' => 5 * 1024 * 1024 * 1024, 'single_file_limit_bytes' => 5 * 1024 * 1024, 'is_admin' => 0, 'level' => 10],
    ];

    public const DEFAULT_GROUP_NAME = '普通用户';

    /** 付费用户自动加入的内置权限组名称。 */
    public const PREMIUM_GROUP_NAME = '高级用户';

    public static function migrate(): void
    {
        $pdo = Database::connection();
        $version = (int) Database::config('schema_version', '1');
        if ($version >= self::CURRENT_VERSION) {
            return;
        }

        if ($version < 3) {
            self::addColumn($pdo, 'users', 'quota_bytes', 'BIGINT NULL DEFAULT NULL AFTER is_admin');
            self::addColumn($pdo, 'users', 'notion_page_id', 'VARCHAR(64) NULL DEFAULT NULL AFTER quota_bytes');
            self::addColumn($pdo, 'uploads', 'display_name', 'VARCHAR(900) NULL DEFAULT NULL AFTER filename');
            self::addColumn($pdo, 'uploads', 'storage_name', 'VARCHAR(900) NULL DEFAULT NULL AFTER display_name');
            self::addColumn($pdo, 'uploads', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER notion_block_id");
            self::addColumn($pdo, 'uploads', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
            $pdo->exec('UPDATE uploads SET display_name = filename WHERE display_name IS NULL');
            $pdo->exec('UPDATE uploads SET storage_name = filename WHERE storage_name IS NULL');
        }

        // 数据归一化只允许执行一次：仅当从 v4 之前的版本升级时才重建权限组与用户归属
        if ($version < 4) {
            self::createGroupsTable($pdo);
            self::createVerificationTable($pdo);
            self::addColumn($pdo, 'users', 'group_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER notion_page_id');
            self::addColumn($pdo, 'users', 'email', 'VARCHAR(191) NULL DEFAULT NULL AFTER group_id');
            self::addColumn($pdo, 'users', 'email_verified_at', 'TIMESTAMP NULL DEFAULT NULL AFTER email');
            self::addColumn($pdo, 'users', 'avatar_path', 'VARCHAR(191) NULL DEFAULT NULL AFTER email_verified_at');
            self::addUniqueIndex($pdo, 'users', 'uniq_users_email', 'email');

            // 容量与管理员身份统一由权限组决定：清空用户级容量，按旧的 is_admin 归入内置组
            $pdo->exec('UPDATE users SET quota_bytes = NULL');
            if (self::hasColumn($pdo, 'users', 'is_admin')) {
                $pdo->prepare('UPDATE users SET group_id = :group_id WHERE is_admin = 1')
                    ->execute(['group_id' => self::builtinGroupId($pdo, '管理员')]);
                $pdo->prepare('UPDATE users SET group_id = :group_id WHERE group_id IS NULL')
                    ->execute(['group_id' => self::builtinGroupId($pdo, self::DEFAULT_GROUP_NAME)]);
                self::dropColumn($pdo, 'users', 'is_admin');
            } else {
                $pdo->prepare('UPDATE users SET group_id = :group_id WHERE group_id IS NULL')
                    ->execute(['group_id' => self::builtinGroupId($pdo, self::DEFAULT_GROUP_NAME)]);
            }
        }

        if ($version < 5) {
            self::createLoginAttemptsTable($pdo);
            // 记录验证码请求来源 IP，用于按 IP 限制发送频次
            self::addColumn($pdo, 'email_verifications', 'ip', 'VARCHAR(45) NULL DEFAULT NULL AFTER email');
            self::addIndex($pdo, 'email_verifications', 'idx_ip_time', '`ip`, `created_at`');
        }

        if ($version < 6) {
            // 补齐内置高级用户组（createGroupsTable 按 name 判重，可安全重复执行）
            self::createGroupsTable($pdo);
            self::createOfficialAccountsTable($pdo);
            self::createUserOfficialAccountsTable($pdo);
            self::createUploadReplicasTable($pdo);
            self::createOrdersTable($pdo);
            self::createAdminAlertsTable($pdo);

            // 自有 Notion 账户绑定与付费到期时间
            self::addColumn($pdo, 'users', 'notion_token_encrypted', 'VARCHAR(512) NULL DEFAULT NULL AFTER notion_page_id');
            self::addColumn($pdo, 'users', 'notion_parent_page_id', 'VARCHAR(64) NULL DEFAULT NULL AFTER notion_token_encrypted');
            self::addColumn($pdo, 'users', 'notion_account_source', "VARCHAR(20) NOT NULL DEFAULT 'own' AFTER notion_parent_page_id");
            self::addColumn($pdo, 'users', 'premium_expires_at', 'DATETIME NULL DEFAULT NULL AFTER notion_account_source');
            self::addIndex($pdo, 'users', 'idx_premium_expires', '`premium_expires_at`');

            // 普通用户单文件上限固定为 5 MB：仅修补历史上留空的内置组，不覆盖管理员自定义过的值
            $pdo->prepare(
                'UPDATE `groups` SET single_file_limit_bytes = :limit
                 WHERE name = :name AND single_file_limit_bytes IS NULL'
            )->execute(['limit' => 5 * 1024 * 1024, 'name' => self::DEFAULT_GROUP_NAME]);

            // 存量数据迁移：全局 Token 转为官方账户池第一个账户，已有文件挂到该账户下
            self::migrateToOfficialPool($pdo);
            self::migrateUploadsToReplicas($pdo);
        }

        if ($version < 7) {
            // 每个官方账户下的用户空间页面缓存（同一用户在主备账户里各有一个页面）
            self::addColumn($pdo, 'user_official_accounts', 'notion_page_id', 'VARCHAR(64) NULL DEFAULT NULL AFTER slot');

            // 没有自有 Token 的用户，其文件实际存放在官方账户中
            $pdo->exec("UPDATE users SET notion_account_source = 'official'
                        WHERE notion_account_source <> 'official'
                          AND (notion_token_encrypted IS NULL OR notion_token_encrypted = '')");

            // 把旧的单页面缓存搬到主账户槽位，之后 users.notion_page_id 只服务自有账户
            $accountId = (int) $pdo->query('SELECT id FROM official_accounts ORDER BY priority DESC, id ASC LIMIT 1')->fetchColumn();
            if ($accountId > 0) {
                $pdo->prepare(
                    'UPDATE user_official_accounts r JOIN users u ON u.id = r.user_id
                     SET r.notion_page_id = u.notion_page_id
                     WHERE r.slot = 1 AND r.account_id = :account_id
                       AND r.notion_page_id IS NULL AND u.notion_page_id IS NOT NULL'
                )->execute(['account_id' => $accountId]);
            }
            $pdo->exec("UPDATE users SET notion_page_id = NULL WHERE notion_account_source = 'official'");

            // 高级用户单文件上限随官方付费账户放宽到 5 GB：仅修正仍是旧默认值的组
            $pdo->prepare(
                'UPDATE `groups` SET single_file_limit_bytes = :limit
                 WHERE name = :name AND single_file_limit_bytes = :old_limit'
            )->execute([
                'limit' => 5 * 1024 * 1024 * 1024,
                'name' => self::PREMIUM_GROUP_NAME,
                'old_limit' => 5 * 1024 * 1024,
            ]);
        }

        if ($version < 8) {
            // 到期前邮件提醒的去重标记：记录最近一次提醒的发送时间，避免用户每次访问都收到邮件
            self::addColumn($pdo, 'users', 'premium_reminder_sent_at', 'DATETIME NULL DEFAULT NULL AFTER premium_expires_at');
        }

        if ($version < 9) {
            // notion_self_declared_paid 从未被写入也从未被读取：付费判定完全以 premium_expires_at 为准，删除这个无用字段
            self::dropColumn($pdo, 'users', 'notion_self_declared_paid');
        }

        Database::setConfig('schema_version', (string) self::CURRENT_VERSION);
    }

    public static function createGroupsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `groups` (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                quota_bytes BIGINT NULL DEFAULT NULL,
                single_file_limit_bytes BIGINT NULL DEFAULT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                level INT NOT NULL DEFAULT 0,
                is_builtin TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach (self::BUILTIN_GROUPS as $group) {
            $statement = $pdo->prepare('SELECT 1 FROM `groups` WHERE name = :name LIMIT 1');
            $statement->execute(['name' => $group['name']]);
            if ($statement->fetch()) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO `groups` (name, quota_bytes, single_file_limit_bytes, is_admin, level, is_builtin)
                 VALUES (:name, :quota_bytes, :single_file_limit_bytes, :is_admin, :level, 1)'
            )->execute([
                'name' => $group['name'],
                'quota_bytes' => $group['quota_bytes'],
                'single_file_limit_bytes' => $group['single_file_limit_bytes'],
                'is_admin' => $group['is_admin'],
                'level' => $group['level'],
            ]);
        }
    }

    public static function createVerificationTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_verifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL,
                ip VARCHAR(45) NULL DEFAULT NULL,
                purpose VARCHAR(20) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_purpose (email, purpose),
                INDEX idx_ip_time (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function createLoginAttemptsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(191) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_username_time (username, attempted_at),
                INDEX idx_ip_time (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 官方 Notion 账户池：每个账户 = 一个集成 Token + 一个已共享的根页面。 */
    public static function createOfficialAccountsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS official_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(64) NOT NULL,
                token_encrypted VARCHAR(512) NOT NULL,
                root_page_id VARCHAR(64) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                priority INT NOT NULL DEFAULT 0,
                last_check_at DATETIME NULL DEFAULT NULL,
                last_error VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 用户与官方账户的固定主备分配关系（slot 1 = 主，2 = 备）。 */
    public static function createUserOfficialAccountsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_official_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                slot TINYINT NOT NULL DEFAULT 1,
                notion_page_id VARCHAR(64) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_slot (user_id, slot),
                UNIQUE KEY uniq_user_account (user_id, account_id),
                INDEX idx_account (account_id),
                CONSTRAINT fk_uoa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_uoa_account FOREIGN KEY (account_id) REFERENCES official_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 每个文件在 Notion 侧的副本记录（role 1 = 主、2 = 备）。 */
    public static function createUploadReplicasTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS upload_replicas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                upload_id BIGINT UNSIGNED NOT NULL,
                role TINYINT NOT NULL DEFAULT 1,
                account_type VARCHAR(20) NOT NULL DEFAULT \'official\',
                official_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
                notion_file_upload_id VARCHAR(64) NULL DEFAULT NULL,
                notion_block_id VARCHAR(64) NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_replica_slot (upload_id, role),
                INDEX idx_replica_account (official_account_id),
                CONSTRAINT fk_replicas_upload FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 高级用户套餐订单。 */
    public static function createOrdersTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_no VARCHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                pay_type VARCHAR(20) NOT NULL DEFAULT \'alipay\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                trade_no VARCHAR(64) NULL DEFAULT NULL,
                expires_before DATETIME NULL DEFAULT NULL,
                expires_after DATETIME NULL DEFAULT NULL,
                notify_raw MEDIUMTEXT NULL,
                paid_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_order_no (order_no),
                INDEX idx_orders_user (user_id, created_at),
                INDEX idx_orders_status (status, created_at),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 后台顶部告警条。 */
    public static function createAdminAlertsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_alerts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL DEFAULT \'warning\',
                message VARCHAR(500) NOT NULL,
                context MEDIUMTEXT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_alerts_read (is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 把旧的全局 Notion Token 迁移为官方账户池的第一个账户（池非空时跳过，可重复执行）。 */
    private static function migrateToOfficialPool(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COUNT(*) FROM official_accounts')->fetchColumn() > 0) {
            return;
        }

        $token = (string) Database::config('notion_token', '');
        $rootPageId = (string) Database::config('default_page_id', '');
        if ($token === '' || $rootPageId === '') {
            return;
        }

        // notion_token 在 config 中已由 Security::encrypt 加密，这里原样沿用密文
        $pdo->prepare(
            'INSERT INTO official_accounts (label, token_encrypted, root_page_id, is_enabled, priority)
             VALUES (:label, :token_encrypted, :root_page_id, 1, 1)'
        )->execute([
            'label' => '默认官方账户',
            'token_encrypted' => $token,
            'root_page_id' => $rootPageId,
        ]);
    }

    /** 存量文件当时都写在全局 Token 的空间里，因此归属官方账户池的第一个账户。 */
    private static function migrateUploadsToReplicas(PDO $pdo): void
    {
        $accountId = (int) $pdo->query('SELECT id FROM official_accounts ORDER BY priority DESC, id ASC LIMIT 1')->fetchColumn();
        if ($accountId <= 0) {
            return;
        }

        $pdo->prepare(
            'INSERT INTO upload_replicas (upload_id, role, account_type, official_account_id, notion_file_upload_id, notion_block_id, status)
             SELECT u.id, 1, \'official\', :account_id, u.notion_file_upload_id, u.notion_block_id, u.status
             FROM uploads u
             WHERE NOT EXISTS (SELECT 1 FROM upload_replicas r WHERE r.upload_id = u.id)'
        )->execute(['account_id' => $accountId]);
    }

    private static function builtinGroupId(PDO $pdo, string $name): int
    {
        $statement = $pdo->prepare('SELECT id FROM `groups` WHERE name = :name LIMIT 1');
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return (bool) $statement->fetch();
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private static function dropColumn(PDO $pdo, string $table, string $column): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
    }

    private static function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name LIMIT 1'
        );
        $statement->execute(['table_name' => $table, 'index_name' => $index]);

        return (bool) $statement->fetch();
    }

    private static function addIndex(PDO $pdo, string $table, string $index, string $columns): void
    {
        if (self::hasIndex($pdo, $table, $index)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
    }

    private static function addUniqueIndex(PDO $pdo, string $table, string $index, string $column): void
    {
        if (self::hasIndex($pdo, $table, $index)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$column}`)");
    }
}
