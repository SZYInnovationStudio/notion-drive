<?php

declare(strict_types=1);

namespace NotionDrive\Restore;

use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\NotionClient;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 还原 / 恢复出厂设置。
 *
 * 全部属高危且不可逆操作，调用方必须先通过三重校验（内置弹窗二次确认 + 管理员密码 + 指定短语），
 * 本类只负责校验中的服务端两重（密码与短语）以及真正的还原动作，不承担页面跳转与提示。
 */
final class RestoreService
{
    /** 还原个性化设置需要管理员逐字输入的确认短语。 */
    public const PHRASE_PERSONALIZATION = '还原个性化设置';

    /** 恢复出厂设置需要管理员逐字输入的确认短语。 */
    public const PHRASE_FACTORY_RESET = '恢复出厂设置';

    /** 个性化设置的配置项：这些键被删除后，页面自动回落内置默认值。 */
    private const PERSONALIZATION_KEYS = ['site_name', 'site_logo', 'site_favicon'];

    /** 恢复出厂设置要清空的本地内容目录（白名单，不接受外部输入）。 */
    private const CONTENT_DIRECTORIES = ['avatars', 'site', 'announcements'];

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * 服务端两重校验：管理员密码 + 指定短语（第一重为前端内置弹窗的二次确认）。
     *
     * 前端弹窗会关闭浏览器原生校验（form.noValidate = true），因此这里必须重新校验一遍，
     * 不能依赖表单的 required 属性。
     */
    public function assertConfirmed(int $adminUserId, string $password, string $phrase, string $expectedPhrase): void
    {
        if ($phrase !== $expectedPhrase) {
            throw new RuntimeException('确认短语不正确，请输入：' . $expectedPhrase);
        }

        $statement = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute(['id' => $adminUserId]);
        $hash = (string) $statement->fetchColumn();
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new RuntimeException('管理员密码不正确，操作已取消。');
        }
    }

    /**
     * 还原个性化设置：把站点名称、站点 logo、favicon 恢复为内置默认。
     *
     * 只清配置项与对应的资源文件，不触碰用户数据、密钥与其余系统设置。
     */
    public function resetPersonalization(): void
    {
        // 必须先用配置里的路径取到文件，再删除配置：顺序颠倒后 siteLogoPath() 会返回空串，文件成了孤儿
        foreach ([\siteLogoPath(), \siteFaviconPath()] as $path) {
            $this->removeStorageFile($path, 'site');
        }

        $placeholders = implode(', ', array_fill(0, count(self::PERSONALIZATION_KEYS), '?'));
        $statement = Database::connection()->prepare(
            'DELETE FROM config WHERE config_key IN (' . $placeholders . ')'
        );
        $statement->execute(self::PERSONALIZATION_KEYS);
    }

    /**
     * 恢复出厂设置：清空整个数据库并回到未安装状态，由调用方跳转到 install.php。
     *
     * 执行顺序刻意把「清库」放在最后：前面任何一步失败都还留有完整数据，不会出现「文件没了但库还在」
     * 这种半损坏状态。清库完成后同时删除 installed.lock 与 config/database.php 才算真正回到未安装状态，
     * 否则 install.php 会因配置仍存在而自愈补锁，安装向导永不可达。
     */
    public function factoryReset(): void
    {
        // 批量归档可能包含上千次外部请求，不能受 PHP 默认执行时限约束
        @set_time_limit(0);

        // 1) 尽力归档 Notion 侧的上传文件块（失败只记录日志，绝不阻断清库）
        $this->archiveUploadedBlocks();

        // 2) 清理本地用户内容文件（头像 / 站点资源 / 公告图片）
        foreach (self::CONTENT_DIRECTORIES as $directory) {
            $this->clearStorageDirectory($directory);
        }

        // 3) 清空全部数据表。保留数据库本身（连接信息不变），install.php 会重新建表
        $pdo = Database::connection();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                if (!is_string($table) || $table === '') {
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // 4) 删除安装锁：整站回到「未安装」，本次跳转与后续请求都会被引导到 install.php
        @unlink(dirname(__DIR__, 2) . '/storage/installed.lock');

        // 5) 删除数据库配置：只删锁会让 install.php 因配置仍存在而补锁自愈，安装向导不可达；
        //    与「回到未安装状态」一致，须一并删除，让安装向导重新收集数据库与管理员信息
        @unlink(dirname(__DIR__, 2) . '/config/database.php');
    }

    /**
     * 归档所有上传文件在 Notion 上的块。
     *
     * 副本可能分布在自有账户或官方账户池中的任意账户，必须用对应账户的 Token，否则会残留孤儿块；
     * 远端失败不能中断流程，否则整个恢复出厂会被一个坏块卡死，仅记录日志供排查。
     */
    private function archiveUploadedBlocks(): void
    {
        $rows = Database::connection()->query(
            'SELECT r.notion_block_id, r.account_type, r.official_account_id, a.token_encrypted,
                    u.notion_token_encrypted
             FROM upload_replicas r
             JOIN uploads u ON u.id = r.upload_id
             LEFT JOIN official_accounts a ON a.id = r.official_account_id
             WHERE r.notion_block_id IS NOT NULL'
        )->fetchAll();

        $clients = [];
        foreach ($rows as $row) {
            $blockId = (string) ($row['notion_block_id'] ?? '');
            if ($blockId === '') {
                continue;
            }

            $token = (string) $row['account_type'] === 'own'
                ? \userNotionToken(['notion_token_encrypted' => (string) ($row['notion_token_encrypted'] ?? '')])
                : \officialAccountToken([
                    'id'              => (int) ($row['official_account_id'] ?? 0),
                    'token_encrypted' => (string) ($row['token_encrypted'] ?? ''),
                ]);
            if ($token === '') {
                Logger::warning('restore', '恢复出厂设置时缺少副本所属账户的 Token，跳过归档。', [
                    'block_id'     => $blockId,
                    'account_type' => (string) $row['account_type'],
                ]);
                continue;
            }

            $client = $clients[$token] ??= new NotionClient($token);
            try {
                $client->archiveBlock($blockId);
            } catch (Throwable $exception) {
                Logger::warning('restore', '恢复出厂设置时归档 Notion 块失败：' . $exception->getMessage(), [
                    'block_id' => $blockId,
                ]);
            }
        }
    }

    /** 删除 storage 下指定子目录内的单个文件（仅限该子目录，避免被路径穿越利用）。 */
    private function removeStorageFile(string $path, string $subdir): void
    {
        if ($path === '') {
            return;
        }
        $root = dirname(__DIR__, 2);
        $base = realpath($root . '/storage/' . $subdir);
        $target = realpath($root . '/' . ltrim($path, '/\\'));
        if ($base !== false && $target !== false && str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
            @unlink($target);
        }
    }

    /** 清空 storage 下指定子目录内的文件；子目录名来自类内白名单，只删文件、不递归子目录。 */
    private function clearStorageDirectory(string $subdir): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/' . $subdir;
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
