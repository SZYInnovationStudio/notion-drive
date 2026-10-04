<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Moderation\ModerationService;
use NotionDrive\Moderation\Verdict;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Security;
use NotionDrive\Tickets\TicketService;
use NotionDrive\Uploads\NotionWriteQueue;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'code' => 'method_not_allowed', 'message' => '请求方法不受支持。'], 405);
}

$user = Auth::requireLogin(true);

// 被管理员豁免的用户跳过关键词与 AI 识别
$moderationExempt = userModerationExempt($user);

/** 参考号：便于在「运行日志」中定位上传失败记录。 */
$reference = strtoupper(bin2hex(random_bytes(4)));
$context = [
    'reference' => $reference,
    'user_id' => (int) $user['id'],
    'username' => (string) $user['username'],
    'client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
];

$fail = static function (string $code, string $message, array $extra = [], int $status = 422) use (&$context): never {
    Logger::warning('upload', $message, $extra + $context + ['code' => $code]);
    jsonResponse(['ok' => false, 'code' => $code, 'message' => $message, 'reference' => $context['reference']], $status);
};

// 封禁期间禁止上传个人文件；工单附件是申诉通道（封禁提示本身引导用户走工单申诉），不受封禁影响
$ban = userActiveBan($user);
$assertNotBanned = static function (string $scope) use ($ban, $fail): void {
    if ($scope === 'drive' && $ban !== null) {
        $fail('account_banned', '账号已被封禁，暂时无法上传文件。如有疑问请通过工单提交申诉。', ['ban_id' => (int) $ban['id']], 403);
    }
};

/** 分块暂存目录：只存放拼接中的分块，与最终数据完全隔离。 */
$chunkDir = dirname(__DIR__) . '/storage/chunks';
/** 需要在本请求结束后删除的分块会话（上传完成、或已确定无法继续）。 */
$cleanupChunkTokens = [];
// $fail() 内部直接 exit，finally 不会执行，因此临时文件清理必须挂在 shutdown 上
register_shutdown_function(static function () use ($chunkDir, &$cleanupChunkTokens): void {
    foreach ($cleanupChunkTokens as $token => $_) {
        @unlink($chunkDir . '/' . $token . '.part');
        @unlink($chunkDir . '/' . $token . '.json');
    }
});

$chunkPartPath = static fn (string $token): string => $chunkDir . '/' . $token . '.part';
$chunkMetaPath = static fn (string $token): string => $chunkDir . '/' . $token . '.json';

/** 校验分块会话令牌：固定 32 位小写十六进制，会直接拼进文件路径，必须先过这一关。 */
$assertChunkToken = static function (string $token) use ($fail): string {
    if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
        $fail('invalid_upload_token', '上传会话标识无效，请刷新页面后重新上传。', [], 400);
    }

    return $token;
};

/** 读取分块会话元数据；不存在或已损坏时返回 null。 */
$readChunkMeta = static function (string $token) use ($chunkMetaPath): ?array {
    $path = $chunkMetaPath($token);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $meta = json_decode($raw, true);

    return is_array($meta) ? $meta : null;
};

/** 覆盖写入分块会话元数据（每收一块更新一次 received）。 */
$writeChunkMeta = static function (string $token, array $meta) use ($chunkMetaPath): void {
    file_put_contents(
        $chunkMetaPath($token),
        (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
};

/** 取出分块会话元数据，并确认它属于当前登录用户。 */
$requireChunkMeta = static function (string $token) use ($fail, $readChunkMeta, $user): array {
    $meta = $readChunkMeta($token);
    if ($meta === null) {
        $fail('upload_session_expired', '上传会话已失效，请重新选择文件后再上传。', [], 410);
    }
    if ((int) ($meta['user_id'] ?? 0) !== (int) $user['id']) {
        $fail('upload_session_forbidden', '该上传会话不属于当前账号。', [], 403);
    }

    return $meta;
};

/** 清理超过 12 小时仍未完成的分块会话（含只写了一半的孤儿分块）。 */
$cleanupStaleChunks = static function () use ($chunkDir): void {
    $deadline = time() - 43200;
    $files = array_merge(glob($chunkDir . '/*.part') ?: [], glob($chunkDir . '/*.json') ?: []);
    foreach ($files as $file) {
        $modifiedAt = @filemtime($file);
        if ($modifiedAt !== false && $modifiedAt < $deadline) {
            @unlink($file);
        }
    }
};

$supported = [
    'aac','adts','mid','midi','mp3','mpga','m4a','m4b','mp4','oga','ogg','opus','wav','wma','weba','flac',
    'pdf','txt','csv','json','js','ts','tsx','py','doc','dot','docx','dotx','xls','xlt','xla','xlsx','xltx',
    'ppt','pot','pps','ppa','pptx','potx','rtf','md','markdown','html','htm','epub','xml','css','odt','ods',
    'odp','ics','yaml','yml','tsv','zip','gz','gzip','tar','7z','bz2','rar','gif','heic','jpeg','jpg','png',
    'svg','tif','tiff','webp','ico','bmp','avif','apng','amv','asf','wmv','avi','f4v','flv','gifv','m4v',
    'mkv','webm','mov','qt','mpeg','ogv','3gp','3g2','dwg','dxf','dwf','rvt','rfa','fbx','3dm','step','stp',
    'iges','igs','stl','obj','3mf','gltf','glb','dae','usdz',
];

$validateFilename = static function (string $filename, int $size) use ($fail, $supported): string {
    if ($filename === '') {
        $fail('invalid_filename', '文件名为空，请重命名后再上传。');
    }
    if (strlen($filename) > 900) {
        $fail('invalid_filename', sprintf('文件名 %d 字节，超过 900 字节上限，请缩短文件名后重试。', strlen($filename)));
    }
    if ($size <= 0) {
        $fail('empty_file', sprintf('文件 %s 大小为 0 字节，无法上传空文件。', $filename));
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === '') {
        $fail('unsupported_type', sprintf('文件 %s 没有扩展名，无法判断类型。Notion 不支持该文件，可压缩为 ZIP / RAR 等压缩包后再上传。', $filename));
    }
    if (!in_array($extension, $supported, true)) {
        $fail('unsupported_type', sprintf(
            'Notion 不支持 .%s 这种文件类型（支持文档、图片、音视频、压缩包及 CAD/3D 等 %d 种扩展名），可压缩为 ZIP / RAR 等压缩包后再上传。',
            $extension,
            count($supported)
        ), ['extension' => $extension]);
    }

    return $extension;
};

/** 处理重名：确认覆盖则保留原名并释放旧文件占用，否则自动编号（1.txt → 1(1).txt）。
 *  查重限定在目标文件夹内，避免不同文件夹的同名文件互相覆盖（尤其是「上传文件夹」批量上传时）。 */
$resolveDuplicate = static function (string $filename, string $duplicateAction, int $folderId = 0) use ($user): array {
    $duplicate = findActiveUploadByName((int) $user['id'], $filename, $folderId);
    if ($duplicate === null) {
        return ['filename' => $filename, 'handled' => '', 'freed_bytes' => 0, 'renamed_to' => '', 'duplicate' => null];
    }
    if ($duplicateAction === 'overwrite') {
        return [
            'filename' => $filename,
            'handled' => 'overwrite',
            'freed_bytes' => (int) $duplicate['file_size'],
            'renamed_to' => '',
            'duplicate' => $duplicate,
        ];
    }

    $renamed = nextAvailableFilename((int) $user['id'], $filename, $folderId);

    return ['filename' => $renamed, 'handled' => 'rename', 'freed_bytes' => 0, 'renamed_to' => $renamed, 'duplicate' => null];
};

/**
 * 统计当前用户尚未落库的分块上传占用（按会话声明大小计）。并发开启多个大文件上传时，
 * 每个会话若都只按「已落库用量」判定配额，合计可远远超出个人容量，须把在途会话一并预留。
 *
 * $skipDirect 为 true 时跳过「在线上传（直传）」会话：直传不在服务器保存文件字节，
 * 因此不计入服务器暂存/磁盘校验；但它同样占用个人配额，故默认（配额校验）必须计入，
 * 否则并发直传可绕过个人容量上限。
 */
$pendingUploadBytes = static function (?string $excludeToken, bool $includeTicket = false, bool $skipDirect = false) use ($chunkDir, $user): int {
    $total = 0;
    foreach (glob($chunkDir . '/*.json') ?: [] as $metaFile) {
        if ($excludeToken !== null && basename($metaFile, '.json') === $excludeToken) {
            continue;
        }
        $raw = @file_get_contents($metaFile);
        if ($raw === false || $raw === '') {
            continue;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta) || (int) ($meta['user_id'] ?? 0) !== (int) $user['id']) {
            continue;
        }
        // 工单附件不计入个人配额，故配额校验不参与预留；但服务器暂存校验必须在内（附件同样占磁盘）
        if (!$includeTicket && (string) ($meta['scope'] ?? 'drive') === 'ticket') {
            continue;
        }
        if ($skipDirect && !empty($meta['direct'])) {
            continue;
        }
        $total += max(0, (int) ($meta['size'] ?? 0));
    }

    return $total;
};

/** 全服在途分块会话占用：所有用户、所有分块会话声明的文件大小合计（直传会话不占服务器磁盘，跳过）。 */
$pendingChunkBytesAll = static function (?string $excludeToken) use ($chunkDir): int {
    $total = 0;
    foreach (glob($chunkDir . '/*.json') ?: [] as $metaFile) {
        if ($excludeToken !== null && basename($metaFile, '.json') === $excludeToken) {
            continue;
        }
        $raw = @file_get_contents($metaFile);
        if ($raw === false || $raw === '') {
            continue;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta) || !empty($meta['direct'])) {
            continue;
        }
        $total += max(0, (int) ($meta['size'] ?? 0));
    }

    return $total;
};

/**
 * 服务器暂存上限校验：已入库的「待写入存储」（writing / write_failed）文件与在途分块会话
 * 合计不得超过后台配置的上限，否则并发上传大文件会把服务器磁盘占满。
 */
$assertStagingBudget = static function (int $size, ?string $excludeToken) use ($fail, $user, $pendingUploadBytes): void {
    $limit = NotionWriteQueue::stagedLimitBytes();
    if ($limit <= 0) {
        return;
    }

    $staged = NotionWriteQueue::stagedBytes((int) $user['id']) + $pendingUploadBytes($excludeToken, true, true);
    if ($staged + $size > $limit) {
        $fail('staging_limit_exceeded', sprintf(
            '服务器待写入暂存已用 %s / 共 %s，本次还需 %s。请等正在写入存储的文件完成后重试，或先删除部分文件。',
            formatBytes($staged),
            formatBytes($limit),
            formatBytes($size)
        ), ['staged_bytes' => $staged, 'staging_limit' => $limit, 'size' => $size]);
    }
};

/**
 * 全服防爆盘额度是否已不足以容纳本次上传。仅当额度真的不够时才对用户开放「在线上传」，
 * 否则用户可以主动选择直传来跳过自动内容审核，属审核旁路。
 */
$serverDiskExceeded = static function (int $size) use ($pendingChunkBytesAll): bool {
    $limit = NotionWriteQueue::serverLimitBytes();
    if ($limit <= 0) {
        return false;
    }

    return NotionWriteQueue::stagedBytesAll() + $pendingChunkBytesAll(null) + $size > $limit;
};

/**
 * 全服防爆盘上限校验：所有用户的「待写入暂存 + 在途分块」合计不得超过后台配置的上限（默认 10 GB）。
 * 超限时返回 server_disk_exceeded，前端据此询问用户是否改为「在线上传 Notion」（直传不占服务器暂存）。
 */
$assertServerDiskBudget = static function (int $size, ?string $excludeToken) use ($fail, $pendingChunkBytesAll): void {
    $limit = NotionWriteQueue::serverLimitBytes();
    if ($limit <= 0) {
        return;
    }

    $staged = NotionWriteQueue::stagedBytesAll() + $pendingChunkBytesAll($excludeToken);
    if ($staged + $size > $limit) {
        $fail('server_disk_exceeded', sprintf(
            '服务器暂存空间已用 %s / 共 %s，本次还需 %s，暂无可用额度。可改为「在线上传 Notion」（文件不经服务器暂存，直接写入 Notion）后重试。',
            formatBytes($staged),
            formatBytes($limit),
            formatBytes($size)
        ), ['staged_bytes' => $staged, 'server_limit' => $limit, 'size' => $size], 507);
    }
};

/** 校验单文件上限与个人容量（覆盖同名文件时，旧文件占用的空间会被释放；在途会话占用一并计入）。
 *  $direct 为 true 表示「在线上传」：文件不经服务器暂存，故跳过暂存与全服磁盘校验，仍校验单文件上限与个人配额。 */
$assertCapacity = static function (string $filename, int $size, int $freedBytes, ?string $excludeToken = null, bool $direct = false) use ($fail, $user, $pendingUploadBytes, $assertStagingBudget, $assertServerDiskBudget): void {
    $limit = userSingleFileLimit($user);
    if ($size > $limit) {
        $plan = (string) Database::config('notion_plan', 'free');
        $fail('file_too_large', sprintf(
            '文件 %s 实际大小 %s，超过%s单文件上限 %s（当前套餐：%s）。',
            $filename,
            formatBytes($size),
            $plan === 'paid' ? '付费版' : '免费版',
            formatBytes($limit),
            $plan
        ), ['size' => $size, 'single_limit' => $limit, 'plan' => $plan], 413);
    }

    $quotaBytes = userQuotaBytes($user);
    $usedBytes = userUsedBytes((int) $user['id']);
    $pendingBytes = $pendingUploadBytes($excludeToken);
    $requiredBytes = $usedBytes + $pendingBytes - $freedBytes + $size;
    if ($quotaBytes !== null && $requiredBytes > $quotaBytes) {
        $fail('quota_exceeded', sprintf(
            '个人空间不足：已用 %s / 共 %s，另有上传中的文件占用 %s，本次需要 %s，请联系管理员调整容量。',
            formatBytes($usedBytes),
            formatBytes($quotaBytes),
            formatBytes($pendingBytes),
            formatBytes($size)
        ), ['used_bytes' => $usedBytes, 'pending_bytes' => $pendingBytes, 'quota_bytes' => $quotaBytes, 'size' => $size, 'freed_bytes' => $freedBytes]);
    }

    if ($direct) {
        return;
    }

    $assertStagingBudget($size, $excludeToken);
    $assertServerDiskBudget($size, $excludeToken);
};

/** 单张工单可保有的附件数量上限。 */
$ticketAttachmentLimit = 20;

/**
 * 工单附件容量校验：附件不计入个人配额，但仍限制单文件上限与单张工单的附件数量，
 * 否则可借工单无限上传超大文件或囤积大量文件，绕过个人容量与单文件限制。
 */
$assertTicketAttachmentCapacity = static function (int $ticketId, string $filename, int $size, ?string $excludeToken = null, bool $direct = false) use ($fail, $user, $ticketAttachmentLimit, $assertStagingBudget, $assertServerDiskBudget): void {
    $limit = userSingleFileLimit($user);
    if ($size > $limit) {
        $fail('file_too_large', sprintf(
            '附件 %s 实际大小 %s，超过单文件上限 %s。',
            $filename,
            formatBytes($size),
            formatBytes($limit)
        ), ['size' => $size, 'single_limit' => $limit], 413);
    }

    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM uploads WHERE scope = \'ticket\' AND ticket_id = :ticket_id AND status IN (' . UPLOAD_CAPACITY_STATUSES . ')'
    );
    $statement->execute(['ticket_id' => $ticketId]);
    if ((int) $statement->fetchColumn() >= $ticketAttachmentLimit) {
        $fail('ticket_attachment_limit', sprintf('单张工单最多上传 %d 个附件，已达上限。', $ticketAttachmentLimit));
    }

    if ($direct) {
        return;
    }

    $assertStagingBudget($size, $excludeToken);
    $assertServerDiskBudget($size, $excludeToken);
};

/** 上传可行性前置检查：在 start 阶段就拦下不可能成功的上传，避免传完整个大文件才失败。 */
$assertUploadAllowed = static function () use ($fail, $user): void {
    $blocked = uploadBlockedReason($user);
    if ($blocked !== '') {
        $fail('upload_blocked', $blocked);
    }
    if (uploadTargets($user) === []) {
        $fail('notion_not_configured', notionTargetsMissingReason($user));
    }
};

/** 由拼接完成的文件推断 MIME，并对常见扩展名做覆盖表修正（与 Notion 的类型识别保持一致）。 */
$computeMime = static function (string $path, string $extension): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path) ?: 'application/octet-stream';
    $knownMimes = [
        'pdf' => 'application/pdf', 'csv' => 'text/csv', 'txt' => 'text/plain', 'md' => 'text/markdown',
        'json' => 'application/json', 'xml' => 'application/xml', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'gz' => 'application/gzip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'svg' => 'image/svg+xml',
    ];
    if (isset($knownMimes[$extension])) {
        return $knownMimes[$extension];
    }
    if ($mime === 'application/zip' || $mime === 'text/plain') {
        return 'application/octet-stream';
    }

    return $mime;
};

/**
 * 「在线上传」（直传）收尾：分片已在 chunk 阶段逐块转发给各 Notion 会话，此处完成会话并挂载到用户空间页面。
 * 全程不在服务器保存文件，故先入库占位（writing）→ complete + 挂载 → 回填副本转 active；
 * 全部目标都失败时删除占位记录并清理，避免留下无法重试的孤儿（没有暂存文件，队列重试也无法推进）。
 */
$finishDirectUpload = static function (
    string $token,
    array $meta,
    string $filename,
    int $size,
    string $mime,
    string $storageName,
    int $folderId
) use ($user, $fail, $context, $moderationExempt, $resolveDuplicate, &$cleanupChunkTokens): never {
    $queue = NotionWriteQueue::create();
    $pdo = Database::connection();
    $uploadId = 0;
    $resolved = ['filename' => $filename, 'handled' => '', 'freed_bytes' => 0, 'renamed_to' => '', 'duplicate' => null];

    try {
        $pdo->beginTransaction();
        try {
            // 与常规路径一致：锁定用户行，串行化同一用户的并发落库（配额与重名判定）
            $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute(['id' => (int) $user['id']]);
            $resolved = $resolveDuplicate((string) $meta['filename'], (string) ($meta['duplicate_action'] ?? ''), $folderId);
            $filename = $resolved['filename'];
            if ($resolved['handled'] !== '') {
                $context['duplicate'] = $resolved['handled'];
            }
            $context['renamed_to'] = $resolved['renamed_to'];
            $context['file']['name'] = $filename;

            $quotaBytes = userQuotaBytes($user);
            $usedBytes = userUsedBytes((int) $user['id']);
            if ($quotaBytes !== null && $usedBytes - $resolved['freed_bytes'] + $size > $quotaBytes) {
                throw new RuntimeException('个人空间不足：并发上传占用了剩余空间，本次上传已取消，请稍后重试。');
            }

            $pdo->prepare(
                'INSERT INTO uploads (user_id, scope, folder_id, ticket_id, filename, display_name, storage_name, file_size, mime_type, notion_file_upload_id, notion_block_id, status, overwrite_upload_id)
                 VALUES (:user_id, :scope, :folder_id, :ticket_id, :filename, :display_name, :storage_name, :file_size, :mime_type, :notion_file_upload_id, :notion_block_id, :status, :overwrite_upload_id)'
            )->execute([
                'user_id' => $user['id'],
                'scope' => 'drive',
                'folder_id' => $folderId > 0 ? $folderId : null,
                'ticket_id' => null,
                'filename' => $filename,
                'display_name' => $filename,
                'storage_name' => $storageName,
                'file_size' => $size,
                'mime_type' => $mime,
                // Notion 副本紧随其后回填，此处先留空占位（该列 NOT NULL，用空串而非 NULL）
                'notion_file_upload_id' => '',
                'notion_block_id' => null,
                'status' => NotionWriteQueue::STATUS_WRITING,
                'overwrite_upload_id' => $resolved['handled'] === 'overwrite' && $resolved['duplicate'] !== null
                    ? (int) $resolved['duplicate']['id']
                    : null,
            ]);
            $uploadId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    } catch (Throwable $exception) {
        $cleanupChunkTokens[$token] = true;
        // 主动抛出的 RuntimeException 都是给用户看的提示文案；其它异常（如 PDOException）不能把原始信息透给用户
        if ($exception instanceof RuntimeException) {
            $fail('direct_failed', $exception->getMessage() !== '' ? $exception->getMessage() : '在线上传失败，请稍后重试。');
        }
        $fail('direct_failed', '在线上传失败：保存文件记录时发生错误，请联系管理员。', [
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    // 按「账户类型 + 账户 id」重新取当前可用 Token（meta 中不保存 Token）
    $byKey = [];
    foreach (uploadTargets($user) as $target) {
        $byKey[(string) $target['type'] . '#' . ($target['account_id'] === null ? '0' : (int) $target['account_id'])] = $target;
    }

    $replicas = [];
    foreach ((array) ($meta['targets'] ?? []) as $session) {
        if (!is_array($session)) {
            continue;
        }
        $key = (string) ($session['account_type'] ?? '') . '#' . (($session['account_id'] ?? null) === null ? '0' : (int) $session['account_id']);
        if (!isset($byKey[$key])) {
            continue;
        }
        $target = $byKey[$key];
        $role = count($replicas) + 1;
        try {
            $client = new NotionClient((string) $target['token']);
            // complete 为非幂等 POST，放在自愈包装之外，避免页面被归档后重试时重复完成
            $client->completeStreamSession((string) $session['complete_url']);
            $replicas[] = notionWriteWithRepair(
                static function () use ($user, $target, $session, $client, $mime, $role): array {
                    $pageId = ensureUserSpacePage($user, $target, $client);
                    $attached = $client->attachFileUpload((string) $session['file_upload_id'], $pageId, $mime);
                    $blockId = (string) ($attached['results'][0]['id'] ?? '');
                    if ($blockId === '') {
                        throw new RuntimeException('Notion 未返回文件块。');
                    }

                    return [
                        'role' => $role,
                        'account_type' => (string) $target['type'],
                        'official_account_id' => $target['account_id'],
                        'notion_file_upload_id' => (string) $session['file_upload_id'],
                        'notion_block_id' => $blockId,
                    ];
                },
                $user,
                $target,
                [
                    'stage' => 'upload',
                    'user_id' => (int) $user['id'],
                    'account_type' => $target['type'],
                    'account_id' => $target['account_id'],
                ]
            );
        } catch (Throwable $exception) {
            Logger::warning('upload', '在线上传账户挂载失败。', [
                'user_id' => (int) $user['id'],
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
                'reason' => $exception->getMessage(),
            ]);
            raiseAdminAlert('error', sprintf('账户「%s」在线上传失败：%s', (string) $target['label'], $exception->getMessage()), [
                'stage' => 'upload',
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
            ]);
        }
    }

    if ($replicas === []) {
        // 没有任何副本写入成功：删除占位记录，避免留下永远无法写入的「待写入」条目
        $pdo->prepare('DELETE FROM uploads WHERE id = :id')->execute(['id' => $uploadId]);
        $cleanupChunkTokens[$token] = true;
        $fail('notion_error', '在线上传失败：存储服务暂时不可用，请稍后重试。');
    }

    if (!$queue->commitReplicas($uploadId, $replicas)) {
        // 记录已被并发删除：把刚写入的副本归档，避免孤儿文件块
        archiveWrittenReplicas($user, $replicas);
        $cleanupChunkTokens[$token] = true;
        $fail('upload_cancelled', '该文件在写入期间已被删除，已取消上传。');
    }

    // 覆盖同名旧文件：新文件已确认写入，此时才把它从列表移除并归档其 Notion 副本
    if ($resolved['handled'] === 'overwrite' && $resolved['duplicate'] !== null) {
        $previousId = (int) $resolved['duplicate']['id'];
        try {
            archiveUploadReplicas(['id' => $previousId], $user);
            Database::connection()->prepare("UPDATE uploads SET status = 'deleted' WHERE id = :id")->execute(['id' => $previousId]);
        } catch (Throwable $exception) {
            Logger::warning('upload', '覆盖旧文件时清理其 Notion 副本失败，旧副本可能残留。', ['previous_upload_id' => $previousId, 'reason' => $exception->getMessage()] + $context);
        }
    }

    // 直传无本地文件可审：风控开启且用户未豁免时登记为待人工复核并提醒管理员（关键词拦截已在 start 执行）
    if (!$moderationExempt) {
        try {
            $moderationService = ModerationService::create();
            if ($moderationService->enabled()) {
                $appealNo = $moderationService->record(
                    (int) $user['id'],
                    $uploadId,
                    $filename,
                    ModerationService::STAGE_FINISH,
                    Verdict::pending('在线上传无本地文件，转人工复核。')
                );
                Logger::warning('upload', '在线上传无法自动审核，已登记待人工复核', ['upload_id' => $uploadId, 'appeal_no' => $appealNo] + $context);
                raiseAdminAlert('warning', sprintf(
                    '在线上传内容待人工复核：用户 %s 上传《%s》（记录号 %s）',
                    (string) $user['username'],
                    $filename,
                    $appealNo
                ), ['upload_id' => $uploadId, 'user_id' => (int) $user['id'], 'filename' => $filename]);
            }
        } catch (Throwable $exception) {
            // 登记失败不阻断已完成的写入（与队列审核一致）
            Logger::error('upload', '在线上传降级审核登记失败，已按正常流程放行', ['error' => $exception->getMessage()] + $context);
        }
    }

    $cleanupChunkTokens[$token] = true;
    $message = match ($resolved['handled']) {
        'overwrite' => sprintf('已通过「在线上传」上传「%s」并覆盖了同名旧文件。', $filename),
        'rename' => sprintf('同名文件已存在，已自动编号为「%s」并在线上传完成。', $filename),
        default => sprintf('已通过「在线上传」上传「%s」。', $filename),
    };

    Logger::info('upload', sprintf('在线上传已完成：%s（%s）', $filename, formatBytes($size)), $context + [
        'code' => 'uploaded_online',
        'upload_id' => $uploadId,
        'storage_backend' => 'notion_stream',
    ]);

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'name' => $filename,
        'duplicate' => $resolved['handled'],
        'writing' => false,
        'online' => true,
        'upload_id' => $uploadId,
    ]);
};

try {
    try {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    } catch (Throwable $exception) {
        $fail('csrf_failed', $exception->getMessage(), [], 419);
    }

    // 强制绑定邮箱时，未绑定邮箱的普通用户不允许上传
    if (!mayUpload($user)) {
        $fail('email_required', '管理员已开启「强制绑定邮箱」，请先到账户管理页绑定并验证邮箱后再上传。', [], 403);
    }

    // 上传前的重名预检：只提交文件名，供前端决定「覆盖旧文件」还是「自动编号」
    if (($_POST['mode'] ?? '') === 'check') {
        // 优先用 items（[{name, folder_id}]）：文件夹上传时每个文件的目标目录不同，重名必须各按自己的文件夹判定。
        // 未传 items 时回退旧的 names 契约（单个 folder_id），兼容旧缓存前端。
        $items = json_decode((string) ($_POST['items'] ?? ''), true);
        if (!is_array($items) || $items === []) {
            $legacyFolderId = max(0, (int) ($_POST['folder_id'] ?? 0));
            $names = json_decode((string) ($_POST['names'] ?? '[]'), true);
            $items = [];
            foreach (is_array($names) ? $names : [] as $name) {
                $items[] = ['name' => $name, 'folder_id' => $legacyFolderId];
            }
        }
        $duplicates = [];
        $checked = [];
        foreach ($items as $item) {
            if (!is_string($item) && !is_array($item)) {
                continue;
            }
            $name = basename(trim((string) (is_array($item) ? ($item['name'] ?? '') : $item)));
            if ($name === '') {
                continue;
            }
            $folderId = max(0, (int) (is_array($item) ? ($item['folder_id'] ?? 0) : 0));
            // 同一「文件夹 + 文件名」只需查一次
            $key = $folderId . '|' . $name;
            if (isset($checked[$key])) {
                continue;
            }
            $checked[$key] = true;
            if (findActiveUploadByName((int) $user['id'], $name, $folderId) !== null) {
                $duplicates[] = ['name' => $name, 'folder_id' => $folderId];
            }
        }
        jsonResponse(['ok' => true, 'duplicates' => $duplicates]);
    }

    if ($_POST === [] && $_FILES === []) {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0) {
            $fail('post_too_large', sprintf(
                '请求体 %s 超过 PHP post_max_size（%s），数据未被服务器接收，请调小「上传分块大小」或调整 PHP 上传限制。',
                formatBytes($contentLength),
                (string) ini_get('post_max_size')
            ), ['content_length' => $contentLength], 413);
        }
        $fail('missing_file', '未接收到上传数据，请重新选择文件后重试。');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    // 工单附件复用同一套分块协议：scope=ticket 的文件归属该工单，不进「我的文件」、
    // 不计入个人配额、不走内容风控，工单关闭时从 Notion 删除；
    // start 阶段校验归属与状态，chunk 通过分块元数据透传，finish 阶段再复核一次
    $resolveTicketScope = static function (int $ticketId) use ($fail, $user): void {
        $statement = Database::connection()->prepare('SELECT id, user_id, status FROM tickets WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $ticketId]);
        $ticket = $statement->fetch();
        if (!is_array($ticket) || ((int) $ticket['user_id'] !== (int) $user['id'] && !isAdmin($user))) {
            $fail('ticket_not_found', '工单不存在或无权访问。', [], 404);
        }
        if (TicketService::isEnded((string) $ticket['status'])) {
            $fail('ticket_closed', '工单已结束，无法继续上传附件。', [], 409);
        }
    };

    // 0) write_status：批量查询「正在写入存储」的进度与状态，供前端在提交后继续刷新进度条。
    //    只查当前用户自己的记录，仅返回展示所需的 status / write_progress
    if ($action === 'write_status') {
        $ids = [];
        foreach (explode(',', (string) ($_POST['ids'] ?? '')) as $raw) {
            $value = (int) trim($raw);
            if ($value > 0) {
                $ids[$value] = $value;
            }
        }
        $ids = array_slice(array_values($ids), 0, 50);
        if ($ids === []) {
            jsonResponse(['ok' => true, 'items' => []]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = Database::connection()->prepare(
            "SELECT id, status, write_progress FROM uploads WHERE user_id = ? AND id IN ($placeholders)"
        );
        $statement->execute([(int) $user['id'], ...$ids]);
        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[(string) $row['id']] = [
                'status' => (string) $row['status'],
                'progress' => (int) $row['write_progress'],
            ];
        }

        jsonResponse(['ok' => true, 'items' => $items]);
    }

    // 1) start：校验文件信息并建立分块会话，返回后续分块所需的大小与总数
    if ($action === 'start') {
        $scope = (($_POST['scope'] ?? '') === 'ticket') ? 'ticket' : 'drive';
        $assertNotBanned($scope);
        // 前端在服务器额度不足时才会请求 direct=1；是否真正允许直传在拿到文件大小后另行判定
        $directRequested = $scope === 'drive' && (string) ($_POST['direct'] ?? '') === '1';
        $ticketId = 0;
        if ($scope === 'ticket') {
            $ticketId = is_numeric($_POST['ticket_id'] ?? null) ? (int) $_POST['ticket_id'] : 0;
            $resolveTicketScope($ticketId);
        }

        $filename = basename(trim(is_string($_POST['filename'] ?? null) ? $_POST['filename'] : ''));
        $size = is_numeric($_POST['size'] ?? null) ? (int) $_POST['size'] : 0;
        $context['file'] = ['name' => $filename, 'size' => $size];

        $extension = $validateFilename($filename, $size);
        $context['file']['extension'] = $extension;

        // 仅当全服额度确实不足以容纳本次上传时才启用直传，避免用户主动选择直传来跳过自动内容审核
        $direct = $directRequested && $serverDiskExceeded($size);
        if ($directRequested && !$direct) {
            $context['direct_downgraded'] = true;
        }

        // 上传前关键词拦截：命中即拒绝（不建立分块会话），并分配唯一申诉号供用户申诉；工单附件不走风控
        try {
            $moderationService = ModerationService::create();
            $blocked = ($scope === 'drive' && !$moderationExempt && $moderationService->enabled()) ? $moderationService->guardFilename($filename) : null;
            if ($blocked !== null && !$moderationService->hasResubmitPass((int) $user['id'], $filename)) {
                $appealNo = $moderationService->record(
                    (int) $user['id'],
                    null,
                    $filename,
                    ModerationService::STAGE_START,
                    $blocked
                );
                Logger::warning('upload', '关键词审核拦截，已拒绝上传', ['appeal_no' => $appealNo, 'matched' => $blocked->matched] + $context);
                raiseAdminAlert('warning', sprintf(
                    '内容风控拦截：用户 %s 上传《%s》被拒绝（申诉号 %s）',
                    (string) $user['username'],
                    $filename,
                    $appealNo
                ), [
                    'appeal_no' => $appealNo,
                    'user_id' => (int) $user['id'],
                    'filename' => $filename,
                    'matched' => $blocked->matched,
                ]);
                jsonResponse([
                    'ok' => false,
                    'code' => 'moderation_rejected',
                    'message' => sprintf(
                        '该文件命中违禁关键词，已拒绝上传。申诉号：%s，如认为误判，可凭此号联系管理员申诉。',
                        $appealNo
                    ),
                    'appeal_no' => $appealNo,
                    'reference' => $context['reference'],
                ], 422);
            }
        } catch (Throwable $exception) {
            // 风控不可用时不阻断正常上传，但记录错误便于管理员排查
            Logger::error('upload', '关键词审核执行失败，已按正常流程放行', ['error' => $exception->getMessage()] + $context);
        }

        // 上传目标文件夹：0 或无此字段表示根目录；越权或已被删除的文件夹一律按根目录处理；工单附件固定在根目录
        $folderId = 0;
        if ($scope === 'drive') {
            $folderId = max(0, (int) ($_POST['folder_id'] ?? 0));
            if ($folderId > 0 && folderRow((int) $user['id'], $folderId) === null) {
                $folderId = 0;
            }
        }

        // 工单附件不做重名处理，也不计入个人配额，但仍校验单文件上限与单张工单的附件数量
        $duplicateAction = is_string($_POST['duplicate_action'] ?? null) ? $_POST['duplicate_action'] : '';
        if ($scope === 'ticket') {
            $resolved = ['filename' => $filename, 'handled' => '', 'freed_bytes' => 0, 'renamed_to' => '', 'duplicate' => null];
            $assertTicketAttachmentCapacity($ticketId, $filename, $size);
        } else {
            $resolved = $resolveDuplicate($filename, $duplicateAction);
            $filename = $resolved['filename'];
            if ($resolved['handled'] !== '') {
                $context['duplicate'] = $resolved['handled'];
            }
            if ($resolved['renamed_to'] !== '') {
                $context['renamed_to'] = $resolved['renamed_to'];
            }
            $context['file']['name'] = $filename;

            $assertCapacity($filename, $size, $resolved['freed_bytes'], null, $direct);
        }

        // 大文件分块上传前先确认真的能写，避免传完才发现目标账户不可用
        $assertUploadAllowed();

        if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
            $fail('storage_unavailable', '服务器无法创建上传暂存目录，请联系管理员。', ['chunk_dir' => $chunkDir], 500);
        }
        $cleanupStaleChunks();

        $token = bin2hex(random_bytes(16));

        if ($direct) {
            // 重传锚定需要整份文件哈希，直传无本地文件无法校验，故命中锚定的文件名一律拒绝直传（改用常规上传）
            $anchorBlocked = false;
            if (!$moderationExempt) {
                try {
                    $anchorService = ModerationService::create();
                    $anchorBlocked = $anchorService->enabled()
                        && $anchorService->resubmitAnchorHash((int) $user['id'], $filename) !== null;
                } catch (Throwable $exception) {
                    Logger::error('upload', '直传重传锚定预检失败，已按正常流程放行', ['error' => $exception->getMessage()] + $context);
                }
            }
            if ($anchorBlocked) {
                $fail('direct_not_allowed', '该文件处于重传内容校验中，无法使用「在线上传」，请按常规方式重新上传原文件。');
            }

            // 分片大小：Notion 要求每片 5~20 MB（末片可小），单分片模式仅适用于 ≤20 MB 且不超过 PHP 单块上限的文件
            $phpChunk = uploadChunkSizeBytes();
            if ($size <= 20 * 1024 * 1024 && $size <= $phpChunk) {
                $partSize = $size;
                $total = 1;
            } else {
                if ($phpChunk < 5 * 1024 * 1024) {
                    $fail('direct_unavailable', '服务器 PHP 上传限制过小，无法使用「在线上传」，请联系管理员调大 upload_max_filesize / post_max_size。');
                }
                $partSize = max(5 * 1024 * 1024, min(20 * 1024 * 1024, $phpChunk));
                $total = max(1, (int) ceil($size / $partSize));
                if ($total > 1000) {
                    $fail('file_too_large', '文件过大，超过 Notion 单文件分片上限（1000 片），无法使用「在线上传」。', ['parts' => $total, 'part_size' => $partSize], 413);
                }
            }
            $multi = $total > 1;

            // 为每个目标账户建立 Notion 上传会话；后续每收一块立即转发给这些会话（meta 中不保存 Token）
            $mime = mimeForExtension($extension);
            $storageName = directStorageName((int) $user['id'], $filename);
            $sessions = [];
            foreach (uploadTargets($user) as $target) {
                $client = new NotionClient((string) $target['token']);
                $session = $client->initStreamSession($storageName, $mime, $multi, $total);
                $sessions[] = [
                    'account_type' => (string) $target['type'],
                    'account_id' => $target['account_id'],
                    'file_upload_id' => $session['id'],
                    'upload_url' => $session['upload_url'],
                    'complete_url' => $session['complete_url'],
                ];
            }
            if ($sessions === []) {
                $fail('notion_not_configured', notionTargetsMissingReason($user));
            }

            $writeChunkMeta($token, [
                'user_id' => (int) $user['id'],
                'filename' => $filename,
                'size' => $size,
                'extension' => $extension,
                'chunk_size' => $partSize,
                'total' => $total,
                'received' => 0,
                'duplicate_action' => $duplicateAction,
                'folder_id' => $folderId,
                'scope' => 'drive',
                'ticket_id' => 0,
                'created_at' => time(),
                'direct' => true,
                'multi' => $multi,
                'part_size' => $partSize,
                'storage_name' => $storageName,
                'mime' => $mime,
                'targets' => $sessions,
            ]);

            jsonResponse([
                'ok' => true,
                'upload_token' => $token,
                'chunk_size' => $partSize,
                'total' => $total,
                'name' => $filename,
                'duplicate' => $resolved['handled'],
                'online' => true,
            ]);
        }

        $chunkSize = uploadChunkSizeBytes();
        $total = max(1, (int) ceil($size / $chunkSize));

        if (file_put_contents($chunkPartPath($token), '', LOCK_EX) === false) {
            $fail('storage_unavailable', '服务器无法创建上传暂存文件，请联系管理员。', ['chunk_dir' => $chunkDir], 500);
        }
        $writeChunkMeta($token, [
            'user_id' => (int) $user['id'],
            'filename' => $filename,
            'size' => $size,
            'extension' => $extension,
            'chunk_size' => $chunkSize,
            'total' => $total,
            'received' => 0,
            'duplicate_action' => $duplicateAction,
            'folder_id' => $folderId,
            'scope' => $scope,
            'ticket_id' => $ticketId,
            'created_at' => time(),
        ]);

        jsonResponse([
            'ok' => true,
            'upload_token' => $token,
            'chunk_size' => $chunkSize,
            'total' => $total,
            'name' => $filename,
            'duplicate' => $resolved['handled'],
        ]);
    }

    // 2) chunk：逐块追加写入，严格按顺序接收
    if ($action === 'chunk') {
        $token = $assertChunkToken(is_string($_POST['upload_token'] ?? null) ? $_POST['upload_token'] : '');
        $meta = $requireChunkMeta($token);

        $received = (int) $meta['received'];
        $total = (int) $meta['total'];
        $index = is_numeric($_POST['index'] ?? null) ? (int) $_POST['index'] : -1;
        if ($index !== $received) {
            Logger::warning('upload', '上传分块顺序不符，已拒绝该分块。', $context + [
                'code' => 'chunk_out_of_order',
                'expected_index' => $received,
                'got_index' => $index,
            ]);
            jsonResponse([
                'ok' => false,
                'code' => 'chunk_out_of_order',
                'message' => '上传分块顺序异常，请重新开始本次上传。',
                'received' => $received,
                'total' => $total,
                'reference' => $reference,
            ], 409);
        }

        $chunk = $_FILES['chunk'] ?? null;
        if (!is_array($chunk)) {
            $fail('missing_chunk', '请求中缺少 chunk 字段，请刷新页面后重试。');
        }
        $phpError = (int) ($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($phpError !== UPLOAD_ERR_OK) {
            $cleanupChunkTokens[$token] = true;
            $fail('php_upload_error', sprintf('分块上传失败（PHP 错误码 %d），本次上传已终止，请重新上传该文件。', $phpError), ['php_error_code' => $phpError]);
        }

        $temporaryPath = (string) $chunk['tmp_name'];
        if (!is_uploaded_file($temporaryPath)) {
            $cleanupChunkTokens[$token] = true;
            $fail('tmp_file_invalid', '分块数据未通过 PHP 安全检查，本次上传已终止，请重新上传该文件。', ['tmp_name' => $temporaryPath]);
        }

        // 直传：每收到一块就立即转发给各 Notion 会话，不在服务器拼接整份文件（服务器最多缓冲当前这一块）
        if (!empty($meta['direct'])) {
            $incomingBytes = (int) $chunk['size'];
            $partSize = (int) ($meta['part_size'] ?? 0);
            $expectedBytes = $partSize > 0 ? min($partSize, max(0, (int) $meta['size'] - $index * $partSize)) : 0;
            if ($incomingBytes <= 0 || $incomingBytes !== $expectedBytes) {
                $cleanupChunkTokens[$token] = true;
                $fail('chunk_size_mismatch', '上传数据校验失败，本次上传已终止，请重新上传该文件。', [
                    'incoming_bytes' => $incomingBytes,
                    'expected_bytes' => $expectedBytes,
                ]);
            }

            // 按「账户类型 + 账户 id」重新取当前可用 Token（meta 中不保存 Token）
            $byKey = [];
            foreach (uploadTargets($user) as $target) {
                $byKey[(string) $target['type'] . '#' . ($target['account_id'] === null ? '0' : (int) $target['account_id'])] = (string) $target['token'];
            }

            $storageName = (string) ($meta['storage_name'] ?? '');
            $mime = (string) ($meta['mime'] ?? mimeForExtension((string) $meta['extension']));
            $partNumber = !empty($meta['multi']) ? $index + 1 : null;
            $sent = 0;
            $lastError = '';
            foreach ((array) ($meta['targets'] ?? []) as $session) {
                if (!is_array($session)) {
                    continue;
                }
                $key = (string) ($session['account_type'] ?? '') . '#' . (($session['account_id'] ?? null) === null ? '0' : (int) $session['account_id']);
                if (!isset($byKey[$key])) {
                    continue;
                }
                try {
                    (new NotionClient($byKey[$key]))->sendStreamPart((string) $session['upload_url'], $temporaryPath, $storageName, $mime, $partNumber);
                    $sent++;
                } catch (Throwable $exception) {
                    $lastError = $exception->getMessage();
                    Logger::warning('upload', '在线上传分片转发失败。', [
                        'index' => $index,
                        'account_type' => $session['account_type'] ?? '',
                        'account_id' => $session['account_id'] ?? null,
                        'reason' => $lastError,
                    ] + $context);
                }
            }
            if ($sent === 0) {
                // 重发同一分片只会覆盖该分片，可安全重试：返回 5xx 让前端重试本片，避免整份文件重新上传
                $fail('notion_error', '在线上传失败：无法把数据写入 Notion，请稍后重试。', [
                    'index' => $index,
                    'detail' => mb_substr($lastError, 0, 200),
                ], 503);
            }

            $meta['received'] = $received + 1;
            $writeChunkMeta($token, $meta);

            jsonResponse(['ok' => true, 'received' => (int) $meta['received'], 'total' => $total]);
        }

        $partPath = $chunkPartPath($token);
        $currentBytes = is_file($partPath) ? (int) filesize($partPath) : 0;
        $incomingBytes = (int) $chunk['size'];
        $expectedBytes = (int) $meta['size'];
        if ($incomingBytes <= 0 || $currentBytes + $incomingBytes > $expectedBytes) {
            $cleanupChunkTokens[$token] = true;
            $fail('chunk_size_mismatch', '上传数据校验失败，本次上传已终止，请重新上传该文件。', [
                'received_bytes' => $currentBytes,
                'incoming_bytes' => $incomingBytes,
                'expected_bytes' => $expectedBytes,
            ]);
        }

        $source = fopen($temporaryPath, 'rb');
        $target = fopen($partPath, 'ab');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            $cleanupChunkTokens[$token] = true;
            $fail('storage_unavailable', '服务器无法写入上传暂存文件，请联系管理员。', ['chunk_dir' => $chunkDir], 500);
        }
        flock($target, LOCK_EX);
        $copied = stream_copy_to_stream($source, $target);
        fflush($target);
        flock($target, LOCK_UN);
        fclose($source);
        fclose($target);

        if ($copied === false || $copied !== $incomingBytes) {
            $cleanupChunkTokens[$token] = true;
            $fail('storage_unavailable', '上传分块写入不完整，本次上传已终止，请重新上传该文件。', [
                'copied_bytes' => $copied === false ? -1 : $copied,
                'incoming_bytes' => $incomingBytes,
            ]);
        }

        $meta['received'] = $received + 1;
        $writeChunkMeta($token, $meta);

        jsonResponse(['ok' => true, 'received' => (int) $meta['received'], 'total' => $total]);
    }

    // 3) finish：分块齐全后双写 Notion 并落库
    if ($action === 'finish') {
        $token = $assertChunkToken(is_string($_POST['upload_token'] ?? null) ? $_POST['upload_token'] : '');
        $meta = $requireChunkMeta($token);

        $scope = ((string) ($meta['scope'] ?? 'drive') === 'ticket') ? 'ticket' : 'drive';
        $assertNotBanned($scope);
        $ticketId = 0;
        if ($scope === 'ticket') {
            $ticketId = (int) ($meta['ticket_id'] ?? 0);
            $resolveTicketScope($ticketId);
        }

        $total = (int) $meta['total'];
        $received = (int) $meta['received'];
        if ($received !== $total) {
            $fail('upload_incomplete', sprintf('文件分块尚未全部上传（%d/%d），请重试。', $received, $total), [], 409);
        }

        $filename = (string) $meta['filename'];
        $size = (int) $meta['size'];
        $context['file'] = ['name' => $filename, 'size' => $size, 'extension' => (string) $meta['extension']];
        $isDirect = !empty($meta['direct']);

        // 直传不在服务器保存文件，故跳过整份文件的字节数校验；其余校验与常规路径一致
        if (!$isDirect) {
            $partPath = $chunkPartPath($token);
            $actualBytes = is_file($partPath) ? (int) filesize($partPath) : 0;
            if ($actualBytes !== $size) {
                $cleanupChunkTokens[$token] = true;
                $fail('upload_incomplete', '上传数据不完整，本次上传已终止，请重新上传该文件。', [
                    'actual_bytes' => $actualBytes,
                    'expected_bytes' => $size,
                ]);
            }
        }

        // 分块已齐全且字节数完全一致，此后失败无法续传：统一登记 token，确保后续校验失败也不会残留 .part（可达 5 GB）
        $cleanupChunkTokens[$token] = true;

        // 重新校验：分块期间文件名与配额可能已被并发上传改变
        $extension = $validateFilename($filename, $size);
        // 重名裁决必须先知道目标文件夹（查重按文件夹隔离），故把文件夹解析提到 $resolveDuplicate 之前
        $folderId = 0;
        if ($scope === 'drive') {
            // 分块期间目标文件夹可能已被删除，落库前再确认一次，失效则落到根目录而不是让整条 INSERT 失败；工单附件固定在根目录
            $folderId = max(0, (int) ($meta['folder_id'] ?? 0));
            if ($folderId > 0 && folderRow((int) $user['id'], $folderId) === null) {
                $folderId = 0;
            }
        }
        if ($scope === 'ticket') {
            // 工单附件不做重名处理，也不计入个人配额，但仍复核单文件上限与单张工单的附件数量
            $resolved = ['filename' => $filename, 'handled' => '', 'freed_bytes' => 0, 'renamed_to' => '', 'duplicate' => null];
            $assertTicketAttachmentCapacity($ticketId, $filename, $size, $token, $isDirect);
        } else {
            $resolved = $resolveDuplicate($filename, (string) ($meta['duplicate_action'] ?? ''), $folderId);
            $filename = $resolved['filename'];
            if ($resolved['handled'] !== '') {
                $context['duplicate'] = $resolved['handled'];
            }
            if ($resolved['renamed_to'] !== '') {
                $context['renamed_to'] = $resolved['renamed_to'];
            }
            $context['file']['name'] = $filename;

            // 本次会话的元数据仍在，须排除自身，否则会把本文件重复计入在途占用（直传会话同样按此排除后单独计入 $size）
            $assertCapacity($filename, $size, $resolved['freed_bytes'], $token, $isDirect);
        }

        if (uploadTargets($user) === []) {
            $assertUploadAllowed();
        }

        // 在线上传：分片已在 chunk 阶段逐块转发给 Notion，此处完成会话、挂载并落库（全程不占服务器暂存）
        if ($isDirect) {
            $directMime = (string) ($meta['mime'] ?? mimeForExtension($extension));
            $context['file']['mime'] = $directMime;
            $finishDirectUpload(
                $token,
                $meta,
                $filename,
                $size,
                $directMime,
                (string) ($meta['storage_name'] ?? directStorageName((int) $user['id'], $filename)),
                $folderId
            );
        }

        $mime = $computeMime($partPath, $extension);
        $context['file']['mime'] = $mime;

        // 管理员放行重传后的内容锚定校验：重传文件必须与申诉记录中的原文件哈希一致，
        // 防止借「同名不同内容」绕过内容审核上传真违规内容（锚定哈希来自放行记录的 content_hash）。
        // 与其它风控调用一致：风控关闭 / 用户豁免时不做校验，且校验本身异常时不阻断正常上传
        $contentHash = '';
        $matchedResubmitAnchor = false;
        if ($scope === 'drive' && !$moderationExempt) {
            try {
                $moderationService = ModerationService::create();
                if ($moderationService->enabled()) {
                    $anchorHash = $moderationService->resubmitAnchorHash((int) $user['id'], (string) $meta['filename']);
                    if ($anchorHash !== null) {
                        $contentHash = is_file($partPath) ? (string) (hash_file('sha256', $partPath) ?: '') : '';
                        if ($contentHash === '' || !hash_equals($anchorHash, $contentHash)) {
                            $fail('resubmit_content_mismatch', '重传文件内容与申诉记录中的原文件不一致，无法重传。请上传与原文件相同的内容后重试。', [
                                'anchor_sha256' => $anchorHash,
                                'actual_sha256' => $contentHash,
                            ]);
                        }
                        $matchedResubmitAnchor = true;
                    }
                }
            } catch (Throwable $exception) {
                // 风控不可用不阻断上传：记录错误便于排查（与 start / finish 其它风控调用一致）
                Logger::error('upload', '重传内容锚定校验执行失败，已按正常流程放行', ['error' => $exception->getMessage()] + $context);
            }
        }

        // 异步写入存储：先把文件收进服务器暂存区，再入库为「正在写入存储」（writing），浏览器立刻拿到成功响应；
        // 真正写 Notion 与内容审核交给后台队列（NotionWriteQueue），用户不必等它写完
        $storageName = uniqueStorageName((int) $user['id'], $filename, $partPath);
        $queue = NotionWriteQueue::create();
        $uploadId = 0;

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            try {
                // 锁定该用户行，串行化同一用户的并发落库：配额与重名判定若只在上传前读一次，
                // 同一用户的两次并发上传会各自读到未含对方的状态而同时通过（TOCTOU 超卖 / 同名重复）
                $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute(['id' => (int) $user['id']]);
                if ($scope === 'drive') {
                    // 持锁后以原始文件名重新裁决重名，避免并发同名上传各写一条可用文件
                    $resolved = $resolveDuplicate((string) $meta['filename'], (string) ($meta['duplicate_action'] ?? ''), $folderId);
                    $filename = $resolved['filename'];
                    if ($resolved['handled'] !== '') {
                        $context['duplicate'] = $resolved['handled'];
                    }
                    $context['renamed_to'] = $resolved['renamed_to'];
                    $context['file']['name'] = $filename;

                    $quotaBytes = userQuotaBytes($user);
                    $usedBytes = userUsedBytes((int) $user['id']);
                    if ($quotaBytes !== null && $usedBytes - $resolved['freed_bytes'] + $size > $quotaBytes) {
                        throw new RuntimeException('个人空间不足：并发上传占用了剩余空间，本次上传已取消，请稍后重试。');
                    }
                }

                $pdo->prepare(
                    'INSERT INTO uploads (user_id, scope, folder_id, ticket_id, filename, display_name, storage_name, file_size, mime_type, notion_file_upload_id, notion_block_id, status, overwrite_upload_id)
                     VALUES (:user_id, :scope, :folder_id, :ticket_id, :filename, :display_name, :storage_name, :file_size, :mime_type, :notion_file_upload_id, :notion_block_id, :status, :overwrite_upload_id)'
                )->execute([
                    'user_id' => $user['id'],
                    'scope' => $scope,
                    'folder_id' => $folderId > 0 ? $folderId : null,
                    'ticket_id' => $scope === 'ticket' ? $ticketId : null,
                    'filename' => $filename,
                    'display_name' => $filename,
                    'storage_name' => $storageName,
                    'file_size' => $size,
                    'mime_type' => $mime,
                    // Notion 副本由后台队列写入，此处先留空占位（该列 NOT NULL，用空串而非 NULL）
                    'notion_file_upload_id' => '',
                    'notion_block_id' => null,
                    'status' => NotionWriteQueue::STATUS_WRITING,
                    // 覆盖上传：记下被覆盖的旧文件，等本文件写入成功后再归档它的副本（失败则恢复旧文件）
                    'overwrite_upload_id' => $resolved['handled'] === 'overwrite' && $resolved['duplicate'] !== null
                        ? (int) $resolved['duplicate']['id']
                        : null,
                ]);
                $uploadId = (int) $pdo->lastInsertId();

                // 暂存文件名带 upload id，必须先拿到 id 才能落位；移动失败即抛异常回滚本条记录
                $queue->stage((int) $user['id'], $uploadId, $partPath);

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            // 入库或暂存失败：清掉可能已落位的暂存文件，避免孤儿文件占着服务器空间
            $queue->discard((int) $user['id'], $uploadId);
            throw $exception;
        }

        $cleanupChunkTokens[$token] = true;

        // 覆盖同名旧文件：这里只把它从列表移除，Notion 副本留到新文件写入成功后再归档（见 NotionWriteQueue）。
        // 若在此处就归档，会出现「新的还没写进 Notion、旧的已从 Notion 删掉」的空窗，写入失败即两代内容都不在 Notion
        if ($resolved['handled'] === 'overwrite' && $resolved['duplicate'] !== null) {
            Database::connection()->prepare("UPDATE uploads SET status = 'deleted' WHERE id = :id")
                ->execute(['id' => (int) $resolved['duplicate']['id']]);
        }

        // 重传凭证一次性消费：锚定校验通过且文件已成功落库后，撤销该文件名的重传放行，
        // 避免该文件名被永久锁定为原哈希（此后同名的新内容可正常上传并重新进入审核）
        if ($matchedResubmitAnchor) {
            try {
                ModerationService::create()->consumeResubmitPass((int) $user['id'], (string) $meta['filename']);
            } catch (Throwable $exception) {
                Logger::warning('upload', '重传凭证消费失败，该文件名可能仍被锚定', ['error' => $exception->getMessage()] + $context);
            }
        }

        $message = match ($resolved['handled']) {
            'overwrite' => sprintf('已上传「%s」并覆盖了同名旧文件，正在写入存储。', $filename),
            'rename' => sprintf('同名文件已存在，已自动编号为「%s」并上传，正在写入存储。', $filename),
            default => sprintf('已上传「%s」，正在写入存储。', $filename),
        };

        Logger::info('upload', sprintf('上传已入库，等待后台写入存储：%s（%s）', $filename, formatBytes($size)), $context + [
            'code' => 'uploaded',
            'upload_id' => $uploadId,
            'storage_backend' => 'notion',
        ]);

        // 浏览器先拿到成功响应，随后在同一进程内立即开始写入 Notion（PHP-FPM 下此刻已与浏览器断开）；
        // 内容审核与暂存文件清理都在队列里完成，非 FPM 环境退化为「写完才结束请求」
        jsonResponseThen([
            'ok' => true,
            'message' => $message,
            'name' => $filename,
            'duplicate' => $resolved['handled'],
            'writing' => true,
            // 供前端据此轮询写入进度（write_status）
            'upload_id' => $uploadId,
        ], static function () use ($queue, $uploadId): void {
            $queue->process($uploadId);
        });
    }

    $fail('invalid_action', '上传请求缺少有效的 action 参数，请刷新页面后重试。', [], 400);
} catch (Throwable $exception) {
    $notionStatus = $exception instanceof NotionApiException ? $exception->status() : 0;
    $code = 'unexpected_error';
    if ($exception instanceof PDOException) {
        $code = 'database_error';
    } elseif ($exception instanceof NotionApiException) {
        $code = 'notion_error';
    }

    $message = match (true) {
        $exception instanceof PDOException => '文件已上传到 Notion，但保存本地记录时发生错误，请联系管理员。',
        $exception instanceof NotionApiException && $notionStatus > 0 => sprintf('Notion 接口返回 HTTP %d：%s', $notionStatus, $exception->getMessage()),
        $exception instanceof NotionApiException => 'Notion 请求失败：' . $exception->getMessage(),
        // 本文件与 helpers.php 中主动抛出的 RuntimeException 都是给用户看的提示文案，可以原样展示
        $exception instanceof RuntimeException => $exception->getMessage() !== '' ? $exception->getMessage() : '上传过程中发生未知错误。',
        default => '服务器处理上传时发生未知错误，请稍后重试，或凭参考号在「运行日志」中查询详情。',
    };

    Logger::error('upload', $exception->getMessage(), $context + [
        'code' => $code,
        'exception' => $exception::class,
        'notion_status' => $notionStatus,
        'file_line' => $exception->getFile() . ':' . $exception->getLine(),
        'trace' => explode("\n", $exception->getTraceAsString())[0] ?? '',
    ]);

    jsonResponse(['ok' => false, 'code' => $code, 'message' => $message, 'reference' => $reference], 422);
}