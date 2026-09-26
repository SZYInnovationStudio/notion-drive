<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\NotionApiException;
use NotionDrive\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'code' => 'method_not_allowed', 'message' => '请求方法不受支持。'], 405);
}

$user = Auth::requireLogin(true);

/** 参考号：便于在「运行日志」中定位本次上传失败记录。 */
$reference = strtoupper(bin2hex(random_bytes(4)));
$context = [
    'reference' => $reference,
    'user_id' => (int) $user['id'],
    'username' => (string) $user['username'],
    'client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
];

/**
 * 记录并响应用户可读的具体失败原因。
 *
 * @param array<string,mixed> $extra
 */
$fail = static function (string $code, string $message, array $extra = [], int $status = 422) use (&$context): never {
    Logger::warning('upload', $message, $extra + $context + ['code' => $code]);
    jsonResponse(['ok' => false, 'code' => $code, 'message' => $message, 'reference' => $context['reference']], $status);
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

/** 校验文件名与大小，返回小写扩展名。 */
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
        $fail('unsupported_type', sprintf('文件 %s 没有扩展名，无法判断类型；Notion 需要可识别的扩展名。', $filename));
    }
    if (!in_array($extension, $supported, true)) {
        $fail('unsupported_type', sprintf(
            '文件类型 .%s 不在 Notion 支持范围内（支持文档、图片、音视频、压缩包及 CAD/3D 等 %d 种扩展名）。',
            $extension,
            count($supported)
        ), ['extension' => $extension]);
    }

    return $extension;
};

/**
 * 处理重名：确认覆盖则保留原名并释放旧文件占用，否则自动编号（1.txt → 1(1).txt）。
 *
 * @return array{filename:string,handled:string,freed_bytes:int,renamed_to:string,duplicate:?array}
 */
$resolveDuplicate = static function (string $filename, string $duplicateAction) use ($user): array {
    $duplicate = findActiveUploadByName((int) $user['id'], $filename);
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

    $renamed = nextAvailableFilename((int) $user['id'], $filename);

    return ['filename' => $renamed, 'handled' => 'rename', 'freed_bytes' => 0, 'renamed_to' => $renamed, 'duplicate' => null];
};

/** 校验单文件上限与个人容量（覆盖同名文件时，旧文件占用的空间会被释放）。 */
$assertCapacity = static function (string $filename, int $size, int $freedBytes) use ($fail, $user): void {
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
    $requiredBytes = $usedBytes - $freedBytes + $size;
    if ($quotaBytes !== null && $requiredBytes > $quotaBytes) {
        $fail('quota_exceeded', sprintf(
            '个人空间不足：已用 %s / 共 %s，剩余 %s，本次需要 %s，请联系管理员调整容量。',
            formatBytes($usedBytes),
            formatBytes($quotaBytes),
            formatBytes(max(0, $quotaBytes - $usedBytes + $freedBytes)),
            formatBytes($size)
        ), ['used_bytes' => $usedBytes, 'quota_bytes' => $quotaBytes, 'size' => $size, 'freed_bytes' => $freedBytes]);
    }
};

/**
 * 上传可行性前置检查：套餐状态与可用账户。
 *
 * 在 start 阶段就拦下不可能成功的上传，避免用户把整个大文件传完才收到失败提示。
 */
$assertUploadAllowed = static function () use ($fail, $user): void {
    $blocked = uploadBlockedReason($user);
    if ($blocked !== '') {
        $fail('upload_blocked', $blocked);
    }
    if (uploadTargets($user) === []) {
        $fail('notion_not_configured', '当前账号尚未完成 Notion 账户绑定，请先到「账户管理」中绑定后再上传。');
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
        $names = json_decode((string) ($_POST['names'] ?? '[]'), true);
        $duplicates = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $name = basename(trim((string) $name));
            if ($name === '' || in_array($name, $duplicates, true)) {
                continue;
            }
            if (findActiveUploadByName((int) $user['id'], $name) !== null) {
                $duplicates[] = $name;
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

    // 1) start：校验文件信息并建立分块会话，返回后续分块所需的大小与总数
    if ($action === 'start') {
        $filename = basename(trim(is_string($_POST['filename'] ?? null) ? $_POST['filename'] : ''));
        $size = is_numeric($_POST['size'] ?? null) ? (int) $_POST['size'] : 0;
        $context['file'] = ['name' => $filename, 'size' => $size];

        $extension = $validateFilename($filename, $size);
        $context['file']['extension'] = $extension;

        $duplicateAction = is_string($_POST['duplicate_action'] ?? null) ? $_POST['duplicate_action'] : '';
        $resolved = $resolveDuplicate($filename, $duplicateAction);
        $filename = $resolved['filename'];
        if ($resolved['handled'] !== '') {
            $context['duplicate'] = $resolved['handled'];
        }
        if ($resolved['renamed_to'] !== '') {
            $context['renamed_to'] = $resolved['renamed_to'];
        }
        $context['file']['name'] = $filename;

        $assertCapacity($filename, $size, $resolved['freed_bytes']);

        // 大文件分块上传前先确认真的能写，避免传完才发现目标账户不可用
        $assertUploadAllowed();

        if (!is_dir($chunkDir) && !mkdir($chunkDir, 0775, true) && !is_dir($chunkDir)) {
            $fail('storage_unavailable', '服务器无法创建上传暂存目录，请联系管理员。', ['chunk_dir' => $chunkDir], 500);
        }
        $cleanupStaleChunks();

        $token = bin2hex(random_bytes(16));
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

        $total = (int) $meta['total'];
        $received = (int) $meta['received'];
        if ($received !== $total) {
            $fail('upload_incomplete', sprintf('文件分块尚未全部上传（%d/%d），请重试。', $received, $total), [], 409);
        }

        $partPath = $chunkPartPath($token);
        $filename = (string) $meta['filename'];
        $size = (int) $meta['size'];
        $context['file'] = ['name' => $filename, 'size' => $size, 'extension' => (string) $meta['extension']];

        $actualBytes = is_file($partPath) ? (int) filesize($partPath) : 0;
        if ($actualBytes !== $size) {
            $cleanupChunkTokens[$token] = true;
            $fail('upload_incomplete', '上传数据不完整，本次上传已终止，请重新上传该文件。', [
                'actual_bytes' => $actualBytes,
                'expected_bytes' => $size,
            ]);
        }

        // 重新校验：分块期间文件名与配额可能已被并发上传改变
        $extension = $validateFilename($filename, $size);
        $resolved = $resolveDuplicate($filename, (string) ($meta['duplicate_action'] ?? ''));
        $filename = $resolved['filename'];
        if ($resolved['handled'] !== '') {
            $context['duplicate'] = $resolved['handled'];
        }
        if ($resolved['renamed_to'] !== '') {
            $context['renamed_to'] = $resolved['renamed_to'];
        }
        $context['file']['name'] = $filename;

        $assertCapacity($filename, $size, $resolved['freed_bytes']);

        if (uploadTargets($user) === []) {
            $cleanupChunkTokens[$token] = true;
            $assertUploadAllowed();
        }

        $mime = $computeMime($partPath, $extension);
        $context['file']['mime'] = $mime;

        // 同步双写：每个目标账户各存一份，至少一份成功即视为成功（失败会自动告警管理员）
        $stored = writeNotionReplicas($user, $partPath, $filename, $mime, $size);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO uploads (user_id, filename, display_name, storage_name, file_size, mime_type, notion_file_upload_id, notion_block_id)
                 VALUES (:user_id, :filename, :display_name, :storage_name, :file_size, :mime_type, :notion_file_upload_id, :notion_block_id)'
            )->execute([
                'user_id' => $user['id'],
                'filename' => $filename,
                'display_name' => $filename,
                'storage_name' => $stored['storage_name'],
                'file_size' => $size,
                'mime_type' => $mime,
                'notion_file_upload_id' => (string) ($stored['replicas'][0]['notion_file_upload_id'] ?? ''),
                'notion_block_id' => (string) ($stored['replicas'][0]['notion_block_id'] ?? ''),
            ]);
            $uploadId = (int) $pdo->lastInsertId();

            $replicaInsert = $pdo->prepare(
                'INSERT INTO upload_replicas (upload_id, role, account_type, official_account_id, notion_file_upload_id, notion_block_id)
                 VALUES (:upload_id, :role, :account_type, :official_account_id, :notion_file_upload_id, :notion_block_id)'
            );
            foreach ($stored['replicas'] as $replica) {
                $replicaInsert->execute([
                    'upload_id' => $uploadId,
                    'role' => (int) $replica['role'],
                    'account_type' => (string) $replica['account_type'],
                    'official_account_id' => $replica['official_account_id'],
                    'notion_file_upload_id' => (string) $replica['notion_file_upload_id'],
                    'notion_block_id' => (string) $replica['notion_block_id'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $cleanupChunkTokens[$token] = true;

        // 新文件已就位后再清理旧文件，避免上传失败却先删掉旧文件
        if ($resolved['handled'] === 'overwrite' && $resolved['duplicate'] !== null) {
            $duplicate = $resolved['duplicate'];
            try {
                archiveUploadReplicas($duplicate, $user);
                Database::connection()->prepare("UPDATE uploads SET status = 'deleted' WHERE id = :id")
                    ->execute(['id' => (int) $duplicate['id']]);
            } catch (Throwable $exception) {
                Logger::warning('upload', sprintf(
                    '新文件 %s 已上传，但清理被覆盖的旧文件（#%d）失败：%s',
                    $filename,
                    (int) $duplicate['id'],
                    $exception->getMessage()
                ), $context + ['code' => 'overwrite_cleanup_failed', 'old_upload_id' => (int) $duplicate['id']]);
            }
        }

        $message = match ($resolved['handled']) {
            'overwrite' => sprintf('已上传「%s」并覆盖了同名旧文件。', $filename),
            'rename' => sprintf('同名文件已存在，已自动编号为「%s」并上传。', $filename),
            default => sprintf('已上传「%s」并附加到 Notion。', $filename),
        };

        Logger::info('upload', sprintf('上传成功：%s（%s，%d 份副本）', $filename, formatBytes($size), count($stored['replicas'])), $context + [
            'code' => 'uploaded',
            'upload_id' => $uploadId,
            'replica_count' => count($stored['replicas']),
        ]);
        jsonResponse(['ok' => true, 'message' => $message, 'name' => $filename, 'duplicate' => $resolved['handled']]);
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