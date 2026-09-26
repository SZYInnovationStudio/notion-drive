<?php

declare(strict_types=1);

namespace NotionDrive;

/**
 * 轻量文件日志：每条记录一行，写入 storage/logs/app-YYYY-MM-DD.log。
 * 格式：[时间] [级别] [通道] 消息 {上下文 JSON}
 */
final class Logger
{
    public const LEVELS = ['ERROR', 'WARNING', 'INFO'];

    private const MAX_MESSAGE_LENGTH = 2000;

    public static function directory(): string
    {
        return dirname(__DIR__) . '/storage/logs';
    }

    public static function filePath(string $name): string
    {
        return self::directory() . '/' . $name;
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^app-\d{4}-\d{2}-\d{2}\.log$/', $name);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('ERROR', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('WARNING', $channel, $message, $context);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('INFO', $channel, $message, $context);
    }

    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = sprintf(
            '[%s] [%s] [%s] %s%s%s',
            date('Y-m-d H:i:s'),
            $level,
            $channel,
            self::flatten($message),
            $context === [] ? '' : ' ' . self::encodeContext($context),
            PHP_EOL
        );

        @file_put_contents(self::filePath('app-' . date('Y-m-d') . '.log'), $line, FILE_APPEND | LOCK_EX);
    }

    private static function flatten(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($message === '') {
            $message = '(无消息)';
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
        }

        return $message;
    }

    /** 上下文脱敏：键名命中敏感词时用占位符替换，并递归处理嵌套数组，避免凭据原样写进日志。 */
    private const MASK_WORDS = ['token', 'password', 'passwd', 'secret', 'key', 'sign', 'signature', 'authorization'];

    private static function encodeContext(array $context): string
    {
        $encoded = json_encode(self::mask($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{}' : $encoded;
    }

    private static function mask(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            $words = preg_split('/[^a-z0-9]+/', strtolower((string) $key)) ?: [];
            $safe[$key] = array_intersect($words, self::MASK_WORDS) === []
                ? (is_array($value) ? self::mask($value) : $value)
                : '[已隐藏]';
        }

        return $safe;
    }

    /** @return list<array{name:string,size:int,modified:int}> */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::directory() . '/app-*.log') ?: [] as $path) {
            $name = basename($path);
            if (!self::isValidName($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'size' => (int) (filesize($path) ?: 0),
                'modified' => (int) (filemtime($path) ?: 0),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * 读取日志行，按级别与关键字过滤后返回最近 $limit 行。
     *
     * @return array{lines:list<array{level:string,text:string}>,total:int,matched:int,truncated:bool}
     */
    public static function read(string $name, int $limit = 200, string $level = 'ALL', string $keyword = ''): array
    {
        $empty = ['lines' => [], 'total' => 0, 'matched' => 0, 'truncated' => false];
        if (!self::isValidName($name) || !is_file(self::filePath($name))) {
            return $empty;
        }

        $limit = max(1, min($limit, 2000));
        $raw = file(self::filePath($name), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $level = strtoupper($level);
        $keyword = trim($keyword);

        $matched = [];
        foreach ($raw as $line) {
            if (in_array($level, self::LEVELS, true) && self::lineLevel($line) !== $level) {
                continue;
            }
            if ($keyword !== '' && mb_stripos($line, $keyword) === false) {
                continue;
            }
            $matched[] = ['level' => self::lineLevel($line), 'text' => $line];
        }

        return [
            'lines' => array_slice($matched, -$limit),
            'total' => count($raw),
            'matched' => count($matched),
            'truncated' => count($matched) > $limit,
        ];
    }

    /**
     * 从固定格式的日志行中解析级别。日志行固定为「[时间] [级别] [通道] 消息」，
     * 因此只匹配行首的两个方括号字段，避免消息正文里出现 [ERROR] 等字样时被误判级别。
     */
    private static function lineLevel(string $line): string
    {
        if (preg_match('/^\s*\[[^\]]*\]\s*\[(ERROR|WARNING|INFO)\]/', $line, $match) === 1) {
            return $match[1];
        }

        return 'INFO';
    }

    public static function clear(string $name): bool
    {
        if (!self::isValidName($name)) {
            return false;
        }

        return is_file(self::filePath($name)) && @file_put_contents(self::filePath($name), '') !== false;
    }

    public static function remove(string $name): bool
    {
        if (!self::isValidName($name)) {
            return false;
        }

        return is_file(self::filePath($name)) && @unlink(self::filePath($name));
    }
}
