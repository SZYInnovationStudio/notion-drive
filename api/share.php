<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Logger;
use NotionDrive\Security;
use NotionDrive\Share\ShareService;

$user = Auth::requireLogin(true);

// 封禁期间不允许生成新的直链（已生成的直链会因「所属账号被封禁」在 s.php 一并失效）
if (userActiveBan($user) !== null) {
    jsonResponse(['ok' => false, 'message' => '账号已被封禁，暂时无法使用直链功能。如有疑问请通过工单提交申诉。'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => '请求方法不受支持。'], 405);
    }
    Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

    // 额度与会员身份都要读最新值：Auth::user() 的快照可能不含刚发放的直链包条数
    $fresh = findUserById((int) $user['id']) ?? $user;
    $userId = (int) $fresh['id'];
    $action = (string) ($_POST['action'] ?? '');

    /** 额度快照：前端据此显示「已用 X / 共 Y」与购买入口 */
    $snapshot = static function () use ($fresh, $userId): array {
        return [
            'enabled' => ShareService::enabled(),
            'premium' => isPremiumUser($fresh),
            'quota' => ShareService::quotaOf($fresh),
            'used' => ShareService::usedCount($userId),
            'remaining' => ShareService::remaining($fresh),
            'default_days' => ShareService::defaultDays(),
            'allowed_days' => ShareService::ALLOWED_DAYS,
        ];
    };

    if ($action === 'create') {
        $days = (int) ($_POST['days'] ?? ShareService::defaultDays());
        $link = ShareService::create($fresh, (int) ($_POST['file_id'] ?? 0), $days);
        jsonResponse([
            'ok' => true,
            'message' => $days > 0 ? sprintf('直链已生成，%d 天后自动失效。', $days) : '直链已生成，长期有效（可随时撤销）。',
            'link' => $link,
        ] + $snapshot());
    }

    if ($action === 'revoke') {
        ShareService::revoke($fresh, (int) ($_POST['id'] ?? 0));
        jsonResponse(['ok' => true, 'message' => '直链已撤销，原链接立即失效。'] + $snapshot());
    }

    if ($action === 'list') {
        // file_id > 0 时只看该文件的直链；为 0 时返回「我的直链」全部（最多 200 条）
        jsonResponse([
            'ok' => true,
            'links' => ShareService::listFor($fresh, max(0, (int) ($_POST['file_id'] ?? 0))),
        ] + $snapshot());
    }

    throw new RuntimeException('未知操作。');
} catch (Throwable $exception) {
    Logger::error('share', $exception->getMessage(), [
        'user_id' => (int) $user['id'],
        'action' => $_POST['action'] ?? null,
        'exception' => $exception::class,
    ]);
    // 不把内部异常原文回显给前端：数据库错误等可能包含 SQL 与服务器路径
    $message = match (true) {
        $exception instanceof PDOException => '保存直链时发生数据库错误，请稍后重试。',
        $exception instanceof RuntimeException => $exception->getMessage(),
        default => '服务器处理直链请求时发生错误，请稍后重试。',
    };
    jsonResponse(['ok' => false, 'message' => $message], 422);
}