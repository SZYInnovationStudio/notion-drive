<?php

declare(strict_types=1);

namespace NotionDrive;

use PDO;

final class Schema
{
    public const CURRENT_VERSION = 27;

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

        // 账号体系只区分「普通用户 / 管理员」，用户表因此需要重新补回 is_admin
        if ($version < 4) {
            self::createVerificationTable($pdo);
            self::addColumn($pdo, 'users', 'email', 'VARCHAR(191) NULL DEFAULT NULL AFTER id');
            self::addColumn($pdo, 'users', 'email_verified_at', 'TIMESTAMP NULL DEFAULT NULL AFTER email');
            self::addColumn($pdo, 'users', 'avatar_path', 'VARCHAR(191) NULL DEFAULT NULL AFTER email_verified_at');
            self::addUniqueIndex($pdo, 'users', 'uniq_users_email', 'email');
        }

        if ($version < 5) {
            self::createLoginAttemptsTable($pdo);
            // 记录验证码请求来源 IP，用于按 IP 限制发送频次
            self::addColumn($pdo, 'email_verifications', 'ip', 'VARCHAR(45) NULL DEFAULT NULL AFTER email');
            self::addIndex($pdo, 'email_verifications', 'idx_ip_time', '`ip`, `created_at`');
        }

        if ($version < 6) {
            self::createOfficialAccountsTable($pdo);
            self::createUserOfficialAccountsTable($pdo);
            self::createUploadReplicasTable($pdo);
            self::createAdminAlertsTable($pdo);

            // 自有 Notion 账户绑定
            self::addColumn($pdo, 'users', 'notion_token_encrypted', 'VARCHAR(512) NULL DEFAULT NULL AFTER notion_page_id');
            self::addColumn($pdo, 'users', 'notion_parent_page_id', 'VARCHAR(64) NULL DEFAULT NULL AFTER notion_token_encrypted');
            self::addColumn($pdo, 'users', 'notion_account_source', "VARCHAR(20) NOT NULL DEFAULT 'own' AFTER notion_parent_page_id");

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
        }

        if ($version < 11) {
            // 站内文件夹：只影响文件列表的归类，Notion 侧存储结构不变
            self::createFoldersTable($pdo);
            self::addColumn($pdo, 'uploads', 'folder_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_id');
            self::addIndex($pdo, 'uploads', 'idx_uploads_folder', '`folder_id`');
        }

        if ($version < 19) {
            // 站点公告：多条，可启用/停用，前端按 cookie 档位弹窗展示
            self::createAnnouncementsTable($pdo);
        }

        if ($version < 21) {
            // R13 文件数据丢失标记：当一个文件在 Notion 的全部副本都失效（空间页被彻底删除、
            // 块被删除且补副本失败）时置 1。置位后该文件仅允许删除，下载/预览/重命名一律拦截
            // 并提示「数据已丢失，请重新上传」，避免每次操作都去实时探测 Notion。
            self::addColumn($pdo, 'uploads', 'data_lost', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
            self::addIndex($pdo, 'uploads', 'idx_uploads_data_lost', '`data_lost`');
        }

        if ($version < 22) {
            // 异步写入存储：上传完成后先把文件入库为 writing（正在写入存储）并暂存在服务器，
            // 由后台队列写入 Notion 后转 active；write_attempts 记录已尝试的写入次数，
            // 达到上限即置为 write_failed（写入失败）并告警管理员，等用户手动重试
            self::addColumn($pdo, 'uploads', 'write_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER data_lost');
            self::addIndex($pdo, 'uploads', 'idx_uploads_status_id', '`status`, `id`');
        }

        if ($version < 23) {
            // 覆盖上传的延后清理：新文件先入库为 writing，被覆盖的旧文件只从列表移除，
            // 其 Notion 副本留到新文件写入成功后再归档——否则「新的还没写进 Notion、旧的已从 Notion 删掉」，
            // 一旦后台写入最终失败，两代内容就都不在 Notion 了。本列记录被覆盖的旧文件 id
            self::addColumn($pdo, 'uploads', 'overwrite_upload_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER write_attempts');
        }

        if ($version < 24) {
            // 写入进度（0-100）：后台写入 Notion 时按已上传分片数回写，供前端展示「正在写入存储」的进度条；
            // 仅用于展示，不影响写入结果，写入完成 / 失败时会清零
            self::addColumn($pdo, 'uploads', 'write_progress', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER overwrite_upload_id');
        }

        if ($version < 26) {
            // 精简为纯网盘：移除工单、公开直链、账户封禁与内容风控四套系统，
            // 同时清理它们留下的表、字段与配置键
            foreach ([
                'ticket_reviews',
                'ticket_archives',
                'ticket_messages',
                'tickets',
                'user_ticket_bans',
                'share_links',
                'user_bans',
                'user_violations',
                'moderation_hashes',
                'moderation_records',
            ] as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }

            // 工单附件标记：附件与其他文件共用 uploads 表
            self::dropIndex($pdo, 'uploads', 'idx_uploads_ticket');
            self::dropColumn($pdo, 'uploads', 'ticket_id');
            self::dropIndex($pdo, 'uploads', 'idx_uploads_scope');
            self::dropColumn($pdo, 'uploads', 'scope');

            // 用户：违规计数、免审核豁免与直链额度
            foreach ([
                'violation_points',
                'violation_cycle_start',
                'violation_ban_until',
                'moderation_exempt',
                'share_links_quota',
            ] as $column) {
                self::dropColumn($pdo, 'users', $column);
            }

            // 订单：直链包商品区分字段，会员订单仍保留
            self::dropColumn($pdo, 'orders', 'product_meta');
            self::dropColumn($pdo, 'orders', 'product_type');

            // 配置：四套系统的全部配置键
            foreach ([
                'share_enabled',
                'share_default_days',
                'share_packs',
                'ban_threshold',
                'ban_notice_mode',
                'ticket_expire_days',
                'ticket_review_enabled',
                'ticket_survey_url',
                'moderation_enabled',
                'moderation_keywords',
                'moderation_scope',
                'moderation_endpoint',
                'moderation_model',
                'moderation_api_key',
                'moderation_timeout',
            ] as $key) {
                $pdo->prepare('DELETE FROM config WHERE config_key = :config_key')->execute(['config_key' => $key]);
            }
        }

        if ($version < 27) {
            // 社区版：移除会员套餐、订单与支付、权限组，账号只区分「普通用户 / 管理员」，
            // 容量改为在用户上单独设置（NULL 表示不限）
            self::addColumn($pdo, 'users', 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER id');

            // 升级前把权限组里的管理员身份继承到 users.is_admin，避免后台失去管理员
            if (self::hasColumn($pdo, 'users', 'group_id')
                && $pdo->query("SHOW TABLES LIKE 'groups'")->fetchColumn() !== false) {
                $pdo->exec('UPDATE users u JOIN `groups` g ON g.id = u.group_id SET u.is_admin = 1 WHERE g.is_admin = 1');
            }

            self::dropColumn($pdo, 'users', 'premium_expires_at');
            self::dropColumn($pdo, 'users', 'premium_reminder_sent_at');
            self::dropColumn($pdo, 'users', 'group_id');
            self::dropIndex($pdo, 'users', 'idx_premium_expires');

            $pdo->exec('DROP TABLE IF EXISTS `orders`');
            $pdo->exec('DROP TABLE IF EXISTS `groups`');

            foreach ([
                'notion_plan',
                'premium_price_1',
                'premium_price_3',
                'premium_price_6',
                'premium_price_12',
                'epay_gateway',
                'epay_pid',
                'epay_key',
                'epay_pay_types',
            ] as $key) {
                $pdo->prepare('DELETE FROM config WHERE config_key = :config_key')->execute(['config_key' => $key]);
            }
        }

        Database::setConfig('schema_version', (string) self::CURRENT_VERSION);
    }

    /** 站点公告：多条，is_active 控制是否展示，前端按 cookie 档位决定弹窗频率。 */
    public static function createAnnouncementsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS announcements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_announcements_active (is_active, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 站内文件夹（支持多层嵌套，parent_id 为 NULL 表示位于根目录）。 */
    public static function createFoldersTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS folders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL DEFAULT NULL,
                name VARCHAR(900) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_folders_user_parent (user_id, parent_id),
                CONSTRAINT fk_folders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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

    private static function dropIndex(PDO $pdo, string $table, string $index): void
    {
        if (!self::hasIndex($pdo, $table, $index)) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
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
