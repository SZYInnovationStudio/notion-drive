<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\NotionClient;
use NotionDrive\Security;

$user = Auth::requireLogin($_SERVER['REQUEST_METHOD'] !== 'GET');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
// 预览模式：内联输出（inline），仅对白名单内、且后台已开启对应类型的文件生效；其余一律按下载处理
$inlineRequested = (string) ($_GET['inline'] ?? '') === '1';
$previewMode = false;
$statement = Database::connection()->prepare(
    "SELECT up.*, u.username, u.notion_token_encrypted, u.notion_account_source
     FROM uploads up JOIN users u ON u.id = up.user_id
     WHERE up.id = :id AND up.status = 'active' LIMIT 1"
);
$statement->execute(['id' => $id]);
$file = $statement->fetch();

if (!$file) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(404);
        exit('文件不存在。');
    }
    jsonResponse(['ok' => false, 'message' => '文件不存在或无权访问。'], 404);
}

// 工单附件：上传者本人、工单归属者与管理员都可下载；改名/删除仍限上传者本人与管理员
$allowedTicketDownload = false;
if ((string) ($file['scope'] ?? 'drive') === 'ticket' && $_SERVER['REQUEST_METHOD'] === 'GET' && (int) ($file['ticket_id'] ?? 0) > 0) {
    $ownerStatement = Database::connection()->prepare('SELECT user_id FROM tickets WHERE id = :id LIMIT 1');
    $ownerStatement->execute(['id' => (int) $file['ticket_id']]);
    $allowedTicketDownload = (int) $ownerStatement->fetchColumn() === (int) $user['id'];
}

if ((int) $file['user_id'] !== (int) $user['id'] && !isAdmin($user) && !$allowedTicketDownload) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(404);
        exit('文件不存在。');
    }
    jsonResponse(['ok' => false, 'message' => '文件不存在或无权访问。'], 404);
}

/** 文件所属用户：下载与删除都要用他（而不是当前访问者）的 Token 去访问 Notion。 */
$owner = [
    'id' => (int) $file['user_id'],
    'notion_token_encrypted' => $file['notion_token_encrypted'] ?? null,
    'notion_account_source' => $file['notion_account_source'] ?? null,
];

// 封禁期间禁止下载、修改与重命名；管理员代管他人文件不受影响（此处判断的是当前操作者）
// 工单附件是申诉通道（封禁提示本身引导用户走工单申诉），工单归属者仍可下载自己工单内的附件
// 下载是浏览器直接跳转的链接，无法弹前端的提示，因此这里回后台配置的封禁模板文案
if (userActiveBan($user) !== null && !$allowedTicketDownload) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(403);
        exit(banNoticeText($user));
    }
    jsonResponse(['ok' => false, 'message' => banNoticeText($user), 'code' => 'account_banned'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 预览：仅当请求带 inline=1、文件类型在预览白名单内、且后台已开启对应开关时，才内联输出；
        // 否则退化为普通下载（避免被当作任意文件的内联挂载点）
        if ($inlineRequested) {
            if (previewKind($file) === '' || !filePreviewable($file)) {
                http_response_code(404);
                exit('该类型不支持预览。');
            }
            $previewMode = true;
        }

        // 按副本顺序逐个尝试：某个账户不可用时自动回退到下一份
        $targets = uploadReplicaTargets($file, $owner);
        if ($targets === []) {
            throw new RuntimeException('该文件当前没有可用的存储副本。');
        }

        $filename = (string) ($file['display_name'] ?: $file['filename']);
        // ASCII 兜底文件名：老浏览器与下载工具不认 filename*=，需剔除非 ASCII 字符并保证保留扩展名
        $asciiName = trim(preg_replace('/[^\x20-\x7E]+/', '', str_replace(['\\', '/', '"'], '', $filename)) ?? '');
        if ($asciiName === '' || $asciiName === '.') {
            $extension = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($filename, PATHINFO_EXTENSION)) ?? '';
            $asciiName = 'download' . ($extension !== '' ? '.' . $extension : '');
        }
        header('Content-Type: ' . (string) $file['mime_type']);
        if ($previewMode) {
            // 内联预览：不触发下载；nosniff 防止浏览器把内容猜成可执行类型
            header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('X-Content-Type-Options: nosniff');
        } else {
            header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        }
        header('Accept-Ranges: bytes');
        header('X-Accel-Buffering: no');

        $lastError = '';
        $fellBack = false;
        foreach ($targets as $target) {
            try {
                $url = (new NotionClient((string) $target['token']))->downloadUrl((string) $target['block_id']);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $fellBack = true;
                Logger::warning('download', '取得副本下载地址失败，尝试下一份副本。', [
                    'file_id' => $id,
                    'replica_id' => $target['replica_id'],
                    'account_type' => $target['type'],
                    'account_id' => $target['account_id'],
                    'reason' => $lastError,
                ]);
                continue;
            }

            // 远端响应头先暂存，等第一个数据块到达再发出；中途失败则一个字节都没写出去，可以安全回退
            $pendingHeaders = [];
            $status = 0;
            $started = false;
            $written = 0;
            // 远端声明的总长度：下载结束后据此校验是否收全，避免把截断的响应当成完整文件
            $expectedLength = null;

            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                // 仅允许跟随 https 跳转，避免被重定向到 http 或内网地址
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RETURNTRANSFER => false,
                // 不设总时长上限（大文件可能很久），仅以「长时间无数据」判定断开，避免连接挂死
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 120,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$pendingHeaders, &$status, &$expectedLength): int {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                        $status = (int) $match[1];
                        // 发生跳转时丢弃上一跳的响应头，只保留最终响应的
                        $pendingHeaders = [];
                        $expectedLength = null;
                    } elseif (preg_match('/^(Content-Length|Content-Range|Accept-Ranges):/i', $header)) {
                        $pendingHeaders[] = trim($header);
                        if (stripos($header, 'Content-Length:') === 0) {
                            $expectedLength = (int) trim(substr($header, strlen('Content-Length:')));
                        }
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$pendingHeaders, &$status, &$started, &$written): int {
                    if (!$started) {
                        // 远端返回错误状态：不输出任何内容，交由外层回退到下一份副本
                        if ($status >= 400) {
                            return 0;
                        }
                        if ($status > 0) {
                            http_response_code($status);
                        }
                        foreach ($pendingHeaders as $header) {
                            header($header, true);
                        }
                        $started = true;
                    }
                    echo $data;
                    flush();
                    $written += strlen($data);
                    return strlen($data);
                },
            ]);
            if (!empty($_SERVER['HTTP_RANGE'])) {
                curl_setopt($handle, CURLOPT_HTTPHEADER, ['Range: ' . $_SERVER['HTTP_RANGE']]);
            }
            $ok = curl_exec($handle);
            $error = curl_error($handle);
            $truncated = $ok === false || ($expectedLength !== null && $written !== $expectedLength);

            if ($written > 0) {
                if ($truncated) {
                    // 用户主动取消下载不属于账户故障，不告警
                    if (!connection_aborted()) {
                        // 响应已开始输出，无法回退也无法改写状态码，只能记录并告警；已声明 Content-Length，客户端会判定下载失败
                        $reason = $ok === false
                            ? $error
                            : sprintf('已接收 %d 字节，远端声明 %d 字节', $written, (int) $expectedLength);
                        Logger::error('download', '下载中途中断，输出的数据可能不完整。', [
                            'file_id' => $id,
                            'user_id' => (int) $user['id'],
                            'replica_id' => $target['replica_id'],
                            'account_type' => $target['type'],
                            'account_id' => $target['account_id'],
                            'reason' => $reason,
                        ]);
                        raiseAdminAlert('error', sprintf('账户「%s」下载文件 #%d 时中断，该副本可能不可用。', (string) $target['label'], $id), [
                            'stage' => 'download',
                            'file_id' => $id,
                            'account_type' => $target['type'],
                            'account_id' => $target['account_id'],
                            'reason' => $reason,
                        ]);
                    }
                    exit;
                }
                if ($fellBack) {
                    Logger::info('download', '已回退到可用副本完成下载。', [
                        'file_id' => $id,
                        'user_id' => (int) $user['id'],
                        'replica_id' => $target['replica_id'],
                        'account_type' => $target['type'],
                        'account_id' => $target['account_id'],
                    ]);
                }
                exit;
            }

            $fellBack = true;
            $lastError = $ok === false ? $error : sprintf('远端返回 HTTP %d', $status);
            Logger::warning('download', '副本下载失败，尝试下一份副本。', [
                'file_id' => $id,
                'replica_id' => $target['replica_id'],
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
                'reason' => $lastError,
            ]);
        }

        throw new RuntimeException('全部副本均不可用：' . $lastError);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => '请求方法不受支持。'], 405);
    }
    Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'rename') {
        $name = basename(trim((string) ($_POST['name'] ?? '')));
        if ($name === '' || strlen($name) > 900 || preg_match('/[\\x00-\\x1F\\x7F]/u', $name)) {
            throw new RuntimeException('文件名称不能为空、包含控制字符或超过 900 字节。');
        }
        $update = Database::connection()->prepare('UPDATE uploads SET display_name = :name WHERE id = :id');
        $update->execute(['name' => $name, 'id' => $id]);
        jsonResponse(['ok' => true, 'message' => '名称已修改。', 'name' => $name]);
    }

    if ($action === 'delete') {
        // 先落库再归档：Notion 归档失败时用户在应用内也已看不到该文件，避免出现「远端已删、本地仍在」的死状态
        $update = Database::connection()->prepare("UPDATE uploads SET status = 'deleted' WHERE id = :id");
        $update->execute(['id' => $id]);
        // 逐个账户归档全部副本；单份失败只记日志并告警，不影响删除结果
        archiveUploadReplicas($file, $owner);
        jsonResponse(['ok' => true, 'message' => '文件已删除。']);
    }

    throw new RuntimeException('未知文件操作。');
} catch (Throwable $exception) {
    Logger::error('file', $exception->getMessage(), [
        'file_id' => $id,
        'action' => $_POST['action'] ?? null,
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'exception' => $exception::class,
    ]);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(502);
        exit('暂时无法下载文件。');
    }
    // 不把内部异常原文回显给前端：数据库错误等可能包含 SQL 与服务器路径
    $message = match (true) {
        $exception instanceof PDOException => '保存文件状态时发生数据库错误，请稍后重试。',
        $exception instanceof RuntimeException => $exception->getMessage(),
        default => '服务器处理文件操作时发生错误，请稍后重试。',
    };
    jsonResponse(['ok' => false, 'message' => $message], 422);
}