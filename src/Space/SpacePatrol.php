<?php

declare(strict_types=1);

namespace NotionDrive\Space;

use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\NotionSpaceRepair;
use Throwable;

/**
 * R13 Notion 空间每日巡检 + 副本自动补齐 + 文件数据丢失标记。
 *
 * 设计为「空间页主动巡检 + 文件级懒判定」的混合方案，避免对每个文件×副本都调用 Notion：
 *  - 每天 12:00 后由页面访问懒触发一次（日期记录在 config，当天不重复），也可由 CLI 脚本或后台手动执行；
 *  - 巡检沿「用户 × 账户」的空间页做检测：在回收站则恢复、彻底 404 则按原父页重建；
 *  - 对数据库里只有 1 份有效副本、但当前有 2 个可用目标账户的文件，用幸存副本字节自动补一份；
 *  - 单份副本的块也已 404（拿不到字节、补不出第二份）时，标记 data_lost；
 *  - 下载 / 预览时若全部副本都 404，由 api/file.php 懒标记 data_lost 并返回 410。
 */
final class SpacePatrol
{
    /** 巡检开关配置键：'1' 开启（默认），'0' 关闭。 */
    public const ENABLED_CONFIG_KEY = 'space_patrol_enabled';
    /** 最近一次巡检日期（Y-m-d，东八区）。 */
    public const LAST_DATE_CONFIG_KEY = 'space_patrol_last_date';
    /** 每次巡检最多补齐的单副本文件数，控制 Notion 调用放大。 */
    private const RECONCILE_LIMIT = 20;

    /** 数据丢失统一提示文案。 */
    public const LOST_MESSAGE = '很抱歉，该文件数据已丢失，请重新上传。';

    public static function patrolEnabled(): bool
    {
        return trim((string) Database::config(self::ENABLED_CONFIG_KEY, '1')) !== '0';
    }

    public static function savePatrolEnabled(bool $enabled): void
    {
        Database::setConfig(self::ENABLED_CONFIG_KEY, $enabled ? '1' : '0');
    }

    public static function lastRunDate(): string
    {
        return trim((string) Database::config(self::LAST_DATE_CONFIG_KEY, ''));
    }

    /**
     * 页面访问时的懒触发：开关开启、当天未跑过、且本地时间已过 12:00 才执行。
     * 任何异常都吞掉并记日志，绝不能因为巡检拖垮正常页面。
     */
    public static function runIfDue(): void
    {
        try {
            if (!self::patrolEnabled()) {
                return;
            }
            $today = date('Y-m-d');
            if (self::lastRunDate() === $today) {
                return;
            }
            if ((int) date('H') < 12) {
                return;
            }
            self::run();
        } catch (Throwable $exception) {
            Logger::error('patrol', '空间巡检懒触发失败：' . $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 执行一次完整巡检，返回统计结果。
     *
     * @return array{spaces:int,restored:int,recreated:int,failed:int,filled:int,lost:int}
     */
    public static function run(): array
    {
        $stats = ['spaces' => 0, 'restored' => 0, 'recreated' => 0, 'failed' => 0, 'filled' => 0, 'lost' => 0];

        self::scanSpaces($stats);
        self::reconcileReplicas($stats);

        Database::setConfig(self::LAST_DATE_CONFIG_KEY, date('Y-m-d'));
        Logger::info('patrol', 'Notion 空间巡检完成。', $stats);

        // 有实质修复动作或发现丢失时通知管理员（raiseAdminAlert 会按其配置发送邮件）
        if ($stats['restored'] > 0 || $stats['recreated'] > 0 || $stats['filled'] > 0 || $stats['lost'] > 0) {
            \raiseAdminAlert(
                $stats['lost'] > 0 ? 'error' : 'warning',
                sprintf(
                    '空间巡检：恢复归档页面 %d、重建丢失页面 %d、补齐副本 %d 份、标记数据丢失 %d 个。',
                    $stats['restored'],
                    $stats['recreated'],
                    $stats['filled'],
                    $stats['lost']
                ),
                ['kind' => 'space_patrol'] + $stats
            );
        }

        return $stats;
    }

    /** 巡检全部「用户 × 账户」空间页：恢复被归档页面、重建被彻底删除页面。 */
    private static function scanSpaces(array &$stats): void
    {
        $repair = new NotionSpaceRepair();

        // 自有账户空间
        $ownRows = Database::connection()->query(
            "SELECT id, username, notion_token_encrypted
               FROM users
              WHERE notion_account_source = 'own'
                AND COALESCE(notion_parent_page_id, '') <> ''
                AND COALESCE(notion_token_encrypted, '') <> ''"
        )->fetchAll();
        foreach ($ownRows as $row) {
            $token = \userNotionToken($row);
            if ($token === '') {
                continue;
            }
            self::applyRepair($repair, $row, 'own', null, new NotionClient($token), $stats);
        }

        // 官方账户空间（仅启用账户）
        $officialRows = Database::connection()->query(
            'SELECT DISTINCT u.id AS user_id, u.username, a.id AS account_id, a.token_encrypted
               FROM user_official_accounts r
               JOIN users u ON u.id = r.user_id
               JOIN official_accounts a ON a.id = r.account_id
              WHERE a.is_enabled = 1'
        )->fetchAll();
        foreach ($officialRows as $row) {
            $account = ['id' => (int) $row['account_id'], 'token_encrypted' => (string) $row['token_encrypted']];
            $token = \officialAccountToken($account);
            if ($token === '') {
                continue;
            }
            $user = ['id' => (int) $row['user_id'], 'username' => (string) $row['username']];
            self::applyRepair($repair, $user, 'official', (int) $row['account_id'], new NotionClient($token), $stats);
        }
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,int|string> $stats
     */
    private static function applyRepair(
        NotionSpaceRepair $repair,
        array $user,
        string $type,
        ?int $accountId,
        NotionClient $client,
        array &$stats
    ): void {
        $stats['spaces']++;
        try {
            $result = $repair->repair($user, $type, $accountId, $client);
            if (!$result['repaired']) {
                // 只有真正尝试失败才计数，「本就正常(none)/无法定位(unresolved)」不算失败
                if ($result['action'] === 'failed') {
                    $stats['failed']++;
                }
                return;
            }
            if ($result['action'] === 'recreated') {
                $stats['recreated']++;
            } else {
                $stats['restored']++;
            }
        } catch (Throwable $exception) {
            $stats['failed']++;
            Logger::warning('patrol', '空间页自动修复异常。', [
                'user_id' => (int) ($user['id'] ?? 0),
                'type' => $type,
                'account_id' => $accountId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 副本补齐：仅处理数据库里恰好 1 份有效副本、但当前存在 2 个可用写入目标的文件。
     * 文件块被外部删除的情况无法仅靠数据库识别，统一在下载/预览时懒判定。
     *
     * @param array<string,int> $stats
     */
    private static function reconcileReplicas(array &$stats): void
    {
        $pdo = Database::connection();
        // 仅取 drive 域文件；工单附件不做副本补齐（随工单关闭清理）。
        // 用子查询先找出「恰好 1 份有效副本」的文件，避免 GROUP BY 与 SELECT u.* 在严格模式下冲突。
        $rows = $pdo->query(
            "SELECT u.*
               FROM uploads u
               JOIN (
                    SELECT upload_id, COUNT(*) AS replica_count
                      FROM upload_replicas
                     WHERE status = 'active'
                     GROUP BY upload_id
                    HAVING replica_count = 1
               ) single ON single.upload_id = u.id
              WHERE u.status = 'active' AND u.data_lost = 0 AND u.scope = 'drive'
              ORDER BY u.updated_at DESC
              LIMIT " . self::RECONCILE_LIMIT
        )->fetchAll();

        foreach ($rows as $upload) {
            $owner = self::findUser((int) $upload['user_id']);
            if ($owner === null) {
                continue;
            }
            try {
                if (self::fillMissingReplica($upload, $owner)) {
                    $stats['filled']++;
                }
            } catch (Throwable $exception) {
                // 补副本失败：若唯一幸存副本也已 404（取不到字节），说明所有副本都失效 → 数据丢失
                if ($exception instanceof NotionApiException && $exception->status() === 404) {
                    self::markLost((int) $upload['id'], '唯一副本块已不存在，自动补副本失败。');
                    $stats['lost']++;
                } else {
                    Logger::warning('patrol', '自动补副本失败。', [
                        'upload_id' => (int) $upload['id'],
                        'user_id' => (int) $owner['id'],
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 为单个文件补齐缺失副本；成功返回 true，本来就无需补（目标不足/已两份）返回 false。
     *
     * @param array<string,mixed> $upload
     * @param array<string,mixed> $owner
     */
    public static function fillMissingReplica(array $upload, array $owner): bool
    {
        $targets = \uploadTargets($owner);
        if (count($targets) < 2) {
            return false; // 当前只有一个可用账户，维持单副本
        }

        // 已有有效副本的目标身份（account_type + official_account_id）
        $existing = Database::connection()->prepare(
            "SELECT account_type, official_account_id FROM upload_replicas
              WHERE upload_id = :upload_id AND status = 'active'"
        );
        $existing->execute(['upload_id' => (int) $upload['id']]);
        $covered = [];
        foreach ($existing->fetchAll() as $row) {
            $covered[(string) $row['account_type'] . '|' . (string) ($row['official_account_id'] ?? '')] = true;
        }

        $survivors = \uploadReplicaTargets($upload, $owner);
        if ($survivors === []) {
            return false;
        }

        foreach ($targets as $target) {
            $key = (string) $target['type'] . '|' . (string) ($target['account_id'] ?? '');
            if (isset($covered[$key])) {
                continue;
            }

            // 从主幸存副本取字节
            $source = $survivors[0];
            $temporaryPath = tempnam(sys_get_temp_dir(), 'nd-replica-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('无法创建临时文件以补齐副本。');
            }

            try {
                (new NotionClient((string) $source['token']))
                    ->downloadBlockToFile((string) $source['block_id'], $temporaryPath);

                $size = (int) filesize($temporaryPath);
                $storageName = (string) (($upload['storage_name'] ?? '') ?: ($upload['filename'] ?? ''));
                $result = \notionWriteWithRepair(
                    static function () use ($owner, $target, $temporaryPath, $storageName, $upload, $size): array {
                        $client = new NotionClient((string) $target['token']);
                        $pageId = \ensureUserSpacePage($owner, $target, $client);
                        $attached = $client->uploadAndAttach(
                            $temporaryPath,
                            $storageName,
                            (string) $upload['mime_type'],
                            $size,
                            $pageId
                        );
                        $blockId = (string) ($attached['block']['results'][0]['id'] ?? '');
                        if ($blockId === '') {
                            throw new \RuntimeException('Notion 未返回补齐副本的文件块。');
                        }

                        return [
                            'file_upload_id' => (string) ($attached['file_upload_id'] ?? ''),
                            'block_id' => $blockId,
                        ];
                    },
                    $owner,
                    $target,
                    ['stage' => 'patrol_fill', 'upload_id' => (int) $upload['id'], 'account_type' => $target['type']]
                );

                $roleStatement = Database::connection()->prepare(
                    "SELECT COALESCE(MAX(role), 0) + 1 FROM upload_replicas WHERE upload_id = :upload_id"
                );
                $roleStatement->execute(['upload_id' => (int) $upload['id']]);
                $nextRole = (int) $roleStatement->fetchColumn();

                Database::connection()->prepare(
                    'INSERT INTO upload_replicas
                        (upload_id, role, account_type, official_account_id, notion_file_upload_id, notion_block_id)
                     VALUES
                        (:upload_id, :role, :account_type, :official_account_id, :notion_file_upload_id, :notion_block_id)'
                )->execute([
                    'upload_id' => (int) $upload['id'],
                    'role' => $nextRole,
                    'account_type' => (string) $target['type'],
                    'official_account_id' => $target['account_id'],
                    'notion_file_upload_id' => (string) $result['file_upload_id'],
                    'notion_block_id' => (string) $result['block_id'],
                ]);

                Logger::info('patrol', '已自动补齐文件副本。', [
                    'upload_id' => (int) $upload['id'],
                    'account_type' => (string) $target['type'],
                    'account_id' => $target['account_id'],
                ]);

                return true;
            } finally {
                @unlink($temporaryPath);
            }
        }

        return false;
    }

    /** 标记文件数据丢失（幂等）；仅在首次从 0→1 时记录并告警，避免重复下载触发重复邮件。 */
    public static function markLost(int $uploadId, string $reason = ''): void
    {
        if ($uploadId <= 0) {
            return;
        }
        $statement = Database::connection()->prepare('UPDATE uploads SET data_lost = 1 WHERE id = :id AND data_lost = 0');
        $statement->execute(['id' => $uploadId]);
        if ($statement->rowCount() < 1) {
            return; // 此前已标记，不重复记日志、不重复发告警
        }
        Logger::error('patrol', '文件全部副本失效，标记为数据丢失。', [
            'upload_id' => $uploadId,
            'reason' => $reason,
        ]);
        \raiseAdminAlert('error', sprintf('文件 #%d 在 Notion 的全部副本均已失效，已标记为数据丢失。%s', $uploadId, $reason), [
            'upload_id' => $uploadId,
            'reason' => $reason,
        ]);
    }

    /** 操作拦截：数据已丢失的文件只允许删除，其余操作一律拒绝。 */
    public static function assertAccessible(array $upload): void
    {
        if ((int) ($upload['data_lost'] ?? 0) === 1) {
            throw new \RuntimeException(self::LOST_MESSAGE);
        }
    }

    /** @return array<string,mixed>|null */
    private static function findUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        // 带上权限组的管理员标记：uploadTargets() 内部要按 isAdmin() 判断官方池可用性，
        // 直接 SELECT * FROM users 缺少 group_is_admin，会把管理员误判为受限用户
        return \findUserById($userId);
    }
}
