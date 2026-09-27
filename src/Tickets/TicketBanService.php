<?php

declare(strict_types=1);

namespace NotionDrive\Tickets;

use NotionDrive\Database;
use NotionDrive\Logger;

/**
 * 禁止提交工单：独立于封禁体系（user_bans）的标记，只限制用户新建工单。
 *
 * 被封禁（user_bans）的用户仍可提交工单；被禁止提交工单（user_ticket_bans）的用户
 * 上传、下载、重命名、新建文件夹都不受影响，已有工单仍可查看与回复。
 */
final class TicketBanService
{
    /** 可选期限（天）：表单取值 => 展示文案；0 表示永久。 */
    private const DURATIONS = [
        '1'  => '1 天',
        '3'  => '3 天',
        '7'  => '7 天',
        '30' => '30 天',
        '0'  => '永久',
    ];

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
    public static function durations(): array
    {
        return self::DURATIONS;
    }

    /** 用户当前是否被禁止提交工单（已到期的记录会自动失效）。 */
    public function isTicketBanned(int $userId): bool
    {
        return $this->activeTicketBan($userId) !== null;
    }

    /**
     * 当前生效中的禁止提交工单记录；读取前先把已到期的记录置为失效。
     *
     * @return array<string, mixed>|null
     */
    public function activeTicketBan(int $userId): ?array
    {
        $this->expireDue();
        $statement = Database::connection()->prepare(
            'SELECT * FROM user_ticket_bans WHERE user_id = :user_id AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $ban = $statement->fetch();

        return is_array($ban) ? $ban : null;
    }

    /**
     * 禁止提交工单（管理员操作）。已在禁止中时不重复写入。
     *
     * @param int|null $days 1/3/7/30 表示到期自动解除；其余取值（含 null、0）为永久。
     */
    public function ban(int $userId, string $reason, ?int $days = null, ?int $adminId = null): void
    {
        if ($this->userExists($userId) === false) {
            throw new \RuntimeException('用户不存在。');
        }
        if ($this->isTicketBanned($userId)) {
            return;
        }

        $validDays = $days !== null && $days > 0 && isset(self::DURATIONS[(string) $days]) ? $days : null;
        $untilSql = $validDays === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . $validDays . ' DAY)';

        Database::connection()->prepare(
            'INSERT INTO user_ticket_bans (user_id, reason, until, created_by)
             VALUES (:user_id, :reason, ' . $untilSql . ', :created_by)'
        )->execute([
            'user_id'    => $userId,
            'reason'     => mb_substr(trim($reason), 0, 500),
            'created_by' => $adminId !== null && $adminId > 0 ? $adminId : null,
        ]);
    }

    /** 解除禁止：结束当前生效中的记录（不含「禁止提交工单」之外的其他限制）。 */
    public function unban(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE user_ticket_bans SET is_active = 0, lifted_at = NOW()
             WHERE user_id = :user_id AND is_active = 1'
        )->execute(['user_id' => $userId]);
    }

    /**
     * 当前被禁止提交工单的用户列表，最新禁止的排在前面。
     *
     * @return array<int, array<string, mixed>>
     */
    public function bannedUsers(): array
    {
        $this->expireDue();
        $statement = Database::connection()->query(
            'SELECT b.id AS ban_id, b.user_id, b.reason, b.until, b.created_at,
                    u.username, u.email
             FROM user_ticket_bans b
             JOIN users u ON u.id = b.user_id
             WHERE b.is_active = 1
             ORDER BY b.created_at DESC, b.id DESC'
        );

        return $statement->fetchAll() ?: [];
    }

    /** 把已过 until 的记录统一置为失效，实现「到期自动解除」。 */
    private function expireDue(): void
    {
        $affected = Database::connection()->exec(
            'UPDATE user_ticket_bans SET is_active = 0, lifted_at = NOW()
             WHERE is_active = 1 AND until IS NOT NULL AND until <= NOW()'
        );

        if ($affected > 0) {
            Logger::info('ticket', '禁止提交工单记录到期自动解除。', ['count' => $affected]);
        }
    }

    private function userExists(int $userId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() > 0;
    }
}