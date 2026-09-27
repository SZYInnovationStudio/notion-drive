<?php

declare(strict_types=1);

use NotionDrive\Bans\BanService;
use NotionDrive\Database;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Mailer;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\NotionSpaceRepair;
use NotionDrive\Schema;
use NotionDrive\Security;

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 站点 ICP 备案号：在「管理后台 · 系统设置 → 站点信息」填写，留空则不显示。 */
function icpLicense(): string
{
    // 安装向导阶段还没有 config 表（installed.lock 未生成），此时视为未填写
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return '';
    }

    return trim((string) Database::config('icp_license', ''));
}

/** 站点公网安备号：在「管理后台 · 系统设置 → 站点信息」填写，留空则不显示。 */
function policeLicense(): string
{
    // 安装向导阶段还没有 config 表（installed.lock 未生成），此时视为未填写
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return '';
    }

    return trim((string) Database::config('police_license', ''));
}

/** 站点名称：同时用作页面内站点名与浏览器标签标题，留空回落「Notion Drive」。 */
function siteName(): string
{
    // 安装向导阶段还没有 config 表（installed.lock 未生成），此时用默认名称
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return 'Notion Drive';
    }

    $name = trim((string) Database::config('site_name', ''));

    return $name === '' ? 'Notion Drive' : $name;
}

/** 浏览器标签标题：页面名 · 站点名称。 */
function siteTitleTag(string $page): string
{
    return '<title>' . e($page . ' · ' . siteName()) . '</title>';
}

/** 管理员上传的 favicon 文件路径（相对站点根），未上传或文件不存在返回空串。 */
function siteFaviconPath(): string
{
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return '';
    }

    $path = trim((string) Database::config('site_favicon', ''));

    return $path !== '' && is_file(dirname(__DIR__) . '/' . $path) ? $path : '';
}

/** 站点资源（logo/favicon）的对外访问地址：storage 受 .htaccess 保护，须经 site-asset.php 代理输出。 */
function siteAssetUrl(string $kind): string
{
    $path = $kind === 'favicon' ? siteFaviconPath() : siteLogoPath();
    if ($path === '') {
        return '';
    }

    return 'site-asset.php?k=' . $kind . '&amp;v=' . e(substr(md5(basename($path)), 0, 8));
}

/** favicon 标签：已上传 ico 用服务器文件，否则回落内置 SVG。 */
function siteFaviconTag(): string
{
    $url = siteAssetUrl('favicon');

    return $url === ''
        ? '<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">'
        : '<link rel="icon" type="image/x-icon" href="' . $url . '">';
}

/** 管理员上传的站点 logo 文件路径（相对站点根），未上传或文件不存在返回空串。 */
function siteLogoPath(): string
{
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return '';
    }

    $path = trim((string) Database::config('site_logo', ''));

    return $path !== '' && is_file(dirname(__DIR__) . '/' . $path) ? $path : '';
}

/** 品牌标识：已上传 logo 用图片，否则用站点名首字母方块。 */
function brandMarkHtml(): string
{
    $url = siteAssetUrl('logo');
    if ($url !== '') {
        return '<img class="brand-logo" src="' . $url . '" alt="">';
    }

    return '<span class="brand-mark">' . e(mb_strtoupper(mb_substr(siteName(), 0, 1))) . '</span>';
}

/** 全站品牌链接（logo + 站点名称）。 */
function brandHtml(string $href): string
{
    return '<a class="brand" href="' . e($href) . '">' . brandMarkHtml() . '<span>' . e(siteName()) . '</span></a>';
}

/** 公告正文 Markdown 渲染：仅支持标题、无序/有序列表、段落、粗体、斜体、行内代码、链接与站内图片。 */
function renderMarkdown(string $text): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $html = '';
    $listType = '';
    $paragraph = [];

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph !== []) {
            $html .= '<p>' . implode('<br>', array_map('renderMarkdownInline', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$listType, &$html): void {
        if ($listType !== '') {
            $html .= '</' . $listType . '>';
            $listType = '';
        }
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
            $flushParagraph();
            $closeList();
            $level = (string) min(strlen($m[1]) + 3, 6); // 正文标题降级，避免与页面 h1/h2 冲突
            $html .= '<h' . $level . '>' . renderMarkdownInline($m[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m) === 1) {
            $flushParagraph();
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . renderMarkdownInline($m[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m) === 1) {
            $flushParagraph();
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . renderMarkdownInline($m[1]) . '</li>';
            continue;
        }

        $closeList();
        $paragraph[] = $line;
    }

    $flushParagraph();
    $closeList();

    return $html;
}

/** Markdown 行内渲染：先转义 HTML，再识别图片/链接/强调；图片与站内链接仅接受 site-asset.php 代理地址。 */
function renderMarkdownInline(string $text): string
{
    // 行内代码先抽出占位，避免其内容被后续规则改写
    $code = [];
    $text = (string) preg_replace_callback(
        '/`([^`]+)`/',
        static function (array $m) use (&$code): string {
            $code[] = $m[1];

            return "\x02" . (count($code) - 1) . "\x03";
        },
        $text
    );

    $text = e($text);

    $text = (string) preg_replace_callback(
        '/!\[([^\]]*)\]\((site-asset\.php\?k=media&amp;f=[A-Za-z0-9._-]+)\)/',
        static fn (array $m): string => '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">',
        $text
    );

    $text = (string) preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+|site-asset\.php\?k=media&amp;f=[A-Za-z0-9._-]+)\)/',
        static fn (array $m): string => '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
        $text
    );

    $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);

    $text = (string) preg_replace_callback(
        '/\x02(\d+)\x03/',
        static fn (array $m): string => '<code>' . e($code[(int) $m[1]] ?? '') . '</code>',
        $text
    );

    return $text;
}

/** 公告图片在正文中的 Markdown 片段（图片统一经 site-asset.php 代理输出，避免 storage 直读被拦）。 */
function announcementImageMarkdown(string $filename): string
{
    return '![](site-asset.php?k=media&f=' . $filename . ')';
}

/** 当前生效的公告列表（按更新时间倒序）；每条附内容指纹 sig，供前台判断公告是否已更新。 */
function announcementList(): array
{
    if (!is_file(dirname(__DIR__) . '/storage/installed.lock')) {
        return [];
    }

    try {
        $rows = Database::connection()->query(
            'SELECT id, title, body, updated_at FROM announcements WHERE is_active = 1 ORDER BY updated_at DESC, id DESC'
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $list = [];
    foreach ($rows ?: [] as $row) {
        $list[] = [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'body'       => (string) $row['body'],
            'updated_at' => (string) $row['updated_at'],
            'sig'        => substr(md5($row['id'] . '|' . $row['updated_at']), 0, 16),
        ];
    }

    return $list;
}

/** 侧边栏底部的 ICP 备案号（用量信息下侧，点击跳转工信部备案系统）；未填写返回空串。移动端由 CSS 隐藏并改由页脚展示。 */
function sidebarIcpHtml(): string
{
    $icp = icpLicense();

    return $icp === '' ? '' : '<p class="sidebar-icp">' . icpLink($icp) . '</p>';
}

function icpLink(string $icp): string
{
    return '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">' . e($icp) . '</a>';
}

/** 侧边栏底部的公网安备号（紧随 ICP 之下，点击跳转全国互联网安全管理服务平台）；未填写返回空串。移动端由 CSS 隐藏并改由页脚展示。 */
function sidebarPoliceHtml(): string
{
    $police = policeLicense();

    return $police === '' ? '' : '<p class="sidebar-police">' . policeLink($police) . '</p>';
}

function policeLink(string $police): string
{
    return '<a href="https://beian.mps.gov.cn/#/query/webSearch" target="_blank" rel="noopener noreferrer">' . e($police) . '</a>';
}

/** 侧边栏「用量信息」区块：我的文件 / 账户管理 / 会员套餐三页共用，非管理员额外展示套餐状态与续费/升级入口。 */
function sidebarStorageHtml(array $user): string
{
    $quotaBytes = userQuotaBytes($user);
    $usedBytes = userUsedBytes((int) $user['id']);
    $usagePercent = $quotaBytes && $quotaBytes > 0 ? min(100, (int) round($usedBytes / $quotaBytes * 100)) : 0;

    $html = '<div class="sidebar-storage">'
        . '<div><span>已用空间</span><strong>' . e(formatBytes($usedBytes)) . '</strong></div>'
        . ($quotaBytes !== null
            ? '<progress max="100" value="' . $usagePercent . '"></progress><small>共 ' . e(formatBytes($quotaBytes)) . '</small>'
            : '<small>不限总容量</small>');

    if (!isAdmin($user)) {
        $html .= '<div><span>会员套餐</span><strong>' . e(premiumShortStatus($user)) . '</strong></div>'
            . '<small><a href="pay.php">' . (isPremiumUser($user) ? '续费 / 延长' : '升级高级用户') . '</a></small>';
    }

    return $html . '</div>';
}

/** 全站页脚：ICP 备案号 + 公网安备号（有侧边栏的页面桌面端改由侧边栏展示）+ SZY创新工作室技术支持署名（固定不可配置）。 */
function siteCreditHtml(): string
{
    $icp = icpLicense();
    $police = policeLicense();

    return '<footer class="site-credit">'
        . ($icp === '' ? '' : '<span class="icp-license">' . icpLink($icp) . '</span>')
        . ($police === '' ? '' : '<span class="police-license">' . policeLink($police) . '</span>')
        . '<span class="site-credit-owner">由 SZY创新工作室 提供技术支持：<a href="https://www.szystudio.cn" target="_blank" rel="noopener noreferrer">www.szystudio.cn</a></span>'
        . '</footer>';
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function appConfig(): array
{
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

/** 预览设置：图片 / PDF / 音视频 三类开关的配置键。 */
const PREVIEW_IMAGE_KEY = 'preview_image_enabled';
const PREVIEW_PDF_KEY   = 'preview_pdf_enabled';
const PREVIEW_AV_KEY    = 'preview_av_enabled';
/** 预览体积提醒阈值（字节）配置键与默认值（20 MB）。 */
const PREVIEW_WARN_BYTES_KEY = 'preview_warn_bytes';
const PREVIEW_WARN_BYTES_DEFAULT = 20 * 1024 * 1024;

/**
 * 预览分类：返回 'image' / 'pdf' / 'av' / ''（不支持预览）。
 * 以存库 mime_type 为主、扩展名兜底；不含 SVG（避免脚本型 SVG 的 XSS 风险）。
 */
function previewKind(array $file): string
{
    $mime = strtolower(trim((string) ($file['mime_type'] ?? '')));
    $name = (string) ($file['display_name'] ?: ($file['filename'] ?? ''));
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    // 图片：排除 svg
    if (str_starts_with($mime, 'image/')) {
        return $mime === 'image/svg+xml' ? '' : 'image';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'], true)) {
        return 'image';
    }

    // PDF
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return 'pdf';
    }

    // 音视频（含 mp3 等小音频，统一归入此大类）
    if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
        return 'av';
    }
    if (in_array($ext, ['mp4', 'mov', 'webm', 'm4v', 'ogv', 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac'], true)) {
        return 'av';
    }

    return '';
}

/** 某预览大类是否开启（管理员可在后台「预览设置」页签分别开关）。 */
function previewKindEnabled(string $kind): bool
{
    $key = match ($kind) {
        'image' => PREVIEW_IMAGE_KEY,
        'pdf'   => PREVIEW_PDF_KEY,
        'av'    => PREVIEW_AV_KEY,
        default => '',
    };
    if ($key === '') {
        return false;
    }

    // 默认全部开启
    return trim((string) Database::config($key, '1')) !== '0';
}

/** 预览体积提醒阈值（字节）：达到或超过时前端弹二次确认。 */
function previewWarnBytes(): int
{
    $bytes = (int) trim((string) Database::config(PREVIEW_WARN_BYTES_KEY, (string) PREVIEW_WARN_BYTES_DEFAULT));

    return $bytes > 0 ? $bytes : PREVIEW_WARN_BYTES_DEFAULT;
}

/** 该文件是否可预览（分类受支持且对应开关已开启）。 */
function filePreviewable(array $file): bool
{
    $kind = previewKind($file);
    if ($kind === '') {
        return false;
    }

    return previewKindEnabled($kind);
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
    // 隔离（quarantined）文件仍占服务器与 Notion 空间，因此同样计入已用容量；
    // 工单附件（scope = ticket）不计入个人容量配额
    $statement = Database::connection()->prepare(
        "SELECT COALESCE(SUM(file_size), 0) FROM uploads WHERE user_id = :user_id AND scope = 'drive' AND status IN ('active', 'quarantined')"
    );
    $statement->execute(['user_id' => $userId]);
    return (int) $statement->fetchColumn();
}

function findGroup(int $groupId): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM `groups` WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $groupId]);
    $group = $statement->fetch();

    return is_array($group) ? $group : null;
}

function allGroups(): array
{
    return Database::connection()
        ->query('SELECT * FROM `groups` ORDER BY level DESC, id ASC')
        ->fetchAll();
}

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

function avatarHtml(array $user, string $class = 'avatar'): string
{
    $avatarPath = (string) ($user['avatar_path'] ?? '');
    if ($avatarPath === '') {
        return '<span class="' . e($class) . '">' . e(mb_strtoupper(mb_substr((string) $user['username'], 0, 1))) . '</span>';
    }

    return '<span class="' . e($class) . '"><img src="avatar.php?u=' . (int) $user['id'] . '&amp;v='
        . e(substr(md5(basename($avatarPath)), 0, 8)) . '" alt="' . e((string) $user['username']) . ' 的头像"></span>';
}

function isAdmin(array $user): bool
{
    return (int) ($user['group_is_admin'] ?? 0) === 1;
}

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

/** 被管理员豁免的用户上传时跳过关键词与 AI 识别。 */
function userModerationExempt(?array $user): bool
{
    return (int) ($user['moderation_exempt'] ?? 0) === 1;
}

/**
 * 用户当前生效中的封禁记录；未被封禁返回 null。
 * 封禁期间禁止上传、下载、修改与重命名，只保留工单申诉入口。
 *
 * @return array<string, mixed>|null
 */
function userActiveBan(?array $user): ?array
{
    if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
        return null;
    }

    return BanService::create()->activeBan((int) $user['id']);
}

/**
 * 账户封禁提示条：在所有页面顶端统一输出，替代以往只在上传/下载时报错的做法。
 * 展示样式由后台「封禁管理」的 ban_notice_mode 决定：
 *   plain  = 仅一句「您的账户已被封禁」；
 *   link   = 文案 + 工单申诉入口；
 *   detail = 文案 + 封禁原因与时间 + 工单申诉入口。
 * 未被封禁时返回空字符串，页面直接输出即可。
 */
function banNoticeHtml(?array $user): string
{
    $ban = userActiveBan($user);
    if ($ban === null) {
        return '';
    }

    $mode = (string) Database::config('ban_notice_mode', 'link');
    $html = '<div class="alert error" id="banNotice" role="alert">' . e(banNoticeMessage($ban, $mode));

    if ($mode === 'plain') {
        return $html . '</div>';
    }

    return $html . '如有疑问请 <a href="tickets.php?appeal=1">提交申诉</a>。</div>';
}

/**
 * 封禁提示文案的正文（不含申诉入口）：由 ban_notice_mode 决定是否展开封禁原因与时间。
 * 页面提示条、前端弹提示、接口错误响应都从这里取词，保证后台改了样式后三处同步变化。
 */
function banNoticeMessage(array $ban, string $mode): string
{
    $message = '您的账户已被封禁';
    if ($mode !== 'detail') {
        return $message . '。';
    }

    // 封禁原因由管理员手填，可能自带句尾标点（如「管理员手动封禁。」）；
    // 直接拼「，封禁时间」会出现「。，」这类重复标点，故先剥掉尾部的标点再拼接
    $reason = trim((string) ($ban['reason'] ?? ''));
    $reason = trim((string) preg_replace('/[。．.，,、；;：:！!？?]+$/u', '', $reason));
    $bannedAt = (string) ($ban['banned_at'] ?? '');

    return $message . '（原因：' . ($reason !== '' ? $reason : '未说明')
        . '，封禁时间：' . ($bannedAt !== '' ? date('Y-m-d H:i', (int) strtotime($bannedAt)) : '未知') . '）。';
}

/**
 * 封禁提示的纯文本版本：供接口错误响应使用（下载链接被直接访问时只能返回文本，不能回 HTML）。
 * 未封禁时返回空字符串。
 */
function banNoticeText(?array $user): string
{
    $ban = userActiveBan($user);
    if ($ban === null) {
        return '';
    }

    $mode = (string) Database::config('ban_notice_mode', 'link');
    $message = banNoticeMessage($ban, $mode);

    return $mode === 'plain' ? $message : $message . '如有疑问请通过工单提交申诉。';
}

function registrationEnabled(): bool
{
    return Database::config('registration_enabled', '0') === '1';
}

function emailRequired(): bool
{
    return Database::config('email_required', '0') === '1';
}

function emailBound(array $user): bool
{
    return (string) ($user['email'] ?? '') !== '' && !empty($user['email_verified_at']);
}

/** 该用户当前是否可以上传（强制邮箱时未绑定邮箱者不可上传）。 */
function mayUpload(array $user): bool
{
    return !emailRequired() || isAdmin($user) || emailBound($user);
}

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

/** 统计用户在某个目标账户下已保存的有效副本数量；$accountId 为 null 表示自有账户。 */
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

function findActiveUploadByName(int $userId, string $name): ?array
{
    $statement = Database::connection()->prepare(
        "SELECT id, filename, display_name, file_size, notion_block_id FROM uploads
         WHERE user_id = :user_id AND scope = 'drive' AND status = 'active'
           AND COALESCE(NULLIF(display_name, ''), filename) = :name
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute(['user_id' => $userId, 'name' => $name]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** 重名自动编号：编号始终插在扩展名之前，不占用扩展名。 */
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

/** 判断当前请求是否走 HTTPS（反向代理终止 TLS 时需看 X-Forwarded-Proto，否则 Cookie 的 Secure 标记会失效）。 */
function requestIsHttps(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strcasecmp($https, 'off') !== 0) {
        return true;
    }

    $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '';

    return strtolower(trim($forwarded)) === 'https';
}

/** 登录限流状态：15 分钟内同一 IP 失败 20 次即锁定；账号维度不直接锁定，否则可被故意输错密码锁号（DoS）。 */
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

function recordLoginFailure(string $username): void
{
    $pdo = Database::connection();
    $pdo->prepare('INSERT INTO login_attempts (username, ip) VALUES (:username, :ip)')
        ->execute(['username' => mb_strcut($username, 0, 191, 'UTF-8'), 'ip' => clientIp()]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
}

function clearLoginFailures(string $username): void
{
    Database::connection()
        ->prepare('DELETE FROM login_attempts WHERE username = :username')
        ->execute(['username' => mb_strcut($username, 0, 191, 'UTF-8')]);
}

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

function captchaField(string $label = '验证码'): string
{
    return '<div class="captcha-field">'
        . '<img id="captchaImage" src="captcha.php" alt="图形验证码" width="120" height="40">'
        . '<input type="text" name="captcha" maxlength="4" autocomplete="off" required placeholder="' . e($label) . '">'
        . '<button type="button" class="captcha-refresh" data-captcha-refresh="captchaImage">换一张</button>'
        . '</div>';
}

/** 从 Notion 页面链接或 ID 中解析出 32 位页面 ID；标题本身常含 a-f 字母，必须从末尾锚定 32 位十六进制串。 */
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

/** 校验用户自有的 Notion 集成 Token 与父页面是否可用（Token 必须能读到该页面）。 */
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

function allOfficialAccounts(): array
{
    return Database::connection()
        ->query('SELECT * FROM official_accounts ORDER BY priority DESC, id ASC')
        ->fetchAll();
}

function activeOfficialAccounts(): array
{
    return Database::connection()
        ->query('SELECT * FROM official_accounts WHERE is_enabled = 1 ORDER BY priority DESC, id ASC')
        ->fetchAll();
}

function findOfficialAccount(int $id): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM official_accounts WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $account = $statement->fetch();

    return is_array($account) ? $account : null;
}

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

function userNotionSource(array $user): string
{
    return ($user['notion_account_source'] ?? '') === 'official' ? 'official' : 'own';
}

function hasOwnNotionAccount(array $user): bool
{
    return userNotionToken($user) !== '' && (string) ($user['notion_parent_page_id'] ?? '') !== '';
}

/** 是否允许使用官方账户来源（仅对有效期内的高级用户与管理员开放，且账户池必须至少有一个已启用账户）。 */
function canUseOfficialSource(array $user): bool
{
    return (isAdmin($user) || isPremiumUser($user)) && activeOfficialAccounts() !== [];
}

/** 用户当前的 Notion 账户来源是否可用（来源可用 ≠ 有剩余容量，仅判断上传目标能否确定）。 */
function notionSourceUsable(array $user): bool
{
    return uploadTargets($user) !== [];
}

/** 当前账号是否被禁止上传新文件（降级为普通用户后官方账户来源被硬性禁用）；返回空串表示允许，否则返回提示文案。 */
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

/** 绑定或更换自有的 Notion 账户（Token + 父页面）；Token 或父页面变化时会清除用户空间页缓存，避免沿用旧父页面下的页面。 */
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

/** 切换用户的 Notion 账户来源；只改变后续上传目标，旧文件在 upload_replicas 中记录了所属账户，仍能从原账户下载。 */
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

    if ($source === 'official' && userOfficialAccounts($userId) === []) {
        autoAssignOfficialAccounts($userId);
    }

    Logger::info('notion', '用户切换了 Notion 账户来源。', [
        'user_id' => $userId,
        'source' => $source,
    ]);
}

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

/** 重建用户的官方账户分配：按传入顺序写入主（slot 1）与备（slot 2）。 */
function assignOfficialAccounts(int $userId, array $accountIds): void
{
    $pdo = Database::connection();

    // 结算订单时本函数会处于外层事务中，此时不能再开事务（PDO 不支持嵌套事务）
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // 分配是「先删后插」：并发分配会互相覆盖甚至触发 uniq_user_slot 冲突，故锁定该用户行串行化
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

function premiumGroupId(): int
{
    $statement = Database::connection()->prepare('SELECT id FROM `groups` WHERE name = :name LIMIT 1');
    $statement->execute(['name' => Schema::PREMIUM_GROUP_NAME]);

    return (int) $statement->fetchColumn();
}

/** 写入后台告警（顶部告警条与告警列表可查看）：相同内容的未读告警只保留一条，写入失败不影响主流程。 */
function raiseAdminAlert(string $level, string $message, array $context = []): void
{
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

function unreadAdminAlerts(int $limit = 20): array
{
    $limit = max(1, min($limit, 100));

    return Database::connection()
        ->query('SELECT id, level, message, created_at FROM admin_alerts WHERE is_read = 0 ORDER BY created_at DESC, id DESC LIMIT ' . $limit)
        ->fetchAll();
}

/** 管理员告警邮件收件人：优先使用后台配置的收件邮箱，未配置时回退到管理员组内已验证的邮箱。 */
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

/** 向管理员发送告警邮件：SMTP 未配置或无收件人时静默跳过；发送失败只记日志，绝不再触发告警（避免告警递归）。 */
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

/** 用户本次上传的目标账户（官方账户来源按主备槽位顺序返回）；已停用或 Token/根页面无效的账户会被跳过，空数组表示没有可用目标。 */
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

    // 官方账户池是全体付费用户共享的资源：管理员指定的主备账户优先，未分配或分配不足时按池内优先级
    // 补齐。这样「池里有启用账户但该用户没有分配记录」也能正常上传，不会误报「池内没有可用账户」。
    $pool = [];
    $seen = [];
    foreach (userOfficialAccounts((int) $user['id']) as $account) {
        $seen[(int) $account['id']] = true;
        $pool[] = $account;
    }
    foreach (activeOfficialAccounts() as $account) {
        if (isset($seen[(int) $account['id']])) {
            continue;
        }
        // 池内补充账户此时还没有用户空间页面，留空由 ensureUserSpacePage 首次上传时创建并回写
        $account['user_page_id'] = '';
        $pool[] = $account;
    }

    $targets = [];
    foreach ($pool as $account) {
        // 与原有的主备冗余一致：最多主 + 备两份，避免向整池重复写入放大 Notion 调用
        if (count($targets) >= 2) {
            break;
        }
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

/** 记录用户在某个官方账户下的空间页面。用户此前没有分配记录时补建一行（共享账户池下按需取用），避免每次上传重复创建页面。 */
function rememberUserSpacePage(int $userId, int $accountId, string $pageId): void
{
    $pdo = Database::connection();

    // INSERT IGNORE 兼容并发首次上传（槽位唯一索引冲突时静默跳过），随后统一按账户更新页面缓存
    $slotStatement = $pdo->prepare('SELECT COALESCE(MAX(slot), 0) + 1 FROM user_official_accounts WHERE user_id = :user_id');
    $slotStatement->execute(['user_id' => $userId]);
    $pdo->prepare(
        'INSERT IGNORE INTO user_official_accounts (user_id, account_id, slot, notion_page_id)
         VALUES (:user_id, :account_id, :slot, :page_id)'
    )->execute([
        'user_id' => $userId,
        'account_id' => $accountId,
        'slot' => (int) $slotStatement->fetchColumn(),
        'page_id' => $pageId,
    ]);

    $pdo->prepare(
        'UPDATE user_official_accounts SET notion_page_id = :page_id
         WHERE user_id = :user_id AND account_id = :account_id'
    )->execute(['page_id' => $pageId, 'user_id' => $userId, 'account_id' => $accountId]);
}

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
        rememberUserSpacePage($userId, $accountId, $pageId);
    }

    return $pageId;
}

/**
 * 执行一次 Notion 写入操作；若因页面/块被归档而失败，先尝试自愈（恢复被归档的空间页面）再重试一次。
 *
 * 只有错误文案命中可修复特征时才尝试修复，其余错误原样抛出；修复未成功时也抛出原始异常，
 * 由调用方按既有逻辑记录日志与告警，行为与修复前保持一致。
 *
 * @param callable():mixed    $operation
 * @param array<string,mixed> $user
 * @param array<string,mixed> $target
 * @param array<string,mixed> $logContext
 *
 * @return mixed
 */
function notionWriteWithRepair(callable $operation, array $user, array $target, array $logContext)
{
    try {
        return $operation();
    } catch (Throwable $exception) {
        if (!NotionSpaceRepair::isRepairable($exception->getMessage())) {
            throw $exception;
        }

        $result = (new NotionSpaceRepair())->repair(
            $user,
            (string) $target['type'],
            $target['account_id'] === null ? null : (int) $target['account_id'],
            new NotionClient((string) $target['token'])
        );

        Logger::warning('notion', '检测到 Notion 页面被归档，已尝试自动修复。', $logContext + [
            'reason' => $exception->getMessage(),
            'repaired' => $result['repaired'],
            'action' => $result['action'],
            'page_id' => $result['page_id'],
            'detail' => $result['detail'],
        ]);

        if (!$result['repaired']) {
            throw $exception;
        }

        return $operation();
    }
}

/** 双写：把临时文件写入用户的全部目标账户，至少一份成功即视为成功；全部失败时对外只提示「存储服务暂时不可用」，不透露账户信息。 */
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
        $role = count($replicas) + 1;
        try {
            $replicas[] = notionWriteWithRepair(
                static function () use ($user, $target, $temporaryPath, $storageName, $mime, $size, $role): array {
                    $client = new NotionClient((string) $target['token']);
                    $pageId = ensureUserSpacePage($user, $target, $client);
                    $result = $client->uploadAndAttach($temporaryPath, $storageName, $mime, $size, $pageId);
                    $blockId = (string) ($result['block']['results'][0]['id'] ?? '');
                    if ($blockId === '') {
                        throw new RuntimeException('Notion 未返回文件块。');
                    }

                    return [
                        'role' => $role,
                        'account_type' => (string) $target['type'],
                        'official_account_id' => $target['account_id'],
                        'notion_file_upload_id' => (string) ($result['file_upload_id'] ?? ''),
                        'notion_block_id' => $blockId,
                    ];
                },
                $user,
                $target,
                [
                    'stage' => 'upload',
                    'user_id' => (int) $user['id'],
                    'account_type' => $target['type'],
                    'account_id' => $target['account_id'],
                ]
            );
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

/** 某个文件的可用下载副本（按 role 升序，主副本在前）；已停用或 Token 失效的账户仍会列出，下载失败后再回退下一份。 */
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

/** 归档文件在全部账户中的副本并统一标记为已删除；单份失败只记日志并告警，不影响文件记录删除。 */
function archiveUploadReplicas(array $upload, array $owner): void
{
    foreach (uploadReplicaTargets($upload, $owner) as $target) {
        try {
            notionWriteWithRepair(
                static function () use ($target): void {
                    (new NotionClient((string) $target['token']))->archiveBlock((string) $target['block_id']);
                },
                $owner,
                $target,
                [
                    'stage' => 'delete',
                    'user_id' => (int) ($owner['id'] ?? 0),
                    'upload_id' => (int) $upload['id'],
                    'account_type' => $target['type'],
                    'account_id' => $target['account_id'],
                ]
            );
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

/** 批量读取文件的副本来源，用于文件列表标记来源与副本数。 */
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
// 站内文件夹
// ---------------------------------------------------------------------------

/** 读取属于该用户的文件夹行；不存在或不属于该用户时返回 null（越权访问一律按不存在处理）。 */
function folderRow(int $userId, int $folderId): ?array
{
    if ($folderId <= 0) {
        return null;
    }
    $statement = Database::connection()->prepare(
        'SELECT id, parent_id, name, created_at FROM folders WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute(['id' => $folderId, 'user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/** 从根目录到指定文件夹的完整路径，用于面包屑；文件夹被移动/删除导致断链时自动截断。 */
function folderAncestors(int $userId, int $folderId): array
{
    $chain = [];
    $current = folderRow($userId, $folderId);
    while ($current !== null) {
        array_unshift($chain, $current);
        $current = folderRow($userId, (int) ($current['parent_id'] ?? 0));
    }

    return $chain;
}

/** 某个文件夹下的直接子文件夹，按名称排序。 */
function folderChildren(int $userId, int $parentId): array
{
    $statement = Database::connection()->prepare(
        'SELECT id, name, created_at, updated_at FROM folders
         WHERE user_id = :user_id AND parent_id <=> :parent_id
         ORDER BY name ASC'
    );
    $statement->execute(['user_id' => $userId, 'parent_id' => $parentId > 0 ? $parentId : null]);

    return $statement->fetchAll();
}

/** 该用户全部文件夹的扁平树（含缩进层级），用于「移动到文件夹」选择器。 */
function folderTree(int $userId): array
{
    $statement = Database::connection()->prepare(
        'SELECT id, parent_id, name FROM folders WHERE user_id = :user_id ORDER BY name ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $byParent = [];
    foreach ($statement->fetchAll() as $row) {
        $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
    }

    $flat = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, &$flat, $byParent): void {
        foreach ($byParent[$parentId] ?? [] as $row) {
            $flat[] = [
                'id' => (int) $row['id'],
                'parent_id' => (int) ($row['parent_id'] ?? 0),
                'name' => (string) $row['name'],
                'depth' => $depth,
            ];
            $walk((int) $row['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    return $flat;
}

/** 每个文件夹子树下的在用文件总数（含嵌套子文件夹），用于删除前的风险提示。 */
function folderFileCountMap(int $userId): array
{
    $statement = Database::connection()->prepare(
        "SELECT folder_id, COUNT(*) AS total FROM uploads
         WHERE user_id = :user_id AND scope = 'drive' AND status = 'active' AND folder_id IS NOT NULL
         GROUP BY folder_id"
    );
    $statement->execute(['user_id' => $userId]);
    $totals = [];
    foreach ($statement->fetchAll() as $row) {
        $totals[(int) $row['folder_id']] = (int) $row['total'];
    }

    // folderTree 是先序（父在前），倒序处理即可保证子文件夹先汇总到父级
    foreach (array_reverse(folderTree($userId)) as $item) {
        $childTotal = $totals[$item['id']] ?? 0;
        if ($item['parent_id'] > 0) {
            $totals[$item['parent_id']] = ($totals[$item['parent_id']] ?? 0) + $childTotal;
        }
    }

    return $totals;
}

/** 指定文件夹自身及其全部子孙文件夹的 ID，用于递归删除。 */
function folderDescendantIds(int $userId, int $folderId): array
{
    $statement = Database::connection()->prepare(
        'SELECT id FROM folders WHERE user_id = :user_id AND parent_id = :parent_id'
    );
    $ids = [$folderId];
    $pending = [$folderId];
    while ($pending !== []) {
        $parentId = (int) array_shift($pending);
        $statement->execute(['user_id' => $userId, 'parent_id' => $parentId]);
        foreach ($statement->fetchAll() as $row) {
            $childId = (int) $row['id'];
            $ids[] = $childId;
            $pending[] = $childId;
        }
    }

    return $ids;
}

// ---------------------------------------------------------------------------
// 付费系统
// ---------------------------------------------------------------------------

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

function premiumPlan(int $months): ?array
{
    $plans = premiumPlans();
    if (!isset($plans[$months])) {
        return null;
    }

    return ['months' => $months, 'price' => $plans[$months], 'label' => $months . ' 个月'];
}

function epayPayTypeOptions(): array
{
    return ['alipay' => '支付宝', 'wxpay' => '微信支付'];
}

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

function payTypeLabel(string $type): string
{
    return ['alipay' => '支付宝', 'wxpay' => '微信支付', 'manual' => '管理员授权'][$type] ?? $type;
}

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'paid' => '已支付',
        'closed' => '已关闭',
        'void' => '已作废',
        default => '待支付',
    };
}

/** 是否为管理员人工开通的订单：trade_no 统一带 MANUAL- 前缀，与网关流水号区分。 */
function isManualOrder(array $order): bool
{
    return str_starts_with((string) ($order['trade_no'] ?? ''), 'MANUAL-');
}

function orderDisplayLabel(array $order): string
{
    return isManualOrder($order) && (string) ($order['status'] ?? '') === 'paid'
        ? '管理员授权'
        : orderStatusLabel((string) ($order['status'] ?? ''));
}

function orderDisplayClass(array $order): string
{
    return isManualOrder($order) && (string) ($order['status'] ?? '') === 'paid'
        ? 'granted'
        : (string) ($order['status'] ?? '');
}

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

function findOrderByNo(string $orderNo): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM orders WHERE order_no = :order_no LIMIT 1');
    $statement->execute(['order_no' => $orderNo]);
    $order = $statement->fetch();

    return is_array($order) ? $order : null;
}

function findUserByUsername(string $username): ?array
{
    // 一并取出权限组的管理员标记，调用方才能用 isAdmin() 判断
    $statement = Database::connection()->prepare(
        'SELECT u.*, g.is_admin AS group_is_admin FROM users u
         LEFT JOIN `groups` g ON g.id = u.group_id WHERE u.username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function findUserById(int $userId): ?array
{
    // 与 findUserByUsername 一致：带上权限组的管理员标记，调用方才能用 isAdmin() 判断
    $statement = Database::connection()->prepare(
        'SELECT u.*, g.is_admin AS group_is_admin FROM users u
         LEFT JOIN `groups` g ON g.id = u.group_id WHERE u.id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

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

/** 站内到期提醒文案：距到期 3 天内、已到期或刚被降级时返回提示，其余为空串；只提醒曾开通过套餐的用户。 */
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

/** 高级套餐到期的懒检查：访问页面时把已到期的付费用户降级回普通组；只降权限组，保留到期时间与文件，降级后仍可查看下载。 */
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

/** 到期前 3 天的邮件提醒（无定时任务，故在访问页面时懒触发）；同一到期周期只提醒一次，发送成功才写去重标记。 */
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

/** 在时间戳上增加月数并按目标月实际天数收敛：strtotime('+1 month') 在月末会溢出到下下月，这里改为取该月最后一天。 */
function addMonthsClamped(int $timestamp, int $months): int
{
    $targetMonth = (int) date('n', $timestamp) - 1 + $months;
    $year = (int) date('Y', $timestamp) + intdiv($targetMonth, 12);
    $month = $targetMonth % 12 + 1;
    $day = min((int) date('j', $timestamp), (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

    return (int) mktime(
        (int) date('G', $timestamp),
        (int) date('i', $timestamp),
        (int) date('s', $timestamp),
        $month,
        $day,
        $year
    );
}

/** 开通或续费高级用户：原套餐未到期时从原到期时间顺延、已到期或首次开通从当前时间起算；永久会员的续费保持永久。 */
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

        // 到期时间为 NULL 且当前已在高级组，说明是永久会员（管理员手工授予）：
        // 一次续费不能把永久权益反而缩成限时，这里保持永久有效、不写到期时间
        $keepPermanent = $before === null && !$isAdminGroup && (int) ($user['group_id'] ?? 0) === premiumGroupId();

        if ($keepPermanent) {
            $after = null;
        } else {
            $base = $before !== null && strtotime($before) > time() ? (int) strtotime($before) : time();
            $after = date('Y-m-d H:i:s', addMonthsClamped($base, $months));

            // 同时清空到期提醒标记，让新的到期周期可以重新提醒
            $pdo->prepare('UPDATE users SET premium_expires_at = :expires_at, premium_reminder_sent_at = NULL WHERE id = :id')
                ->execute(['expires_at' => $after, 'id' => $userId]);
        }

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

/** 管理员人工开通/续费高级用户并生成一条「管理员授权」订单（金额 0 元、trade_no 用 MANUAL- 前缀），作为后台对账凭据。 */
function grantManualPremiumOrder(int $userId, int $months, int $operatorId): array
{
    if (!isset(premiumPlans()[$months])) {
        throw new RuntimeException('请选择有效的开通时长。');
    }

    $pdo = Database::connection();
    $orderNo = generateOrderNo();

    // 订单落库与顺延套餐必须同一个事务：任一步失败都不能留下「有订单没开通」或反之的脏数据
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // 锁定用户行，确认用户仍在（后台页面可能停留在已删除用户的旧渲染上）
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute(['id' => $userId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('该用户不存在或已被删除。');
        }

        $pdo->prepare(
            "INSERT INTO orders (order_no, user_id, months, amount, pay_type, status, trade_no, paid_at)
             VALUES (:order_no, :user_id, :months, 0.00, 'manual', 'paid', :trade_no, NOW())"
        )->execute([
            'order_no' => $orderNo,
            'user_id' => $userId,
            'months' => $months,
            'trade_no' => 'MANUAL-' . $operatorId,
        ]);

        $result = grantPremium($userId, $months);

        // 回填本次开通前后的到期时间，订单列表与用户端可据此看到顺延结果
        $pdo->prepare('UPDATE orders SET expires_before = :before, expires_after = :after WHERE order_no = :order_no')
            ->execute([
                'before' => $result['expires_before'],
                'after' => $result['expires_after'],
                'order_no' => $orderNo,
            ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['order_no' => $orderNo] + $result;
}

/** 订单当初实际贡献的会员时长（秒）＝下单后到期时间 − max(下单前到期时间, 下单时刻)，与 grantPremium 起算点一致；缺字段时返回 null。 */
function orderContributedSeconds(array $order): ?int
{
    $afterAt = strtotime((string) ($order['expires_after'] ?? ''));
    if ($afterAt === false) {
        return null;
    }

    $beforeAt = (int) strtotime((string) ($order['expires_before'] ?? ''));
    $paidAt = (string) ($order['paid_at'] ?? '');
    $orderedAt = (int) strtotime($paidAt !== '' ? $paidAt : (string) ($order['created_at'] ?? ''));
    $base = max($beforeAt, $orderedAt);
    if ($base <= 0) {
        return null;
    }

    $contributed = $afterAt - $base;

    return $contributed > 0 ? $contributed : null;
}

/** 作废指定的一笔订单并把该订单当初贡献的会员时长精确回滚：只动这一笔，按 expires_before/expires_after 算出的秒数从当前到期时间扣除，扣完才降回普通组；永久有效与管理员组不受影响。 */
function revokePremium(int $userId, int $orderId): array
{
    $pdo = Database::connection();

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $statement = $pdo->prepare('SELECT group_id, premium_expires_at FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('该用户不存在或已被删除。');
        }

        // 先锁定这一笔待作废的订单并取出月数与下单前后的到期时间：
        // 取不到说明已被作废或状态已变更，直接报错
        $orderStatement = $pdo->prepare("SELECT months, expires_before, expires_after, paid_at, created_at FROM orders WHERE id = :id AND user_id = :user_id AND status = 'paid' LIMIT 1 FOR UPDATE");
        $orderStatement->execute(['id' => $orderId, 'user_id' => $userId]);
        $lockedOrder = $orderStatement->fetch();
        if (!is_array($lockedOrder)) {
            throw new RuntimeException('该订单已作废，无需重复操作。');
        }
        $months = (int) $lockedOrder['months'];

        // 权限组单独读取，避免 FOR UPDATE 顺带锁住 groups 行而阻塞其他用户的结算
        $groupStatement = $pdo->prepare('SELECT is_admin FROM `groups` WHERE id = :id LIMIT 1');
        $groupStatement->execute(['id' => (int) $user['group_id']]);
        $isAdminGroup = (int) $groupStatement->fetchColumn() === 1;

        $currentExpiry = ($user['premium_expires_at'] ?? null) !== null && (string) $user['premium_expires_at'] !== ''
            ? (string) $user['premium_expires_at']
            : null;
        $stillPremium = false;
        $expiresAt = $currentExpiry;
        if ($currentExpiry === null) {
            // 永久有效：没有具体到期时间可扣，保持高级用户不变
            $stillPremium = true;
        } else {
            $contributed = orderContributedSeconds($lockedOrder);
            if ($contributed !== null) {
                // 用订单自己贡献的秒数精确回滚：作废这一笔不会吃掉同一用户其它订单的时长
                $remaining = (int) strtotime($currentExpiry) - $contributed;
            } else {
                // 缺少下单前后的到期时间（首次开通等）：退化为按订单月数回退
                $remaining = strtotime('-' . $months . ' month', (int) strtotime($currentExpiry));
            }
            if ($remaining !== false && $remaining > time()) {
                // 扣减后仍有剩余有效期：保持高级用户，仅缩短到期时间
                $expiresAt = date('Y-m-d H:i:s', $remaining);
                $stillPremium = true;
            } else {
                // 扣完即失效：到期时间落在当前时刻，留下「何时失效」的时间点
                $expiresAt = date('Y-m-d H:i:s');
            }
            $pdo->prepare('UPDATE users SET premium_expires_at = :expires_at, premium_reminder_sent_at = NULL WHERE id = :id')
                ->execute(['expires_at' => $expiresAt, 'id' => $userId]);
        }

        // 只有扣完会员时长才降回普通用户组；管理员组不调整，避免把管理员降级出管理组
        if (!$stillPremium && !$isAdminGroup) {
            $defaultGroup = defaultGroupId();
            if ($defaultGroup > 0) {
                $pdo->prepare('UPDATE users SET group_id = :group_id WHERE id = :id')
                    ->execute(['group_id' => $defaultGroup, 'id' => $userId]);
            }
        }

        // 只作废这一笔订单：同一用户的其他已支付订单保持原状，避免「作废一笔连作废全部」
        $void = $pdo->prepare("UPDATE orders SET status = 'void', voided_at = NOW() WHERE id = :id AND user_id = :user_id AND status = 'paid'");
        $void->execute(['id' => $orderId, 'user_id' => $userId]);
        $voidedOrders = $void->rowCount();
        if ($voidedOrders === 0) {
            // 并发下订单已被作废：回滚上面的套餐调整，提示无需重复操作
            throw new RuntimeException('该订单已作废，无需重复操作。');
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

    Logger::info('payment', '作废单笔订单并扣减对应会员时长。', [
        'user_id' => $userId,
        'order_id' => $orderId,
        'months' => $months,
        'voided_orders' => $voidedOrders,
        'still_premium' => $stillPremium,
        'expires_at' => $expiresAt,
    ]);

    return [
        'voided_orders' => $voidedOrders,
        'months' => $months,
        'expires_at' => $expiresAt,
        'still_premium' => $stillPremium,
    ];
}

/** 结算订单并开通/续费高级用户（幂等）：异步通知与主动查单可能并发到达，用行锁保证同一订单只开通一次。 */
function settleOrderPayment(array $order, string $tradeNo, array $notify = []): bool
{
    $pdo = Database::connection();

    $pdo->beginTransaction();
    try {
        // 加锁顺序统一为「users → orders」：createOrder / revokePremium 都是先锁 users 行再动 orders 行，
        // 结算若反过来先锁 orders 行再等 users 行，会与「下单时关闭旧待支付单」反向等待而死锁
        $userId = (int) ($order['user_id'] ?? 0);
        if ($userId > 0) {
            $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE')->execute(['id' => $userId]);
        }

        $statement = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => (int) $order['id']]);
        $locked = $statement->fetch();

        // 允许进入结算的只有「待支付」与「已关闭」两种状态：
        //  - 已支付：直接跳过（幂等），避免重复开通
        //  - 已作废：管理员已明确撤销并精确回滚过时长，绝不能被重放的回调复活
        //  - 已关闭：多是反复下单时被新单顶掉的旧单，若这笔其实已在网关付款成功，
        //    回调必须能把它开通回来，否则用户的钱会白付（关闭只代表本站放弃该单，不代表没付款）
        if (!is_array($locked) || !in_array((string) $locked['status'], ['pending', 'closed'], true)) {
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

/** 主动向网关查单并对账结算（异步通知丢失时的兜底），用户从收银台回跳或手动刷新状态时调用。 */
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
