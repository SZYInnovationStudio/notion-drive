<?php

declare(strict_types=1);

namespace NotionDrive\Uploads;

use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Moderation\ModerationService;
use NotionDrive\Moderation\Verdict;
use RuntimeException;
use Throwable;

/**
 * 异步写入存储队列：上传完成后文件先暂存在服务器本地、入库为 writing（正在写入存储），
 * 浏览器立刻能在「我的文件」里看到并预览 / 下载 / 重命名 / 移动 / 删除；
 * 再由本队列把暂存文件写入 Notion，成功后回填副本、转 active，并删除服务器上的暂存文件。
 *
 * 执行时机（不依赖 cron 与常驻进程）：
 *   1. 上传请求用 jsonResponseThen() 断开浏览器后立即处理本次文件；
 *   2. 「我的文件」页面访问时做一次懒清扫，接管进程被中断而停在 writing 的记录、清理孤儿暂存文件。
 *
 * 并发安全：以暂存文件本身作互斥锁（flock），保证同一条记录同一时刻只有一个进程在写。
 */
final class NotionWriteQueue
{
    /** 正在写入存储：暂存在服务器，Notion 尚未写入。 */
    public const STATUS_WRITING = 'writing';
    /** 写入存储失败：暂存文件仍保留，等待用户手动重试。 */
    public const STATUS_FAILED = 'write_failed';

    /** 后台写入的最大尝试次数（首次 + 2 次重试）。 */
    private const MAX_ATTEMPTS = 3;
    /** 单条写入超过此时长仍未更新，视为进程掉线，允许懒清扫接管（真正的并发由 flock 兜住）。 */
    private const STALE_SECONDS = 1800;
    /** 懒清扫的单次时长预算（秒）：避免页面访问把 PHP 进程占住太久。 */
    private const SWEEP_BUDGET_SECONDS = 20;
    /** 重试前的等待时间（微秒）。 */
    private const RETRY_DELAY_MICRO = 500000;

    public static function create(): self
    {
        return new self();
    }

    /** 暂存根目录：与最终数据、分块暂存完全隔离。 */
    public static function stagingRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage/staging';
    }

    /** 按用户分目录暂存，避免单目录堆积过多文件。 */
    public static function stagingDir(int $userId): string
    {
        return self::stagingRoot() . '/' . $userId;
    }

    public static function stagingPath(int $userId, int $uploadId): string
    {
        return self::stagingDir($userId) . '/' . $uploadId . '.bin';
    }

    /** 每用户在服务器上的「待写入暂存」上限（字节，0 表示不限）：后台「上传与容量」可配，默认 1 GB。 */
    public static function stagedLimitBytes(): int
    {
        $megabytes = (int) Database::config('staging_limit_mb', '1024');

        return $megabytes > 0 ? $megabytes * 1024 * 1024 : 0;
    }

    /** 该用户当前占用的待写入暂存字节数（含写入失败、等待重试的文件；工单附件一样占服务器空间，故一并计入）。 */
    public static function stagedBytes(int $userId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(SUM(file_size), 0) FROM uploads
             WHERE user_id = :user_id AND status IN (:writing, :failed)'
        );
        $statement->execute([
            'user_id' => $userId,
            'writing' => self::STATUS_WRITING,
            'failed'  => self::STATUS_FAILED,
        ]);

        return (int) $statement->fetchColumn();
    }

    /** 全服「待写入暂存」上限（字节，0 表示不限）：后台「上传与容量」可配，默认 10 GB。 */
    public static function serverLimitBytes(): int
    {
        $megabytes = (int) Database::config('server_disk_limit_mb', '10240');

        return $megabytes > 0 ? $megabytes * 1024 * 1024 : 0;
    }

    /** 全服当前占用的待写入暂存字节数（所有用户合计；工单附件一样占服务器空间，故一并计入）。 */
    public static function stagedBytesAll(): int
    {
        $statement = Database::connection()->query(
            "SELECT COALESCE(SUM(file_size), 0) FROM uploads WHERE status IN ('" . self::STATUS_WRITING . "', '" . self::STATUS_FAILED . "')"
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * 把拼接完成的文件收进暂存区，返回暂存路径。
     * 同分区内 rename 是原子操作；跨分区时回退为复制后删除。失败即抛异常，由调用方回滚数据库记录。
     */
    public function stage(int $userId, int $uploadId, string $sourcePath): string
    {
        $directory = self::stagingDir($userId);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('服务器无法创建上传暂存目录，请联系管理员。');
        }

        $target = self::stagingPath($userId, $uploadId);
        if (!@rename($sourcePath, $target)) {
            if (!@copy($sourcePath, $target)) {
                throw new RuntimeException('服务器无法暂存上传文件，请联系管理员。');
            }
            @unlink($sourcePath);
        }

        return $target;
    }

    /** 删除某条上传的暂存文件，并顺带清掉已空的用户暂存目录。 */
    public function discard(int $userId, int $uploadId): void
    {
        @unlink(self::stagingPath($userId, $uploadId));

        $directory = self::stagingDir($userId);
        if (!is_dir($directory)) {
            return;
        }
        $entries = @scandir($directory);
        // 只删空目录：并发写入时目录里可能还有其它文件的暂存
        if (is_array($entries) && $entries === ['.', '..']) {
            @rmdir($directory);
        }
    }

    /**
     * 推进一条上传的写入。已转 active / 已删除的记录直接返回；
     * 暂存文件被其它进程持锁时也直接返回（说明它正在被写入），保证幂等。
     */
    public function process(int $uploadId): void
    {
        $upload = $this->loadUpload($uploadId);
        if ($upload === null) {
            return;
        }

        $userId = (int) $upload['user_id'];
        $stagingPath = self::stagingPath($userId, $uploadId);
        if (!is_file($stagingPath)) {
            // 暂存文件已不在（进程崩溃后被清理、磁盘故障等）：无法再写入，直接判失败，避免永远卡在「正在写入存储」
            $this->markFailed($upload, '服务器上的暂存文件已不存在，无法写入存储。');

            return;
        }

        $lock = fopen($stagingPath, 'rb');
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        try {
            $this->write($upload, $stagingPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // Windows 上无法删除仍被本进程打开的文件，而 write() 内部删暂存时锁句柄还没释放；
        // 这里释放后再补一次（已删则无副作用），避免暂存文件要等下一次懒清扫才被清掉
        $this->discardIfSettled($userId, $uploadId);
    }

    /** 记录已不再是「待写入」状态（已写入成功 / 已隔离 / 已删除）时删除暂存文件。 */
    private function discardIfSettled(int $userId, int $uploadId): void
    {
        $upload = $this->findUpload($uploadId);
        if ($upload !== null
            && in_array((string) $upload['status'], [self::STATUS_WRITING, self::STATUS_FAILED], true)) {
            return;
        }

        $this->discard($userId, $uploadId);
    }

    /** 手动重试：把「写入失败」的记录重新置为待写入并清零尝试次数；返回是否受理。 */
    public function retry(int $uploadId, int $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE uploads SET status = :writing, write_attempts = 0, write_progress = 0
             WHERE id = :id AND user_id = :user_id AND status = :failed'
        );
        $statement->execute([
            'writing' => self::STATUS_WRITING,
            'id'      => $uploadId,
            'user_id' => $userId,
            'failed'  => self::STATUS_FAILED,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * 懒清扫：接管长期停在 writing 的记录（进程被中断），并清理孤儿暂存文件。
     * 必须在浏览器断开之后调用，避免把页面访问拖住。
     */
    public function sweep(int $budgetSeconds = self::SWEEP_BUDGET_SECONDS): void
    {
        $deadline = time() + max(1, $budgetSeconds);
        $statement = Database::connection()->prepare(
            'SELECT id FROM uploads WHERE status = :writing AND updated_at < :stale ORDER BY id ASC LIMIT 3'
        );
        $statement->execute([
            'writing' => self::STATUS_WRITING,
            'stale'   => date('Y-m-d H:i:s', time() - self::STALE_SECONDS),
        ]);

        foreach ($statement->fetchAll() as $row) {
            if (time() >= $deadline) {
                break;
            }
            try {
                $this->process((int) $row['id']);
            } catch (Throwable $exception) {
                // 单条记录出错不应中断对其余记录的接管
                Logger::error('queue', '懒清扫处理待写入记录时出错。', [
                    'upload_id' => (int) $row['id'],
                    'error'     => $exception->getMessage(),
                ]);
            }
        }

        $this->discardOrphans();
    }

    /** 真正执行写入：失败按次数重试，达到上限置为「写入失败」并告警管理员。 */
    private function write(array $upload, string $stagingPath): void
    {
        $uploadId = (int) $upload['id'];
        $userId = (int) $upload['user_id'];
        $owner = $this->owner($userId);
        $filename = $this->displayName($upload);
        $mime = (string) $upload['mime_type'];
        $size = (int) $upload['file_size'];
        $attempts = (int) $upload['write_attempts'];
        $lastError = '';
        // 写入进度仅供前端展示进度条：单调递增、失败不影响真正的写入流程
        $lastPercent = -1;
        $reportProgress = static function (int $percent) use ($uploadId, &$lastPercent): void {
            $percent = max(0, min(100, $percent));
            if ($percent <= $lastPercent) {
                return;
            }
            $lastPercent = $percent;
            try {
                Database::connection()->prepare(
                    'UPDATE uploads SET write_progress = GREATEST(write_progress, :percent) WHERE id = :id'
                )->execute(['percent' => $percent, 'id' => $uploadId]);
            } catch (Throwable) {
                // 进度列仅用于展示，写失败（如迁移未执行）不应中断上传
            }
        };

        while (true) {
            $attempts++;
            // 先落尝试次数：进程若在此后崩溃，后续判断才能据此得知已重试过几次
            Database::connection()->prepare('UPDATE uploads SET write_attempts = :attempts WHERE id = :id')
                ->execute(['attempts' => $attempts, 'id' => $uploadId]);

            try {
                $stored = writeNotionReplicas(
                    $owner,
                    $stagingPath,
                    $filename,
                    $mime,
                    $size,
                    (string) ($upload['storage_name'] ?? ''),
                    $reportProgress
                );
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                Logger::warning('queue', '写入 Notion 失败，准备重试。', [
                    'upload_id' => $uploadId,
                    'user_id'   => $userId,
                    'attempt'   => $attempts,
                    'reason'    => $lastError,
                ]);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->markFailed($upload, $lastError);

                    return;
                }
                usleep(self::RETRY_DELAY_MICRO);

                continue;
            }

            // 回填副本并转 active；若记录在写入期间已被用户删除，UPDATE 影响 0 行，刚写入的副本随即成为孤儿，必须归档
            try {
                $committed = $this->commitReplicas($uploadId, $stored['replicas']);
            } catch (Throwable $exception) {
                // 落库失败：Notion 副本已写入但本地没有记录，不清理将永远无法通过应用删除（孤儿副本）
                archiveWrittenReplicas($owner, $stored['replicas']);
                throw $exception;
            }
            if (!$committed) {
                archiveWrittenReplicas($owner, $stored['replicas']);
                Logger::info('queue', '文件在写入存储期间被删除，已归档刚写入的副本。', ['upload_id' => $uploadId]);
                // 覆盖上传的新文件被删掉：把被覆盖的旧文件恢复可用，否则两代文件都会从用户视野里消失
                $this->rollbackOverwrite($upload);
                $this->discard($userId, $uploadId);

                return;
            }

            break;
        }

        Logger::info('queue', '文件已写入存储。', [
            'upload_id' => $uploadId,
            'user_id'   => $userId,
            'attempts'  => $attempts,
        ]);

        // 新文件已确认写入 Notion，此时才归档被覆盖旧文件的副本（失败则恢复旧文件，见 markFailed）
        $this->finalizeOverwrite($upload);

        // 内容审核放在写入完成之后：命中违规时把文件置为隔离（quarantined），与改造前「先存储后隔离」的语义一致
        $this->moderate($upload, $owner, $filename, $mime, $size, $stagingPath);

        // 审核（AI 识别）需要读暂存文件，因此暂存文件最后才删
        $this->discard($userId, $uploadId);
    }

    /** 回填副本并把记录转为 active；记录已不是待写入状态（被删除等）时返回 false，且不写任何副本。
     *  在线上传（直传）路径由 api/upload.php 在 finish 阶段直接调用。 */
    public function commitReplicas(int $uploadId, array $replicas): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE uploads
                 SET status = :active, write_attempts = 0, write_progress = 100,
                     notion_file_upload_id = :file_upload_id, notion_block_id = :block_id
                 WHERE id = :id AND status IN (:writing, :failed)'
            );
            $update->execute([
                'active'         => 'active',
                'file_upload_id' => (string) ($replicas[0]['notion_file_upload_id'] ?? ''),
                'block_id'       => (string) ($replicas[0]['notion_block_id'] ?? ''),
                'id'             => $uploadId,
                'writing'        => self::STATUS_WRITING,
                'failed'         => self::STATUS_FAILED,
            ]);
            if ($update->rowCount() === 0) {
                $pdo->rollBack();

                return false;
            }

            $insert = $pdo->prepare(
                'INSERT INTO upload_replicas (upload_id, role, account_type, official_account_id, notion_file_upload_id, notion_block_id)
                 VALUES (:upload_id, :role, :account_type, :official_account_id, :notion_file_upload_id, :notion_block_id)'
            );
            foreach ($replicas as $replica) {
                $insert->execute([
                    'upload_id'               => $uploadId,
                    'role'                    => (int) $replica['role'],
                    'account_type'            => (string) $replica['account_type'],
                    'official_account_id'     => $replica['official_account_id'],
                    'notion_file_upload_id'   => (string) $replica['notion_file_upload_id'],
                    'notion_block_id'         => (string) $replica['notion_block_id'],
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        return true;
    }

    /**
     * 内容审核：先按全站内容指纹比对，命中白名单免重复审核、命中黑名单直接按违规处理，未收录才调用 AI。
     * 工单附件不走风控；审核本身异常不阻断写入结果（与改造前一致）。
     */
    private function moderate(array $upload, array $owner, string $filename, string $mime, int $size, string $stagingPath): void
    {
        if ((string) $upload['scope'] !== 'drive' || userModerationExempt($owner)) {
            return;
        }

        $uploadId = (int) $upload['id'];
        $userId = (int) $owner['id'];
        $context = ['user_id' => $userId, 'username' => (string) ($owner['username'] ?? ''), 'upload_id' => $uploadId];

        try {
            $moderationService = ModerationService::create();
            if (!$moderationService->enabled()) {
                return;
            }

            $contentHash = is_file($stagingPath) ? (string) (hash_file('sha256', $stagingPath) ?: '') : '';
            if ($contentHash !== '') {
                $context['sha256'] = $contentHash;
            }

            $known = $contentHash !== '' ? $moderationService->lookupHash($contentHash) : null;
            if ($known === Verdict::APPROVED) {
                Logger::info('upload', '内容指纹命中白名单，本次免重复审核', $context);
                // 指纹命中白名单即审核通过：获准重传的用户重新上传成功后自动闭环待处理记录
                $moderationService->completeResubmit($userId, $filename);

                return;
            }

            $verdict = $known === Verdict::FLAGGED
                ? Verdict::flagged('该内容此前已被判定违规（SHA-256 命中），无需重复审核。', '', 'hash', 'sha256', $contentHash)
                : $moderationService->inspect($filename, $mime, $stagingPath, $size);

            if ($verdict->isFlagged()) {
                $appealNo = $moderationService->record($userId, $uploadId, $filename, ModerationService::STAGE_FINISH, $verdict, $contentHash);
                // 自动识别只隔离当前文件，不写全站黑名单：AI 存在误判，待管理员人工打回时才登记黑名单
                $moderationService->quarantine($uploadId);
                Logger::warning('upload', '内容审核命中，文件已暂停使用', [
                    'appeal_no' => $appealNo,
                    'provider'  => $verdict->provider,
                    'matched'   => $verdict->matched,
                ] + $context);
                raiseAdminAlert('warning', sprintf(
                    '内容风控拦截：用户 %s 上传《%s》已被暂停使用（申诉号 %s）',
                    (string) $owner['username'],
                    $filename,
                    $appealNo
                ), [
                    'appeal_no' => $appealNo,
                    'upload_id' => $uploadId,
                    'user_id'   => $userId,
                    'filename'  => $filename,
                    'reason'    => $verdict->reason,
                ]);

                return;
            }

            if ($verdict->isPending()) {
                // AI 不可用或超时：不阻断、不隔离，仅登记为待人工复核并提醒管理员；结论未定，不登记内容指纹
                $moderationService->record($userId, $uploadId, $filename, ModerationService::STAGE_FINISH, $verdict, $contentHash);
                Logger::warning('upload', '内容审核降级为人工复核', ['reason' => $verdict->reason] + $context);
                raiseAdminAlert('warning', '内容审核降级：AI 接口不可用，已有文件转为人工复核，请检查风控配置。', [
                    'upload_id' => $uploadId,
                    'reason'    => $verdict->reason,
                ]);

                return;
            }

            // 审核通过：仅当审核确实执行过才登记内容指纹，避免把「未实际审核」的内容登记为已通过（指纹投毒）
            if (ModerationService::isScanned($verdict)) {
                $moderationService->rememberHash($contentHash, Verdict::APPROVED);
            }
            $moderationService->completeResubmit($userId, $filename);
        } catch (Throwable $exception) {
            Logger::error('upload', '内容审核执行失败，文件已按正常流程放行', ['error' => $exception->getMessage()] + $context);
        }
    }

    /**
     * 覆盖上传成功：归档被覆盖旧文件的 Notion 副本，并清空本行的覆盖标记。
     * 旧行此时已是 deleted（不在「我的文件」里），但它的 upload_replicas 仍是 active，据此逐个归档。
     */
    private function finalizeOverwrite(array $upload): void
    {
        $uploadId = (int) $upload['id'];
        $previousId = (int) ($upload['overwrite_upload_id'] ?? 0);
        if ($previousId <= 0) {
            return;
        }

        try {
            $previous = $this->findUpload($previousId);
            if ($previous !== null) {
                archiveUploadReplicas($previous, $this->owner((int) $previous['user_id']));
            }
        } catch (Throwable $exception) {
            Logger::warning('queue', '清理被覆盖旧文件的 Notion 副本失败，旧副本可能残留。', [
                'upload_id'          => $uploadId,
                'previous_upload_id' => $previousId,
                'reason'             => $exception->getMessage(),
            ]);
        }

        $this->clearOverwriteMark($uploadId);
    }

    /**
     * 覆盖上传未成功（写入失败，或新文件在写入期间被删除）：把被覆盖的旧文件恢复为可用。
     * 旧文件的 Notion 副本此前一直被保留，因此恢复后立即可下载，用户不会两代内容都拿不到。
     */
    public function rollbackOverwrite(array $upload): void
    {
        $uploadId = (int) $upload['id'];
        $previousId = (int) ($upload['overwrite_upload_id'] ?? 0);
        if ($previousId <= 0) {
            return;
        }

        Database::connection()->prepare(
            "UPDATE uploads SET status = 'active' WHERE id = :id AND status = 'deleted'"
        )->execute(['id' => $previousId]);
        $this->clearOverwriteMark($uploadId);

        Logger::info('queue', '覆盖上传未成功，已把被覆盖的旧文件恢复为可用。', [
            'upload_id'          => $uploadId,
            'previous_upload_id' => $previousId,
        ]);
    }

    private function clearOverwriteMark(int $uploadId): void
    {
        Database::connection()->prepare('UPDATE uploads SET overwrite_upload_id = NULL WHERE id = :id')
            ->execute(['id' => $uploadId]);
    }

    private function findUpload(int $uploadId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM uploads WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $uploadId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** 置为「写入失败」并告警管理员；暂存文件保留，用户可手动重试。 */
    private function markFailed(array $upload, string $reason): void
    {
        $uploadId = (int) $upload['id'];
        // 命名占位符在同一语句里不能重复（原生预处理会报 HY093 Invalid parameter number），故 SET 与 IN 用不同名字
        Database::connection()->prepare(
            'UPDATE uploads SET status = :failed_status, write_progress = 0 WHERE id = :id AND status IN (:writing, :failed_match)'
        )->execute([
            'failed_status' => self::STATUS_FAILED,
            'id'            => $uploadId,
            'writing'       => self::STATUS_WRITING,
            'failed_match'  => self::STATUS_FAILED,
        ]);

        // 覆盖上传失败：把被覆盖的旧文件恢复可用，否则用户既拿不到新文件、旧文件也不在列表里
        $this->rollbackOverwrite($upload);

        Logger::error('queue', '写入 Notion 最终失败，文件已标记为写入失败。', ['upload_id' => $uploadId, 'reason' => $reason]);
        raiseAdminAlert('error', sprintf(
            '文件 #%d《%s》写入 Notion 失败，用户可在「我的文件」中手动重试。',
            $uploadId,
            $this->displayName($upload)
        ), [
            'stage'     => 'upload',
            'upload_id' => $uploadId,
            'reason'    => $reason,
        ]);
    }

    /** 清理孤儿暂存文件：记录早已转 active / 隔离 / 删除，但暂存文件因进程中断或文件占用而未删掉。 */
    private function discardOrphans(): void
    {
        $candidates = [];
        foreach (glob(self::stagingRoot() . '/*/*.bin') ?: [] as $path) {
            $userId = (int) basename(dirname($path));
            $uploadId = (int) basename($path, '.bin');
            if ($userId > 0 && $uploadId > 0) {
                $candidates[$uploadId] = ['user_id' => $userId, 'path' => $path];
            } else {
                @unlink($path);
            }
        }
        if ($candidates === []) {
            return;
        }

        $ids = array_keys($candidates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = Database::connection()->prepare(
            "SELECT id, user_id, status FROM uploads WHERE id IN ($placeholders)"
        );
        $statement->execute($ids);

        $active = [];
        foreach ($statement->fetchAll() as $row) {
            $active[(int) $row['id']] = ['user_id' => (int) $row['user_id'], 'status' => (string) $row['status']];
        }

        foreach ($candidates as $uploadId => $candidate) {
            $row = $active[$uploadId] ?? null;
            if ($row !== null
                && $row['user_id'] === $candidate['user_id']
                && ($row['status'] === self::STATUS_WRITING || $row['status'] === self::STATUS_FAILED)) {
                continue; // 仍在使用或等待重试，保留
            }
            @unlink($candidate['path']);
        }
    }

    private function loadUpload(int $uploadId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM uploads WHERE id = :id AND status IN (:writing, :failed) LIMIT 1'
        );
        $statement->execute([
            'id'      => $uploadId,
            'writing' => self::STATUS_WRITING,
            'failed'  => self::STATUS_FAILED,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** 文件在应用内展示的名字优先用 display_name（用户可能已重命名），否则用原始文件名。 */
    private function displayName(array $upload): string
    {
        $display = (string) ($upload['display_name'] ?? '');

        return $display !== '' ? $display : (string) $upload['filename'];
    }

    private function owner(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('文件所属用户不存在。');
        }

        return $row;
    }
}