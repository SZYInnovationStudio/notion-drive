<?php

declare(strict_types=1);

namespace NotionDrive\Tickets;

use NotionDrive\Database;
use NotionDrive\Logger;
use PDOException;

/**
 * 工单系统：申诉「快捷提单」与用户自发工单都落在这里，
 * 用户与管理员通过 ticket_messages 逐条对话，每次提交都保留内容与时间。
 */
final class TicketService
{
    public const CATEGORY_APPEAL  = 'appeal';
    public const CATEGORY_GENERAL = 'general';

    public const STATUS_OPEN       = 'open';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_EXPIRED    = 'expired';

    public const ROLE_USER  = 'user';
    public const ROLE_ADMIN = 'admin';

    /** 归档维度：用户自己的归档 / 某位管理员的归档，同一工单可被不同 viewer 各自归档 */
    public const VIEWER_USER  = 'user';
    public const VIEWER_ADMIN = 'admin';

    /** 工单标题最大长度（对应 tickets.subject 的列宽） */
    public const MAX_SUBJECT_LENGTH = 200;
    /** 留言内容最大长度（对应 ticket_messages.body 的列宽） */
    public const MAX_BODY_LENGTH = 2000;

    /** 每张工单最多可重开次数（每单独立计数） */
    public const MAX_REOPEN = 5;
    /** 关闭后允许重开的窗口天数（从关闭时刻起算），逾期视为过期、不可重开 */
    public const REOPEN_WINDOW_DAYS = 30;
    /** 工单过期天数配置键：值 <= 0 表示不过期 */
    public const EXPIRE_CONFIG_KEY = 'ticket_expire_days';

    /** 工单评价：最低分 */
    public const MIN_SCORE = 0.5;
    /** 工单评价：最高分 */
    public const MAX_SCORE = 5.0;
    /** 工单评价：分值步进（0.5 一档，共 10 档） */
    public const SCORE_STEP = 0.5;
    /** 评价留言最大长度（对应 ticket_reviews.comment 的列宽） */
    public const MAX_COMMENT_LENGTH = 500;
    /** 外部调研链接配置键：留空表示不展示调研入口 */
    public const SURVEY_URL_CONFIG_KEY = 'ticket_survey_url';
    /** 站内评价开关配置键：'1' 开启（默认），'0' 关闭；关闭时评价区块与调研入口都不展示 */
    public const REVIEW_ENABLED_CONFIG_KEY = 'ticket_review_enabled';

    /**
     * 工单过期档位（后台下拉）：0 表示不过期，其余为「客服回复后等待用户回复」的天数。
     *
     * @return array<int, string>
     */
    public static function expirePresets(): array
    {
        return [
            0  => '不过期',
            1  => '1 天',
            7  => '7 天',
            15 => '15 天',
            30 => '30 天',
        ];
    }

    /**
     * 当前生效的过期天数：<= 0 表示不过期。全局一个档位（含手动输入的天数）。
     */
    public static function expireDays(): int
    {
        $days = (int) trim((string) Database::config(self::EXPIRE_CONFIG_KEY, '0'));

        return $days > 0 ? $days : 0;
    }

    /**
     * 站内评价开关：默认开启，仅在配置显式为 '0' 时关闭。
     * 关闭后，工单详情页不再渲染评价区块与外部调研入口，服务端也拒绝提交评价。
     */
    public static function reviewEnabled(): bool
    {
        return trim((string) Database::config(self::REVIEW_ENABLED_CONFIG_KEY, '1')) !== '0';
    }

    /**
     * 保存站内评价开关。
     */
    public static function saveReviewEnabled(bool $enabled): void
    {
        Database::setConfig(self::REVIEW_ENABLED_CONFIG_KEY, $enabled ? '1' : '0');
    }

    private const TICKET_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TICKET_LENGTH   = 6;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_APPEAL  => '申诉',
            self::CATEGORY_GENERAL => '普通工单',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN       => '待处理',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_CLOSED     => '已关闭',
            self::STATUS_EXPIRED    => '已过期',
        ];
    }

    /**
     * 结束态（已关闭 / 已过期）——不再接受回复与上传。
     *
     * @return string[]
     */
    public static function endedStatuses(): array
    {
        return [self::STATUS_CLOSED, self::STATUS_EXPIRED];
    }

    public static function isEnded(string $status): bool
    {
        return in_array($status, self::endedStatuses(), true);
    }

    /**
     * 提新工单：写 tickets 并落首条留言，返回工单 id。
     */
    public function openTicket(
        int $userId,
        string $subject,
        string $body,
        string $category = self::CATEGORY_GENERAL,
        ?int $relatedRecordId = null
    ): int {
        // 被禁止提交工单的用户不能再新建工单（含文件页快捷申诉的新建），但已有工单仍可回复
        if (TicketBanService::create()->isTicketBanned($userId)) {
            throw new \RuntimeException('你已被禁止提交工单，请联系管理员。');
        }

        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '') {
            throw new \RuntimeException('请填写工单标题。');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new \RuntimeException('工单标题最多 ' . self::MAX_SUBJECT_LENGTH . ' 个字符。');
        }
        if ($body === '') {
            throw new \RuntimeException('请填写工单内容。');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \RuntimeException('工单内容最多 ' . self::MAX_BODY_LENGTH . ' 个字符。');
        }
        if (!array_key_exists($category, self::categories())) {
            $category = self::CATEGORY_GENERAL;
        }

        $pdo = Database::connection();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $ticketNo = self::generateTicketNo();
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO tickets
                        (ticket_no, user_id, category, subject, status, related_record_id, last_message_at)
                     VALUES
                        (:ticket_no, :user_id, :category, :subject, :status, :related_record_id, NOW())'
                );
                $statement->execute([
                    'ticket_no'         => $ticketNo,
                    'user_id'           => $userId,
                    'category'          => $category,
                    'subject'           => mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH),
                    'status'            => self::STATUS_OPEN,
                    'related_record_id' => $relatedRecordId,
                ]);
                $ticketId = (int) $pdo->lastInsertId();
                $this->insertMessage($ticketId, self::ROLE_USER, $userId, $body);

                // 用户提交新工单时提醒管理员处理：写入后台告警（新告警会同时发邮件通知）。
                $nameStatement = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
                $nameStatement->execute(['id' => $userId]);
                $authorName = (string) ($nameStatement->fetchColumn() ?: ('#' . $userId));
                $subjectBrief = mb_strlen($subject) > 80 ? mb_substr($subject, 0, 80) . '…' : $subject;
                \raiseAdminAlert(
                    'warning',
                    sprintf(
                        '用户「%s」提交了%s工单 %s：%s',
                        $authorName,
                        $category === self::CATEGORY_APPEAL ? '申诉' : '新',
                        $ticketNo,
                        $subjectBrief
                    ),
                    [
                        'ticket_id' => $ticketId,
                        'ticket_no'  => $ticketNo,
                        'user_id'    => $userId,
                        'category'   => $category,
                    ]
                );

                return $ticketId;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('工单号生成失败，请重试。');
    }

    /**
     * 追加一条留言（用户或管理员），并刷新工单的最后留言时间。
     */
    public function appendMessage(int $ticketId, string $role, int $authorId, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('请填写回复内容。');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \RuntimeException('回复内容最多 ' . self::MAX_BODY_LENGTH . ' 个字符。');
        }

        $this->insertMessage($ticketId, $role, $authorId, $body);
    }

    /**
     * 申诉「快捷提单」：同一条申诉记录复用同一张工单；
     * 已关闭且仍在重开窗口内、次数未用尽时重开原工单（重开次数 +1），
     * 已过期 / 超期 / 次数用尽则新建一张工单；未结束的直接追加留言。
     */
    public function appendAppealMessage(int $recordId, int $userId, string $appealNo, string $filename, string $body): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT * FROM tickets
             WHERE related_record_id = :record_id AND category = :category
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['record_id' => $recordId, 'category' => self::CATEGORY_APPEAL]);
        $ticket = $statement->fetch();

        if (is_array($ticket)) {
            $ticketId = (int) $ticket['id'];
            $status = (string) $ticket['status'];

            if ($status === self::STATUS_CLOSED) {
                [$canReopen] = $this->reopenState($ticket);
                if ($canReopen) {
                    $this->reopenTicket($ticketId);
                } else {
                    return $this->openTicket(
                        $userId,
                        '申诉 ' . $appealNo . ' · ' . $filename,
                        $body,
                        self::CATEGORY_APPEAL,
                        $recordId
                    );
                }
            } elseif ($status === self::STATUS_EXPIRED) {
                // 过期工单不可恢复、不可重开：为该申诉记录另起一张新工单
                return $this->openTicket(
                    $userId,
                    '申诉 ' . $appealNo . ' · ' . $filename,
                    $body,
                    self::CATEGORY_APPEAL,
                    $recordId
                );
            }

            $this->appendMessage($ticketId, self::ROLE_USER, $userId, $body);

            return $ticketId;
        }

        return $this->openTicket(
            $userId,
            '申诉 ' . $appealNo . ' · ' . $filename,
            $body,
            self::CATEGORY_APPEAL,
            $recordId
        );
    }

    /**
     * 用户在详情页回复自己的工单；工单状态由系统按「是否有管理员回复」自动判定。
     */
    public function replyAsUser(int $ticketId, int $userId, string $body): void
    {
        $ticket = $this->findForUser($ticketId, $userId);
        if ($ticket === null) {
            throw new \RuntimeException('工单不存在。');
        }
        if (self::isEnded((string) $ticket['status'])) {
            throw new \RuntimeException('工单已结束，无法继续回复。');
        }

        $this->appendMessage($ticketId, self::ROLE_USER, $userId, $body);
    }

    /**
     * 管理员回复；工单状态由系统按「是否有管理员回复」自动判定为处理中。
     */
    public function replyAsAdmin(int $ticketId, int $adminId, string $body): void
    {
        $ticket = $this->find($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('工单不存在。');
        }

        $this->appendMessage($ticketId, self::ROLE_ADMIN, $adminId, $body);
    }

    /**
     * 用户侧的工单列表：未结束的排在前面，其余按最后留言时间倒序；已归档（软删除）的不展示。
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketsForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $statement = Database::connection()->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS message_count
             FROM tickets t
             WHERE t.user_id = :user_id
               AND NOT EXISTS (
                     SELECT 1 FROM ticket_archives a
                     WHERE a.ticket_id = t.id AND a.viewer_kind = :viewer_kind AND a.viewer_id = :viewer_id
               )
             ORDER BY (t.status IN ('closed', 'expired')) ASC, COALESCE(t.last_message_at, t.created_at) DESC
             LIMIT " . $limit
        );
        $statement->execute([
            'user_id'     => $userId,
            'viewer_kind' => self::VIEWER_USER,
            'viewer_id'   => $userId,
        ]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 后台工单列表，可按状态、类别筛选；已归档（该管理员软删除）的不展示。
     *
     * @return array<int, array<string, mixed>>
     */
    public function allTickets(string $status = '', string $category = '', int $limit = 200, int $viewerId = 0): array
    {
        $limit = max(1, min(500, $limit));
        $conditions = [];
        $params = [];
        if (array_key_exists($status, self::statuses())) {
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }
        if (array_key_exists($category, self::categories())) {
            $conditions[] = 't.category = :category';
            $params['category'] = $category;
        }
        if ($viewerId > 0) {
            $conditions[] = 'NOT EXISTS (
                    SELECT 1 FROM ticket_archives a
                    WHERE a.ticket_id = t.id AND a.viewer_kind = :viewer_kind AND a.viewer_id = :viewer_id
                )';
            $params['viewer_kind'] = self::VIEWER_ADMIN;
            $params['viewer_id']   = $viewerId;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $statement = Database::connection()->prepare(
            "SELECT t.*, u.username, u.email,
                    (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS message_count
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id
             {$where}
             ORDER BY (t.status IN ('closed', 'expired')) ASC, COALESCE(t.last_message_at, t.created_at) DESC
             LIMIT " . $limit
        );
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 归档页列表：仅展示该 viewer 已归档的工单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function archivedTickets(string $viewerKind, int $viewerId, string $status = '', string $category = '', int $limit = 200): array
    {
        if ($viewerId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $kind = $viewerKind === self::VIEWER_ADMIN ? self::VIEWER_ADMIN : self::VIEWER_USER;
        $conditions = ['a.viewer_kind = :viewer_kind', 'a.viewer_id = :viewer_id'];
        $params = ['viewer_kind' => $kind, 'viewer_id' => $viewerId];
        if (array_key_exists($status, self::statuses())) {
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }
        if (array_key_exists($category, self::categories())) {
            $conditions[] = 't.category = :category';
            $params['category'] = $category;
        }

        $statement = Database::connection()->prepare(
            "SELECT t.*, u.username, u.email, a.created_at AS archived_at,
                    (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS message_count
             FROM ticket_archives a
             INNER JOIN tickets t ON t.id = a.ticket_id
             LEFT JOIN users u ON u.id = t.user_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT " . $limit
        );
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 归档（软删除）：写入 viewer 维度的 ticket_archives，仅该 viewer 的列表不再展示，可恢复。
     * 不修改工单本身、也不清除附件（附件仅在工单置为结束态时清除）。
     *
     * $onlyEligible = true 时按 viewer 校验可归档条件：
     *  - 用户：仅自己的工单，且已结束（closed / expired）
     *  - 管理员：仅已过期（expired）
     * $onlyEligible = false 用于后台「批量归档 / 清除全部工单」这类不限状态的整理动作。
     *
     * @param array<int, mixed> $ticketIds
     */
    public function archiveTickets(array $ticketIds, string $viewerKind, int $viewerId, bool $onlyEligible = true): int
    {
        $ids = self::normalizeIds($ticketIds);
        if ($ids === [] || $viewerId <= 0) {
            return 0;
        }
        $kind = $viewerKind === self::VIEWER_ADMIN ? self::VIEWER_ADMIN : self::VIEWER_USER;
        $in = implode(',', $ids);

        if ($onlyEligible) {
            $where = $kind === self::VIEWER_ADMIN
                ? "t.status = '" . self::STATUS_EXPIRED . "'"
                : "t.user_id = " . $viewerId . " AND t.status IN ('" . self::STATUS_CLOSED . "', '" . self::STATUS_EXPIRED . "')";
            $eligible = Database::connection()
                ->query("SELECT t.id FROM tickets t WHERE t.id IN ({$in}) AND {$where}")
                ->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $ids = self::normalizeIds($eligible);
            if ($ids === []) {
                return 0;
            }
            $in = implode(',', $ids);
        }

        // 用 INSERT ... SELECT 一次写入，IGNORE 保证重复归档不报错
        $statement = Database::connection()->prepare(
            "INSERT IGNORE INTO ticket_archives (ticket_id, viewer_kind, viewer_id)
             SELECT t.id, :viewer_kind, :viewer_id FROM tickets t WHERE t.id IN ({$in})"
        );
        $statement->execute(['viewer_kind' => $kind, 'viewer_id' => $viewerId]);

        return $statement->rowCount();
    }

    /**
     * 清除全部工单：把当前 viewer 可见的全部工单（含申诉工单）归档，而非全站真删，可恢复。
     */
    public function archiveAllTickets(string $viewerKind, int $viewerId): int
    {
        if ($viewerId <= 0) {
            return 0;
        }
        $kind = $viewerKind === self::VIEWER_ADMIN ? self::VIEWER_ADMIN : self::VIEWER_USER;
        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO ticket_archives (ticket_id, viewer_kind, viewer_id)
             SELECT t.id, :viewer_kind, :viewer_id FROM tickets t'
        );
        $statement->execute(['viewer_kind' => $kind, 'viewer_id' => $viewerId]);

        return $statement->rowCount();
    }

    /**
     * 恢复归档：把工单移出 viewer 的归档列表。
     *
     * @param array<int, mixed> $ticketIds
     */
    public function restoreTickets(array $ticketIds, string $viewerKind, int $viewerId): int
    {
        $ids = self::normalizeIds($ticketIds);
        if ($ids === [] || $viewerId <= 0) {
            return 0;
        }
        $kind = $viewerKind === self::VIEWER_ADMIN ? self::VIEWER_ADMIN : self::VIEWER_USER;
        $statement = Database::connection()->prepare(
            'DELETE FROM ticket_archives
             WHERE viewer_kind = :viewer_kind AND viewer_id = :viewer_id AND ticket_id IN (' . implode(',', $ids) . ')'
        );
        $statement->execute(['viewer_kind' => $kind, 'viewer_id' => $viewerId]);

        return $statement->rowCount();
    }

    /**
     * 管理员批量改状态；置为结束态（closed / expired）时按关闭路径清除附件。
     *
     * @param array<int, mixed> $ticketIds
     */
    public function setStatuses(array $ticketIds, string $status): int
    {
        if (!array_key_exists($status, self::statuses())) {
            throw new \RuntimeException('无效的工单状态。');
        }
        $ids = self::normalizeIds($ticketIds);
        if ($ids === []) {
            return 0;
        }

        $changed = 0;
        if (self::isEnded($status)) {
            foreach ($ids as $id) {
                $this->closeTicket($id, null, $status);
                $changed++;
            }

            return $changed;
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('UPDATE tickets SET status = :status WHERE id = :id AND status <> :status_check');
        foreach ($ids as $id) {
            $statement->execute(['status' => $status, 'id' => $id, 'status_check' => $status]);
            $changed += $statement->rowCount();
        }

        return $changed;
    }

    /**
     * @param array<int, mixed> $ticketIds
     * @return int[]
     */
    private static function normalizeIds(array $ticketIds): array
    {
        $ids = [];
        foreach ($ticketIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $ticketId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT t.*, u.username, u.email
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $ticketId]);
        $ticket = $statement->fetch();

        return is_array($ticket) ? $ticket : null;
    }

    /**
     * 用户只能看到自己的工单。
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $ticketId, int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT t.* FROM tickets t WHERE t.id = :id AND t.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $ticketId, 'user_id' => $userId]);
        $ticket = $statement->fetch();

        return is_array($ticket) ? $ticket : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $ticketId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT m.*, u.username
             FROM ticket_messages m
             LEFT JOIN users u ON u.id = m.author_id
             WHERE m.ticket_id = :ticket_id
             ORDER BY m.id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 工单附件：scope = ticket 且归属该工单的在用文件（不出现在「我的文件」）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function attachments(int $ticketId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT up.id, up.filename, up.display_name, up.file_size, up.mime_type, up.user_id, up.created_at, u.username
             FROM uploads up
             LEFT JOIN users u ON u.id = up.user_id
             WHERE up.scope = 'ticket' AND up.ticket_id = :ticket_id AND up.status = 'active'
             ORDER BY up.id ASC"
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 结束工单：已关闭 / 已过期都是终态，用户与管理员都可以结束。
     * 传入 $userId 时校验归属（普通用户只能结束自己的工单）。
     * 同时记录关闭时间（closed_at）或过期时间（expired_at），供重开窗口与统计使用。
     */
    public function closeTicket(int $ticketId, ?int $userId = null, string $status = self::STATUS_CLOSED): void
    {
        if ($userId !== null) {
            if ($this->findForUser($ticketId, $userId) === null) {
                throw new \RuntimeException('工单不存在。');
            }
        } elseif ($this->find($ticketId) === null) {
            throw new \RuntimeException('工单不存在。');
        }

        if (!self::isEnded($status)) {
            $status = self::STATUS_CLOSED;
        }

        $timeColumn = $status === self::STATUS_EXPIRED ? 'expired_at' : 'closed_at';
        Database::connection()
            ->prepare("UPDATE tickets SET status = :status, {$timeColumn} = NOW() WHERE id = :id")
            ->execute(['status' => $status, 'id' => $ticketId]);

        // 结束即失效：把该工单的附件从 Notion 删除并本地标记为已删除（不可恢复）
        $this->purgeAttachments($ticketId);
    }

    /**
     * 到期清扫：把满足条件的工单置为「已过期」并清除附件（过期不可恢复、不可重开，对话保留）。
     * 规则一（配置为「不过期」时跳过）：最后一条留言来自管理员（客服已回复、等用户回复），
     *   且此后超过 N 天用户未回复的工单 → 过期。
     * 规则二：已关闭的工单，关闭满 REOPEN_WINDOW_DAYS 天仍未重开 → 过期。
     *
     * @return int 本次被置为过期的工单数
     */
    public function expireDueTickets(): int
    {
        $expired = 0;

        $expireDays = self::expireDays();
        if ($expireDays > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - $expireDays * 86400);
            $statement = Database::connection()->prepare(
                "SELECT t.id FROM tickets t
                 WHERE t.status IN (:open, :processing)
                   AND COALESCE(t.last_message_at, t.created_at) <= :cutoff
                   AND (
                         SELECT m.author_role FROM ticket_messages m
                         WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1
                       ) = :admin_role"
            );
            $statement->execute([
                'open'       => self::STATUS_OPEN,
                'processing' => self::STATUS_PROCESSING,
                'cutoff'     => $cutoff,
                'admin_role' => self::ROLE_ADMIN,
            ]);
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
                if ($this->expireTicket((int) $id)) {
                    $expired++;
                }
            }
        }

        $closedCutoff = date('Y-m-d H:i:s', time() - self::REOPEN_WINDOW_DAYS * 86400);
        $statement = Database::connection()->prepare(
            "SELECT t.id FROM tickets t
             WHERE t.status = :closed
               AND COALESCE(t.closed_at, t.last_message_at, t.created_at) <= :cutoff"
        );
        $statement->execute(['closed' => self::STATUS_CLOSED, 'cutoff' => $closedCutoff]);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            if ($this->expireTicket((int) $id)) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * 把单张工单置为过期：不可恢复、不可重开；附件从 Notion 删除，对话保留。
     * 已过期的工单不会重复处理。
     */
    private function expireTicket(int $ticketId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE tickets SET status = :expired, expired_at = NOW() WHERE id = :id AND status <> :check'
        );
        $statement->execute([
            'expired' => self::STATUS_EXPIRED,
            'id'      => $ticketId,
            'check'   => self::STATUS_EXPIRED,
        ]);
        if ($statement->rowCount() === 0) {
            return false;
        }

        $this->purgeAttachments($ticketId);

        return true;
    }

    /**
     * 重开已关闭的工单：复用原工单，reopen_count + 1，回到「待处理」等待处理。
     * 传入 $userId 时校验归属（普通用户只能重开自己的工单）；$userId 为 null 表示管理员发起。
     * 已过期 / 超出 30 天窗口 / 重开次数用尽都会抛出异常。
     */
    public function reopenTicket(int $ticketId, ?int $userId = null): void
    {
        $ticket = $userId !== null ? $this->findForUser($ticketId, $userId) : $this->find($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('工单不存在。');
        }

        [$canReopen, $reason] = $this->reopenState($ticket);
        if (!$canReopen) {
            throw new \RuntimeException($reason);
        }

        Database::connection()
            ->prepare('UPDATE tickets SET status = :status, closed_at = NULL, reopen_count = reopen_count + 1 WHERE id = :id')
            ->execute(['status' => self::STATUS_OPEN, 'id' => $ticketId]);
    }

    /**
     * 判断工单当前可否重开，并给出不可重开的原因。
     *
     * @param array<string, mixed> $ticket
     * @return array{0: bool, 1: string} [可否重开, 不可重开原因]
     */
    public function reopenState(array $ticket): array
    {
        $status = (string) ($ticket['status'] ?? '');
        if ($status === self::STATUS_EXPIRED) {
            return [false, '工单已过期，不可重开。'];
        }
        if ($status !== self::STATUS_CLOSED) {
            return [false, '工单未关闭，无需重开。'];
        }
        if ((int) ($ticket['reopen_count'] ?? 0) >= self::MAX_REOPEN) {
            return [false, '重开次数已用尽（上限 ' . self::MAX_REOPEN . ' 次）。'];
        }
        if (self::reopenDeadlinePassed($ticket)) {
            return [false, '已超过关闭后 ' . self::REOPEN_WINDOW_DAYS . ' 天的重开期限。'];
        }

        return [true, ''];
    }

    /**
     * 重开信息：剩余可重开次数与重开截止时间（供详情页展示）。
     *
     * @param array<string, mixed> $ticket
     * @return array{remaining: int, deadline: string}
     */
    public function reopenInfo(array $ticket): array
    {
        $closedAt = (string) ($ticket['closed_at'] ?? '');
        $deadline = $closedAt === ''
            ? ''
            : date('Y-m-d H:i', strtotime($closedAt) + self::REOPEN_WINDOW_DAYS * 86400);

        return [
            'remaining' => max(0, self::MAX_REOPEN - (int) ($ticket['reopen_count'] ?? 0)),
            'deadline'  => $deadline,
        ];
    }

    /**
     * 关闭是否已超出重开窗口（从关闭时刻起算）。
     *
     * @param array<string, mixed> $ticket
     */
    private static function reopenDeadlinePassed(array $ticket): bool
    {
        $closedAt = (string) ($ticket['closed_at'] ?? '');
        if ($closedAt === '') {
            // 历史数据没有 closed_at：以最后留言 / 创建时间为参照
            $closedAt = (string) ($ticket['last_message_at'] ?? $ticket['created_at'] ?? '');
        }
        if ($closedAt === '') {
            return false;
        }

        return time() > strtotime($closedAt) + self::REOPEN_WINDOW_DAYS * 86400;
    }

    /**
     * 可选分值档位：0.5 ~ 5.0，步进 0.5，共 10 档（供星星选择器渲染）。
     *
     * @return float[]
     */
    public static function scoreOptions(): array
    {
        $options = [];
        for ($score = self::MIN_SCORE; $score <= self::MAX_SCORE + 0.0001; $score += self::SCORE_STEP) {
            $options[] = round($score, 1);
        }

        return $options;
    }

    /**
     * 取某张工单的评价（每个工单至多一条）；没有评价时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function reviewFor(int $ticketId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT r.*, u.username
             FROM ticket_reviews r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = :ticket_id LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $review = $statement->fetch();

        return is_array($review) ? $review : null;
    }

    /**
     * 提交 / 覆盖评价：仅工单归属人能评价，且工单必须处于结束态（已关闭 / 已过期）。
     * 同一工单只有一条评价，重复提交会覆盖上次的分数、匿名与留言（Q26）。
     */
    public function submitReview(int $ticketId, int $userId, float $score, bool $anonymous, string $comment): void
    {
        if (!self::reviewEnabled()) {
            throw new \RuntimeException('本站尚未开启工单评价。');
        }

        $ticket = $this->findForUser($ticketId, $userId);
        if ($ticket === null) {
            throw new \RuntimeException('工单不存在。');
        }
        if (!self::isEnded((string) $ticket['status'])) {
            throw new \RuntimeException('工单结束后才能评价。');
        }

        $score = round($score, 1);
        if (!in_array($score, self::scoreOptions(), true)) {
            throw new \RuntimeException(sprintf('评分需为 %.1f ~ %.1f 之间、以 %.1f 为步进的分值。', self::MIN_SCORE, self::MAX_SCORE, self::SCORE_STEP));
        }
        $comment = trim($comment);
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new \RuntimeException('评价留言最多 ' . self::MAX_COMMENT_LENGTH . ' 个字符。');
        }

        Database::connection()->prepare(
            'INSERT INTO ticket_reviews (ticket_id, user_id, score, is_anonymous, comment)
             VALUES (:ticket_id, :user_id, :score, :is_anonymous, :comment)
             ON DUPLICATE KEY UPDATE
                 user_id = VALUES(user_id),
                 score = VALUES(score),
                 is_anonymous = VALUES(is_anonymous),
                 comment = VALUES(comment)'
        )->execute([
            'ticket_id'    => $ticketId,
            'user_id'      => $userId,
            'score'        => number_format($score, 1, '.', ''),
            'is_anonymous' => $anonymous ? 1 : 0,
            'comment'      => mb_substr($comment, 0, self::MAX_COMMENT_LENGTH),
        ]);
    }

    /**
     * 评价统计（Q31）：平均分、评价总数与各分档分布（0.5 一档，含 0 值档）。
     *
     * @return array{count: int, average: float, distribution: array<string, int>}
     */
    public function reviewStats(): array
    {
        $pdo = Database::connection();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ticket_reviews')->fetchColumn();
        $average = $count > 0 ? round((float) $pdo->query('SELECT AVG(score) FROM ticket_reviews')->fetchColumn(), 2) : 0.0;

        $distribution = [];
        foreach (self::scoreOptions() as $score) {
            $distribution[number_format($score, 1, '.', '')] = 0;
        }
        $rows = $pdo->query('SELECT score, COUNT(*) AS total FROM ticket_reviews GROUP BY score')->fetchAll() ?: [];
        foreach ($rows as $row) {
            $key = number_format((float) $row['score'], 1, '.', '');
            if (array_key_exists($key, $distribution)) {
                $distribution[$key] = (int) $row['total'];
            }
        }

        return ['count' => $count, 'average' => $average, 'distribution' => $distribution];
    }

    /**
     * 最近评价明细（供后台「评价统计」页展示）；匿名评价不展示用户名。
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentReviews(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = Database::connection()->prepare(
            "SELECT r.*, t.ticket_no, t.subject, u.username
             FROM ticket_reviews r
             LEFT JOIN tickets t ON t.id = r.ticket_id
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT " . $limit
        );
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * 外部调研链接（Q30）：留空表示不展示调研入口。
     */
    public static function surveyUrl(): string
    {
        return trim((string) Database::config(self::SURVEY_URL_CONFIG_KEY, ''));
    }

    /**
     * 保存外部调研链接；仅接受 http / https，留空表示关闭该入口。
     */
    public static function saveSurveyUrl(string $url): void
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('调研链接需以 http:// 或 https:// 开头，留空表示不展示。');
        }
        Database::setConfig(self::SURVEY_URL_CONFIG_KEY, $url);
    }

    /**
     * 清理工单附件：逐个归档 Notion 副本，再把本地记录置为已删除。
     * 单份失败只记日志与告警，不阻断工单关闭；删除不可恢复。
     */
    private function purgeAttachments(int $ticketId): void
    {
        $attachments = $this->attachments($ticketId);
        if ($attachments === []) {
            return;
        }

        $pdo = Database::connection();
        $ownerStatement = $pdo->prepare(
            'SELECT id, notion_token_encrypted, notion_account_source FROM users WHERE id = :id LIMIT 1'
        );
        $markDeleted = $pdo->prepare("UPDATE uploads SET status = 'deleted' WHERE id = :id");
        $owners = [];

        foreach ($attachments as $attachment) {
            $uploadId = (int) $attachment['id'];
            $ownerId = (int) $attachment['user_id'];
            if (!array_key_exists($ownerId, $owners)) {
                $ownerStatement->execute(['id' => $ownerId]);
                $row = $ownerStatement->fetch();
                $owners[$ownerId] = is_array($row) ? $row : null;
            }
            $owner = $owners[$ownerId];
            if ($owner === null) {
                continue;
            }

            try {
                archiveUploadReplicas($attachment, $owner);
            } catch (\Throwable $exception) {
                Logger::warning('ticket', sprintf(
                    '工单 #%d 关闭时清理附件 #%d 失败：%s',
                    $ticketId,
                    $uploadId,
                    $exception->getMessage()
                ), ['ticket_id' => $ticketId, 'upload_id' => $uploadId]);
            }

            $markDeleted->execute(['id' => $uploadId]);
        }
    }

    /**
     * 按「是否已有管理员回复」自动判定状态：有待处理的工单若无管理员留言则为待处理，
     * 一旦有管理员回复则推进为处理中；已结束（已关闭 / 已过期）的工单不受影响（保持终态）。
     */
    private function refreshDerivedStatus(int $ticketId): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = :ticket_id AND author_role = :role'
        );
        $statement->execute(['ticket_id' => $ticketId, 'role' => self::ROLE_ADMIN]);
        $status = (int) $statement->fetchColumn() > 0 ? self::STATUS_PROCESSING : self::STATUS_OPEN;

        $pdo->prepare('UPDATE tickets SET status = :status WHERE id = :id AND status NOT IN (:closed, :expired)')
            ->execute([
                'status'  => $status,
                'id'      => $ticketId,
                'closed'  => self::STATUS_CLOSED,
                'expired' => self::STATUS_EXPIRED,
            ]);
    }

    /**
     * 批量取「申诉记录 id → 工单线程」，供文件页直接展示对话。
     *
     * @param int[] $recordIds
     * @return array<int, array{ticket: array<string, mixed>, messages: array<int, array<string, mixed>>}>
     */
    public function appealThreadsByRecords(array $recordIds): array
    {
        $ids = [];
        foreach ($recordIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $pdo = Database::connection();
        $in = implode(',', $ids);

        $tickets = $pdo->query(
            "SELECT * FROM tickets
             WHERE category = 'appeal' AND related_record_id IN ({$in})
             ORDER BY id DESC"
        )->fetchAll() ?: [];

        $threads = [];
        $ticketIdToRecord = [];
        foreach ($tickets as $ticket) {
            $recordId = (int) $ticket['related_record_id'];
            $ticketIdToRecord[(int) $ticket['id']] = $recordId;
            // 同一申诉记录可能有多张工单（过期后重新提单）：DESC 排序下保留最新的那张
            if (!isset($threads[$recordId])) {
                $threads[$recordId] = ['ticket' => $ticket, 'messages' => []];
            }
        }
        if ($threads === []) {
            return [];
        }

        $messages = $pdo->query(
            'SELECT m.*, u.username
             FROM ticket_messages m
             LEFT JOIN users u ON u.id = m.author_id
             WHERE m.ticket_id IN (' . implode(',', array_keys($ticketIdToRecord)) . ')
             ORDER BY m.id ASC'
        )->fetchAll() ?: [];

        foreach ($messages as $message) {
            $recordId = $ticketIdToRecord[(int) $message['ticket_id']] ?? null;
            if ($recordId !== null) {
                $threads[$recordId]['messages'][] = $message;
            }
        }

        return $threads;
    }

    private function insertMessage(int $ticketId, string $role, int $authorId, string $body): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO ticket_messages (ticket_id, author_role, author_id, body)
             VALUES (:ticket_id, :author_role, :author_id, :body)'
        )->execute([
            'ticket_id'   => $ticketId,
            'author_role' => $role === self::ROLE_ADMIN ? self::ROLE_ADMIN : self::ROLE_USER,
            'author_id'   => $authorId,
            'body'        => mb_substr($body, 0, self::MAX_BODY_LENGTH),
        ]);

        $pdo->prepare('UPDATE tickets SET last_message_at = NOW() WHERE id = :id')
            ->execute(['id' => $ticketId]);

        $this->refreshDerivedStatus($ticketId);
    }

    private static function generateTicketNo(): string
    {
        $suffix = '';
        $max = strlen(self::TICKET_ALPHABET) - 1;
        for ($i = 0; $i < self::TICKET_LENGTH; $i++) {
            $suffix .= self::TICKET_ALPHABET[random_int(0, $max)];
        }

        return 'T-' . $suffix;
    }
}