<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Uploads\NotionWriteQueue;

/** 文件夹名称与文件名同规则：非空、不超过 900 字节、不含控制字符，并剥掉路径分隔符。 */
function normalizeFolderName(string $raw): string
{
    $name = basename(trim($raw));
    if ($name === '' || strlen($name) > 900 || preg_match('/[\\x00-\\x1F\\x7F]/u', $name)) {
        throw new RuntimeException('文件夹名称不能为空、包含控制字符或超过 900 字节。');
    }

    return $name;
}

$user = Auth::requireLogin(true);
$userId = (int) $user['id'];

// 封禁期间禁止文件夹的创建/重命名/移动/删除（均属「修改」范畴）
if (userActiveBan($user) !== null) {
    jsonResponse(['ok' => false, 'message' => '账号已被封禁，暂时无法管理文件夹。如有疑问请通过工单提交申诉。'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => '请求方法不受支持。'], 405);
    }
    Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $connection = Database::connection();

    if ($action === 'create') {
        $name = normalizeFolderName((string) ($_POST['name'] ?? ''));
        $parentId = max(0, (int) ($_POST['parent_id'] ?? 0));
        if ($parentId > 0 && folderRow($userId, $parentId) === null) {
            throw new RuntimeException('上级文件夹不存在。');
        }
        $connection->prepare('INSERT INTO folders (user_id, parent_id, name) VALUES (:user_id, :parent_id, :name)')
            ->execute(['user_id' => $userId, 'parent_id' => $parentId > 0 ? $parentId : null, 'name' => $name]);
        jsonResponse(['ok' => true, 'message' => '文件夹已创建。', 'id' => (int) $connection->lastInsertId(), 'name' => $name]);
    }

    // 「上传文件夹」用：一次请求按相对路径建好整棵目录树，避免一个文件夹一个请求。
    // paths 形如 ["A","A/B","A/B/C"]（父先于子）；inspect=1 时只探测哪些路径已存在（供前端让用户选合并/新建），不写库；
    // merges 列出用户选择「合并进已有」的路径，其余已存在路径则新建同名文件夹。
    if ($action === 'create_tree') {
        $rootId = max(0, (int) ($_POST['parent_id'] ?? 0));
        if ($rootId > 0 && folderRow($userId, $rootId) === null) {
            throw new RuntimeException('上级文件夹不存在。');
        }
        $paths = json_decode((string) ($_POST['paths'] ?? '[]'), true);
        if (!is_array($paths) || $paths === []) {
            throw new RuntimeException('没有需要创建的文件夹。');
        }
        // 路径必须父先于子：短的排前面，同长度按名称稳定排序，保证父目录先被解析出 id
        $paths = array_values(array_unique(array_filter(array_map(
            static fn ($path): string => is_string($path) ? trim($path) : '',
            $paths
        ), static fn (string $path): bool => $path !== '')));
        usort($paths, static function (string $a, string $b): int {
            return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
        });

        $inspect = (string) ($_POST['inspect'] ?? '') === '1';
        $merges = json_decode((string) ($_POST['merges'] ?? '[]'), true);
        $mergeSet = [];
        foreach (is_array($merges) ? $merges : [] as $merge) {
            if (is_string($merge)) {
                $mergeSet[$merge] = true;
            }
        }

        $findChild = $connection->prepare(
            'SELECT id FROM folders WHERE user_id = :user_id AND parent_id <=> :parent_id AND name = :name LIMIT 1'
        );
        $insert = $connection->prepare(
            'INSERT INTO folders (user_id, parent_id, name) VALUES (:user_id, :parent_id, :name)'
        );

        // 已解析出的「相对路径 → 文件夹 id」；空串代表起点（当前所在文件夹）
        $resolved = ['' => $rootId];
        $conflicts = [];

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $s): bool => $s !== ''));
            if ($segments === []) {
                continue;
            }
            $parentId = $rootId;
            $currentPath = '';
            foreach ($segments as $segment) {
                $segment = normalizeFolderName($segment);
                $currentPath = $currentPath === '' ? $segment : $currentPath . '/' . $segment;
                // 本层已在本请求内解析过，直接复用，避免父目录被创建两次
                if (array_key_exists($currentPath, $resolved)) {
                    $parentId = $resolved[$currentPath];
                    continue;
                }
                $findChild->execute([
                    'user_id'   => $userId,
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'name'      => $segment,
                ]);
                $existingId = $findChild->fetchColumn();
                $existingId = $existingId === false ? 0 : (int) $existingId;

                if ($existingId > 0) {
                    if ($inspect) {
                        // 探测阶段：登记冲突后按「合并」继续向下走，这样嵌套层级的冲突也能被发现。
                        // 用户若对上层改选「新建」，下层的 merge 决策会在真正创建时因目标不存在而自然落空
                        $conflicts[] = $currentPath;
                        $resolved[$currentPath] = $existingId;
                        $parentId = $existingId;
                        continue;
                    }
                    if (isset($mergeSet[$currentPath])) {
                        $resolved[$currentPath] = $existingId;
                        $parentId = $existingId;
                        continue;
                    }
                } elseif ($inspect) {
                    // 探测阶段遇到不存在的目录：其下层不可能是「已存在」，无需继续
                    break;
                }

                $insert->execute([
                    'user_id'   => $userId,
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'name'      => $segment,
                ]);
                $newId = (int) $connection->lastInsertId();
                $resolved[$currentPath] = $newId;
                $parentId = $newId;
            }
        }

        if ($inspect) {
            jsonResponse(['ok' => true, 'conflicts' => array_values(array_unique($conflicts))]);
        }
        // 全量回传「相对路径 → 文件夹 id」（含中间层级），前端据此把每个文件放进对应目录
        unset($resolved['']);
        jsonResponse(['ok' => true, 'message' => '文件夹结构已就绪。', 'folders' => $resolved === [] ? new \stdClass() : $resolved]);
    }

    if ($action === 'move_file') {
        $fileId = (int) ($_POST['file_id'] ?? 0);
        // 0 表示移到根目录（即「移出文件夹」）
        $targetId = max(0, (int) ($_POST['folder_id'] ?? 0));
        if ($targetId > 0 && folderRow($userId, $targetId) === null) {
            throw new RuntimeException('目标文件夹不存在。');
        }
        $statement = $connection->prepare(
            "SELECT id, data_lost FROM uploads WHERE id = :id AND user_id = :user_id AND scope = 'drive' AND status IN (" . UPLOAD_VISIBLE_STATUSES . ') LIMIT 1'
        );
        $statement->execute(['id' => $fileId, 'user_id' => $userId]);
        $upload = $statement->fetch();
        if (!$upload) {
            jsonResponse(['ok' => false, 'message' => '文件不存在或无权访问。'], 404);
        }
        // R13 数据已丢失的文件只能删除，不允许移动
        if ((int) ($upload['data_lost'] ?? 0) === 1) {
            jsonResponse(['ok' => false, 'message' => \NotionDrive\Space\SpacePatrol::LOST_MESSAGE, 'code' => 'data_lost'], 410);
        }
        $connection->prepare('UPDATE uploads SET folder_id = :folder_id WHERE id = :id')
            ->execute(['folder_id' => $targetId > 0 ? $targetId : null, 'id' => $fileId]);
        jsonResponse(['ok' => true, 'message' => $targetId > 0 ? '已移动到目标文件夹。' : '已移出文件夹。']);
    }

    // 以下操作都针对具体文件夹，先确认它属于当前用户（不存在即按无权访问处理）
    $folderId = (int) ($_POST['id'] ?? 0);
    if (folderRow($userId, $folderId) === null) {
        jsonResponse(['ok' => false, 'message' => '文件夹不存在或无权访问。'], 404);
    }

    if ($action === 'rename') {
        $name = normalizeFolderName((string) ($_POST['name'] ?? ''));
        $connection->prepare('UPDATE folders SET name = :name WHERE id = :id AND user_id = :user_id')
            ->execute(['name' => $name, 'id' => $folderId, 'user_id' => $userId]);
        jsonResponse(['ok' => true, 'message' => '文件夹已重命名。', 'name' => $name]);
    }

    if ($action === 'delete') {
        // 递归删除：该文件夹及全部子孙文件夹一并移除，其中的文件按删除文件处理（本地标记 + 归档 Notion 副本）
        // 含被内容审核隔离（quarantined）的文件：它们不在「我的文件」中显示，若不在此一并清除，
        // 会变成既看不到、也无法删除的孤儿记录，永久占用用户容量。
        $folderIds = folderDescendantIds($userId, $folderId);
        $placeholders = implode(',', array_fill(0, count($folderIds), '?'));

        $statement = $connection->prepare(
            "SELECT up.*, u.username, u.notion_token_encrypted, u.notion_account_source
             FROM uploads up JOIN users u ON u.id = up.user_id
             WHERE up.folder_id IN ({$placeholders}) AND up.status IN (" . UPLOAD_CAPACITY_STATUSES . ')'
        );
        $statement->execute($folderIds);
        $files = $statement->fetchAll();

        // 先落库再归档：Notion 归档失败时用户在应用内也已看不到这些文件，避免出现「远端已删、本地仍在」的死状态
        $connection->prepare('UPDATE uploads SET status = \'deleted\' WHERE folder_id IN (' . $placeholders . ') AND status IN (' . UPLOAD_CAPACITY_STATUSES . ')')
            ->execute($folderIds);
        foreach ($files as $file) {
            // 尚未写入存储的文件没有 Notion 副本，清掉服务器上的暂存文件即可
            if (in_array((string) $file['status'], [NotionWriteQueue::STATUS_WRITING, NotionWriteQueue::STATUS_FAILED], true)) {
                NotionWriteQueue::create()->discard((int) $file['user_id'], (int) $file['id']);
            }
            archiveUploadReplicas($file, [
                'id' => (int) $file['user_id'],
                'notion_token_encrypted' => $file['notion_token_encrypted'] ?? null,
                'notion_account_source' => $file['notion_account_source'] ?? null,
            ]);
        }

        // ID 集合来自 folderDescendantIds，已限定为当前用户的文件夹
        $connection->prepare("DELETE FROM folders WHERE id IN ({$placeholders})")->execute($folderIds);

        jsonResponse(['ok' => true, 'message' => sprintf('文件夹已删除，其中 %d 个文件一并移除。', count($files))]);
    }

    throw new RuntimeException('未知文件夹操作。');
} catch (Throwable $exception) {
    Logger::error('folder', $exception->getMessage(), [
        'action' => $_POST['action'] ?? null,
        'user_id' => $userId,
        'username' => (string) ($user['username'] ?? ''),
        'exception' => $exception::class,
    ]);
    // 不把内部异常原文回显给前端：数据库错误等可能包含 SQL 与服务器路径
    $message = match (true) {
        $exception instanceof PDOException => '保存文件夹状态时发生数据库错误，请稍后重试。',
        $exception instanceof RuntimeException => $exception->getMessage(),
        default => '服务器处理文件夹操作时发生错误，请稍后重试。',
    };
    jsonResponse(['ok' => false, 'message' => $message], 422);
}