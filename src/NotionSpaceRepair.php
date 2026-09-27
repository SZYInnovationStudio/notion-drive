<?php

declare(strict_types=1);

namespace NotionDrive;

use Throwable;

/**
 * Notion 空间页面自愈。
 *
 * 当用户空间页面（或其祖先页面）在 Notion 里被移入回收站时，向该页面写入/删除文件块会报
 * 「Can't edit block that is archived」「Can't edit page on block with an archived ancestor」。
 * 这类故障不涉及数据丢失，只要把被归档的页面移出回收站即可恢复，因此归为「可自动修复」范围。
 *
 * 本类只处理这一种特征明确的错误：先按错误文案判定是否属于可修复范围，再定位空间页面、
 * 沿祖先链恢复被归档的页面；空间页面彻底不存在（404）时按原父页面重建并回写缓存。
 */
final class NotionSpaceRepair
{
    /** 可自动修复的错误特征（小写匹配）：块/页面/祖先页面被归档。 */
    private const REPAIRABLE_SIGNATURES = [
        'block that is archived',
        'archived ancestor',
        'you must unarchive',
    ];

    /** 祖先链回溯的最大层数，避免父链异常成环时无限循环。 */
    private const MAX_ANCESTOR_DEPTH = 6;

    /** 错误文案是否属于可自动修复范围。 */
    public static function isRepairable(string $message): bool
    {
        $message = strtolower($message);
        foreach (self::REPAIRABLE_SIGNATURES as $signature) {
            if (str_contains($message, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 尝试修复用户空间页面；返回修复结果，不抛异常（失败原因放在 detail 里由调用方记录）。
     *
     * @param array<string,mixed> $user
     * @param string              $type      'official' 或 'own'
     * @param int|null            $accountId 官方账户 ID；自有账户为 null
     *
     * @return array{repaired:bool,action:string,page_id:string,detail:string}
     */
    public function repair(array $user, string $type, ?int $accountId, NotionClient $client): array
    {
        try {
            $space = $this->resolveSpace($user, $type, $accountId);
            if ($space === null) {
                return self::result(false, 'unresolved', '', '未能确定用户空间页面，跳过自动修复。');
            }

            $rootPageId = (string) $space['root'];
            $pageId = (string) $space['page'];
            $restored = [];

            // 1) 沿祖先链把被归档的页面移出回收站：先近后远，未归档的页面跳过
            if ($pageId !== '') {
                foreach ($this->ancestorChain($pageId, $rootPageId, $client) as $candidate) {
                    if ($this->state($candidate, $client) !== 'trashed') {
                        continue;
                    }
                    try {
                        $client->restorePage($candidate);
                        $restored[] = $candidate;
                    } catch (Throwable $exception) {
                        Logger::warning('notion', '恢复被归档的 Notion 页面失败。', [
                            'user_id' => (int) ($user['id'] ?? 0),
                            'page_id' => $candidate,
                            'reason' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            // 2) 空间页面已被彻底删除（不在回收站、接口返回 404）时按原父页面重建并回写缓存
            if ($pageId === '' || $this->state($pageId, $client) === 'missing') {
                $pageId = $client->createUserSpace($rootPageId, (string) ($user['username'] ?? ''));
                $this->persist((int) ($user['id'] ?? 0), $type, $accountId, $pageId);

                return self::result(true, 'recreated', $pageId, '空间页面已丢失，已按原父页面重建。');
            }

            if ($restored === []) {
                return self::result(false, 'none', $pageId, '空间页面及其祖先均未被归档，不属于可修复范围。');
            }

            return self::result(true, 'restored', $pageId, '已恢复被归档的页面：' . implode(', ', $restored));
        } catch (Throwable $exception) {
            return self::result(false, 'failed', '', '自动修复失败：' . $exception->getMessage());
        }
    }

    /**
     * 解析用户在该目标账户下的空间页面与根页面。
     *
     * @param array<string,mixed> $user
     *
     * @return array{root:string,page:string}|null
     */
    private function resolveSpace(array $user, string $type, ?int $accountId): ?array
    {
        if ($type === 'own') {
            $root = \normalizePageId((string) ($user['notion_parent_page_id'] ?? ''));
            if ($root === '') {
                return null;
            }

            return ['root' => $root, 'page' => \normalizePageId((string) ($user['notion_page_id'] ?? ''))];
        }

        if ($accountId === null) {
            return null;
        }
        $account = \findOfficialAccount($accountId);
        if ($account === null) {
            return null;
        }
        $root = \normalizePageId((string) ($account['root_page_id'] ?? ''));
        if ($root === '') {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT notion_page_id FROM user_official_accounts WHERE user_id = :user_id AND account_id = :account_id LIMIT 1'
        );
        $statement->execute(['user_id' => (int) ($user['id'] ?? 0), 'account_id' => $accountId]);

        return ['root' => $root, 'page' => \normalizePageId((string) $statement->fetchColumn())];
    }

    /** 判断页面状态：active（可用）/ trashed（在回收站）/ missing（接口 404 或不可读）。 */
    private function state(string $pageId, NotionClient $client): string
    {
        if ($pageId === '') {
            return 'missing';
        }

        try {
            $page = $client->retrievePage($pageId);
        } catch (NotionApiException) {
            // 页面被彻底删除或当前不可读，统一按「不存在」处理，交由上层决定是否重建
            return 'missing';
        }

        return !empty($page['in_trash']) || !empty($page['archived']) ? 'trashed' : 'active';
    }

    /**
     * 从空间页面向上回溯到根页面，返回途经的页面 ID（含空间页面自身）。
     * 中间某层不可读时提前结束，已收集到的层级仍然可用于恢复。
     *
     * @return list<string>
     */
    private function ancestorChain(string $pageId, string $rootPageId, NotionClient $client): array
    {
        $chain = [];
        $cursor = $pageId;

        for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH && $cursor !== ''; $depth++) {
            $chain[] = $cursor;
            if ($cursor === $rootPageId) {
                break;
            }

            try {
                $page = $client->retrievePage($cursor);
            } catch (Throwable) {
                break;
            }

            $parent = $page['parent'] ?? null;
            $parentId = is_array($parent) && (string) ($parent['type'] ?? '') === 'page_id'
                ? \normalizePageId((string) ($parent['page_id'] ?? ''))
                : '';
            if ($parentId === '' || $parentId === $cursor) {
                break;
            }

            $cursor = $parentId;
        }

        return $chain;
    }

    /** 把新建的空间页面回写到缓存：官方账户记入 user_official_accounts，自有账户记入 users。 */
    private function persist(int $userId, string $type, ?int $accountId, string $pageId): void
    {
        if ($userId <= 0) {
            return;
        }

        if ($type === 'own') {
            Database::connection()->prepare('UPDATE users SET notion_page_id = :page_id WHERE id = :id')
                ->execute(['page_id' => $pageId, 'id' => $userId]);

            return;
        }

        if ($accountId !== null) {
            \rememberUserSpacePage($userId, $accountId, $pageId);
        }
    }

    /** @return array{repaired:bool,action:string,page_id:string,detail:string} */
    private static function result(bool $repaired, string $action, string $pageId, string $detail): array
    {
        return ['repaired' => $repaired, 'action' => $action, 'page_id' => $pageId, 'detail' => $detail];
    }
}
