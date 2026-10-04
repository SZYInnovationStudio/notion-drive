<?php

declare(strict_types=1);

namespace NotionDrive;

use PDO;

final class Schema
{
    public const CURRENT_VERSION = 25;

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

        if ($version < 10) {
            // 作废订阅时把对应用户的已支付订单状态改为 void，并记录作废时间供列表展示与追溯
            self::addColumn($pdo, 'orders', 'voided_at', 'DATETIME NULL DEFAULT NULL AFTER paid_at');
        }

        if ($version < 11) {
            // 站内文件夹：只影响文件列表的归类，Notion 侧存储结构不变
            self::createFoldersTable($pdo);
            self::addColumn($pdo, 'uploads', 'folder_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER user_id');
            self::addIndex($pdo, 'uploads', 'idx_uploads_folder', '`folder_id`');
        }

        if ($version < 12) {
            // 内容风控：记录关键词/AI 审核结论与申诉复核结果；
            // 命中文件通过 uploads.status = 'quarantined' 隔离（该列为 VARCHAR(20)，无需 ALTER）
            self::createModerationRecordsTable($pdo);
        }

        if ($version < 13) {
            // 用户申诉文本与免审核豁免：申诉内容由用户自己填写，管理员在后台查看；
            // 被豁免的用户上传时跳过关键词与 AI 识别（users.moderation_exempt = 1）
            self::addColumn($pdo, 'moderation_records', 'appeal_text', "VARCHAR(1000) NOT NULL DEFAULT '' AFTER review_note");
            self::addColumn($pdo, 'moderation_records', 'appealed_at', 'DATETIME NULL DEFAULT NULL AFTER appeal_text');
            self::addColumn($pdo, 'users', 'moderation_exempt', 'TINYINT(1) NOT NULL DEFAULT 0');
        }

        if ($version < 14) {
            // 工单系统：申诉改为「快捷提单」，用户也可自行提普通工单；
            // 每次提交都落一条留言（ticket_messages），替代此前「一条申诉文本反复覆盖」的写法
            self::createTicketsTable($pdo);
            self::createTicketMessagesTable($pdo);
            self::migrateAppealsToTickets($pdo);
        }

        if ($version < 15) {
            // 封禁系统：被管理员打回的违规文件累计达到阈值即自动封号；
            // 封禁期间禁止上传/下载/修改/重命名，仅保留工单申诉入口
            self::createUserBansTable($pdo);
            if (Database::config('ban_threshold', null) === null) {
                Database::setConfig('ban_threshold', '5');
            }
        }

        if ($version < 16) {
            // 内容指纹：已审核过的内容按 SHA-256 全站去重，
            // 命中「已通过」免重复审核，命中「已违规」直接拦截；
            // moderation_records 同步记录该内容指纹，供管理员改判（打回/放行重传）时联动白黑名单
            self::createModerationHashesTable($pdo);
            self::addColumn($pdo, 'moderation_records', 'content_hash', "CHAR(64) NOT NULL DEFAULT '' AFTER filename");
        }

        if ($version < 17) {
            // 工单附件：文件同样双写 Notion，但不属于「我的文件」（scope = drive），
            // 仅在所属工单内可见/可下载，工单关闭时从 Notion 删除
            self::addColumn($pdo, 'uploads', 'scope', "VARCHAR(20) NOT NULL DEFAULT 'drive' AFTER user_id");
            self::addColumn($pdo, 'uploads', 'ticket_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER folder_id');
            self::addIndex($pdo, 'uploads', 'idx_uploads_scope', 'scope');
            self::addIndex($pdo, 'uploads', 'idx_uploads_ticket', 'ticket_id, status');
        }

        if ($version < 18) {
            // 用户侧「清除记录」：用户在面板上把某条未通过审核的提示折叠隐藏。
            // 只做展示层标记（user_dismissed = 1），不改 status，管理员仍可正常复核该记录；
            // 因此无论用户是否清除过，文件状态与违规计数都不受影响
            self::addColumn($pdo, 'moderation_records', 'user_dismissed', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER appealed_at');
        }

        if ($version < 19) {
            // 工单增强：closed_at 记录关闭时间（用于评价/重开/过期统计）、
            // reopen_count 累计重开次数（上限 5）、expired_at 记录因超时被自动关闭的时间
            self::addColumn($pdo, 'tickets', 'closed_at', 'DATETIME NULL DEFAULT NULL AFTER last_message_at');
            self::addColumn($pdo, 'tickets', 'reopen_count', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER closed_at');
            self::addColumn($pdo, 'tickets', 'expired_at', 'DATETIME NULL DEFAULT NULL AFTER reopen_count');

            // 可信度（违规分）：与 moderation_records 违规计数联动，
            // violation_points 为当前累计分、violation_cycle_start 为当前 30 天周期起点、
            // violation_ban_until 为违规自动封禁的解封时间（NULL 表示未封禁）
            self::addColumn($pdo, 'users', 'violation_points', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER group_id');
            self::addColumn($pdo, 'users', 'violation_cycle_start', 'DATETIME NULL DEFAULT NULL AFTER violation_points');
            self::addColumn($pdo, 'users', 'violation_ban_until', 'DATETIME NULL DEFAULT NULL AFTER violation_cycle_start');

            // 工单归档标记：用户/管理员各自把已读工单从列表折叠，互不影响
            self::createTicketArchivesTable($pdo);
            // 工单评价：每个工单一条，用户关闭后可打分/留言，支持匿名
            self::createTicketReviewsTable($pdo);
            // 禁止提交工单（独立标记，不计入封禁体系，仅限制新建工单）
            self::createUserTicketBansTable($pdo);
            // 站点公告：多条，可启用/停用，前端按 cookie 档位弹窗展示
            self::createAnnouncementsTable($pdo);
        }

        if ($version < 20) {
            // 违规留痕：可信度（违规次数）的追加式记录。
            // 内容风控打回与管理员手动加分都写这里，账户页据此展示「历史违规记录」；
            // 当前分数仍以实时口径统计（被打回的审核记录数 + 手动加分净额），
            // 这样管理员「清除违规记录」后分数会同步下降，不会与留痕打架
            self::createUserViolationsTable($pdo);
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

        if ($version < 25) {
            // 直链分享：免登录可下载/预览的公开链接
            self::createShareLinksTable($pdo);
            // 直链包额度：用户已购的直链条数（管理员视为不限，不读该值）
            self::addColumn($pdo, 'users', 'share_links_quota', 'INT NOT NULL DEFAULT 0 AFTER moderation_exempt');
            // 订单区分商品：此前只有「高级会员」一种，存量订单一律按 premium 处理
            self::addColumn($pdo, 'orders', 'product_type', "VARCHAR(20) NOT NULL DEFAULT 'premium' AFTER months");
            self::addColumn($pdo, 'orders', 'product_meta', 'VARCHAR(255) NULL DEFAULT NULL AFTER product_type');
            // 直链默认配置：总开关先关闭，等管理员配好套餐价格再手动开启
            if (Database::config('share_enabled', null) === null) {
                Database::setConfig('share_enabled', '0');
            }
            if (Database::config('share_default_days', null) === null) {
                Database::setConfig('share_default_days', '7');
            }
            if (Database::config('share_packs', null) === null) {
                Database::setConfig('share_packs', json_encode([
                    ['count' => 50, 'price' => '', 'enabled' => 1],
                    ['count' => 100, 'price' => '', 'enabled' => 1],
                    ['count' => 200, 'price' => '', 'enabled' => 1],
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        Database::setConfig('schema_version', (string) self::CURRENT_VERSION);
    }

    /** 违规留痕：可信度分数的追加式明细（内容风控打回 / 管理员手动加分）。 */
    public static function createUserViolationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_violations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT \'points\',
                source VARCHAR(20) NOT NULL DEFAULT \'manual\',
                record_kind VARCHAR(20) NOT NULL DEFAULT \'manual\',
                record_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                upload_id BIGINT UNSIGNED NULL DEFAULT NULL,
                points INT NOT NULL DEFAULT 0,
                reason VARCHAR(500) NOT NULL DEFAULT \'\',
                admin_id BIGINT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_violations_user (user_id, id),
                INDEX idx_user_violations_source (user_id, source),
                CONSTRAINT fk_user_violations_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 内容指纹白/黑名单：全站共享的已审核内容 SHA-256 结论。 */
    public static function createModerationHashesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS moderation_hashes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                content_hash CHAR(64) NOT NULL,
                decision VARCHAR(20) NOT NULL DEFAULT \'approved\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_moderation_hash (content_hash),
                INDEX idx_moderation_hash_decision (decision, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 用户封禁记录：is_active = 1 表示当前处于封禁中，解封后置 0 并记录解封时间。 */
    public static function createUserBansTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_bans (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(500) NOT NULL DEFAULT \'\',
                violation_count INT UNSIGNED NOT NULL DEFAULT 0,
                banned_by BIGINT UNSIGNED NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                banned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lifted_at DATETIME NULL DEFAULT NULL,
                lifted_by BIGINT UNSIGNED NULL DEFAULT NULL,
                INDEX idx_user_bans_user (user_id, is_active),
                INDEX idx_user_bans_active (is_active, banned_at),
                CONSTRAINT fk_user_bans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 工单：category = appeal 的是由申诉「快捷提单」生成的，general 的是用户自己提的。 */
    public static function createTicketsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tickets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_no VARCHAR(16) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                category VARCHAR(20) NOT NULL DEFAULT \'general\',
                subject VARCHAR(200) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'open\',
                related_record_id BIGINT UNSIGNED NULL DEFAULT NULL,
                last_message_at DATETIME NULL DEFAULT NULL,
                closed_at DATETIME NULL DEFAULT NULL,
                reopen_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expired_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tickets_no (ticket_no),
                INDEX idx_tickets_user (user_id, created_at),
                INDEX idx_tickets_status (status, created_at),
                INDEX idx_tickets_record (related_record_id),
                CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 工单留言：用户与管理员逐条对话，每次提交都保留内容与时间。 */
    public static function createTicketMessagesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                author_role VARCHAR(10) NOT NULL DEFAULT \'user\',
                author_id BIGINT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket_messages_ticket (ticket_id, created_at),
                CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 工单归档标记：用户/管理员各自把已读工单从列表折叠，互不影响。 */
    public static function createTicketArchivesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_archives (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                viewer_kind VARCHAR(10) NOT NULL DEFAULT \'user\',
                viewer_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_archive_viewer (ticket_id, viewer_kind, viewer_id),
                INDEX idx_ticket_archives_viewer (viewer_kind, viewer_id),
                CONSTRAINT fk_ticket_archives_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 工单评价：每个工单一条，用户关闭后可打分/留言，支持匿名。 */
    public static function createTicketReviewsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_reviews (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                score DECIMAL(2,1) NOT NULL DEFAULT 0.0,
                is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
                comment VARCHAR(500) NOT NULL DEFAULT \'\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_review (ticket_id),
                INDEX idx_ticket_reviews_score (score, created_at),
                CONSTRAINT fk_ticket_reviews_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                CONSTRAINT fk_ticket_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** 禁止提交工单：独立标记，仅限制新建工单，不影响上传/下载/重命名/新建文件夹，也不计入封禁体系。 */
    public static function createUserTicketBansTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_ticket_bans (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(500) NOT NULL DEFAULT \'\',
                until DATETIME NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lifted_at DATETIME NULL DEFAULT NULL,
                INDEX idx_user_ticket_bans_user (user_id, is_active),
                CONSTRAINT fk_user_ticket_bans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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

    /** 升级前用户只填过「一条申诉文本」：转成对应申诉工单的首条留言，避免内容丢失。 */
    private static function migrateAppealsToTickets(PDO $pdo): void
    {
        // 迁移无事务且 schema_version 失败不推进，整段可能被重跑；已建过的申诉工单须跳过，
        // 否则同一条记录会被重复建单与重复留言
        $legacy = $pdo->query(
            "SELECT id, user_id, appeal_no, filename, appeal_text, appealed_at, created_at
             FROM moderation_records WHERE appeal_text <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM tickets t WHERE t.category = 'appeal' AND t.related_record_id = moderation_records.id
               )"
        )->fetchAll();

        foreach ($legacy ?: [] as $row) {
            $pdo->prepare(
                'INSERT INTO tickets (ticket_no, user_id, category, subject, status, related_record_id, last_message_at)
                 VALUES (:ticket_no, :user_id, :category, :subject, :status, :related_record_id, :last_message_at)'
            )->execute([
                'ticket_no'         => self::generateTicketNo(),
                'user_id'           => (int) $row['user_id'],
                'category'          => 'appeal',
                'subject'           => mb_substr('申诉 ' . (string) $row['appeal_no'] . ' · ' . (string) $row['filename'], 0, 200),
                'status'            => 'open',
                'related_record_id' => (int) $row['id'],
                'last_message_at'   => (string) ($row['appealed_at'] ?? $row['created_at']),
            ]);
            $pdo->prepare(
                'INSERT INTO ticket_messages (ticket_id, author_role, author_id, body, created_at)
                 VALUES (:ticket_id, :author_role, :author_id, :body, :created_at)'
            )->execute([
                'ticket_id'   => (int) $pdo->lastInsertId(),
                'author_role' => 'user',
                'author_id'   => (int) $row['user_id'],
                'body'        => (string) $row['appeal_text'],
                'created_at'  => (string) ($row['appealed_at'] ?? $row['created_at']),
            ]);
        }
    }

    /** 内容风控记录（关键词拦截 / AI 识别 / 申诉复核）。 */
    public static function createModerationRecordsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS moderation_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                appeal_no VARCHAR(16) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                upload_id BIGINT UNSIGNED NULL DEFAULT NULL,
                filename VARCHAR(900) NOT NULL,
                content_hash CHAR(64) NOT NULL DEFAULT \'\',
                stage VARCHAR(20) NOT NULL DEFAULT \'finish\',
                decision VARCHAR(20) NOT NULL DEFAULT \'flagged\',
                status VARCHAR(20) NOT NULL DEFAULT \'open\',
                provider VARCHAR(40) NOT NULL DEFAULT \'\',
                model VARCHAR(128) NOT NULL DEFAULT \'\',
                reason VARCHAR(500) NOT NULL DEFAULT \'\',
                matched VARCHAR(255) NOT NULL DEFAULT \'\',
                detail MEDIUMTEXT NULL,
                reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
                reviewed_at DATETIME NULL DEFAULT NULL,
                review_note VARCHAR(500) NOT NULL DEFAULT \'\',
                appeal_text VARCHAR(1000) NOT NULL DEFAULT \'\',
                appealed_at DATETIME NULL DEFAULT NULL,
                user_dismissed TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_moderation_appeal (appeal_no),
                INDEX idx_moderation_user (user_id, created_at),
                INDEX idx_moderation_status (status, created_at),
                INDEX idx_moderation_upload (upload_id),
                CONSTRAINT fk_moderation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

    /** 直链分享链接：token 免登录访问，记录有效期、访问次数与撤销状态。
     *  file_id 不建外键：uploads 记录组件里只做状态标记、极少物理删除，避免误加级联。 */
    public static function createShareLinksTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS share_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                file_id BIGINT UNSIGNED NOT NULL,
                expires_at DATETIME NULL DEFAULT NULL,
                download_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_accessed_at DATETIME NULL DEFAULT NULL,
                last_ip VARCHAR(45) NULL DEFAULT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_share_links_token (token),
                INDEX idx_share_links_file (file_id),
                INDEX idx_share_links_user (user_id, revoked_at),
                CONSTRAINT fk_share_links_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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
                product_type VARCHAR(20) NOT NULL DEFAULT \'premium\',
                product_meta VARCHAR(255) NULL DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                pay_type VARCHAR(20) NOT NULL DEFAULT \'alipay\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                trade_no VARCHAR(64) NULL DEFAULT NULL,
                expires_before DATETIME NULL DEFAULT NULL,
                expires_after DATETIME NULL DEFAULT NULL,
                notify_raw MEDIUMTEXT NULL,
                paid_at DATETIME NULL DEFAULT NULL,
                voided_at DATETIME NULL DEFAULT NULL,
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

    /** 工单号：T- 加 6 位，去掉易混字符，与申诉号风格保持一致。 */
    private static function generateTicketNo(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, $max)];
        }

        return 'T-' . $suffix;
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
