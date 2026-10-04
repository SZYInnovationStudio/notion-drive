<?php

declare(strict_types=1);

namespace NotionDrive\Share;

use NotionDrive\Database;
use NotionDrive\Logger;

/**
 * 直链分享：链接的签发、校验、撤销、额度与访问计数。
 *
 * 设计要点：
 * - 直链是**公开**的（免登录可下载/预览），所以它的安全性依赖「有效期 + 可随时撤销 + 访问计数审计」，
 *   而不是登录态。token 用 32 字节随机数（64 位 hex），不可预测。
 * - 额度口径：已购条数 = users.share_links_quota（管理员不限）；
 *   已用条数只统计「未撤销且未过期」的链接，过期或撤销后自动释放，避免买了条数却被过期链接永久占住。
 * - 内容本身复用 FileStreamer（与站内下载同一实现），本类只做鉴权与记账。
 */
final class ShareService
{
    /** 可选的有效期档位（天）；0 表示永久 */
    public const ALLOWED_DAYS = [1, 7, 30, 0];

    /** 单条直链访问次数达到该值时告警一次，用于发现链接被大量盗刷 */
    private const HOT_ACCESS_ALERT_AT = 500;

    /** token 形态：32 字节随机数的 hex，固定 64 位 */
    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    // ------------------------------------------------------------------
    // 配置
    // ------------------------------------------------------------------

    /** 全局总开关（后台可关；关闭后所有已生成的直链立即失效） */
    public static function enabled(): bool
    {
        return (string) Database::config('share_enabled', '0') === '1';
    }

    public static function defaultDays(): int
    {
        $days = (int) Database::config('share_default_days', '7');

        return in_array($days, self::ALLOWED_DAYS, true) ? $days : 7;
    }

    /** 直链套餐列表（按条数升序）。config 里的 JSON 坏掉时一律当空，不让后台填错就整页崩。 */
    public static function packs(): array
    {
        $decoded = json_decode((string) Database::config('share_packs', '[]'), true);
        $packs = [];
        foreach (is_array($decoded) ? $decoded : [] as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            $count = (int) ($pack['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $packs[] = [
                'count' => $count,
                'price' => (string) ($pack['price'] ?? ''),
                'enabled' => (int) ($pack['enabled'] ?? 0) === 1,
            ];
        }
        usort($packs, static fn (array $a, array $b): int => $a['count'] <=> $b['count']);

        return $packs;
    }

    /** 按条数取套餐（下单用）：不存在或未上架返回 null */
    public static function packByCount(int $count): ?array
    {
        foreach (self::packs() as $pack) {
            if ($pack['count'] === $count && $pack['enabled']) {
                return $pack;
            }
        }

        return null;
    }

    /** 后台保存套餐：价格必须是合法非负数字（可留空表示暂不售卖）。返回整理后的列表。 */
    public static function savePacks(array $packs): array
    {
        $normalized = [];
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            $count = (int) ($pack['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $price = trim((string) ($pack['price'] ?? ''));
            if ($price !== '' && (!is_numeric($price) || (float) $price < 0)) {
                throw new \RuntimeException(sprintf('直链包「%d 条」的价格需要填写非负数字，或留空表示暂不出售。', $count));
            }
            $normalized[] = [
                'count' => $count,
                'price' => $price === '' ? '' : number_format((float) $price, 2, '.', ''),
                'enabled' => (int) ($pack['enabled'] ?? 0) === 1 ? 1 : 0,
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['count'] <=> $b['count']);
        Database::setConfig('share_packs', json_encode($normalized, JSON_UNESCAPED_UNICODE));

        return $normalized;
    }

    // ------------------------------------------------------------------
    // 额度
    // ------------------------------------------------------------------

    /** 已购直链条数；管理员返回 null 表示不限 */
    public static function quotaOf(array $user): ?int
    {
        if (isAdmin($user)) {
            return null;
        }

        return max(0, (int) ($user['share_links_quota'] ?? 0));
    }

    /** 已用条数：只算未撤销且未过期的链接 */
    public static function usedCount(int $userId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM share_links
             WHERE user_id = :user_id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** 剩余可生成条数；null 表示不限 */
    public static function remaining(array $user): ?int
    {
        $quota = self::quotaOf($user);

        return $quota === null ? null : max(0, $quota - self::usedCount((int) $user['id']));
    }

    // ------------------------------------------------------------------
    // 生成 / 撤销 / 列表
    // ------------------------------------------------------------------

    public static function linkUrl(string $token): string
    {
        return siteUrl() . '/s.php?t=' . $token;
    }

    /**
     * 生成一条直链。失败抛 RuntimeException，消息可直接展示给用户。
     * 管理员可为任意用户的文件生成（额度不限），普通用户只能给属于自己的文件生成。
     */
    public static function create(array $user, int $fileId, int $days): array
    {
        if (!self::enabled()) {
            throw new \RuntimeException('直链服务当前未开放，请稍后再试。');
        }
        if (!isPremiumUser($user)) {
            throw new \RuntimeException('直链仅对高级用户开放，开通后即可使用。');
        }
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            throw new \RuntimeException('不支持的有效期，请重新选择。');
        }

        $isAdmin = isAdmin($user);
        $sql = "SELECT id, filename, display_name FROM uploads
                WHERE id = :id AND scope = 'drive' AND status IN (" . UPLOAD_VISIBLE_STATUSES . ')';
        $params = ['id' => $fileId];
        if (!$isAdmin) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = (int) $user['id'];
        }
        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $file = $statement->fetch();
        if (!$file) {
            throw new \RuntimeException('文件不存在、不可用或无权分享。');
        }

        $remaining = self::remaining($user);
        if ($remaining !== null && $remaining <= 0) {
            throw new \RuntimeException('直链条数已用完，购买直链包后可以继续生成。');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        Database::connection()->prepare(
            'INSERT INTO share_links (token, user_id, file_id, expires_at) VALUES (:token, :user_id, :file_id, :expires_at)'
        )->execute([
            'token' => $token,
            'user_id' => (int) $user['id'],
            'file_id' => $fileId,
            'expires_at' => $expiresAt,
        ]);
        $shareId = (int) Database::connection()->lastInsertId();

        Logger::info('share', '生成直链。', [
            'share_id' => $shareId,
            'file_id' => $fileId,
            'user_id' => (int) $user['id'],
            'days' => $days,
        ]);

        return [
            'id' => $shareId,
            'token' => $token,
            'url' => self::linkUrl($token),
            'expires_at' => $expiresAt,
            'days' => $days,
        ];
    }

    /** 撤销直链：本人或管理员 */
    public static function revoke(array $user, int $shareId): void
    {
        $sql = 'UPDATE share_links SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL';
        $params = ['id' => $shareId];
        if (!isAdmin($user)) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = (int) $user['id'];
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException('链接不存在或已经撤销。');
        }
        Logger::info('share', '撤销直链。', ['share_id' => $shareId, 'user_id' => (int) $user['id']]);
    }

    /**
     * 直链列表（默认给「我的直链」，传 fileId 则只看某个文件）。
     * 附带完整链接与状态标签，前端直接渲染。
     */
    public static function listFor(array $user, int $fileId = 0): array
    {
        $sql = 'SELECT s.id, s.token, s.file_id, s.expires_at, s.download_count, s.last_accessed_at, s.revoked_at, s.created_at,
                       up.display_name, up.filename
                FROM share_links s JOIN uploads up ON up.id = s.file_id
                WHERE s.user_id = :user_id';
        $params = ['user_id' => (int) $user['id']];
        if ($fileId > 0) {
            $sql .= ' AND s.file_id = :file_id';
            $params['file_id'] = $fileId;
        }
        // 列表只用于人工核对，限量即可，避免老用户积压成千上万条时拖慢页面
        $statement = Database::connection()->prepare($sql . ' ORDER BY s.id DESC LIMIT 200');
        $statement->execute($params);

        $now = time();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $expired = $row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= $now;
            $revoked = $row['revoked_at'] !== null;
            $rows[] = [
                'id' => (int) $row['id'],
                'file_id' => (int) $row['file_id'],
                'file_name' => (string) ($row['display_name'] ?: $row['filename']),
                'url' => self::linkUrl((string) $row['token']),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
                'permanent' => $row['expires_at'] === null,
                'download_count' => (int) $row['download_count'],
                'last_accessed_at' => (string) ($row['last_accessed_at'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'revoked' => $revoked,
                'expired' => $expired,
                'active' => !$revoked && !$expired,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // 公开访问校验（s.php 用）
    // ------------------------------------------------------------------

    /**
     * 校验 token 并取回文件与所属账户。
     *
     * @return array{ok: bool, http: int, message: string, share?: array, file?: array, owner?: array}
     */
    public static function resolve(string $token): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'http' => 404, 'message' => '直链服务当前未开放。'];
        }
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return ['ok' => false, 'http' => 404, 'message' => '链接无效。'];
        }

        $statement = Database::connection()->prepare('SELECT * FROM share_links WHERE token = :token LIMIT 1');
        $statement->execute(['token' => $token]);
        $share = $statement->fetch();
        if (!$share) {
            return ['ok' => false, 'http' => 404, 'message' => '链接无效。'];
        }
        if ($share['revoked_at'] !== null) {
            return ['ok' => false, 'http' => 410, 'message' => '该链接已被撤销。'];
        }
        if ($share['expires_at'] !== null && strtotime((string) $share['expires_at']) <= time()) {
            return ['ok' => false, 'http' => 410, 'message' => '该链接已过期。'];
        }

        // 只允许分享「我的文件」里当前可见的文件：内容审核隔离、已删除的一律不出内容
        $fileStatement = Database::connection()->prepare(
            "SELECT up.*, u.username, u.notion_token_encrypted, u.notion_account_source
             FROM uploads up JOIN users u ON u.id = up.user_id
             WHERE up.id = :id AND up.scope = 'drive' AND up.status IN (" . UPLOAD_VISIBLE_STATUSES . ') LIMIT 1'
        );
        $fileStatement->execute(['id' => (int) $share['file_id']]);
        $file = $fileStatement->fetch();
        if (!$file) {
            return ['ok' => false, 'http' => 404, 'message' => '文件当前不可用。'];
        }
        if ((int) ($file['data_lost'] ?? 0) === 1) {
            return ['ok' => false, 'http' => 410, 'message' => \NotionDrive\Space\SpacePatrol::LOST_MESSAGE];
        }

        // 文件所属账号被封禁时直链一并失效，与站内「封禁期间禁止下载」口径一致
        $ownerId = (int) $file['user_id'];
        $ownerUser = findUserById($ownerId);
        if ($ownerUser === null || userActiveBan($ownerUser) !== null) {
            return ['ok' => false, 'http' => 403, 'message' => '文件所属账号当前不可用。'];
        }

        return [
            'ok' => true,
            'http' => 200,
            'message' => '',
            'share' => $share,
            'file' => $file,
            'owner' => [
                'id' => $ownerId,
                'notion_token_encrypted' => $file['notion_token_encrypted'] ?? null,
                'notion_account_source' => $file['notion_account_source'] ?? null,
            ],
        ];
    }

    /**
     * 记一次访问。
     * 次数只在「完整请求」（不带 Range）时 +1：否则视频预览拖动进度、下载器分块续传会把次数刷成几百次。
     */
    public static function touch(array $share, bool $countDownload, string $ip): int
    {
        $connection = Database::connection();
        $connection->prepare(
            'UPDATE share_links SET last_accessed_at = NOW(), last_ip = :ip'
            . ($countDownload ? ', download_count = download_count + 1' : '')
            . ' WHERE id = :id'
        )->execute(['ip' => mb_substr($ip, 0, 45), 'id' => (int) $share['id']]);

        $count = (int) $share['download_count'] + ($countDownload ? 1 : 0);
        if (!$countDownload) {
            return $count;
        }

        Logger::info('share', '直链被访问。', [
            'share_id' => (int) $share['id'],
            'file_id' => (int) $share['file_id'],
            'user_id' => (int) $share['user_id'],
            'download_count' => $count,
            'ip' => $ip,
        ]);
        // 达到阈值只告警一次：直链是公开的，被转发到公开渠道后访问量会异常增长
        if ($count === self::HOT_ACCESS_ALERT_AT) {
            raiseAdminAlert('warning', sprintf(
                '直链 #%d（文件 #%d）访问次数已达 %d 次，请留意该链接是否被大量传播。',
                (int) $share['id'],
                (int) $share['file_id'],
                self::HOT_ACCESS_ALERT_AT
            ), ['stage' => 'share', 'share_id' => (int) $share['id']]);
        }

        return $count;
    }

    /** 给用户增加直链包条数（订单结算 / 管理员手动发放共用，调用方保证幂等）。 */
    public static function grantPack(int $userId, int $links): void
    {
        if ($links <= 0) {
            throw new \RuntimeException('直链包条数不合法。');
        }
        $statement = Database::connection()->prepare(
            'UPDATE users SET share_links_quota = share_links_quota + :links WHERE id = :user_id'
        );
        $statement->execute(['links' => $links, 'user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException('用户不存在，直链包未能发放。');
        }
        Logger::info('share', '发放直链包条数。', ['user_id' => $userId, 'links' => $links]);
    }
}