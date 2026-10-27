<?php

declare(strict_types=1);

use NotionDrive\Database;
use NotionDrive\Logger;
use NotionDrive\Mail\MailTemplate;
use NotionDrive\Mailer;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\NotionSpaceRepair;
use NotionDrive\Security;
use NotionDrive\Uploads\NotionWriteQueue;

/**
 * 「我的文件」中可见的上传状态：正在写入存储（writing）与写入失败（write_failed）
 * 的文件同样要显示出来，并可预览、下载、重命名、移动、删除。
 */
const UPLOAD_VISIBLE_STATUSES = "'active', 'writing', 'write_failed'";

/** 计入个人容量与服务器暂存占用的上传状态。 */
const UPLOAD_CAPACITY_STATUSES = "'active', 'writing', 'write_failed'";

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

/** 侧边栏导航图标：内联描边 SVG（避免依赖 emoji 字体，各平台渲染一致）；未知名称返回空串。 */
function navIcon(string $name): string
{
    $shapes = [
        'files'   => '<path d="M3 7a2 2 0 0 1 2-2h3.6l2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'admin'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logs'    => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'account' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ];

    if (!isset($shapes[$name])) {
        return '';
    }

    return '<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $shapes[$name] . '</svg></span>';
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

/** 侧边栏「用量信息」区块：我的文件 / 账户管理两页共用，非管理员额外展示账号类型。 */
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

    // 服务器待写入暂存：上传已入库、正等后台写入 Notion 的文件（含写入失败待重试的）占用的服务器磁盘，
    // 与「已用空间」（个人容量）口径不同，单独一行展示，便于用户判断为何暂时无法继续上传
    $stagedBytes = NotionWriteQueue::stagedBytes((int) $user['id']);
    $stagedLimit = NotionWriteQueue::stagedLimitBytes();
    $stagedPercent = $stagedLimit > 0 ? min(100, (int) round($stagedBytes / $stagedLimit * 100)) : 0;
    $html .= '<div><span>待写入暂存</span><strong>' . e(formatBytes($stagedBytes)) . '</strong></div>'
        . ($stagedLimit > 0
            ? '<progress max="100" value="' . $stagedPercent . '"></progress><small>共 ' . e(formatBytes($stagedLimit)) . '</small>'
            : '<small>不限暂存额度</small>');

    if (!isAdmin($user)) {
        $html .= '<div><span>账号类型</span><strong>普通用户</strong></div>';
    }

    return $html . '</div>';
}

/** 全站页脚：社区版声明 + ICP 备案号 + 公网安备号（有侧边栏的页面桌面端改由侧边栏展示）+ SZY创新工作室技术支持署名（固定不可配置）。 */
function siteCreditHtml(): string
{
    $icp = icpLicense();
    $police = policeLicense();

    return '<footer class="site-credit">'
        . '<span class="site-credit-notice">社区版免费禁止商业</span>'
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

/**
 * 先输出 JSON 响应并与浏览器断开（PHP-FPM 下用 fastcgi_finish_request 立即断连），
 * 再在后台执行 $after。用于「上传秒回、写入存储异步进行」：用户不必等 Notion 写完。
 * 非 FPM 环境（mod_php / 内置服务器）没有该函数，退化为「$after 跑完才结束请求」，
 * 行为与改造前一致，只是不再等待内容审核。
 */
function jsonResponseThen(array $data, callable $after, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    // 声明长度，浏览器收到完整响应即可结束本次请求，不必等脚本（后台写入）真正跑完才收到 EOF；
    // 非 FPM 环境没有 fastcgi_finish_request，这一步是「秒回」能否成立的关键
    $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen($body));
    echo $body;
    finishResponseThen($after);
    exit;
}

/**
 * 把已生成的输出发给浏览器并断开（PHP-FPM 下立即断连），再在后台执行 $after。
 * 供页面在输出完毕后做懒清扫使用，避免把后台工作算进页面加载时间。
 * 非 FPM 环境没有 fastcgi_finish_request，退化为「$after 跑完才结束请求」。
 */
function finishResponseThen(callable $after): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }

    // 浏览器已断开也不能中断后台工作（用户可能立刻离开或刷新页面）
    ignore_user_abort(true);
    @set_time_limit(0);
    try {
        $after();
    } catch (Throwable $exception) {
        Logger::error('queue', '请求结束后的后台任务执行失败', ['error' => $exception->getMessage()]);
    }
}

/**
 * 把耗时任务（如 SMTP 发信）推迟到响应发给浏览器之后再执行，用户不必干等。
 * 与 finishResponseThen 的区别：本函数在**脚本结束时**（shutdown）才断连并执行，
 * 因此调用方在这之后仍可继续设置响应头（如提交成功后 `header('Location: ...')` 跳转），不会破坏响应。
 * 非 FPM 环境没有 fastcgi_finish_request，退化为请求结束时执行，与改造前行为一致。
 */
function deferAfterResponse(callable $task): void
{
    /** @var list<callable> $queue */
    static $queue = [];
    static $registered = false;

    $queue[] = $task;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function () use (&$queue): void {
        if ($queue === []) {
            return;
        }
        $tasks = $queue;
        $queue = [];
        // 复用 finishResponseThen：FPM 下先把已生成的响应（含 Location 头）发给浏览器，再跑后台任务
        finishResponseThen(static function () use ($tasks): void {
            foreach ($tasks as $task) {
                try {
                    $task();
                } catch (Throwable $exception) {
                    Logger::warning('alert', '响应后置任务执行失败。', ['reason' => $exception->getMessage()]);
                }
            }
        });
    });
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
    $name = (string) (($file['display_name'] ?? '') ?: ($file['filename'] ?? ''));
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

/**
 * 预览输出的安全 Content-Type：仅当存库 mime 与预览大类一致（或可由白名单扩展名确定）时返回，
 * 否则返回 null 表示不得内联输出。避免「文件名 a.png、内容为 HTML」被以 text/html + inline 渲染（同源 HTML 注入）。
 */
function previewContentType(array $file, string $kind): ?string
{
    $mime = strtolower(trim((string) ($file['mime_type'] ?? '')));
    $name = (string) (($file['display_name'] ?? '') ?: ($file['filename'] ?? ''));
    $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

    $extMimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/x-m4v',
        'ogv' => 'video/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
    ];

    if ($kind === 'image') {
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return $mime;
        }

        return $extMimes[$ext] ?? null;
    }

    if ($kind === 'pdf') {
        if ($mime === 'application/pdf') {
            return $mime;
        }

        return $ext === 'pdf' ? 'application/pdf' : null;
    }

    if ($kind === 'av') {
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return $mime;
        }

        return $extMimes[$ext] ?? null;
    }

    return null;
}

/** 自有 Notion 账户的单文件上限：Notion 官方对自有账户的硬限制为 5 MB。 */
function ownFileLimitBytes(): int
{
    return 5 * 1024 * 1024;
}

/** 官方账户的单文件上限：可在后台调整，默认 5 GB。 */
function officialFileLimitBytes(): int
{
    $fallback = 5 * 1024 * 1024 * 1024;
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
        'SELECT COALESCE(SUM(file_size), 0) FROM uploads WHERE user_id = :user_id AND status IN (' . UPLOAD_CAPACITY_STATUSES . ')'
    );
    $statement->execute(['user_id' => $userId]);
    return (int) $statement->fetchColumn();
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
    return (int) ($user['is_admin'] ?? 0) === 1;
}

/** 个人容量上限（null 表示不限），仅由 users.quota_bytes 决定。 */
function userQuotaBytes(array $user): ?int
{
    $quota = $user['quota_bytes'] ?? null;

    return $quota === null ? null : (int) $quota;
}

function userSingleFileLimit(array $user): int
{
    return userNotionSource($user) === 'own' ? ownFileLimitBytes() : officialFileLimitBytes();
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

/** 在线上传（直传）的存储名：无本地文件可算哈希，故仅用用户、时间与随机数组成唯一前缀。 */
function directStorageName(int $userId, string $originalName): string
{
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $stem = pathinfo($originalName, PATHINFO_FILENAME);
    $prefix = sprintf('u%d_%s_%s__', $userId, gmdate('YmdHis'), bin2hex(random_bytes(4)));
    $suffix = $extension === '' ? '' : '.' . $extension;
    $available = max(1, 900 - strlen($prefix) - strlen($suffix));
    $trimmedStem = mb_strcut($stem, 0, $available, 'UTF-8');

    return $prefix . $trimmedStem . $suffix;
}

/** 在线上传无本地文件可探测内容，按扩展名映射推断 MIME；未知类型回退 application/octet-stream。 */
function mimeForExtension(string $extension): string
{
    $map = [
        'pdf' => 'application/pdf', 'csv' => 'text/csv', 'txt' => 'text/plain', 'md' => 'text/markdown',
        'markdown' => 'text/markdown', 'json' => 'application/json', 'xml' => 'application/xml',
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript',
        'ts' => 'text/plain', 'tsx' => 'text/plain', 'py' => 'text/plain', 'yaml' => 'text/yaml',
        'yml' => 'text/yaml', 'tsv' => 'text/tab-separated-values', 'ics' => 'text/calendar',
        'doc' => 'application/msword', 'dot' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'xls' => 'application/vnd.ms-excel', 'xlt' => 'application/vnd.ms-excel',
        'xla' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        'ppt' => 'application/vnd.ms-powerpoint', 'pot' => 'application/vnd.ms-powerpoint',
        'pps' => 'application/vnd.ms-powerpoint', 'ppa' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
        'rtf' => 'application/rtf', 'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'epub' => 'application/epub+zip',
        'zip' => 'application/zip', 'gz' => 'application/gzip', 'gzip' => 'application/gzip',
        'tar' => 'application/x-tar', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'bz2' => 'application/x-bzip2',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif', 'apng' => 'image/apng',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ico' => 'image/x-icon', 'heic' => 'image/heic',
        'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg', 'mpga' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'm4b' => 'audio/mp4',
        'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
        'flac' => 'audio/flac', 'aac' => 'audio/aac', 'adts' => 'audio/aac', 'wma' => 'audio/x-ms-wma',
        'mid' => 'audio/midi', 'midi' => 'audio/midi', 'weba' => 'audio/webm',
        'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'qt' => 'video/quicktime',
        'webm' => 'video/webm', 'ogv' => 'video/ogg', 'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv',
        'mkv' => 'video/x-matroska', 'mpeg' => 'video/mpeg', '3gp' => 'video/3gpp', '3g2' => 'video/3gpp2',
        'flv' => 'video/x-flv', 'f4v' => 'video/x-f4v', 'asf' => 'video/x-ms-asf', 'amv' => 'video/x-amv',
    ];

    return $map[strtolower($extension)] ?? 'application/octet-stream';
}

function findActiveUploadByName(int $userId, string $name, int $folderId = 0): ?array
{
    // 重名按文件夹隔离：同名文件可在不同文件夹共存；folder_id <=> NULL 是 NULL 安全等值，能命中根目录（未归类）的文件
    $statement = Database::connection()->prepare(
        "SELECT id, filename, display_name, file_size, notion_block_id FROM uploads
         WHERE user_id = :user_id AND status IN (" . UPLOAD_VISIBLE_STATUSES . ")
           AND COALESCE(NULLIF(display_name, ''), filename) = :name
           AND folder_id <=> :folder_id
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([
        'user_id'   => $userId,
        'name'      => $name,
        'folder_id' => $folderId > 0 ? $folderId : null,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** 重名自动编号：编号始终插在扩展名之前，不占用扩展名。查重同样限定在同一文件夹内。 */
function nextAvailableFilename(int $userId, string $name, int $folderId = 0): string
{
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $suffix = $extension === '' ? '' : '.' . $extension;

    for ($index = 1; $index <= 999; $index++) {
        $marker = '(' . $index . ')';
        $limit = max(1, 900 - strlen($suffix) - strlen($marker));
        $candidate = mb_strcut($stem, 0, $limit, 'UTF-8') . $marker . $suffix;
        if (findActiveUploadByName($userId, $candidate, $folderId) === null) {
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

/**
 * 当前请求来源 IP。
 *
 * 公网直连时只取 REMOTE_ADDR；位于反向代理之后（直连对端为本机/内网地址）时改看 X-Forwarded-For，
 * 否则所有访客都会算作同一个代理 IP，按 IP 的登录限流与验证码限流形同虚设。
 * 该头可被客户端伪造，因此从右往左读取（代理是从右侧追加的），跳过受信任的内网/保留地址，
 * 取第一个公网地址；客户端自带的左侧条目一律不采信。
 */
function clientIp(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if (remoteAddrIsTrustedProxy())
    {
        $chain = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        for ($i = count($chain) - 1; $i >= 0; $i--)
        {
            $candidate = trim($chain[$i]);
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false)
            {
                continue;
            }

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false)
            {
                return substr($candidate, 0, 45);
            }
        }
    }

    return $ip === '' ? '0.0.0.0' : substr($ip, 0, 45);
}

/** 直连对端是否为本机/内网地址：仅此情形才可能位于受信任的反向代理之后。 */
function remoteAddrIsTrustedProxy(): bool
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * 判断当前请求是否走 HTTPS。
 *
 * 反向代理终止 TLS 时需看 X-Forwarded-Proto，才能让 Cookie 的 Secure 标记与回调地址协议正确；
 * 但该头完全由客户端提供、可被伪造，因此仅当直连对端是反向代理（本机/内网地址）时才采信，
 * 公网直连请求一律以 Web 服务器给出的 HTTPS 变量为准。
 */
function requestIsHttps(): bool
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strcasecmp($https, 'off') !== 0) {
        return true;
    }

    if (!remoteAddrIsTrustedProxy()) {
        return false;
    }

    $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '';

    return strtolower(trim($forwarded)) === 'https';
}

/**
 * 登录限流状态：15 分钟内同一 IP 或同一账号失败 20 次即锁定。
 * 叠加账号维度这道防线，是因为仅靠 IP 阈值时攻击者轮换 IP 即可对同一账号持续撞库；
 * 账号锁定 15 分钟自动解锁，且失败 3 次起就要求图形验证码，可缓解「故意输错密码锁号」的 DoS。
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
    $accountLocked = $accountFailures >= 20;
    $ipLocked = $ipFailures >= 20;
    $locked = $accountLocked || $ipLocked;
    $retryAfter = $locked
        ? max(
            $accountLocked ? (int) $accountRow['retry_after'] : 0,
            $ipLocked ? (int) $ipRow['retry_after'] : 0
        )
        : 0;

    return [
        'locked' => $locked,
        'failures' => $accountFailures,
        'ip_failures' => $ipFailures,
        'captcha' => $accountFailures >= 3 || $ipFailures >= 3,
        'retry_after' => $retryAfter,
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

/** 是否允许使用官方账户来源：所有用户均可使用，前提是账户池至少有一个已启用账户。 */
function canUseOfficialSource(array $user): bool
{
    return activeOfficialAccounts() !== [];
}

/** 用户当前的 Notion 账户来源是否可用（来源可用 ≠ 有剩余容量，仅判断上传目标能否确定）。 */
function notionSourceUsable(array $user): bool
{
    return uploadTargets($user) !== [];
}

/** 没有可用上传目标时的准确提示：按账户来源区分「未绑定自有账户」「官方池无可用账户」，避免一律误报「未绑定」。 */
function notionTargetsMissingReason(array $user): string
{
    if (userNotionSource($user) !== 'official') {
        return '当前账号尚未完成 Notion 账户绑定，请先到「账户管理」中绑定后再上传。';
    }

    return activeOfficialAccounts() === []
        ? '官方账户池当前没有已启用的账户，请联系管理员在后台启用官方账户后再上传。'
        : '官方账户池当前没有可用账户（账户被停用，或 Token / 根页面失效），请联系管理员。';
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
        throw new RuntimeException('官方账户池当前没有可用账户，请联系管理员。');
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

    // 可能处于外层事务中，此时不能再开事务（PDO 不支持嵌套事务）
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
        // 用条件插入取代「先查后插」：两个并发请求可能同时判定“无未读同名告警”而各插一条，
        // 造成重复告警与重复邮件；把检查与写入合成一条语句可消除该竞态
        $statement = $pdo->prepare(
            'INSERT INTO admin_alerts (level, message, context)
             SELECT :level, :message, :context FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM admin_alerts WHERE is_read = 0 AND message = :duplicate_message)'
        );
        $statement->execute([
            'level'             => $level,
            'message'           => $message,
            'context'           => $context === [] ? null : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'duplicate_message' => $message,
        ]);
        $inserted = $statement->rowCount() > 0;
    } catch (Throwable $exception) {
        Logger::warning('alert', '写入后台告警失败。', ['reason' => $exception->getMessage()]);
    }

    // 只在新告警时发邮件，避免重复提醒；同一请求内相同内容也只发一次。
    // 发信走 SMTP 可达数秒，推迟到响应发出后再发，避免用户删除文件等操作时干等
    if ($inserted) {
        deferAfterResponse(static function () use ($level, $message, $context): void {
            sendAdminAlertMail($level, $message, $context);
        });
    }

    // 告警保留期：已读告警保留 30 天、全部告警保留 180 天，避免表无限增长（懒清理，无 cron）
    try {
        Database::connection()->exec(
            'DELETE FROM admin_alerts
             WHERE (is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                OR created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)'
        );
    } catch (Throwable $exception) {
        Logger::warning('alert', '清理过期后台告警失败。', ['reason' => $exception->getMessage()]);
    }
}

function unreadAdminAlerts(int $limit = 20): array
{
    $limit = max(1, min($limit, 100));

    return Database::connection()
        ->query('SELECT id, level, message, created_at FROM admin_alerts WHERE is_read = 0 ORDER BY created_at DESC, id DESC LIMIT ' . $limit)
        ->fetchAll();
}

/** 管理员告警邮件收件人：优先使用后台配置的收件邮箱，未配置时回退到管理员账号内已验证的邮箱。 */
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
        "SELECT email FROM users
         WHERE is_admin = 1 AND email IS NOT NULL AND email <> '' AND email_verified_at IS NOT NULL
         GROUP BY email"
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
        $site = siteName();
        $subject = ($level === 'error' ? '[严重] ' : '[提醒] ') . $site . ' 后台告警';
        $body = "后台产生了一条新的告警：\n\n级别：" . $level . "\n内容：" . $message;
        if ($context !== []) {
            $body .= "\n\n上下文：\n" . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $body .= "\n\n请登录管理后台查看详情。\n\n本邮件由系统自动发送，请勿直接回复。";

        // 复用同一条 SMTP 连接群发（只握手/认证一次），并附带 HTML 版本
        $failed = $mailer->sendMany($recipients, $subject, $body, MailTemplate::alert($level, $message, $context, $site));
        foreach ($failed as $recipient) {
            Logger::warning('alert', '发送管理员告警邮件失败。', ['to' => $recipient]);
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
        if ($token !== '' && normalizePageId($rootPageId) !== '') {
            return [[
                'type' => 'own',
                'account_id' => null,
                'token' => $token,
                'root_page_id' => $rootPageId,
                'label' => '自有账户',
                'page_id' => (string) ($user['notion_page_id'] ?? ''),
            ]];
        }

        // 自有账户尚未绑定：不直接失败，官方池可用时回落到官方池，
        // 避免「用官方池却被迫先绑定自有账户」；池内没有可用账户时返回空，由上层给出准确提示
        if (!canUseOfficialSource($user)) {
            return [];
        }
    }

    // 官方账户池是全体用户共享的资源：管理员指定的主备账户优先，未分配或分配不足时按池内优先级
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

/**
 * 双写：把临时文件写入用户的全部目标账户，至少一份成功即视为成功；全部失败时对外只提示「存储服务暂时不可用」，不透露账户信息。
 *
 * $onProgress 可选，签名 (int $percent)：把各账户的分片上传进度换算成整体 0-100 的「正在写入存储」进度回调给上层。
 */
function writeNotionReplicas(array $user, string $temporaryPath, string $filename, string $mime, int $size, ?string $storageName = null, ?callable $onProgress = null): array
{
    $targets = uploadTargets($user);
    if ($targets === []) {
        throw new RuntimeException(notionTargetsMissingReason($user));
    }

    // 同一文件在所有账户中共用同一个存储名，便于人工在 Notion 中核对。
    // 异步写入（后台队列）时存储名已在入库时算好并随文件保存，这里复用，避免前后端看到两个名字
    if ($storageName === null || $storageName === '') {
        $storageName = uniqueStorageName((int) $user['id'], $filename, $temporaryPath);
    }
    $replicas = [];
    // 整体进度按「目标账户」平分：第一个账户占 0~1/n、第二个占 1/n~2/n …… 全部账户都是同体积上传
    $targetCount = max(1, count($targets));
    $slot = 0;

    foreach ($targets as $target) {
        $role = count($replicas) + 1;
        $reportProgress = $onProgress === null ? null : static function (int $completed, int $total) use ($onProgress, $slot, $targetCount): void {
            $fraction = $total > 0 ? $completed / $total : 1.0;
            $onProgress((int) round(($slot + $fraction) / $targetCount * 100));
        };
        $slot++;
        try {
            $replicas[] = notionWriteWithRepair(
                static function () use ($user, $target, $temporaryPath, $storageName, $mime, $size, $role, $reportProgress): array {
                    $client = new NotionClient((string) $target['token']);
                    $pageId = ensureUserSpacePage($user, $target, $client);
                    $result = $client->uploadAndAttach($temporaryPath, $storageName, $mime, $size, $pageId, $reportProgress);
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

/**
 * 补偿：数据库落库失败时，把刚写入 Notion、但未成功登记进库的副本逐个归档，
 * 避免留下既不在「我的文件」里、又无法通过应用删除的孤儿文件块。
 *
 * @param array<int, array<string, mixed>> $replicas writeNotionReplicas() 返回的副本列表
 */
function archiveWrittenReplicas(array $user, array $replicas): void
{
    foreach ($replicas as $replica) {
        $blockId = (string) ($replica['notion_block_id'] ?? '');
        if ($blockId === '') {
            continue;
        }

        $type = (string) ($replica['account_type'] ?? '');
        if ($type === 'own') {
            $token = userNotionToken($user);
            $accountId = null;
            $label = '自有账户';
        } else {
            $accountId = (int) ($replica['official_account_id'] ?? 0);
            $statement = Database::connection()->prepare(
                'SELECT id, label, token_encrypted FROM official_accounts WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $accountId]);
            $account = $statement->fetch();
            if (!is_array($account)) {
                continue;
            }
            $token = officialAccountToken($account);
            $label = (string) ($account['label'] ?? '官方账户');
        }
        if ($token === '') {
            continue;
        }

        try {
            notionWriteWithRepair(
                static function () use ($token, $blockId): void {
                    (new NotionClient($token))->archiveBlock($blockId);
                },
                $user,
                ['type' => $type, 'account_id' => $accountId, 'label' => $label, 'token' => $token],
                [
                    'stage' => 'delete',
                    'user_id' => (int) ($user['id'] ?? 0),
                    'account_type' => $type,
                    'account_id' => $accountId,
                ]
            );
        } catch (Throwable $exception) {
            Logger::warning('upload', '落库失败后归档 Notion 副本失败。', [
                'user_id' => (int) ($user['id'] ?? 0),
                'account_id' => $accountId,
                'reason' => $exception->getMessage(),
            ]);
            raiseAdminAlert('warning', sprintf('账户「%s」清理孤儿副本失败：%s', $label, $exception->getMessage()), [
                'stage' => 'delete',
                'account_id' => $accountId,
            ]);
        }
    }
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
         WHERE user_id = :user_id AND status IN (" . UPLOAD_VISIBLE_STATUSES . ") AND folder_id IS NOT NULL
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

function findUserByUsername(string $username): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
}

function findUserById(int $userId): ?array
{
    $statement = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    return is_array($user) ? $user : null;
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
