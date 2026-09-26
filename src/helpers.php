<?php

declare(strict_types=1);

use NotionDrive\Database;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Mailer;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Schema;
use NotionDrive\Security;

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,mixed> $data */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function appConfig(): array
{
    /** @var array<string,mixed> $config */
    $config = require dirname(__DIR__) . '/config/database.php';
    return $config;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return number_format($size, $index === 0 ? 0 : 1) . ' ' . $units[$index];
}

/** 自有 Notion 账户的单文件上限：Notion 官方对自有账户的硬限制为 5 MB。 */
function ownFileLimitBytes(): int
{
    return 5 * 1024 * 1024;
}

/** 官方账户的单文件上限：仅当官方账户为付费方案时放宽，可在后台调整。 */
function officialFileLimitBytes(): int
{
    $fallback = 5 * 1024 * 1024 * 1024;
    if (Database::config('notion_plan', 'free') !== 'paid') {
        return 5 * 1024 * 1024;
    }

    $configured = (int) Database::config('official_single_file_limit_bytes', (string) $fallback);

    return $configured > 0 ? $configured : $fallback;
}

/** 解析 php.ini 的体积写法（如 2M、8M、1G）；「-1」表示不限。 */
function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    if ($value === '-1') {
        return -1;
    }

    $number = (int) $value;
    $unit = strtolower(substr($value, -1));

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/** 分块上传的分块大小：默认 10 MB，并按服务器 ini 限制自动收敛，最低 256 KB。 */
function uploadChunkSizeBytes(): int
{
    $configured = (int) Database::config('upload_chunk_size_mb', '10');
    $chunk = ($configured > 0 ? $configured : 10) * 1024 * 1024;

    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $limit = iniBytes((string) ini_get($key));
        if ($limit > 0) {
            // 预留同请求内其他表单字段与 multipart 边界占用的开销
            $chunk = min($chunk, $limit - 64 * 1024);
        }
    }

    return max(256 * 1024, $chunk);
}

function userUsedBytes(int $userId): int
{
    $statement = Database::connection()->prepare(
        "SELECT COALESCE(SUM(file_size), 0) FROM uploads WHERE user_id = :user_id AND status = 'active'"
    );
    $statement->execute(['user_id' => $userId]);
    return (int) $statement->fetchColumn();
}

/** 读取权限组；不存在时返回 null。 */
function findGroup(int $groupId): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM `groups` WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $groupId]);
    $group = $statement->fetch();

    return is_array($group) ? $group : null;
}

/** 全部权限组（按等级从高到低）。 */
function allGroups(): array
{
    return Database::connection()
        ->query('SELECT * FROM `groups` ORDER BY level DESC, id ASC')
        ->fetchAll();
}

/** 默认权限组 ID（新注册用户自动加入的内置普通用户组）。 */
function defaultGroupId(): int
{
    $statement = Database::connection()->prepare('SELECT id FROM `groups` WHERE name = :name LIMIT 1');
    $statement->execute(['name' => Schema::DEFAULT_GROUP_NAME]);
    $groupId = (int) $statement->fetchColumn();
    if ($groupId > 0) {
        return $groupId;
    }

    // 内置普通用户组被改名或删除时回退到等级最低的组，避免新用户拿不到权限组
    return (int) Database::connection()
        ->query('SELECT id FROM `groups` ORDER BY level ASC, id ASC LIMIT 1')
        ->fetchColumn();
}

/** 删除头像文件（仅允许删除 storage/avatars 目录内的文件，避免被路径穿越利用）。 */
function removeAvatarFile(string $avatarPath): void
{
    if ($avatarPath === '') {
        return;
    }
    $base = realpath(dirname(__DIR__) . '/storage/avatars');
    $target = realpath(dirname(__DIR__) . '/' . ltrim($avatarPath, '/\\'));
    if ($base !== false && $target !== false && str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        @unlink($target);
    }
}

/** 渲染用户头像：有自定义头像时经 avatar.php 输出，否则显示用户名首字母。 */
function avatarHtml(array $user, string $class = 'avatar'): string
{
    $avatarPath = (string) ($user['avatar_path'] ?? '');
    if ($avatarPath === '') {
        return '<span class="' . e($class) . '">' . e(mb_strtoupper(mb_substr((string) $user['username'], 0, 1))) . '</span>';
    }

    return '<span class="' . e($class) . '"><img src="avatar.php?u=' . (int) $user['id'] . '&amp;v='
        . e(substr(md5(basename($avatarPath)), 0, 8)) . '" alt="' . e((string) $user['username']) . ' 的头像"></span>';
}

/** 用户是否为管理员（依据权限组的管理员标记）。 */
function isAdmin(array $user): bool
{
    return (int) ($user['group_is_admin'] ?? 0) === 1;
}

/** 用户实际生效的容量：用户级 quota_bytes 非空时覆盖组容量。 */
function userQuotaBytes(array $user): ?int
{
    $override = $user['quota_bytes'] ?? null;
    if ($override !== null) {
        return (int) $override;
    }

    // 权限组不存在（LEFT JOIN 未命中）属于账号数据异常：按 0 容量处理，宁可拒绝上传也不放行超额写入
    if (($user['group_name'] ?? null) === null) {
        return 0;
    }

    $groupQuota = $user['group_quota_bytes'] ?? null;

    return $groupQuota === null ? null : (int) $groupQuota;
}

/** 用户实际生效的单文件上限：先按账户来源取 Notion 侧上限，再与组上限取小。 */
function userSingleFileLimit(array $user): int
{
    $sourceLimit = userNotionSource($user) === 'own' ? ownFileLimitBytes() : officialFileLimitBytes();
    $groupLimit = $user['group_single_file_limit_bytes'] ?? null;
    if ($groupLimit === null) {
        return $sourceLimit;
    }

    return min($sourceLimit, (int) $groupLimit);
}

function userUploadLimit(array $user): int
{
    $singleFileLimit = userSingleFileLimit($user);
    $quota = userQuotaBytes($user);
    if ($quota === null) {
        return $singleFileLimit;
    }

    $remaining = max(0, $quota - userUsedBytes((int) $user['id']));
    return min($singleFileLimit, $remaining);
}

/** 是否开放新账号注册。 */
function registrationEnabled(): bool
{
    return Database::config('registration_enabled', '0') === '1';
}

/** 是否强制所有用户绑定邮箱。 */
function emailRequired(): bool
{
    return Database::config('email_required', '0') === '1';
}

/** 用户是否已绑定并验证邮箱。 */
function emailBound(array $user): bool
{
    return (string) ($user['email'] ?? '') !== '' && !empty($user['email_verified_at']);
}

/** 该用户当前是否可以上传（强制邮箱时未绑定邮箱者不可上传）。 */
function mayUpload(array $user): bool
{
    return !emailRequired() || isAdmin($user) || emailBound($user);
}

/** SMTP 是否已配置完整（启用强制邮箱的前提）。 */
function smtpConfigured(): bool
{
    foreach (['smtp_host', 'smtp_port', 'smtp_from'] as $key) {
        if (trim((string) Database::config($key, '')) === '') {
            return false;
        }
    }

    return true;
}

function quotaLabel(?int $bytes): string
{
    return $bytes === null ? '不限容量' : formatBytes($bytes);
}

/**
 * 统计用户在某个目标账户下已保存的有效副本数量。
 *
 * 用于判断该账户下是否已经存在用户空间页面：已有文件时无需再花一次接口调用去自检页面。
 * $accountId 为 null 表示自有账户。
 */
function accountReplicaCount(int $userId, ?int $accountId): int
{
    $sql = "SELECT COUNT(*) FROM upload_replicas r
            JOIN uploads u ON u.id = r.upload_id
            WHERE u.user_id = :user_id AND u.status = 'active' AND r.status = 'active'";
    $params = ['user_id' => $userId];
    if ($accountId === null) {
        $sql .= " AND r.account_type = 'own'";
    } else {
        $sql .= " AND r.account_type = 'official' AND r.official_account_id = :account_id";
        $params['account_id'] = $accountId;
    }

    $statement = Database::connection()->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

/** 归一化 Notion 页面 ID：去掉非十六进制字符并转小写，便于比较。 */
function normalizePageId(string $id): string
{
    return strtolower((string) preg_replace('/[^a-fA-F0-9]/', '', $id));
}

function uniqueStorageName(int $userId, string $originalName, string $filePath): string
{
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $stem = pathinfo($originalName, PATHINFO_FILENAME);
    $hash = substr((string) hash_file('sha256', $filePath), 0, 16);
    $prefix = sprintf('u%d_%s_%s_%s__', $userId, gmdate('YmdHis'), $hash, bin2hex(random_bytes(4)));
    $suffix = $extension === '' ? '' : '.' . $extension;
    $available = max(1, 900 - strlen($prefix) - strlen($suffix));
    $trimmedStem = mb_strcut($stem, 0, $available, 'UTF-8');

    return $prefix . $trimmedStem . $suffix;
}

/**
 * 查找同一用户名下已存在的有效文件（用于上传时的重名检测）。
 *
 * @return array<string,mixed>|null
 */
function findActiveUploadByName(int $userId, string $name): ?array
{
    $statement = Database::connection()->prepare(
        "SELECT id, filename, display_name, file_size, notion_block_id FROM uploads
         WHERE user_id = :user_id AND status = 'active'
           AND COALESCE(NULLIF(display_name, ''), filename) = :name
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute(['user_id' => $userId, 'name' => $name]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** 重名自动编号：1.txt → 1(1).txt、1(2).txt……（编号始终插在扩展名之前，不占用扩展名）。 */
function nextAvailableFilename(int $userId, string $name): string
{
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $suffix = $extension === '' ? '' : '.' . $extension;

    for ($index = 1; $index <= 999; $index++) {
        $marker = '(' . $index . ')';
        $limit = max(1, 900 - strlen($suffix) - strlen($marker));
        $candidate = mb_strcut($stem, 0, $limit, 'UTF-8') . $marker . $suffix;
        if (findActiveUploadByName($userId, $candidate) === null) {
            return $candidate;
        }
    }

    $marker = '(' . bin2hex(random_bytes(3)) . ')';
    $limit = max(1, 900 - strlen($suffix) - strlen($marker));

    return mb_strcut($stem, 0, $limit, 'UTF-8') . $marker . $suffix;
}

/** 把字节数换算成输入框显示值：整单位取整，否则保留 6 位小数（避免 5 MB 显示成 0 GB）。 */
function bytesToInputValue(?int $bytes, int $unitBytes): string
{
    if ($bytes === null) {
        return '-1';
    }

    $value = $bytes / $unitBytes;
    if (abs($value - round($value)) < 0.0000005) {
        return (string) (int) round($value);
    }

    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

/** 当前请求来源 IP（只取 REMOTE_ADDR，不信任可伪造的转发头）。 */
function clientIp(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip === '' ? '0.0.0.0' : substr($ip, 0, 45);
}

/**
 * 判断当前请求是否走 HTTPS。
 * Nginx/CDN 等反向代理终止 TLS 时 PHP 侧 $_SERVER['HTTPS'] 为空，此时需要看代理写入的
 * X-Forwarded-Proto，否则 Cookie 的 Secure 标记与会话安全会失效。
 */
function requestIsHttps(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strcasecmp($https, 'off') !== 0) {
        return true;
    }

    $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '';

    return strtolower(trim($forwarded)) === 'https';
}

/**
 * 登录限流状态：15 分钟内同一 IP 失败 20 次即锁定；同一账号失败 3 次后开始要求验证码。
 * 账号维度不直接锁定，否则他人可故意输错密码把某个账号锁死（锁号 DoS）。
 *
 * @return array{locked:bool,failures:int,ip_failures:int,captcha:bool,retry_after:int}
 */
function loginThrottle(string $username): array
{
    $pdo = Database::connection();
    $username = mb_strcut($username, 0, 191, 'UTF-8');

    $account = $pdo->prepare(
        'SELECT COUNT(*) AS failures,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MIN(attempted_at) + INTERVAL 15 MINUTE)) AS retry_after
         FROM login_attempts WHERE username = :username AND attempted_at > NOW() - INTERVAL 15 MINUTE'
    );
    $account->execute(['username' => $username]);
    $accountRow = $account->fetch() ?: ['failures' => 0, 'retry_after' => 0];

    $byIp = $pdo->prepare(
        'SELECT COUNT(*) AS failures,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MIN(attempted_at) + INTERVAL 15 MINUTE)) AS retry_after
         FROM login_attempts WHERE ip = :ip AND attempted_at > NOW() - INTERVAL 15 MINUTE'
    );
    $byIp->execute(['ip' => clientIp()]);
    $ipRow = $byIp->fetch() ?: ['failures' => 0, 'retry_after' => 0];

    $accountFailures = (int) $accountRow['failures'];
    $ipFailures = (int) $ipRow['failures'];
    $locked = $ipFailures >= 20;

    return [
        'locked' => $locked,
        'failures' => $accountFailures,
        'ip_failures' => $ipFailures,
        'captcha' => $accountFailures >= 3 || $ipFailures >= 3,
        'retry_after' => $locked ? (int) $ipRow['retry_after'] : 0,
    ];
}

/** 记录一次登录失败，并顺手清理过期记录。 */
function recordLoginFailure(string $username): void
{
    $pdo = Database::connection();
    $pdo->prepare('INSERT INTO login_attempts (username, ip) VALUES (:username, :ip)')
        ->execute(['username' => mb_strcut($username, 0, 191, 'UTF-8'), 'ip' => clientIp()]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
}

/** 登录成功后清空该账号的失败记录。 */
function clearLoginFailures(string $username): void
{
    Database::connection()
        ->prepare('DELETE FROM login_attempts WHERE username = :username')
        ->execute(['username' => mb_strcut($username, 0, 191, 'UTF-8')]);
}

/** 生成图形验证码并写入 session（由 captcha.php 输出图片）。 */
function issueCaptcha(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($index = 0; $index < 4; $index++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $_SESSION['captcha'] = ['code' => $code, 'expires' => time() + 300];

    return $code;
}

/** 校验验证码，大小写不敏感且一次性使用。 */
function verifyCaptcha(?string $input): bool
{
    $captcha = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);
    if (!is_array($captcha)) {
        return false;
    }

    $code = (string) ($captcha['code'] ?? '');
    $expires = (int) ($captcha['expires'] ?? 0);
    if ($code === '' || $expires < time()) {
        return false;
    }

    $input = trim((string) $input);

    return $input !== '' && strcasecmp($input, $code) === 0;
}

/** 渲染验证码输入控件（登录、注册表单共用）。 */
function captchaField(string $label = '验证码'): string
{
    return '<div class="captcha-field">'
        . '<img id="captchaImage" src="captcha.php" alt="图形验证码" width="120" height="40">'
        . '<input type="text" name="captcha" maxlength="4" autocomplete="off" required placeholder="' . e($label) . '">'
        . '<button type="button" class="captcha-refresh" data-captcha-refresh="captchaImage">换一张</button>'
        . '</div>';
}

/**
 * 从 Notion 页面链接或 ID 中解析出 32 位页面 ID；无法解析时返回空串。
 *
 * 页面链接形如 https://www.notion.so/Page-Title-{32位十六进制}，而标题本身常含有 a-f 字母，
 * 因此不能整体剥离非十六进制字符，必须从末尾锚定 32 位十六进制串。
 */
function extractNotionPageId(string $input): string
{
    $value = trim($input);
    if ($value === '') {
        return '';
    }

    // 去掉查询串与锚点（Notion 分享链接常在末尾附加 ?pvs=4 / #xxx）
    $value = (string) preg_split('/[?#]/', $value)[0];
    $value = rtrim($value, '/');
    if ($value === '') {
        return '';
    }

    // 纯 ID（32 位）或带横线的 UUID
    if (preg_match('/^[0-9a-fA-F-]{32,36}$/', $value) === 1) {
        $compact = normalizePageId($value);
        if (strlen($compact) === 32) {
            return $compact;
        }
    }

    $segment = substr($value, (int) strrpos($value, '/') + 1);
    if (preg_match('/([0-9a-fA-F]{32})$/', $segment, $matches) === 1) {
        return strtolower($matches[1]);
    }
    if (preg_match('/([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/', $segment, $matches) === 1) {
        return normalizePageId($matches[1]);
    }

    return '';
}

/**
 * 校验用户自有的 Notion 集成 Token 与父页面是否可用（Token 必须能读到该页面）。
 *
 * @return array{token:string,page_id:string}
 */
function assertNotionBinding(string $token, string $pageInput): array
{
    $token = trim($token);
    if ($token === '' || preg_match('/\s/', $token) === 1 || strlen($token) < 20 || strlen($token) > 200) {
        throw new RuntimeException('请填写有效的 Notion 集成 Token（以 ntn_ 或 secret_ 开头的一长串字符）。');
    }

    $pageId = extractNotionPageId($pageInput);
    if ($pageId === '') {
        throw new RuntimeException('无法从该链接或 ID 中解析出 Notion 页面，请粘贴页面链接或链接末尾的 32 位 ID。');
    }

    try {
        (new NotionClient($token))->retrievePage($pageId);
    } catch (NotionApiException $exception) {
        Logger::warning('notion', '校验用户绑定的 Notion 页面失败。', [
            'page_id' => $pageId,
            'reason' => $exception->getMessage(),
        ]);
        throw new RuntimeException('无法访问该 Notion 页面：请在 Notion 中把页面共享给该集成（页面右上角 ··· → 连接），并确认 Token 与页面属于同一工作区。');
    }

    return ['token' => $token, 'page_id' => $pageId];
}

/** 全部官方账户（优先级高的在前）。 */
function allOfficialAccounts(): array
{
    return Database::connection()
        ->query('SELECT * FROM official_accounts ORDER BY priority DESC, id ASC')
        ->fetchAll();
}

/** 已启用的官方账户。 */
function activeOfficialAccounts(): array
{
    return Database::connection()
        ->query('SELECT * FROM official_accounts WHERE is_enabled = 1 ORDER BY priority DESC, id ASC')
        ->fetchAll();
}

/** 读取单个官方账户；不存在时返回 null。 */
function findOfficialAccount(int $id): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM official_accounts WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $account = $statement->fetch();

    return is_array($account) ? $account : null;
}

/** 解密官方账户的 Token；密文为空或无法解密时返回空串。 */
function officialAccountToken(array $account): string
{
    $encrypted = (string) ($account['token_encrypted'] ?? '');
    if ($encrypted === '') {
        return '';
    }

    try {
        return Security::decrypt($encrypted, (string) appConfig()['app_key']);
    } catch (Throwable $exception) {
        Logger::warning('notion', '无法解密官方账户 Token。', [
            'account_id' => (int) ($account['id'] ?? 0),
            'reason' => $exception->getMessage(),
        ]);

        return '';
    }
}

/** 解密用户自有的 Notion Token；未绑定或密文无法解密时返回空串。 */
function userNotionToken(array $user): string
{
    $encrypted = (string) ($user['notion_token_encrypted'] ?? '');
    if ($encrypted === '') {
        return '';
    }

    try {
        return Security::decrypt($encrypted, (string) appConfig()['app_key']);
    } catch (Throwable $exception) {
        // 密文损坏或 app_key 变更时不应让整个页面崩溃，按「未绑定」处理并留痕
        Logger::warning('notion', '无法解密用户自有 Notion Token。', [
            'user_id' => (int) ($user['id'] ?? 0),
            'reason' => $exception->getMessage(),
        ]);

        return '';
    }
}

/** 用户当前使用的 Notion 账户来源：official（官方账户）或 own（自有账户）。 */
function userNotionSource(array $user): string
{
    return ($user['notion_account_source'] ?? '') === 'official' ? 'official' : 'own';
}

/** 用户是否已绑定可用的自有 Notion 账户（Token 与父页面都齐全）。 */
function hasOwnNotionAccount(array $user): bool
{
    return userNotionToken($user) !== '' && (string) ($user['notion_parent_page_id'] ?? '') !== '';
}

/**
 * 是否允许使用官方账户来源。
 *
 * 官方账户池只对有效期内的高级用户（与管理员）开放；账户池还必须至少有一个已启用账户，
 * 否则切换后照样无法上传，不如直接拒绝。
 */
function canUseOfficialSource(array $user): bool
{
    return (isAdmin($user) || isPremiumUser($user)) && activeOfficialAccounts() !== [];
}

/** 用户当前的 Notion 账户来源是否可用（来源可用 ≠ 有剩余容量，仅判断上传目标能否确定）。 */
function notionSourceUsable(array $user): bool
{
    return uploadTargets($user) !== [];
}

/**
 * 当前账号是否被禁止上传新文件。
 *
 * 降级为普通用户后，官方账户来源会被硬性禁用（官方账户不限容量，仅靠配额数学拦不住）；
 * 自有账户来源的普通用户仍可在 5 GB 配额内正常上传。返回空串表示允许上传，否则返回提示文案。
 */
function uploadBlockedReason(array $user): string
{
    if (userNotionSource($user) !== 'official' || isPremiumUser($user)) {
        return '';
    }

    // 区分「到期降级」与「从未开通」：没开通过的用户不该被提示「套餐已到期」
    return premiumExpiresAt($user) !== null
        ? '高级用户套餐已到期，官方账户上传已暂停；续费后即可恢复，也可以切换到自有账户继续上传。'
        : '官方账户仅对高级用户开放，当前无法用它上传新文件；开通高级套餐后即可使用，也可以切换到自有账户继续上传。';
}

/**
 * 绑定或更换自有的 Notion 账户（Token + 父页面）。
 *
 * Token 或父页面发生变化时会清除用户空间页缓存，避免沿用旧父页面下创建的页面。
 *
 * @param array<string,mixed> $user 当前用户行（需含 notion_token_encrypted 与 notion_parent_page_id）
 */
function bindOwnNotionAccount(int $userId, array $user, string $token, string $parentInput): void
{
    $binding = assertNotionBinding($token, $parentInput);

    $changed = userNotionToken($user) !== (string) $binding['token']
        || (string) ($user['notion_parent_page_id'] ?? '') !== (string) $binding['page_id'];

    $sql = 'UPDATE users SET notion_token_encrypted = :token, notion_parent_page_id = :page_id';
    if ($changed) {
        $sql .= ', notion_page_id = NULL';
    }
    $sql .= ' WHERE id = :id';

    Database::connection()->prepare($sql)->execute([
        'token' => Security::encrypt((string) $binding['token'], (string) appConfig()['app_key']),
        'page_id' => (string) $binding['page_id'],
        'id' => $userId,
    ]);

    Logger::info('notion', '用户绑定/更新了自有 Notion 账户。', [
        'user_id' => $userId,
        'changed' => $changed,
    ]);
}

/**
 * 切换用户的 Notion 账户来源。
 *
 * 只改变后续上传的目标账户：已上传文件在 upload_replicas 中逐副本记录了所属账户，
 * 因此切换后旧文件仍能从原账户下载，不受影响。
 */
function switchNotionSource(int $userId, array $user, string $source): void
{
    $source = $source === 'official' ? 'official' : 'own';

    if ($source === 'own') {
        if (!hasOwnNotionAccount($user)) {
            throw new RuntimeException('切换到自有账户前，请先填写并验证 Notion 集成 Token 与父页面。');
        }
    } elseif (!canUseOfficialSource($user)) {
        throw new RuntimeException('官方账户仅对有效期内的付费用户开放，请先购买高级用户套餐。');
    }

    Database::connection()
        ->prepare('UPDATE users SET notion_account_source = :source WHERE id = :id')
        ->execute(['source' => $source, 'id' => $userId]);

    // 使用官方账户但还没有固定分配时，立即按负载分配主备账户
    if ($source === 'official' && userOfficialAccounts($userId) === []) {
        autoAssignOfficialAccounts($userId);
    }

    Logger::info('notion', '用户切换了 Notion 账户来源。', [
        'user_id' => $userId,
        'source' => $source,
    ]);
}

/** 用户被固定分配的主备官方账户（主账户在前），并带出该账户下的用户空间页面缓存。 */
function userOfficialAccounts(int $userId): array
{
    $statement = Database::connection()->prepare(
        'SELECT a.*, r.slot, r.notion_page_id AS user_page_id
         FROM user_official_accounts r
         JOIN official_accounts a ON a.id = r.account_id
         WHERE r.user_id = :user_id
         ORDER BY r.slot ASC'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

/**
 * 重建用户的官方账户分配：按传入顺序写入主（slot 1）与备（slot 2）。
 *
 * @param list<int|string> $accountIds
 */
function assignOfficialAccounts(int $userId, array $accountIds): void
{
    $pdo = Database::connection();

    // 结算订单时本函数会处于外层事务中，此时不能再开事务（PDO 不支持嵌套事务）
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // 锁定该用户行：分配过程是「先删后插」，并发的自动分配/管理员分配会互相覆盖，
        // 甚至触发 uniq_user_slot 唯一键冲突，串行化到同一用户即可避免
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $userId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('用户不存在，无法分配官方账户。');
        }

        // 保留仍然保留的账户下已有的用户空间页面，避免重新分配时重复创建 Notion 页面
        $existing = [];
        $statement = $pdo->prepare('SELECT account_id, notion_page_id FROM user_official_accounts WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        foreach ($statement->fetchAll() as $row) {
            $existing[(int) $row['account_id']] = $row['notion_page_id'];
        }

        $pdo->prepare('DELETE FROM user_official_accounts WHERE user_id = :user_id')->execute(['user_id' => $userId]);

        $insert = $pdo->prepare(
            'INSERT INTO user_official_accounts (user_id, account_id, slot, notion_page_id)
             VALUES (:user_id, :account_id, :slot, :notion_page_id)'
        );
        $slot = 1;
        $seen = [];
        foreach ($accountIds as $accountId) {
            $accountId = (int) $accountId;
            // 最多主备两个槽位，且同一账户不可重复分配（受唯一索引约束）
            if ($slot > 2 || $accountId <= 0 || isset($seen[$accountId])) {
                continue;
            }
            $seen[$accountId] = true;
            $insert->execute([
                'user_id' => $userId,
                'account_id' => $accountId,
                'slot' => $slot,
                'notion_page_id' => $existing[$accountId] ?? null,
            ]);
            $slot++;
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** 内置高级用户组 ID；组不存在时返回 0。 */
function premiumGroupId(): int
{
    $statement = Database::connection()->prepare('SELECT id FROM `groups` WHERE name = :name LIMIT 1');
    $statement->execute(['name' => Schema::PREMIUM_GROUP_NAME]);

    return (int) $statement->fetchColumn();
}

/**
 * 写入后台告警（管理员在后台顶部告警条与告警列表中查看）。
 *
 * 相同内容的未读告警只保留一条，避免持续性故障刷屏；写入失败不影响主流程。
 * 除写库外还会写入运行日志，并在产生新告警时向管理员发送提醒邮件。
 *
 * @param array<string,mixed> $context
 */
function raiseAdminAlert(string $level, string $message, array $context = []): void
{
    // 运行日志与告警同源，便于在「运行日志」中按通道排查
    if ($level === 'error') {
        Logger::error('alert', $message, $context);
    } else {
        Logger::warning('alert', $message, $context);
    }

    $inserted = false;
    try {
        $pdo = Database::connection();
        $message = mb_strcut($message, 0, 500, 'UTF-8');
        $statement = $pdo->prepare('SELECT 1 FROM admin_alerts WHERE is_read = 0 AND message = :message LIMIT 1');
        $statement->execute(['message' => $message]);
        if (!$statement->fetch()) {
            $pdo->prepare('INSERT INTO admin_alerts (level, message, context) VALUES (:level, :message, :context)')
                ->execute([
                    'level' => $level,
                    'message' => $message,
                    'context' => $context === [] ? null : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            $inserted = true;
        }
    } catch (Throwable $exception) {
        Logger::warning('alert', '写入后台告警失败。', ['reason' => $exception->getMessage()]);
    }

    // 只在新告警时发邮件，避免重复提醒；同一请求内相同内容也只发一次
    if ($inserted) {
        sendAdminAlertMail($level, $message, $context);
    }
}

/** 未读后台告警（新的在前），用于后台顶部告警条。 */
function unreadAdminAlerts(int $limit = 20): array
{
    $limit = max(1, min($limit, 100));

    return Database::connection()
        ->query('SELECT id, level, message, created_at FROM admin_alerts WHERE is_read = 0 ORDER BY created_at DESC, id DESC LIMIT ' . $limit)
        ->fetchAll();
}

/**
 * 管理员告警邮件收件人：优先使用后台配置的收件邮箱，未配置时回退到管理员组内已验证的邮箱。
 *
 * @return list<string>
 */
function adminAlertRecipients(): array
{
    $configured = trim((string) Database::config('alert_email', ''));
    if ($configured !== '') {
        $list = [];
        foreach (preg_split('/[,，;；\s]+/u', $configured) ?: [] as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $list[] = $email;
            }
        }

        return array_values(array_unique($list));
    }

    $statement = Database::connection()->query(
        "SELECT u.email FROM users u
         JOIN `groups` g ON g.id = u.group_id
         WHERE g.is_admin = 1 AND u.email IS NOT NULL AND u.email <> '' AND u.email_verified_at IS NOT NULL
         GROUP BY u.email"
    );

    return array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), static fn (string $email): bool => $email !== ''));
}

/**
 * 向管理员发送告警邮件。
 *
 * SMTP 未配置或没有可用收件人时静默跳过；发送失败只记日志，绝不再触发告警（避免告警递归）。
 *
 * @param array<string,mixed> $context
 */
function sendAdminAlertMail(string $level, string $message, array $context = []): void
{
    static $sent = [];
    $fingerprint = md5($level . '|' . $message);
    if (isset($sent[$fingerprint])) {
        return;
    }
    $sent[$fingerprint] = true;

    if (!smtpConfigured()) {
        return;
    }
    $recipients = adminAlertRecipients();
    if ($recipients === []) {
        return;
    }

    try {
        $mailer = Mailer::fromConfig();
        $subject = ($level === 'error' ? '[严重] ' : '[提醒] ') . 'Notion Drive 后台告警';
        $body = "后台产生了一条新的告警：\n\n级别：" . $level . "\n内容：" . $message;
        if ($context !== []) {
            $body .= "\n\n上下文：\n" . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $body .= "\n\n请登录管理后台查看详情。\n\n本邮件由系统自动发送，请勿直接回复。";
        foreach ($recipients as $recipient) {
            try {
                $mailer->send($recipient, $subject, $body);
            } catch (Throwable $exception) {
                Logger::warning('alert', '发送管理员告警邮件失败。', ['to' => $recipient, 'reason' => $exception->getMessage()]);
            }
        }
    } catch (Throwable $exception) {
        Logger::warning('alert', '初始化管理员告警邮件失败。', ['reason' => $exception->getMessage()]);
    }
}

/**
 * 用户本次上传的目标账户：自有账户来源只有一个目标，官方账户来源按主备槽位顺序返回。
 *
 * 已停用或 Token / 根页面无效的账户会被跳过；返回空数组表示当前没有任何可用目标。
 *
 * @param array<string,mixed> $user
 * @return list<array{type:string,account_id:?int,token:string,root_page_id:string,label:string,page_id:string}>
 */
function uploadTargets(array $user): array
{
    if (userNotionSource($user) === 'own') {
        $token = userNotionToken($user);
        $rootPageId = (string) ($user['notion_parent_page_id'] ?? '');
        if ($token === '' || normalizePageId($rootPageId) === '') {
            return [];
        }

        return [[
            'type' => 'own',
            'account_id' => null,
            'token' => $token,
            'root_page_id' => $rootPageId,
            'label' => '自有账户',
            'page_id' => (string) ($user['notion_page_id'] ?? ''),
        ]];
    }

    // 官方账户仅对有效期内的高级用户（与管理员）开放；降级后立即停止向官方账户写入
    if (uploadBlockedReason($user) !== '') {
        return [];
    }

    $targets = [];
    foreach (userOfficialAccounts((int) $user['id']) as $account) {
        if ((int) ($account['is_enabled'] ?? 0) !== 1) {
            continue;
        }
        $token = officialAccountToken($account);
        $rootPageId = (string) ($account['root_page_id'] ?? '');
        if ($token === '' || normalizePageId($rootPageId) === '') {
            continue;
        }
        $targets[] = [
            'type' => 'official',
            'account_id' => (int) $account['id'],
            'token' => $token,
            'root_page_id' => $rootPageId,
            'label' => (string) $account['label'],
            'page_id' => (string) ($account['user_page_id'] ?? ''),
        ];
    }

    return $targets;
}

/**
 * 取得（必要时创建）用户在某个目标账户下的空间页面，并把页面 ID 缓存到对应记录。
 *
 * @param array<string,mixed> $user
 * @param array<string,mixed> $target uploadTargets() 返回的一项
 */
function ensureUserSpacePage(array $user, array $target, NotionClient $client): string
{
    $userId = (int) $user['id'];
    $accountId = $target['account_id'] === null ? null : (int) $target['account_id'];
    $pageId = (string) $target['page_id'];

    // 该账户下还没有文件时，页面缓存可能已被用户在 Notion 里移动或删除，先自检一次
    if ($pageId !== '' && accountReplicaCount($userId, $accountId) === 0
        && !$client->pageIsUnder($pageId, (string) $target['root_page_id'])) {
        Logger::info('upload', '用户空间页面已不在根页面下，重新创建。', [
            'user_id' => $userId,
            'account_id' => $accountId,
        ]);
        $pageId = '';
    }

    if ($pageId !== '') {
        return $pageId;
    }

    $pageId = $client->createUserSpace((string) $target['root_page_id'], (string) $user['username']);
    if ($accountId === null) {
        Database::connection()->prepare('UPDATE users SET notion_page_id = :page_id WHERE id = :id')
            ->execute(['page_id' => $pageId, 'id' => $userId]);
    } else {
        Database::connection()->prepare(
            'UPDATE user_official_accounts SET notion_page_id = :page_id
             WHERE user_id = :user_id AND account_id = :account_id'
        )->execute(['page_id' => $pageId, 'user_id' => $userId, 'account_id' => $accountId]);
    }

    return $pageId;
}

/**
 * 双写：把临时文件写入用户的全部目标账户，至少一份成功即视为成功。
 *
 * 返回统一的存储名与成功副本列表（role 依次为 1、2）。单份失败只记日志并告警；
 * 全部失败时抛异常，对外只提示「存储服务暂时不可用」，不透露账户信息。
 *
 * @param array<string,mixed> $user
 * @return array{storage_name:string,replicas:list<array<string,mixed>>}
 */
function writeNotionReplicas(array $user, string $temporaryPath, string $filename, string $mime, int $size): array
{
    $targets = uploadTargets($user);
    if ($targets === []) {
        throw new RuntimeException('当前账号尚未完成 Notion 账户绑定，请先在「账户管理」中绑定后再上传。');
    }

    // 同一文件在所有账户中共用同一个存储名，便于人工在 Notion 中核对
    $storageName = uniqueStorageName((int) $user['id'], $filename, $temporaryPath);
    $replicas = [];

    foreach ($targets as $target) {
        try {
            $client = new NotionClient((string) $target['token']);
            $pageId = ensureUserSpacePage($user, $target, $client);
            $result = $client->uploadAndAttach($temporaryPath, $storageName, $mime, $size, $pageId);
            $blockId = (string) ($result['block']['results'][0]['id'] ?? '');
            if ($blockId === '') {
                throw new RuntimeException('Notion 未返回文件块。');
            }
            $replicas[] = [
                'role' => count($replicas) + 1,
                'account_type' => (string) $target['type'],
                'official_account_id' => $target['account_id'],
                'notion_file_upload_id' => (string) ($result['file_upload_id'] ?? ''),
                'notion_block_id' => $blockId,
            ];
        } catch (Throwable $exception) {
            Logger::warning('upload', 'Notion 副本写入失败。', [
                'user_id' => (int) $user['id'],
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
                'reason' => $exception->getMessage(),
            ]);
            raiseAdminAlert('error', sprintf('账户「%s」写入失败：%s', (string) $target['label'], $exception->getMessage()), [
                'stage' => 'upload',
                'account_type' => $target['type'],
                'account_id' => $target['account_id'],
            ]);
        }
    }

    if ($replicas === []) {
        throw new RuntimeException('存储服务暂时不可用，请稍后重试。');
    }

    return ['storage_name' => $storageName, 'replicas' => $replicas];
}

/**
 * 某个文件的可用下载副本（按 role 升序，主副本在前）。
 *
 * 已停用或 Token 失效的官方账户仍会列出：用户在其中的文件依然可以尝试下载，失败后再回退到下一份。
 *
 * @param array<string,mixed> $upload uploads 记录（需含 id）
 * @param array<string,mixed> $owner  文件所属用户（需含 notion_token_encrypted）
 * @return list<array{replica_id:int,type:string,account_id:?int,label:string,token:string,block_id:string}>
 */
function uploadReplicaTargets(array $upload, array $owner): array
{
    $statement = Database::connection()->prepare(
        'SELECT r.id, r.account_type, r.official_account_id, r.notion_block_id, a.label, a.token_encrypted
         FROM upload_replicas r
         LEFT JOIN official_accounts a ON a.id = r.official_account_id
         WHERE r.upload_id = :upload_id AND r.status = \'active\'
         ORDER BY r.role ASC'
    );
    $statement->execute(['upload_id' => (int) $upload['id']]);

    $ownerToken = userNotionToken($owner);
    $targets = [];
    foreach ($statement->fetchAll() as $row) {
        $blockId = (string) ($row['notion_block_id'] ?? '');
        if ($blockId === '') {
            continue;
        }

        if ((string) $row['account_type'] === 'own') {
            if ($ownerToken === '') {
                continue;
            }
            $targets[] = [
                'replica_id' => (int) $row['id'],
                'type' => 'own',
                'account_id' => null,
                'label' => '自有账户',
                'token' => $ownerToken,
                'block_id' => $blockId,
            ];
            continue;
        }

        $token = officialAccountToken($row);
        if ($token === '') {
            continue;
        }
        $targets[] = [
            'replica_id' => (int) $row['id'],
            'type' => 'official',
            'account_id' => (int) $row['official_account_id'],
            'label' => (string) ($row['label'] ?? '官方账户'),
            'token' => $token,
            'block_id' => $blockId,
        ];
    }

    return $targets;
}

/**
 * 归档某个文件在全部账户中的副本，并把副本记录统一标记为已删除。
 *
 * 单份归档失败只记日志并告警，不影响文件记录的删除。
 *
 * @param array<string,mixed> $upload
 * @param array<string,mixed> $owner
 */
function archiveUploadReplicas(array $upload, array $owner): void
{
    foreach (uploadReplicaTargets($upload, $owner) as $target) {
        try {
            (new NotionClient((string) $target['token']))->archiveBlock((string) $target['block_id']);
        } catch (Throwable $exception) {
            Logger::warning('file', '归档 Notion 文件块失败。', [
                'user_id' => (int) ($owner['id'] ?? 0),
                'upload_id' => (int) $upload['id'],
                'account_id' => $target['account_id'],
                'reason' => $exception->getMessage(),
            ]);
            raiseAdminAlert('warning', sprintf('账户「%s」删除文件失败：%s', (string) $target['label'], $exception->getMessage()), [
                'stage' => 'delete',
                'account_id' => $target['account_id'],
            ]);
        }
    }

    Database::connection()->prepare("UPDATE upload_replicas SET status = 'deleted' WHERE upload_id = :upload_id")
        ->execute(['upload_id' => (int) $upload['id']]);
}

/**
 * 批量读取文件的副本来源，用于文件列表标记来源与副本数。
 *
 * @param list<int|string> $uploadIds
 * @return array<int,list<array{type:string,label:string,enabled:bool}>>
 */
function uploadReplicaSources(array $uploadIds): array
{
    $ids = [];
    foreach ($uploadIds as $uploadId) {
        $uploadId = (int) $uploadId;
        if ($uploadId > 0) {
            $ids[$uploadId] = true;
        }
    }
    if ($ids === []) {
        return [];
    }

    $ids = array_keys($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = Database::connection()->prepare(
        "SELECT r.upload_id, r.account_type, a.label, a.is_enabled
         FROM upload_replicas r
         LEFT JOIN official_accounts a ON a.id = r.official_account_id
         WHERE r.status = 'active' AND r.upload_id IN ({$placeholders})
         ORDER BY r.upload_id ASC, r.role ASC"
    );
    $statement->execute($ids);

    $sources = [];
    foreach ($statement->fetchAll() as $row) {
        $isOwn = (string) $row['account_type'] === 'own';
        $sources[(int) $row['upload_id']][] = [
            'type' => $isOwn ? 'own' : 'official',
            'label' => $isOwn ? '自有账户' : (string) ($row['label'] ?? '官方账户'),
            'enabled' => $isOwn || (int) ($row['is_enabled'] ?? 0) === 1,
        ];
    }

    return $sources;
}

// ---------------------------------------------------------------------------
// 付费系统
// ---------------------------------------------------------------------------

/** 站点根地址：优先取后台配置，留空时按当前请求自动识别（用于拼装支付回调地址）。 */
function siteUrl(): string
{
    $configured = trim((string) Database::config('site_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    // Host 头由客户端提供，会出现在回调地址里，格式不合法时宁可不识别
    if ($host === '' || !preg_match('/^(?:\[[0-9A-Fa-f:]+\]|[A-Za-z0-9.\-]+)(?::\d{1,5})?$/', $host)) {
        return '';
    }

    $scheme = requestIsHttps() ? 'https://' : 'http://';

    // 应用可能部署在子目录，需要把脚本所在目录一并带上
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }

    return $scheme . $host . $scriptDir;
}

/** 拼装站点内的绝对地址。 */
function absoluteUrl(string $path): string
{
    $base = siteUrl();

    return $base === '' ? $path : $base . '/' . ltrim($path, '/');
}

/** 易支付的异步通知地址（网关不支持带参数，只能是固定路径）。 */
function epayNotifyUrl(): string
{
    return absoluteUrl('pay_notify.php');
}

/** 易支付的同步回跳地址（网关不支持带参数，回跳后由该页自行跳转带上订单号）。 */
function epayReturnUrl(): string
{
    return absoluteUrl('pay_return.php');
}

/** 高级用户套餐档位：月数 => 价格（元），价格可在后台调整。 */
function premiumPlans(): array
{
    $defaults = [1 => '30.00', 3 => '90.00', 6 => '180.00', 12 => '360.00'];

    $plans = [];
    foreach ($defaults as $months => $fallback) {
        $price = (float) Database::config('premium_price_' . $months, $fallback);
        // 价格兜底为 0.01 元，避免后台误填 0 或负数导致无法下单
        $plans[$months] = max(0.01, round($price, 2));
    }

    return $plans;
}

/** 指定月数的套餐；档位不存在时返回 null。 */
function premiumPlan(int $months): ?array
{
    $plans = premiumPlans();
    if (!isset($plans[$months])) {
        return null;
    }

    return ['months' => $months, 'price' => $plans[$months], 'label' => $months . ' 个月'];
}

/**
 * 易支付支持的全部支付渠道（type => 中文名）。
 *
 * @return array<string,string>
 */
function epayPayTypeOptions(): array
{
    return ['alipay' => '支付宝', 'wxpay' => '微信支付'];
}

/**
 * 已启用的支付渠道（易支付的 type 参数）。
 *
 * @return array<string,string>
 */
function epayPayTypes(): array
{
    $all = epayPayTypeOptions();

    $types = [];
    foreach (explode(',', (string) Database::config('epay_pay_types', 'alipay,wxpay')) as $type) {
        $type = trim($type);
        if (isset($all[$type]) && !isset($types[$type])) {
            $types[$type] = $all[$type];
        }
    }

    return $types;
}

/** 支付渠道中文名。 */
function payTypeLabel(string $type): string
{
    return ['alipay' => '支付宝', 'wxpay' => '微信支付'][$type] ?? $type;
}

/** 订单状态中文名。 */
function orderStatusLabel(string $status): string
{
    return match ($status) {
        'paid' => '已支付',
        'closed' => '已关闭',
        default => '待支付',
    };
}

/** 生成唯一订单号：ND + 年月日时分秒 + 4 位随机数。 */
function generateOrderNo(): string
{
    $statement = Database::connection()->prepare('SELECT 1 FROM orders WHERE order_no = :order_no LIMIT 1');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $orderNo = 'ND' . date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $statement->execute(['order_no' => $orderNo]);
        if (!$statement->fetch()) {
            return $orderNo;
        }
    }

    throw new RuntimeException('订单号生成失败，请重试。');
}

/** 按订单号读取订单；不存在时返回 null。 */
function findOrderByNo(string $orderNo): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM orders WHERE order_no = :order_no LIMIT 1');
    $statement->execute(['order_no' => $orderNo]);
    $order = $statement->fetch();

    return is_array($order) ? $order : null;
}

/** 创建待支付订单。 */
function createOrder(int $userId, int $months, float $amount, string $payType): array
{
    $pdo = Database::connection();
    $orderNo = generateOrderNo();

    $pdo->beginTransaction();
    try {
        // 锁定用户行：双击「立即支付」会并发进入这里，此时「关闭旧单 + 插入新单」之间没有任何行锁，
        // 两个请求都看不到彼此的待支付订单，最终会同时插入两笔 pending。串行化到同一用户即可避免
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $userId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('用户不存在，无法创建订单。');
        }

        // 同一订单号在网关重复提单的行为不可靠，因此每次下单都换新订单号并关闭该用户此前的待支付订单
        $pdo->prepare("UPDATE orders SET status = 'closed' WHERE user_id = :user_id AND status = 'pending'")
            ->execute(['user_id' => $userId]);

        $pdo->prepare(
            "INSERT INTO orders (order_no, user_id, months, amount, pay_type, status)
             VALUES (:order_no, :user_id, :months, :amount, :pay_type, 'pending')"
        )->execute([
            'order_no' => $orderNo,
            'user_id' => $userId,
            'months' => $months,
            'amount' => number_format($amount, 2, '.', ''),
            'pay_type' => $payType,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    $order = findOrderByNo($orderNo);
    if ($order === null) {
        throw new RuntimeException('订单创建失败，请重试。');
    }

    return $order;
}

/** 高级用户套餐的到期时间；未开通时返回 null（null 也代表永久有效）。 */
function premiumExpiresAt(array $user): ?string
{
    $value = $user['premium_expires_at'] ?? null;

    return $value === null || $value === '' ? null : (string) $value;
}

/** 套餐是否已逾期：未设置到期时间表示永久有效。 */
function premiumExpired(array $user): bool
{
    $expiresAt = premiumExpiresAt($user);

    return $expiresAt !== null && strtotime($expiresAt) <= time();
}

/** 用户是否为有效的高级用户（管理员始终有效；到期时间已过视为失效）。 */
function isPremiumUser(array $user): bool
{
    if (isAdmin($user)) {
        return true;
    }
    if ((int) ($user['group_id'] ?? 0) !== premiumGroupId()) {
        return false;
    }

    return !premiumExpired($user);
}

/** 距到期剩余天数（不足一天按一天计）；永久有效或未开通返回 null。 */
function premiumDaysLeft(array $user): ?int
{
    $expiresAt = premiumExpiresAt($user);
    if ($expiresAt === null) {
        return null;
    }

    $remaining = strtotime($expiresAt) - time();

    return $remaining <= 0 ? 0 : (int) ceil($remaining / 86400);
}

/** 套餐状态文案（完整版，用于会员套餐面板与套餐页）。 */
function premiumStatusText(array $user): string
{
    if (isAdmin($user)) {
        return '管理员 · 不受套餐限制';
    }
    $expiresAt = premiumExpiresAt($user);
    if (isPremiumUser($user)) {
        return $expiresAt === null
            ? '高级用户 · 永久有效'
            : '高级用户 · 剩余 ' . (int) premiumDaysLeft($user) . ' 天（' . date('Y-m-d', (int) strtotime($expiresAt)) . ' 到期）';
    }
    if ($expiresAt !== null && premiumExpired($user)) {
        return '套餐已到期（' . date('Y-m-d', (int) strtotime($expiresAt)) . '），续费后恢复高级权限';
    }

    return '普通用户';
}

/** 套餐状态文案（精简版，用于侧栏套餐卡）。 */
function premiumShortStatus(array $user): string
{
    if (isAdmin($user)) {
        return '不受限制';
    }
    $expiresAt = premiumExpiresAt($user);
    if (isPremiumUser($user)) {
        return $expiresAt === null ? '高级 · 永久' : '高级 · ' . (int) premiumDaysLeft($user) . ' 天';
    }

    // 到期时间在未来但权限组不是高级组（管理员手工改过组）时按普通用户展示，不能谎报「已到期」
    return $expiresAt !== null && premiumExpired($user) ? '已到期' : '普通用户';
}

/**
 * 站内到期提醒文案：距到期 3 天以内、已到期或刚被降级时返回提示，其余情况返回空字符串。
 *
 * 只提醒曾经开通过套餐的用户（设置了到期时间），普通用户与管理员不提示。
 */
function premiumNotice(array $user): string
{
    if (isAdmin($user)) {
        return '';
    }
    $expiresAt = premiumExpiresAt($user);
    if ($expiresAt === null) {
        return '';
    }
    $date = date('Y-m-d', (int) strtotime($expiresAt));
    if (premiumExpired($user)) {
        // 已到期：可能已被懒检查降级为普通用户，两种情况都提示续费
        return '你的高级套餐已于 ' . $date . ' 到期，账号已恢复普通用户权限（容量 5 GB、单文件 5 MB）；已上传文件仍可查看与下载，续费后即可恢复高级权益。';
    }
    if ((int) ($user['group_id'] ?? 0) !== premiumGroupId()) {
        return '';
    }
    $daysLeft = premiumDaysLeft($user);
    if ($daysLeft !== null && $daysLeft <= 3) {
        return '你的高级套餐将在 ' . (int) $daysLeft . ' 天后到期（' . $date . '），到期后无法上传新文件，请及时续费。';
    }

    return '';
}

/**
 * 高级套餐到期的懒检查：登录用户访问任意页面时把已到期的付费用户降级回普通用户组。
 *
 * 只降级权限组，保留到期时间（作为「曾开通/已降级」的判定依据）、已上传文件与官方账户绑定，
 * 因此降级后文件仍可查看与下载；能否上传新文件由配额数学天然决定（降级后超出普通组容量即无法上传）。
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed> 降级后的用户数据（未触发降级时原样返回）
 */
function downgradeExpiredPremium(array $user): array
{
    if (isAdmin($user) || (int) ($user['group_id'] ?? 0) !== premiumGroupId() || !premiumExpired($user)) {
        return $user;
    }

    try {
        $groupId = defaultGroupId();
        if ($groupId <= 0) {
            return $user;
        }
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET group_id = :group_id WHERE id = :id')
            ->execute(['group_id' => $groupId, 'id' => (int) $user['id']]);

        // 同步内存中的数据，避免本次请求内仍按高级组判定
        $group = findGroup($groupId);
        $user['group_id'] = $groupId;
        $user['group_name'] = (string) ($group['name'] ?? Schema::DEFAULT_GROUP_NAME);
        $user['group_quota_bytes'] = $group['quota_bytes'] ?? null;
        $user['group_single_file_limit_bytes'] = $group['single_file_limit_bytes'] ?? null;
        $user['group_is_admin'] = (int) ($group['is_admin'] ?? 0);

        Logger::info('payment', '高级套餐已到期，账号自动降级为普通用户。', [
            'user_id' => (int) $user['id'],
            'expires_at' => premiumExpiresAt($user),
        ]);
    } catch (Throwable $exception) {
        // 降级失败不影响正常访问，下次访问会重试
        Logger::warning('payment', '自动降级失败。', [
            'user_id' => (int) $user['id'],
            'reason' => $exception->getMessage(),
        ]);
    }

    return $user;
}

/**
 * 到期前 3 天的邮件提醒（无定时任务，故在用户访问页面时懒触发）。
 *
 * 同一到期周期只提醒一次：发送成功才写入去重标记；续费或管理员调整到期时间会清空该标记。
 */
function maybeSendPremiumReminder(array $user): void
{
    if (isAdmin($user) || !isPremiumUser($user) || !emailBound($user) || !smtpConfigured()) {
        return;
    }
    $expiresAt = premiumExpiresAt($user);
    if ($expiresAt === null) {
        return;
    }
    $daysLeft = premiumDaysLeft($user);
    if ($daysLeft === null || $daysLeft > 3) {
        return;
    }
    if ((string) ($user['premium_reminder_sent_at'] ?? '') !== '') {
        return;
    }

    $date = date('Y-m-d', (int) strtotime($expiresAt));
    $body = "你好 " . (string) $user['username'] . "：\n\n"
        . "你的 Notion Drive 高级套餐将于 " . $date . " 到期（剩余 " . (int) $daysLeft . " 天）。\n"
        . "到期后账号会自动恢复为普通用户权限（容量 5 GB、单文件 5 MB），"
        . "已上传的文件仍可查看与下载，但无法再上传新文件。\n\n"
        . "如需继续使用不限总容量的高级权益，请登录后前往「会员套餐」页面续费：\n"
        . absoluteUrl('pay.php') . "\n\n"
        . "本邮件由系统自动发送，请勿直接回复。";

    try {
        Mailer::fromConfig()->send((string) $user['email'], 'Notion Drive 高级套餐即将到期', $body);
        Database::connection()
            ->prepare('UPDATE users SET premium_reminder_sent_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $user['id']]);
        Logger::info('payment', '已发送高级套餐到期提醒邮件。', [
            'user_id' => (int) $user['id'],
            'expires_at' => $expiresAt,
        ]);
    } catch (Throwable $exception) {
        // 发信失败只记日志，下次访问会重试；不写标记以免漏掉提醒
        Logger::warning('payment', '发送高级套餐到期提醒邮件失败。', [
            'user_id' => (int) $user['id'],
            'reason' => $exception->getMessage(),
        ]);
    }
}

/** 按当前负载自动为用户分配主备官方账户（负载最低者优先）。 */
function autoAssignOfficialAccounts(int $userId): void
{
    $accounts = activeOfficialAccounts();
    if ($accounts === []) {
        return;
    }

    $loads = [];
    foreach (Database::connection()
        ->query('SELECT account_id, COUNT(*) AS total FROM user_official_accounts GROUP BY account_id')
        ->fetchAll() as $row) {
        $loads[(int) $row['account_id']] = (int) $row['total'];
    }
    usort($accounts, static fn (array $left, array $right): int => ($loads[(int) $left['id']] ?? 0) <=> ($loads[(int) $right['id']] ?? 0));

    $accountIds = [];
    foreach (array_slice($accounts, 0, 2) as $account) {
        $accountIds[] = (int) $account['id'];
    }

    assignOfficialAccounts($userId, $accountIds);
}

/**
 * 开通或续费高级用户。
 *
 * 续费顺延：原套餐未到期时从原到期时间起算，已到期或首次开通时从当前时间起算。
 *
 * @return array{expires_before:?string,expires_after:string}
 */
function grantPremium(int $userId, int $months): array
{
    $months = max(1, $months);
    $pdo = Database::connection();

    // 结算订单时本函数会被包在外层事务里，此时不能重复开启事务（PDO 不支持嵌套事务）
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // 先锁定用户行再读取到期时间：同一用户的两笔订单并发结算时，后到者会等前一笔提交后
        // 再基于新的到期时间顺延，否则两笔都从同一个旧到期时间起算，会丢掉一期时长
        $statement = $pdo->prepare(
            'SELECT premium_expires_at, notion_account_source, group_id FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('用户不存在，无法开通高级用户。');
        }

        // 权限组单独读取，避免 FOR UPDATE 顺带锁住 groups 行而阻塞其他用户的结算
        $groupStatement = $pdo->prepare('SELECT is_admin FROM `groups` WHERE id = :id LIMIT 1');
        $groupStatement->execute(['id' => (int) ($user['group_id'] ?? 0)]);
        $isAdminGroup = (int) $groupStatement->fetchColumn() === 1;

        $before = $user['premium_expires_at'] !== null ? (string) $user['premium_expires_at'] : null;
        $base = $before !== null && strtotime($before) > time() ? (int) strtotime($before) : time();
        $after = date('Y-m-d H:i:s', (int) strtotime('+' . $months . ' month', $base));

        // 同时清空到期提醒标记，让新的到期周期可以重新提醒
        $pdo->prepare('UPDATE users SET premium_expires_at = :expires_at, premium_reminder_sent_at = NULL WHERE id = :id')
            ->execute(['expires_at' => $after, 'id' => $userId]);

        // 管理员不随付费调整权限组，避免把管理员降级出管理组
        if (!$isAdminGroup) {
            $groupId = premiumGroupId();
            if ($groupId > 0) {
                $pdo->prepare('UPDATE users SET group_id = :group_id WHERE id = :id')
                    ->execute(['group_id' => $groupId, 'id' => $userId]);
            }
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    // 使用官方账户的高级用户必须具备可用的官方账户，否则付费后依然无法上传
    if (userNotionSource($user) === 'official' && userOfficialAccounts($userId) === []) {
        autoAssignOfficialAccounts($userId);
    }

    Logger::info('payment', '开通/续费高级用户。', [
        'user_id' => $userId,
        'months' => $months,
        'expires_before' => $before,
        'expires_after' => $after,
    ]);

    return ['expires_before' => $before, 'expires_after' => $after];
}

/**
 * 结算订单并开通/续费高级用户（幂等）。
 *
 * 异步通知与主动查单可能并发到达，这里用行锁保证同一订单只会开通一次。
 *
 * @param array<string,mixed> $order 订单行
 * @param array<string,mixed> $notify 网关原始回调参数（主动查单结算时传空数组）
 * @return bool 本次是否真正完成了开通
 */
function settleOrderPayment(array $order, string $tradeNo, array $notify = []): bool
{
    $pdo = Database::connection();

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => (int) $order['id']]);
        $locked = $statement->fetch();

        // 已支付过的订单直接跳过，避免重复叠加套餐时长
        if (!is_array($locked) || (string) $locked['status'] === 'paid') {
            $pdo->commit();
            return false;
        }

        $result = grantPremium((int) $locked['user_id'], (int) $locked['months']);

        $pdo->prepare(
            "UPDATE orders
             SET status = 'paid', trade_no = :trade_no, expires_before = :expires_before,
                 expires_after = :expires_after, notify_raw = :notify_raw, paid_at = NOW()
             WHERE id = :id"
        )->execute([
            'trade_no' => $tradeNo === '' ? null : mb_strcut($tradeNo, 0, 64, 'UTF-8'),
            'expires_before' => $result['expires_before'],
            'expires_after' => $result['expires_after'],
            'notify_raw' => $notify === [] ? null : (string) json_encode($notify, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => (int) $locked['id'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    Logger::info('payment', '订单支付成功并已开通高级用户。', [
        'order_no' => (string) $order['order_no'],
        'user_id' => (int) $order['user_id'],
        'trade_no' => $tradeNo,
    ]);

    return true;
}

/**
 * 主动向网关查单并对账结算（异步通知丢失时的兜底）。
 *
 * 用户从网关收银台回跳、或手动点击「刷新状态」时调用。
 *
 * @param array<string,mixed> $order 订单行
 * @return array{paid:bool,message:string} paid 为 true 表示订单已确认支付并完成开通
 */
function reconcileOrder(array $order): array
{
    $epay = Epay::fromConfig();
    if ($epay === null) {
        return ['paid' => false, 'message' => '支付功能尚未配置，请联系管理员。'];
    }

    $result = $epay->queryOrder((string) $order['order_no']);
    if ($result === null) {
        return ['paid' => false, 'message' => '暂时无法连接支付网关，请稍后点击「刷新状态」重试。'];
    }

    if (!Epay::isPaidResult($result)) {
        return ['paid' => false, 'message' => '该订单尚未完成支付，支付后请点击「刷新状态」。'];
    }

    // 金额核对：网关回传金额与订单金额不符时拒绝开通并留下告警（查单接口未回传金额时跳过）
    $paidAmount = $result['money'] ?? $result['amount'] ?? null;
    if ($paidAmount !== null && abs((float) $paidAmount - (float) $order['amount']) >= 0.01) {
        Logger::error('payment', '查单金额与订单金额不一致，已拒绝开通。', [
            'order_no' => (string) $order['order_no'],
            'expected' => (string) $order['amount'],
        ]);
        raiseAdminAlert('error', '订单查单金额不一致，请人工核对：' . (string) $order['order_no']);

        return ['paid' => false, 'message' => '订单金额校验失败，请联系管理员处理。'];
    }

    $settled = settleOrderPayment($order, (string) ($result['trade_no'] ?? ''), $result);

    return [
        'paid' => true,
        'message' => $settled ? '支付成功，高级用户已开通。' : '该订单已完成支付。',
    ];
}
