<?php

declare(strict_types=1);

namespace NotionDrive\Moderation;

use NotionDrive\Bans\BanService;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Tickets\TicketService;
use PDO;
use PDOException;
use Throwable;

/**
 * 内容风控门面：配置读取、审核调度、记录落库与申诉复核。
 */
final class ModerationService
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_EXEMPTED  = 'exempted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_RESUBMIT  = 'resubmit';
    public const STATUS_CLEARED   = 'cleared';

    public const STAGE_START  = 'start';
    public const STAGE_FINISH = 'finish';

    private const APPEAL_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const APPEAL_LENGTH   = 6;
    private const ALLOWED_SCOPE   = ['filename', 'text', 'image'];

    /** 用户申诉文本最大长度（对应 moderation_records.appeal_text 的列宽） */
    public const MAX_APPEAL_LENGTH = 1000;

    private function __construct(
        private bool $enabled,
        private string $keywordsRaw,
        private array $scope,
        private string $endpoint,
        private string $apiKey,
        private string $model,
        private int $timeout
    ) {
    }

    public static function create(): self
    {
        $apiKey = '';
        $stored = (string) Database::config('moderation_api_key', '');
        if ($stored !== '') {
            try {
                $apiKey = Security::decrypt($stored, (string) (appConfig()['app_key'] ?? ''));
            } catch (Throwable $exception) {
                Logger::warning('moderation', 'API Key 解密失败', ['error' => $exception->getMessage()]);
                $apiKey = '';
            }
        }

        $scope = self::parseScope((string) Database::config('moderation_scope', 'filename,text,image'));

        return new self(
            (string) Database::config('moderation_enabled', '0') === '1',
            (string) Database::config('moderation_keywords', ''),
            $scope,
            (string) Database::config('moderation_endpoint', ''),
            $apiKey,
            (string) Database::config('moderation_model', 'gpt-4o-mini'),
            (int) Database::config('moderation_timeout', '8')
        );
    }

    /**
     * 后台配置页读取用：API Key 只回传「是否已设置」。
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'enabled'      => $this->enabled,
            'keywords'     => $this->keywordsRaw,
            'scope'        => $this->scope,
            'endpoint'     => $this->endpoint,
            'model'        => $this->model,
            'api_key_set'  => $this->apiKey !== '',
            'timeout'      => $this->timeout,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function saveSettings(array $input, string $newApiKey): void
    {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '') {
            if (!preg_match('#^https://#i', $endpoint)) {
                throw new \RuntimeException('AI 接口地址必须使用 https。');
            }
            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException('AI 接口地址格式不正确。');
            }
            if (self::isPrivateHost($endpoint)) {
                throw new \RuntimeException('AI 接口地址不能指向内网或本机。');
            }
        }

        $timeout = (int) ($input['timeout'] ?? 8);
        if ($timeout < 2 || $timeout > 60) {
            throw new \RuntimeException('AI 审核超时需在 2 到 60 秒之间。');
        }

        $model = trim((string) ($input['model'] ?? ''));
        if (mb_strlen($model) > 128) {
            throw new \RuntimeException('模型名称过长。');
        }

        $scope = self::parseScope($input['scope'] ?? []);
        if ($scope === []) {
            $scope = self::ALLOWED_SCOPE;
        }

        $keywords = trim((string) ($input['keywords'] ?? ''));
        if (mb_strlen($keywords) > 20000) {
            throw new \RuntimeException('关键词列表过长，请精简后再保存。');
        }

        $enabled = (string) ($input['enabled'] ?? '0') === '1' ? '1' : '0';

        Database::setConfig('moderation_enabled', $enabled);
        Database::setConfig('moderation_keywords', $keywords);
        Database::setConfig('moderation_scope', implode(',', $scope));
        Database::setConfig('moderation_endpoint', $endpoint);
        Database::setConfig('moderation_model', $model !== '' ? $model : 'gpt-4o-mini');
        Database::setConfig('moderation_timeout', (string) $timeout);

        if ($newApiKey !== '') {
            Database::setConfig(
                'moderation_api_key',
                Security::encrypt($newApiKey, (string) (appConfig()['app_key'] ?? ''))
            );
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return string[]
     */
    public function keywords(): array
    {
        return self::parseKeywords($this->keywordsRaw);
    }

    /**
     * @return string[]
     */
    public function scope(): array
    {
        return $this->scope;
    }

    /**
     * 上传前（start 阶段）仅按文件名做关键词拦截；命中即拒绝且不落盘。
     */
    public function guardFilename(string $filename): ?Verdict
    {
        if (!$this->enabled) {
            return null;
        }

        $moderator = new KeywordModerator($this->keywords());
        if (!$moderator->hasKeywords()) {
            return null;
        }

        $verdict = $moderator->review(['filename' => $filename]);

        return $verdict->isFlagged() ? $verdict : null;
    }

    /**
     * 上传完成后（finish 阶段）的关键词 + AI 双重识别。
     */
    public function inspect(string $filename, string $mime, string $path, int $size): Verdict
    {
        if (!$this->enabled) {
            return Verdict::approved('', 'disabled');
        }

        $keyword = (new KeywordModerator($this->keywords()))->review([
            'filename' => $filename,
            'text'     => str_starts_with($mime, 'text/') ? self::readTextSample($path) : '',
        ]);
        if ($keyword->isFlagged()) {
            return $keyword;
        }

        if ($this->endpoint === '' || $this->apiKey === '') {
            return Verdict::approved('ai', 'unconfigured');
        }

        return (new AiModerator($this->endpoint, $this->apiKey, $this->model, $this->timeout, $this->scope))
            ->review([
                'filename' => $filename,
                'mime'     => $mime,
                'path'     => $path,
                'size'     => $size,
            ]);
    }

    /**
     * 「通过」结论是否来自真实执行的审核判断（用于决定能否写入全站白名单）。
     * 只有把文件内容（文本或图片）一并送审才算「已审核」：未配置 AI（unconfigured）、
     * 风控关闭（disabled）、审核范围不含任何载荷（skipped）以及只审了文件名（filename）时，
     * 内容实际未被检查，返回 false，避免把未经审核的内容登记为「已通过」而长期免审（指纹投毒）。
     */
    public static function isScanned(Verdict $verdict): bool
    {
        // AiModerator 在有内容载荷参与时把 detail 记为 filename+text / filename+image（含两者组合），
        // 仅审文件名时固定为 filename，据此判定是否至少审过一类内容
        return str_starts_with($verdict->detail, 'filename+');
    }

    /**
     * 读取文本类文件的开头片段用于关键词内容侧匹配（二进制 / 非 UTF-8 文件返回空串）。
     */
    private static function readTextSample(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $raw = (string) fread($handle, 6000);
        fclose($handle);

        if ($raw === '' || str_contains($raw, "\0") || !mb_check_encoding($raw, 'UTF-8')) {
            return '';
        }

        return trim($raw);
    }

    /**
     * 写入风控记录，返回申诉号。$contentHash 为该内容的全站 SHA-256 指纹（可为空）。
     */
    public function record(
        int $userId,
        ?int $uploadId,
        string $filename,
        string $stage,
        Verdict $verdict,
        string $contentHash = ''
    ): string {
        $pdo = Database::connection();
        $contentHash = self::normalizeHash($contentHash);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $appealNo = self::generateAppealNo();
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO moderation_records
                        (appeal_no, user_id, upload_id, filename, content_hash, stage, decision, status, provider, model, reason, matched, detail)
                     VALUES
                        (:appeal_no, :user_id, :upload_id, :filename, :content_hash, :stage, :decision, :status, :provider, :model, :reason, :matched, :detail)'
                );
                $statement->execute([
                    'appeal_no'    => $appealNo,
                    'user_id'      => $userId,
                    'upload_id'    => $uploadId,
                    'filename'     => mb_substr($filename, 0, 900),
                    'content_hash' => $contentHash,
                    'stage'        => $stage,
                    'decision'     => $verdict->decision,
                    'status'       => self::STATUS_OPEN,
                    'provider'     => mb_substr($verdict->provider, 0, 40),
                    'model'        => mb_substr($verdict->model, 0, 128),
                    'reason'       => mb_substr($verdict->reason, 0, 500),
                    'matched'      => mb_substr($verdict->matched, 0, 255),
                    'detail'       => mb_substr($verdict->detail, 0, 60000),
                ]);

                return $appealNo;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('申诉号生成失败，请重试。');
    }

    /**
     * 全站内容指纹查询：同哈希内容已被审核过则返回其结论（approved / flagged），未收录返回 null。
     */
    public function lookupHash(string $contentHash): ?string
    {
        $contentHash = self::normalizeHash($contentHash);
        if ($contentHash === '') {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT decision FROM moderation_hashes
             WHERE content_hash = :content_hash AND decision IN (:approved, :flagged)
             LIMIT 1'
        );
        $statement->execute([
            'content_hash' => $contentHash,
            'approved'     => Verdict::APPROVED,
            'flagged'      => Verdict::FLAGGED,
        ]);
        $decision = $statement->fetchColumn();

        return is_string($decision) && $decision !== '' ? $decision : null;
    }

    /**
     * 登记 / 更新内容指纹结论：approved 进入白名单（免重复审核），flagged 进入黑名单（直接拦截）。
     */
    public function rememberHash(string $contentHash, string $decision): void
    {
        $contentHash = self::normalizeHash($contentHash);
        if ($contentHash === '' || !in_array($decision, [Verdict::APPROVED, Verdict::FLAGGED], true)) {
            return;
        }

        Database::connection()->prepare(
            'INSERT INTO moderation_hashes (content_hash, decision) VALUES (:content_hash, :decision)
             ON DUPLICATE KEY UPDATE decision = VALUES(decision)'
        )->execute(['content_hash' => $contentHash, 'decision' => $decision]);
    }

    /**
     * 撤销内容指纹：管理员放行重传 / 清除记录时调用，让该内容下次上传重新进入审核，
     * 而不是被指纹直接拦截。
     *
     * 同一内容可能被多个用户先后上传并分别被判违规。仅当没有「其它仍处于已打回状态的记录」
     * 引用同一指纹时才可删除，否则会因单条记录的放行而误将全站仍属违规的内容解除拦截。
     */
    public function forgetHash(string $contentHash, int $exceptRecordId = 0): void
    {
        $contentHash = self::normalizeHash($contentHash);
        if ($contentHash === '') {
            return;
        }

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM moderation_records
             WHERE content_hash = :content_hash AND status = :status AND id <> :except_id'
        );
        $statement->execute([
            'content_hash' => $contentHash,
            'status'       => self::STATUS_REJECTED,
            'except_id'    => $exceptRecordId,
        ]);
        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        Database::connection()->prepare('DELETE FROM moderation_hashes WHERE content_hash = :content_hash')
            ->execute(['content_hash' => $contentHash]);
    }

    public function quarantine(int $uploadId): void
    {
        $statement = Database::connection()->prepare(
            "UPDATE uploads SET status = 'quarantined' WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['id' => $uploadId]);
    }

    /**
     * 打回：维持不可用。
     *
     * @return array{appeal_no: string, message: string}
     */
    public function reject(int $recordId, int $adminId, string $note): array
    {
        $record = $this->requireRecord($recordId);

        // 待人工复核（AI 降级）的文件此前仍是可用状态，打回时才真正隔离
        if ($record['upload_id'] !== null) {
            $this->quarantine((int) $record['upload_id']);
        }

        $this->closeRecord($recordId, self::STATUS_REJECTED, $adminId, $note);
        // 人工判定违规：该内容指纹进入黑名单，全站再次上传同哈希内容直接拦截
        $this->rememberHash((string) ($record['content_hash'] ?? ''), Verdict::FLAGGED);

        // 只有人工判定违规才计入：累计达到阈值即自动封号（AI 降级转人工也走这里）
        $autoBanned = BanService::create()->evaluateAutoBan((int) $record['user_id']);

        // 违规留痕：账户页「历史违规记录」据此展示每一次被判违规的文件，
        // 当前分数由打回记录数实时统计，清除记录后分数会同步下降
        BanService::create()->recordViolation(
            (int) $record['user_id'],
            (string) ($record['reason'] ?? '') !== '' ? (string) $record['reason'] : ('文件「' . (string) $record['filename'] . '」未通过内容审核。'),
            'moderation',
            $recordId,
            $record['upload_id'] === null ? null : (int) $record['upload_id'],
            $adminId
        );

        // 待人工复核（AI 降级）的记录文件此前可用，打回才真正隔离
        $isPending = (string) $record['decision'] === Verdict::PENDING;

        return [
            'appeal_no' => (string) $record['appeal_no'],
            'message'   => ($isPending
                ? '已判定违规申诉号 ' . $record['appeal_no'] . '，文件已隔离。'
                : '已打回申诉号 ' . $record['appeal_no'] . '，文件维持不可用。')
                . ($autoBanned ? ' 该用户违规已累计达阈值，已自动封禁。' : ''),
        ];
    }

    /**
     * 允许重传：用户可再次上传同名文件，上传后仍会被识别。
     *
     * @return array{appeal_no: string, message: string}
     */
    public function allowResubmit(int $recordId, int $adminId, string $note): array
    {
        $record = $this->requireRecord($recordId);
        $this->closeRecord($recordId, self::STATUS_RESUBMIT, $adminId, $note);
        // 放行重传：撤销该内容指纹，让重传内容重新进入审核而不是被指纹直接拦截
        $this->forgetHash((string) ($record['content_hash'] ?? ''), $recordId);

        return [
            'appeal_no' => (string) $record['appeal_no'],
            'message'   => '已允许申诉号 ' . $record['appeal_no'] . ' 重新上传，请提醒用户重传后再复核。',
        ];
    }

    /**
     * 清除违规记录：管理员手动抹掉一条违规记录（状态改为 cleared），
     * 该条不再计入违规次数（BanService 只统计 status = rejected），也不会触发自动封禁。
     * 与「放行重传」一致，会撤销该内容指纹黑名单，避免用户再次上传同内容被直接拦截；
     * 文件本身仍保持隔离（内容确实未通过审核，不在此处放行可用）。
     *
     * @return array{appeal_no: string, message: string}
     */
    public function clearRecord(int $recordId, int $adminId, string $note): array
    {
        $record = $this->requireRecord($recordId);
        // 仅「已打回」的记录可清除：避免把待复核 / 已放行重传 / 已清除的记录改成清除态，
        // 从而错误地扣减违规计数或撤销全站指纹
        if ((string) $record['status'] !== self::STATUS_REJECTED) {
            throw new \RuntimeException('仅可清除「已打回」状态的违规记录。');
        }
        $this->closeRecord($recordId, self::STATUS_CLEARED, $adminId, $note);
        $this->forgetHash((string) ($record['content_hash'] ?? ''), $recordId);

        return [
            'appeal_no' => (string) $record['appeal_no'],
            'message'   => '已清除申诉号 ' . $record['appeal_no'] . ' 的违规记录，该条不再计入违规次数。',
        ];
    }

    public function hasResubmitPass(int $userId, string $filename): bool
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM moderation_records
             WHERE user_id = :user_id AND filename = :filename AND status = :status"
        );
        $statement->execute([
            'user_id'  => $userId,
            'filename' => mb_substr($filename, 0, 900),
            'status'   => self::STATUS_RESUBMIT,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * 后台列表：最近的审核记录。
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRecords(int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $statement = Database::connection()->query(
            'SELECT r.id, r.appeal_no, r.filename, r.stage, r.decision, r.status, r.provider, r.model,
                    r.reason, r.matched, r.review_note, r.created_at, r.reviewed_at, r.upload_id,
                    u.username, u.email
             FROM moderation_records r
             LEFT JOIN users u ON u.id = r.user_id
             ORDER BY r.id DESC
             LIMIT ' . $limit
        );

        return $statement->fetchAll() ?: [];
    }

    /**
     * 用户侧提示：被判定违规且尚未处理的记录（含已获准重传的），已由用户清除的除外。
     *
     * @return array<int, array<string, mixed>>
     */
    public function userNotices(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, appeal_no, filename, reason, status, stage, appeal_text, appealed_at, created_at
             FROM moderation_records
             WHERE user_id = :user_id AND decision = :decision AND status IN (:status_open, :status_resubmit)
                   AND user_dismissed = 0
             ORDER BY id DESC
             LIMIT 20'
        );
        $statement->execute([
            'user_id'         => $userId,
            'decision'        => Verdict::FLAGGED,
            'status_open'     => self::STATUS_OPEN,
            'status_resubmit' => self::STATUS_RESUBMIT,
        ]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * 用户侧「清除记录」：把自己的某条未通过审核提示从面板隐藏。
     * 只置展示标记 user_dismissed = 1，不改 status，管理员仍可正常复核该记录。
     */
    public function dismissNotice(int $recordId, int $userId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE moderation_records SET user_dismissed = 1
             WHERE id = :id AND user_id = :user_id AND decision = :decision
                   AND status IN (:status_open, :status_resubmit)'
        );
        $statement->execute([
            'id'              => $recordId,
            'user_id'         => $userId,
            'decision'        => Verdict::FLAGGED,
            'status_open'     => self::STATUS_OPEN,
            'status_resubmit' => self::STATUS_RESUBMIT,
        ]);

        if ($statement->rowCount() === 0 && !$this->noticeExists($recordId, $userId)) {
            throw new \RuntimeException('该记录不存在或已处理完毕。');
        }
    }

    /**
     * 用户侧「一键删除」：把面板上全部未通过审核提示一次性隐藏。
     *
     * 与单条清除同源同口径：只置展示标记 user_dismissed = 1，不改 status、不动风控记录与违规计数，
     * 管理员仍可正常复核；管理员复核时 closeRecord() 会把标记归零，提示会再次出现。
     *
     * @return int 本次实际隐藏的条数
     */
    public function dismissAllNotices(int $userId): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE moderation_records SET user_dismissed = 1
             WHERE user_id = :user_id AND decision = :decision
                   AND status IN (:status_open, :status_resubmit) AND user_dismissed = 0'
        );
        $statement->execute([
            'user_id'         => $userId,
            'decision'        => Verdict::FLAGGED,
            'status_open'     => self::STATUS_OPEN,
            'status_resubmit' => self::STATUS_RESUBMIT,
        ]);

        return $statement->rowCount();
    }

    private function noticeExists(int $recordId, int $userId): bool
    {
        // 与 dismissNotice() 的 UPDATE 条件同口径：只有「未通过审核且仍在处理中」的提示才算存在。
        // 已被管理员复核（打回 / 放行 / 清除）的记录不应再回「已清除」，避免误导用户以为是自己清掉的
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM moderation_records
             WHERE id = :id AND user_id = :user_id AND decision = :decision
                   AND status IN (:status_open, :status_resubmit)'
        );
        $statement->execute([
            'id'              => $recordId,
            'user_id'         => $userId,
            'decision'        => Verdict::FLAGGED,
            'status_open'     => self::STATUS_OPEN,
            'status_resubmit' => self::STATUS_RESUBMIT,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * 用户为自己的申诉号提交申诉：快捷提一张申诉工单并落一条留言，
     * 同时在 moderation_records 上保留「最近一条」摘要，供后台列表兼容展示。
     */
    public function submitAppeal(int $recordId, int $userId, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('请填写申诉理由。');
        }
        if (mb_strlen($text) > self::MAX_APPEAL_LENGTH) {
            throw new \RuntimeException('申诉理由最多 ' . self::MAX_APPEAL_LENGTH . ' 个字符。');
        }

        // 先查存在性：MySQL 在「更新后值未变化」时返回 0 affected rows，
        // 直接看 rowCount 会把重复提交相同文本误判成「已处理完毕」
        $record = $this->findOpenRecord($recordId, $userId);
        if ($record === null) {
            throw new \RuntimeException('该申诉号不存在或已处理完毕，无法提交。');
        }

        Database::connection()->prepare(
            'UPDATE moderation_records SET appeal_text = :appeal_text, appealed_at = NOW() WHERE id = :id'
        )->execute([
            'appeal_text' => mb_substr($text, 0, self::MAX_APPEAL_LENGTH),
            'id'          => $recordId,
        ]);

        TicketService::create()->appendAppealMessage(
            $recordId,
            $userId,
            (string) $record['appeal_no'],
            (string) $record['filename'],
            $text
        );
    }

    /**
     * 取一条「仍可申诉」的记录（未处理 / 已获准重传），用于提交前的存在性校验。
     *
     * @return array<string, mixed>|null
     */
    private function findOpenRecord(int $recordId, int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, appeal_no, filename
             FROM moderation_records
             WHERE id = :id AND user_id = :user_id AND status IN (:status_open, :status_resubmit)
             LIMIT 1'
        );
        $statement->execute([
            'id'              => $recordId,
            'user_id'         => $userId,
            'status_open'     => self::STATUS_OPEN,
            'status_resubmit' => self::STATUS_RESUBMIT,
        ]);
        $record = $statement->fetch();

        return is_array($record) ? $record : null;
    }

    /**
     * 后台列表：用户已提交申诉文本的记录（按申诉时间倒序）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function appealRecords(int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $statement = Database::connection()->query(
            'SELECT r.id, r.appeal_no, r.filename, r.stage, r.decision, r.status,
                    r.reason, r.appeal_text, r.appealed_at, r.review_note, r.reviewed_at, r.created_at,
                    u.username, u.email
             FROM moderation_records r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.appealed_at IS NOT NULL
             ORDER BY r.appealed_at DESC, r.id DESC
             LIMIT ' . $limit
        );

        return $statement->fetchAll() ?: [];
    }

    /**
     * 后台列表：全部用户的免审核标记，已豁免的排在前面。
     *
     * @return array<int, array<string, mixed>>
     */
    public function moderationUsers(): array
    {
        $statement = Database::connection()->query(
            'SELECT id, username, email, moderation_exempt
             FROM users
             ORDER BY moderation_exempt DESC, id ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    /**
     * 设置用户的免审核上传标记：被豁免的用户上传时跳过关键词与 AI 识别。
     */
    public function setUserExempt(int $userId, bool $exempt): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET moderation_exempt = :exempt WHERE id = :id'
        );
        $statement->execute([
            'exempt' => $exempt ? 1 : 0,
            'id'     => $userId,
        ]);

        if ($statement->rowCount() === 0 && !$this->userExists($userId)) {
            throw new \RuntimeException('用户不存在。');
        }
    }

    private function userExists(int $userId): bool
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRecord(int $recordId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM moderation_records WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $recordId]);
        $record = $statement->fetch();

        if (!is_array($record)) {
            throw new \RuntimeException('审核记录不存在或已被删除。');
        }

        return $record;
    }

    private function closeRecord(int $recordId, string $status, int $adminId, string $note): void
    {
        // user_dismissed 一并归零：管理员一旦复核（打回 / 放行重传 / 清除），
        // 就把用户此前「清除记录」的隐藏标记撤销，保证新结论能重新出现在用户面板上
        $statement = Database::connection()->prepare(
            'UPDATE moderation_records
             SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(), review_note = :review_note,
                 user_dismissed = 0
             WHERE id = :id'
        );
        $statement->execute([
            'status'      => $status,
            'reviewed_by' => $adminId > 0 ? $adminId : null,
            'review_note' => mb_substr(trim($note), 0, 500),
            'id'          => $recordId,
        ]);
    }

    /**
     * 归一化内容指纹：仅接受 64 位十六进制 SHA-256，合法返回小写，否则返回空串（视为未提供）。
     */
    private static function normalizeHash(string $hash): string
    {
        $hash = strtolower(trim($hash));

        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : '';
    }

    private static function generateAppealNo(): string
    {
        $suffix = '';
        $max = strlen(self::APPEAL_ALPHABET) - 1;
        for ($i = 0; $i < self::APPEAL_LENGTH; $i++) {
            $suffix .= self::APPEAL_ALPHABET[random_int(0, $max)];
        }

        return 'R-' . $suffix;
    }

    /**
     * @return string[]
     */
    public static function parseKeywords(string $raw): array
    {
        $parts = preg_split('/[\r\n,，;；]+/u', $raw) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $word = trim($part);
            if ($word !== '' && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function parseScope(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,，;；]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return self::ALLOWED_SCOPE;
        }

        $scope = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string) $item));
            if (in_array($item, self::ALLOWED_SCOPE, true) && !in_array($item, $scope, true)) {
                $scope[] = $item;
            }
        }

        return $scope;
    }

    private static function isPrivateHost(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return true;
        }

        return self::isPrivateAddress($host);
    }

    /** 判断主机名指向的地址是否属于内网/保留网段（覆盖 IPv6、十进制整型 IPv4 与域名解析结果）。 */
    private static function isPrivateAddress(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'localhost.localdomain') {
            return true;
        }

        // PHP 的 FILTER_VALIDATE_IP 不认「十进制整型 IPv4」（如 2130706433 即 127.0.0.1），先归一化
        if (preg_match('/^[0-9]+$/', $host) === 1 && (int) $host <= 4294967295) {
            $host = long2ip((int) $host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::ipIsPrivate($host);
        }

        // 域名：解析成 IP 再判定，避免「公网域名指向内网地址」绕过字面量检查
        $addresses = [];
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $addresses = $resolved;
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && $record['ipv6'] !== '') {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        foreach ($addresses as $address) {
            if (self::ipIsPrivate($address)) {
                return true;
            }
        }

        // 无法解析时不阻断（域名可能稍后才生效），但也不因此认定其为公网
        return false;
    }

    private static function ipIsPrivate(string $ip): bool
    {
        // 先把 IPv4 映射的 IPv6（如 ::ffff:127.0.0.1）还原成 IPv4，再统一判定
        $packed = @inet_pton($ip);
        if (is_string($packed) && strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF")) {
            $mapped = @inet_ntop(substr($packed, 12));
            if (is_string($mapped)) {
                $ip = $mapped;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
