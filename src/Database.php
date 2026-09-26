<?php

declare(strict_types=1);

namespace NotionDrive;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $configFile = dirname(__DIR__) . '/config/database.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('应用尚未安装。');
        }

        /** @var array{host:string,port:int,name:string,user:string,password:string,app_key:string} $config */
        $config = require $configFile;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['name']
        );

        try {
            self::$connection = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('无法连接数据库，请检查配置。', 0, $exception);
        }

        // 会话时区与 PHP 对齐：时间分别由 PHP 的 date() 与 MySQL 的 CURRENT_TIMESTAMP 写入，时区不一致会使页面时间与到期判断整体偏移
        try {
            self::$connection->exec("SET time_zone = '" . self::utcOffset() . "'");
        } catch (PDOException $exception) {
            Logger::warning('db', '设置数据库会话时区失败，时间显示与到期判断可能与实际时区不一致。', [
                'error' => $exception->getMessage(),
            ]);
        }

        return self::$connection;
    }

    /** 当前 PHP 时区相对 UTC 的偏移，形如 +08:00（MySQL 会话时区只接受这种数字写法）。 */
    private static function utcOffset(): string
    {
        $offset = (int) (new DateTimeImmutable())->getOffset();
        $sign = $offset < 0 ? '-' : '+';
        // MySQL 允许的偏移上限为 ±13:59，超出时截断，避免 SET time_zone 直接报错
        $offset = min(abs($offset), 13 * 3600 + 59 * 60);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    public static function config(string $key, ?string $default = null): ?string
    {
        $statement = self::connection()->prepare(
            'SELECT config_value FROM config WHERE config_key = :config_key LIMIT 1'
        );
        $statement->execute(['config_key' => $key]);
        $value = $statement->fetchColumn();

        // fetchColumn() 在无结果时返回 false，在字段值为 SQL NULL 时返回 null，两种情况都应回退到默认值
        return ($value === false || $value === null) ? $default : (string) $value;
    }

    public static function setConfig(string $key, string $value): void
    {
        $statement = self::connection()->prepare(
            'INSERT INTO config (config_key, config_value) VALUES (:config_key, :config_value)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        $statement->execute(['config_key' => $key, 'config_value' => $value]);
    }
}
