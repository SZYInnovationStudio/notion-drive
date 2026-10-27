<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Files\FileStreamer;
use NotionDrive\Logger;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Security;
use NotionDrive\Space\SpacePatrol;
use NotionDrive\Uploads\NotionWriteQueue;

$user = Auth::requireLogin($_SERVER['REQUEST_METHOD'] !== 'GET');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
// 预览模式：内联输出（inline），仅对白名单内、且后台已开启对应类型的文件生效；其余一律按下载处理
$inlineRequested = (string) ($_GET['inline'] ?? '') === '1';
$previewMode = false;
$statement = Database::connection()->prepare(
    "SELECT up.*, u.username, u.notion_token_encrypted, u.notion_account_source
     FROM uploads up JOIN users u ON u.id = up.user_id
     WHERE up.id = :id AND up.status IN (" . UPLOAD_VISIBLE_STATUSES . ') LIMIT 1'
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

if ((int) $file['user_id'] !== (int) $user['id'] && !isAdmin($user)) {
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

/** 是否尚未写入存储（正在写入 / 写入失败）：这类文件没有 Notion 副本，下载与预览直接读服务器上的暂存文件。 */
$stagingPending = in_array((string) $file['status'], [NotionWriteQueue::STATUS_WRITING, NotionWriteQueue::STATUS_FAILED], true);

// R13 数据丢失：全部 Notion 副本都失效的文件只允许删除，下载 / 预览 / 重命名一律拦截
$postAction = (string) ($_POST['action'] ?? '');
if ((int) ($file['data_lost'] ?? 0) === 1 && $postAction !== 'delete') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit(SpacePatrol::LOST_MESSAGE);
    }
    jsonResponse(['ok' => false, 'message' => SpacePatrol::LOST_MESSAGE, 'code' => 'data_lost'], 410);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 下载与预览统一交给 FileStreamer，保证 Range 透传、预览白名单、多副本回退等行为一致
        FileStreamer::stream($file, $owner, $inlineRequested, [
            'viewer_id' => (int) $user['id'],
            'via' => 'file',
        ]);
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
        // 尚未写入存储的文件没有 Notion 副本，删掉服务器上的暂存文件即可；
        // 若后台此刻正在写，其回填 UPDATE 会因状态不符影响 0 行，并把刚写入的副本自行归档
        if ($stagingPending) {
            // 覆盖上传失败后又把新文件删掉：把被覆盖的旧文件恢复可用，避免两代文件都从列表里消失
            if ((int) ($file['overwrite_upload_id'] ?? 0) > 0) {
                NotionWriteQueue::create()->rollbackOverwrite($file);
            }
            NotionWriteQueue::create()->discard((int) $file['user_id'], $id);
        }
        // 逐个账户归档全部副本要调用 Notion 接口，往返可达数秒；改为先把响应发给浏览器，
        // 再在后台归档，避免用户每次删除都干等（用户反馈「删除时会卡顿 5 秒」）。
        // 「先落库再归档」的语义不变：文件状态已在上方置为 deleted，失败仍只记日志并告警。
        jsonResponseThen(['ok' => true, 'message' => '文件已删除。'], static function () use ($file, $owner): void {
            archiveUploadReplicas($file, $owner);
        });
    }

    if ($action === 'retry_write') {
        $queue = NotionWriteQueue::create();
        if (!$queue->retry($id, (int) $file['user_id'])) {
            throw new RuntimeException('该文件当前不需要重试写入。');
        }
        Logger::info('file', '用户手动重试写入存储。', ['file_id' => $id, 'user_id' => (int) $user['id']]);
        jsonResponseThen(['ok' => true, 'message' => '已重新提交，正在写入存储。'], static function () use ($queue, $id): void {
            $queue->process($id);
        });
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