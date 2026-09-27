<?php

declare(strict_types=1);

namespace NotionDrive\Bans;

use NotionDrive\Database;
use NotionDrive\Logger;

/**
 * 封禁门面：违规累计达阈值自动封号，管理员也可手动封禁/解封。
 *
 * 违规次数只统计被管理员打回（moderation_records.status = 'rejected'）的记录：
 * AI 掉线或识别不了时先转人工，人工判定违规才计入，避免误封。
 */
final class BanService
{
    /** 自动封禁阈值允许的范围（次）。 */
    private const MIN_THRESHOLD = 1;
    private const MAX_THRESHOLD = 100;
    private const DEFAULT_THRESHOLD = 5;

    /** 可信度周期天数：满一个周期后重新累计违规分（R10：30 天自动重置）。 */
    private const CYCLE_DAYS = 30;
    /** 违规自动封禁的时长（天）：到期自动解封并把违规分清零（R10）。 */
    private const AUTO_BAN_DAYS = 30;
    /** 手动加分的单次上限，防止误输入写坏数据。 */
    private const MAX_MANUAL_POINTS = 100;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /** 自动封禁阈值：来自 config.ban_threshold，非法值回退到默认值。 */
    public function threshold(): int
    {
        $value = (int) Database::config('ban_threshold', (string) self::DEFAULT_THRESHOLD);

        return max(self::MIN_THRESHOLD, min(self::MAX_THRESHOLD, $value > 0 ? $value : self::DEFAULT_THRESHOLD));
    }

    public function setThreshold(int $value): void
    {
        $value = max(self::MIN_THRESHOLD, min(self::MAX_THRESHOLD, $value));
        Database::setConfig('ban_threshold', (string) $value);
    }

    /** 用户当前是否有生效中的封禁。 */
    public function isBanned(int $userId): bool
    {
        return $this->activeBan($userId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeBan(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM user_bans WHERE user_id = :user_id AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $ban = $statement->fetch();

        return is_array($ban) ? $ban : null;
    }

    /** 违规次数：被管理员打回的审核记录条数。 */
    public function violationCount(int $userId): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM moderation_records WHERE user_id = :user_id AND status = 'rejected'"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * 手动封禁（管理员操作）。已在封禁中时不重复写入。
     */
    public function ban(int $userId, string $reason, ?int $adminId = null): void
    {
        if ($this->userExists($userId) === false) {
            throw new \RuntimeException('用户不存在。');
        }
        if ($this->isBanned($userId)) {
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO user_bans (user_id, reason, violation_count, banned_by)
             VALUES (:user_id, :reason, :violation_count, :banned_by)'
        )->execute([
            'user_id'         => $userId,
            'reason'          => mb_substr(trim($reason), 0, 500),
            'violation_count' => $this->violationCount($userId),
            'banned_by'       => $adminId !== null && $adminId > 0 ? $adminId : null,
        ]);
    }

    /** 解封：结束当前生效中的封禁。 */
    public function unban(int $userId, ?int $adminId = null): void
    {
        Database::connection()->prepare(
            'UPDATE user_bans SET is_active = 0, lifted_at = NOW(), lifted_by = :lifted_by
             WHERE user_id = :user_id AND is_active = 1'
        )->execute([
            'user_id'   => $userId,
            'lifted_by' => $adminId !== null && $adminId > 0 ? $adminId : null,
        ]);
    }

    /**
     * 可信度（违规次数）总览：数字 + 分母 + 等级 + 进度 + 明细，账户页「可信度」卡片直接消费。
     * 读取前先做懒结算（自动解封到期、跨周期累计），保证展示与真实状态一致。
     *
     * @return array{points: int, threshold: int, label: string, tone: string, banned: bool, ban_until: string, history: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>}
     */
    public function trustSummary(int $userId): array
    {
        $this->expireDue($userId);
        $this->accrueCycle($userId);

        $statement = Database::connection()->prepare(
            'SELECT violation_ban_until FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        $row = is_array($row) ? $row : [];

        $threshold = $this->threshold();
        // 分数取实时口径（被打回的审核记录 + 手动加分），与自动封禁判定同源，
        // 保证管理员「清除违规记录」后账户页数字同步下降
        $points = $this->currentScore($userId);
        $banUntil = (string) ($row['violation_ban_until'] ?? '');
        $banned = $this->isBanned($userId);

        return [
            'points'    => $points,
            'threshold' => $threshold,
            'label'     => $this->trustLabel($points, $threshold),
            'tone'      => $this->trustTone($points, $threshold),
            'banned'    => $banned,
            'ban_until' => $banUntil,
            'history'   => $this->violationHistory($userId),
            'recent'    => $this->recentViolations($userId),
        ];
    }

    /**
     * 跨周期重置（R10：30 天自动重置）：满 30 天把周期起点前移到当前时间，
     * 之后的违规重新累计（周期内的违规由 currentScore 按起点过滤统计）。
     */
    private function accrueCycle(int $userId): void
    {
        $statement = Database::connection()->prepare(
            'SELECT violation_cycle_start FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $start = (string) ($statement->fetchColumn() ?: '');

        // 首次使用（含历史存量用户）：把周期起点设为该用户最早一次违规的时间，
        // 既不会把历史违规误清，又能让「距最早违规已超 30 天」的账号进入新周期
        if ($start === '') {
            $statement = Database::connection()->prepare(
                "SELECT MIN(occurred_at) FROM (
                    SELECT COALESCE(reviewed_at, created_at) AS occurred_at
                    FROM moderation_records WHERE user_id = :uid1 AND status = 'rejected'
                    UNION ALL
                    SELECT created_at FROM user_violations WHERE user_id = :uid2 AND source = 'manual'
                 ) AS v"
            );
            $statement->execute(['uid1' => $userId, 'uid2' => $userId]);
            $earliest = (string) ($statement->fetchColumn() ?: '');
            if ($earliest === '') {
                $earliest = date('Y-m-d H:i:s');
            }

            Database::connection()->prepare(
                'UPDATE users SET violation_cycle_start = :start WHERE id = :id AND violation_cycle_start IS NULL'
            )->execute(['start' => $earliest, 'id' => $userId]);

            $start = $earliest;
        }

        if (strtotime($start) > strtotime('-' . self::CYCLE_DAYS . ' days')) {
            return;
        }

        Database::connection()->prepare(
            'UPDATE users SET violation_cycle_start = NOW() WHERE id = :id'
        )->execute(['id' => $userId]);

        Logger::info('ban', '用户可信度进入新周期，违规分重新累计。', ['user_id' => $userId]);
    }

    /**
     * 自动解封到期（R10）：违规自动封禁满 30 天后解除封禁并把违规分清零。
     * 与「禁止提交工单」的到期解除一致，靠页面访问懒触发（无 cron）。
     */
    public function expireDue(?int $userId = null): void
    {
        $now = date('Y-m-d H:i:s');
        $where = 'violation_ban_until IS NOT NULL AND violation_ban_until <= :now';
        $params = ['now' => $now];
        if ($userId !== null && $userId > 0) {
            $where .= ' AND id = :id';
            $params['id'] = $userId;
        }

        $statement = Database::connection()->prepare('SELECT id, violation_ban_until FROM users WHERE ' . $where);
        $statement->execute($params);
        $rows = $statement->fetchAll() ?: [];
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $this->unban((int) $row['id'], null);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // 归零：解封后从新周期重新累计（分数由实时口径统计，故同时重置周期起点）
        Database::connection()->prepare(
            'UPDATE users SET violation_ban_until = NULL, violation_cycle_start = NOW()
             WHERE id IN (' . $placeholders . ')'
        )->execute($ids);

        Logger::info('ban', '违规自动封禁到期，已自动解封并清零违规分。', ['user_ids' => $ids]);
    }

    /**
     * 管理员手动给用户加分（违规次数）。加分到阈值即触发封禁，并写违规留痕便于账户页展示历史。
     * 普通用户每次上传被判定违规时同样走这里，故不会出现「分数与留痕对不上」的情况。
     */
    public function addViolation(int $userId, int $points, string $reason, ?int $adminId = null): void
    {
        if ($this->userExists($userId) === false) {
            throw new \RuntimeException('用户不存在。');
        }
        if ($points < 1 || $points > self::MAX_MANUAL_POINTS) {
            throw new \RuntimeException('每次增加的违规次数需为 1–' . self::MAX_MANUAL_POINTS . ' 之间的整数。');
        }

        $this->expireDue($userId);
        $this->accrueCycle($userId);

        $threshold = $this->threshold();
        $current = $this->currentScore($userId);
        $next = min($threshold, $current + $points);
        $note = mb_substr(trim($reason), 0, 500);
        if ($note === '') {
            $note = $adminId !== null ? '管理员手动增加违规次数。' : '上传内容被判定违规。';
        }

        // 只写留痕，分数由 currentScore 实时统计（清除违规记录后分数会同步下降）
        Database::connection()->prepare(
            'INSERT INTO user_violations (user_id, kind, source, record_kind, record_id, points, reason, admin_id)
             VALUES (:user_id, :kind, :source, :record_kind, :record_id, :points, :reason, :admin_id)'
        )->execute([
            'user_id'     => $userId,
            'kind'        => 'points',
            'source'      => 'manual',
            'record_kind' => 'manual',
            'record_id'   => 0,
            'points'      => $next - $current,
            'reason'      => $note,
            'admin_id'    => $adminId !== null && $adminId > 0 ? $adminId : null,
        ]);

        if ($next >= $threshold && $this->isBanned($userId) === false) {
            $this->ban($userId, '累计 ' . $next . ' 次违规，达到自动封禁阈值（' . $threshold . ' 次）。', null);
            Database::connection()->prepare(
                'UPDATE users SET violation_ban_until = DATE_ADD(NOW(), INTERVAL ' . self::AUTO_BAN_DAYS . ' DAY) WHERE id = :id'
            )->execute(['id' => $userId]);

            Logger::warning('ban', '用户违规累计达阈值，已自动封禁（' . self::AUTO_BAN_DAYS . ' 天后自动解封）。', [
                'user_id'   => $userId,
                'points'    => $next,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * 违规留痕写入（供内容风控打回时调用）。
     *
     * 内容风控的打回不直接走 addViolation：管理员可将已计入分数的记录「清除」
     * （status=rejected → cleared），因此账户页的违规次数与留痕都以实时口径
     * 统计 rejected 记录 + 管理员手动加分，避免出现「清除了记录但分数还在」。
     */
    public function recordViolation(int $userId, string $reason, string $recordKind, int $recordId, ?int $uploadId = null, ?int $adminId = null): void
    {
        if ($this->userExists($userId) === false) {
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO user_violations (user_id, kind, source, record_kind, record_id, upload_id, points, reason, admin_id)
             VALUES (:user_id, :kind, :source, :record_kind, :record_id, :upload_id, :points, :reason, :admin_id)'
        )->execute([
            'user_id'     => $userId,
            'kind'        => 'record',
            'source'      => 'moderation',
            'record_kind' => mb_substr($recordKind, 0, 20),
            'record_id'   => $recordId,
            'upload_id'   => $uploadId !== null && $uploadId > 0 ? $uploadId : null,
            'points'      => 0,
            'reason'      => mb_substr(trim($reason), 0, 500),
            'admin_id'    => $adminId !== null && $adminId > 0 ? $adminId : null,
        ]);
    }

    /** 违规留痕：按周期聚合，账户页「历史违规记录」折叠区消费。 */
    public function violationHistory(int $userId, int $limit = 12): array
    {
        $limit = max(1, min(60, $limit));
        $statement = Database::connection()->prepare(
            'SELECT kind, source, record_kind, points, reason, created_at
             FROM user_violations
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 当前违规的明细来源：被打回的审核记录 + 管理员手动加分，用于展示「哪一次违规」。
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentViolations(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $statement = Database::connection()->prepare(
            "SELECT r.status, r.filename, r.reason, r.review_note, r.appeal_no,
                    COALESCE(r.reviewed_at, r.created_at) AS occurred_at
             FROM moderation_records r
             WHERE r.user_id = :user_id AND r.status = 'rejected'
             ORDER BY occurred_at DESC, r.id DESC
             LIMIT " . $limit
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll() ?: [];
    }

    /** 违规次数等级文案（R10）：0 良好、1 轻微、2–3 中等、4 高危、达阈值封禁。 */
    public function trustLabel(int $points, ?int $threshold = null): string
    {
        $threshold = $threshold ?? $this->threshold();
        if ($points <= 0) {
            return '良好';
        }
        if ($points >= $threshold) {
            return '封禁';
        }

        return match ($points) {
            1 => '轻微',
            2, 3 => '中等',
            default => '高危',
        };
    }

    /** 等级对应的展示色调（供 CSS class 使用）：good / warn / high / ban。 */
    public function trustTone(int $points, ?int $threshold = null): string
    {
        $threshold = $threshold ?? $this->threshold();
        if ($points <= 0) {
            return 'good';
        }
        if ($points >= $threshold) {
            return 'ban';
        }

        return $points >= 4 ? 'high' : 'warn';
    }

    /**
     * 自动封禁判定：违规累计达到阈值时封号。
     *
     * 阈值口径与账户页「可信度」保持一致（当前被打回的审核记录数 + 管理员手动加分），
     * 否则会出现「账户页显示 5/5 却未封禁」这类前后台不一致。
     *
     * @return bool 本次是否触发了自动封禁
     */
    public function evaluateAutoBan(int $userId): bool
    {
        if ($this->isBanned($userId)) {
            return false;
        }

        $threshold = $this->threshold();
        $count = $this->currentScore($userId);
        if ($count < $threshold) {
            return false;
        }

        $this->ban(
            $userId,
            '累计 ' . $count . ' 次违规文件，达到自动封禁阈值（' . $threshold . ' 次）。',
            null
        );
        Logger::warning('ban', '用户因累计违规被自动封禁', [
            'user_id'    => $userId,
            'violations' => $count,
            'threshold'  => $threshold,
        ]);

        return true;
    }

    /**
     * 可信度分数（违规次数）：本周期内被打回的审核记录数 + 管理员手动加分的净额，
     * 上限为阈值（5/5 即为满）。账户页 x/5 与自动封禁判定共用此方法。
     *
     * 按周期起点过滤，30 天周期一到分数自然归零重新累计；实时统计而非读累计列，
     * 保证管理员「清除违规记录」后分数同步下降。
     */
    public function currentScore(int $userId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT violation_cycle_start FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $cycleStart = (string) ($statement->fetchColumn() ?: '');

        // 周期内的违规：起点及之后发生的才计入（起点取最早违规时间，故历史违规仍在）
        $since = $cycleStart === '' ? '' : ' AND COALESCE(r.reviewed_at, r.created_at) >= :since';
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM moderation_records r WHERE r.user_id = :user_id AND r.status = 'rejected'" . $since
        );
        $params = ['user_id' => $userId];
        if ($since !== '') {
            $params['since'] = $cycleStart;
        }
        $statement->execute($params);
        $recordCount = (int) $statement->fetchColumn();

        $since = $cycleStart === '' ? '' : ' AND created_at >= :since';
        $statement = Database::connection()->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM user_violations
             WHERE user_id = :user_id AND source = 'manual'" . $since
        );
        $params = ['user_id' => $userId];
        if ($since !== '') {
            $params['since'] = $cycleStart;
        }
        $statement->execute($params);
        $manualPoints = (int) $statement->fetchColumn();

        return max(0, min($this->threshold(), $recordCount + $manualPoints));
    }

    /**
     * 后台列表：全部用户 + 当前封禁状态 + 违规次数（可信度分，含手动加分），封禁中的排在前面。
     *
     * @return array<int, array<string, mixed>>
     */
    public function bannedUsers(): array
    {
        $statement = Database::connection()->query(
            "SELECT u.id, u.username, u.email, u.moderation_exempt,
                    u.violation_ban_until, g.is_admin AS group_is_admin,
                    b.id AS ban_id, b.reason, b.banned_at, b.banned_by,
                    ((SELECT COUNT(*) FROM moderation_records r
                       WHERE r.user_id = u.id AND r.status = 'rejected'
                         AND (u.violation_cycle_start IS NULL
                              OR COALESCE(r.reviewed_at, r.created_at) >= u.violation_cycle_start))
                     + COALESCE((SELECT SUM(v.points) FROM user_violations v
                                 WHERE v.user_id = u.id AND v.source = 'manual'
                                   AND (u.violation_cycle_start IS NULL
                                         OR v.created_at >= u.violation_cycle_start)), 0)) AS violations
             FROM users u
             LEFT JOIN `groups` g ON g.id = u.group_id
             LEFT JOIN user_bans b ON b.user_id = u.id AND b.is_active = 1
             ORDER BY (b.id IS NULL) ASC, b.banned_at DESC, u.id ASC"
        );

        return $statement->fetchAll() ?: [];
    }

    private function userExists(int $userId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() > 0;
    }
}