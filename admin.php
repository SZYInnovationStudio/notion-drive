<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Mailer;
use NotionDrive\Moderation\ModerationService;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Restore\RestoreService;
use NotionDrive\Security;
use NotionDrive\Space\SpacePatrol;
use NotionDrive\Tickets\TicketBanService;
use NotionDrive\Tickets\TicketService;

$currentUser = Auth::requireAdmin();
$error = '';

/** 后台二级分区：键名为 ?tab= 取值；label 用于菜单，desc 显示在页头。 */
function adminTabs(): array
{
    return [
        'users' => ['group' => '管理后台', 'label' => '用户管理', 'desc' => '用户账号、容量与权限组'],
        'content' => ['group' => '管理后台', 'label' => '账户池管理', 'desc' => '官方账户池与用户账户分配'],
        'risk' => ['group' => '管理后台', 'label' => '内容风控', 'desc' => '关键词拦截、AI 识别、豁免免审核上传与申诉复核'],
        'files' => ['group' => '管理后台', 'label' => '文件管理', 'desc' => '查看与管理各用户的文件'],
        'tickets' => ['group' => '管理后台', 'label' => '工单设置', 'desc' => '工单过期、重开、评价与批量管理'],
        'preview' => ['group' => '管理后台', 'label' => '预览设置', 'desc' => '图片 / PDF / 音视频在线预览的开关与体积提醒阈值'],
        'reviews' => ['group' => '管理后台', 'label' => '评价统计', 'desc' => '工单评价的平均分、分布与最近明细'],
        'system' => ['group' => '管理后台', 'label' => '系统设置', 'desc' => '站点信息、注册与邮箱、上传与支付'],
        'restore' => ['group' => '管理后台', 'label' => '还原/恢复', 'desc' => '站点还原与恢复出厂设置'],
    ];
}

function normalizeAdminTab(mixed $value): string
{
    return is_string($value) && array_key_exists($value, adminTabs()) ? $value : '';
}

$adminTabs = adminTabs();
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
// 当前分区：优先取 ?tab=；未指定时，带 user_id 进入（订单页点「用户」）默认展示账户池管理
$requestedTab = normalizeAdminTab($_GET['tab'] ?? null);
$activeTab = $requestedTab !== '' ? $requestedTab : ($selectedUserId > 0 ? 'content' : 'users');
// 表单保存后仍回到当前分区，避免管理员被弹回默认页签
$adminHome = 'admin.php?tab=' . $activeTab;

function parseQuota(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }
    if (!is_numeric($value) || (float) $value < 0) {
        throw new RuntimeException('容量必须是大于等于 0 的数字；留空或 -1 表示不限。');
    }
    return (int) round((float) $value * 1024 * 1024 * 1024);
}

function parseMegabytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return null;
    }
    if (!is_numeric($value) || (float) $value < 0) {
        throw new RuntimeException('单文件上限必须是大于等于 0 的数字（MB）；留空或 -1 表示不限。');
    }
    return (int) round((float) $value * 1024 * 1024);
}

/** 保存 SMTP 表单字段；密码留空表示沿用旧值。 */
function saveSmtpSettings(): void
{
    $host = trim((string) ($_POST['smtp_host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('请填写 SMTP 服务器地址。');
    }
    $port = (int) ($_POST['smtp_port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP 端口需在 1–65535 之间。');
    }
    $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        throw new RuntimeException('SMTP 加密方式无效。');
    }
    $from = trim((string) ($_POST['smtp_from'] ?? ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('发件人邮箱地址无效。');
    }
    // 告警收件邮箱允许留空（留空时回退到管理员组内已验证的邮箱），填写时支持多个收件人
    $alertEmail = trim((string) ($_POST['alert_email'] ?? ''));
    foreach (preg_split('/[,，;；\s]+/u', $alertEmail) ?: [] as $item) {
        $item = trim($item);
        if ($item !== '' && !filter_var($item, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('告警收件邮箱「' . $item . '」格式不正确，多个邮箱请用逗号分隔。');
        }
    }

    Database::setConfig('smtp_host', $host);
    Database::setConfig('smtp_port', (string) $port);
    Database::setConfig('smtp_encryption', $encryption);
    Database::setConfig('smtp_username', trim((string) ($_POST['smtp_username'] ?? '')));
    Database::setConfig('smtp_from', $from);
    Database::setConfig('smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? '')));
    Database::setConfig('alert_email', $alertEmail);

    $password = (string) ($_POST['smtp_password'] ?? '');
    if ($password !== '') {
        $config = appConfig();
        Database::setConfig('smtp_password', Security::encrypt($password, (string) $config['app_key']));
    }
}

/** 保存站点 logo / favicon：logo 支持常见位图（≤2MB），favicon 仅 .ico（≤1MB），均存 storage/site。 */
function storeSiteImage(array $file, string $kind): string
{
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('图片上传失败，请重新选择后重试。');
    }
    $size = (int) ($file['size'] ?? 0);

    $directory = __DIR__ . '/storage/site';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建站点资源目录，请检查 storage 目录权限。');
    }

    if ($kind === 'favicon') {
        if ($size > 1024 * 1024) {
            throw new RuntimeException('favicon 文件不能超过 1 MB。');
        }
        // .ico 无统一 MIME，改用扩展名 + ICO 文件头（00 00 01 00）双重校验
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $header = (string) file_get_contents($temporaryPath, false, null, 0, 4);
        if ($extension !== 'ico' || $header !== "\x00\x00\x01\x00") {
            throw new RuntimeException('favicon 仅支持 .ico 格式。');
        }
        $filename = 'favicon-' . bin2hex(random_bytes(8)) . '.ico';
    } else {
        if ($size > 2 * 1024 * 1024) {
            throw new RuntimeException('站点 logo 不能超过 2 MB。');
        }
        $info = @getimagesize($temporaryPath);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
        if ($extension === '') {
            throw new RuntimeException('站点 logo 仅支持 JPG、PNG、GIF 或 WebP 格式。');
        }
        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        throw new RuntimeException('图片保存失败，请稍后重试。');
    }

    return 'storage/site/' . $filename;
}

/** 删除旧的站点资源文件（仅限 storage/site 内，避免路径穿越）。 */
function removeSiteAsset(string $path): void
{
    if ($path === '') {
        return;
    }
    $base = realpath(__DIR__ . '/storage/site');
    $target = realpath(__DIR__ . '/' . $path);
    if ($base !== false && $target !== false && str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        @unlink($target);
    }
}

/** 保存公告正文图片：支持 JPG/PNG/GIF/WebP（≤5 MB），存 storage/announcements，返回文件名（供 site-asset.php 代理输出）。 */
function storeAnnouncementImage(array $file): string
{
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('图片上传失败，请重新选择后重试。');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('公告图片不能超过 5 MB。');
    }

    $info = @getimagesize($temporaryPath);
    $mime = is_array($info) ? (string) $info['mime'] : '';
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => '',
    };
    if ($extension === '') {
        throw new RuntimeException('公告图片仅支持 JPG、PNG、GIF 或 WebP 格式。');
    }

    $directory = __DIR__ . '/storage/announcements';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建公告图片目录，请检查 storage 目录权限。');
    }

    $filename = 'ann-' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        throw new RuntimeException('图片保存失败，请稍后重试。');
    }

    return $filename;
}

/** 删除公告图片文件（仅限 storage/announcements 内，避免路径穿越）。 */
function removeAnnouncementImage(string $filename): void
{
    if ($filename === '' || preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) {
        return;
    }
    $base = realpath(__DIR__ . '/storage/announcements');
    $target = realpath(__DIR__ . '/storage/announcements/' . $filename);
    if ($base !== false && $target !== false && str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        @unlink($target);
    }
}

/** 从公告正文中提取引用的图片文件名（用于删除公告时清理孤儿图片）。 */
function announcementImagesInBody(string $body): array
{
    preg_match_all('/site-asset\.php\?k=media&(?:amp;)?f=([A-Za-z0-9._-]+)/', $body, $matches);

    return array_values(array_unique($matches[1] ?? []));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Security::verifyCsrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $pdo = Database::connection();

        if ($action === 'create_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
                throw new RuntimeException('用户名需为 3–64 个字符，仅使用文字、数字、点、横线或下划线。');
            }
            if (mb_strlen($password) < 10) {
                throw new RuntimeException('密码至少需要 10 个字符。');
            }
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $group = findGroup($groupId > 0 ? $groupId : defaultGroupId());
            if ($group === null) {
                throw new RuntimeException('请选择有效的权限组。');
            }
            $notionSource = (string) ($_POST['notion_source'] ?? 'own') === 'official' ? 'official' : 'own';
            $statement = $pdo->prepare('INSERT INTO users (username, password_hash, group_id, quota_bytes, notion_account_source) VALUES (:username, :password_hash, :group_id, :quota_bytes, :notion_account_source)');
            $statement->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'group_id' => (int) $group['id'],
                'quota_bytes' => parseQuota((string) ($_POST['quota_gb'] ?? '')),
                'notion_account_source' => $notionSource,
            ]);
            // 选择官方账户来源的新用户立即分配主备账户，否则建号后依然无法上传
            if ($notionSource === 'official') {
                autoAssignOfficialAccounts((int) $pdo->lastInsertId());
            }
            flash('success', $notionSource === 'official'
                ? '用户已创建，并已分配官方账户。'
                : '用户已创建；该用户需要在「账户管理」中绑定自有 Notion 账户后才能上传。');
        } elseif ($action === 'update_user') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $targetStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
            $targetStatement->execute(['id' => $id]);
            if ((int) $targetStatement->fetchColumn() === 0) {
                // 目标用户可能在别处已被删除，此时静默改 0 行会给出「已更新」的错误承诺
                throw new RuntimeException('该用户不存在或已被删除。');
            }
            if (!preg_match('/^[\p{L}\p{N}_.-]{3,64}$/u', $username)) {
                throw new RuntimeException('用户名格式无效。');
            }
            $group = findGroup((int) ($_POST['group_id'] ?? 0));
            if ($group === null) {
                throw new RuntimeException('请选择有效的权限组。');
            }
            if ($id === (int) $currentUser['id'] && (int) $group['is_admin'] !== 1) {
                throw new RuntimeException('不能取消自己的管理员权限。');
            }
            if ($password !== '' && mb_strlen($password) < 10) {
                throw new RuntimeException('新密码至少需要 10 个字符。');
            }
            $sql = 'UPDATE users SET username = :username, group_id = :group_id, quota_bytes = :quota_bytes';
            $params = ['id' => $id, 'username' => $username, 'group_id' => (int) $group['id'], 'quota_bytes' => parseQuota((string) ($_POST['quota_gb'] ?? ''))];
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            flash('success', '用户资料、权限组与容量已更新。');
        } elseif ($action === 'save_group') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $name = trim((string) ($_POST['group_name'] ?? ''));
            if (!preg_match('/^[\p{L}\p{N}_.\- ]{1,64}$/u', $name)) {
                throw new RuntimeException('权限组名称需为 1–64 个字符，仅使用文字、数字、空格、点、横线或下划线。');
            }
            $level = (int) ($_POST['group_level'] ?? 0);
            if ($level < 0 || $level > 1000) {
                throw new RuntimeException('等级需在 0–1000 之间。');
            }
            $quota = parseQuota((string) ($_POST['group_quota_gb'] ?? ''));
            $singleLimit = parseMegabytes((string) ($_POST['group_single_mb'] ?? ''));
            $isAdminGroup = isset($_POST['group_is_admin']) ? 1 : 0;

            if ($groupId > 0) {
                $existing = findGroup($groupId);
                if ($existing === null) {
                    throw new RuntimeException('权限组不存在。');
                }
                if ((int) $existing['is_builtin'] === 1 && $existing['name'] !== $name) {
                    throw new RuntimeException('内置权限组的名称不可修改。');
                }
                if ($groupId === (int) $currentUser['group_id'] && $isAdminGroup === 0) {
                    throw new RuntimeException('不能移除自己所在权限组的管理员权限。');
                }
                if ((int) $existing['is_admin'] === 1 && $isAdminGroup === 0) {
                    $adminGroups = (int) $pdo->query('SELECT COUNT(*) FROM `groups` WHERE is_admin = 1')->fetchColumn();
                    if ($adminGroups <= 1) {
                        throw new RuntimeException('至少要保留一个管理员权限组。');
                    }
                }
                $pdo->prepare(
                    'UPDATE `groups` SET name = :name, quota_bytes = :quota_bytes, single_file_limit_bytes = :single_file_limit_bytes,
                        is_admin = :is_admin, level = :level WHERE id = :id'
                )->execute([
                    'id' => $groupId,
                    'name' => $name,
                    'quota_bytes' => $quota,
                    'single_file_limit_bytes' => $singleLimit,
                    'is_admin' => $isAdminGroup,
                    'level' => $level,
                ]);
                flash('success', '权限组已更新。');
            } else {
                $pdo->prepare(
                    'INSERT INTO `groups` (name, quota_bytes, single_file_limit_bytes, is_admin, level, is_builtin)
                     VALUES (:name, :quota_bytes, :single_file_limit_bytes, :is_admin, :level, 0)'
                )->execute([
                    'name' => $name,
                    'quota_bytes' => $quota,
                    'single_file_limit_bytes' => $singleLimit,
                    'is_admin' => $isAdminGroup,
                    'level' => $level,
                ]);
                flash('success', '权限组已创建。');
            }
        } elseif ($action === 'delete_group') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $group = findGroup($groupId);
            if ($group === null) {
                throw new RuntimeException('权限组不存在。');
            }
            if ((int) $group['is_builtin'] === 1) {
                throw new RuntimeException('内置权限组不可删除。');
            }
            $members = $pdo->prepare('SELECT COUNT(*) FROM users WHERE group_id = :id');
            $members->execute(['id' => $groupId]);
            if ((int) $members->fetchColumn() > 0) {
                throw new RuntimeException('该权限组下仍有用户，请先把用户迁移到其他权限组。');
            }
            $pdo->prepare('DELETE FROM `groups` WHERE id = :id')->execute(['id' => $groupId]);
            flash('success', '权限组已删除。');
        } elseif ($action === 'save_access' || $action === 'test_mail') {
            // 注册开关与 SMTP 配置相互独立：未填写 SMTP 服务器时只保存注册开关，不校验邮件设置
            if ($action === 'test_mail' || trim((string) ($_POST['smtp_host'] ?? '')) !== '') {
                saveSmtpSettings();
            }

            // 测试邮件与保存设置共用同一张表单，统一落库，避免开关改动被静默丢弃
            $emailRequired = isset($_POST['email_required']) ? '1' : '0';
            if ($emailRequired === '1' && !smtpConfigured()) {
                throw new RuntimeException('开启「强制绑定邮箱」前，请先填写完整的 SMTP 配置。');
            }
            Database::setConfig('registration_enabled', isset($_POST['registration_enabled']) ? '1' : '0');
            Database::setConfig('email_required', $emailRequired);

            if ($action === 'test_mail') {
                $recipient = trim((string) ($_POST['smtp_test_to'] ?? ''));
                Mailer::fromConfig()->send($recipient, 'Notion Drive 测试邮件', "这是一封来自 Notion Drive 的测试邮件。\n\n如果你收到了它，说明 SMTP 配置可以正常发信。");
                Logger::info('mail', '已发送 SMTP 测试邮件。', ['to' => $recipient, 'operator' => $currentUser['username']]);
                flash('success', '测试邮件已发送至 ' . $recipient . '，请检查收件箱。注册与邮箱设置也已一并保存。');
            } else {
                flash('success', '注册与邮箱设置已保存。');
            }
        } elseif ($action === 'save_site') {
            // 站点名称留空即回落默认「Notion Drive」，不做格式校验，原样保存
            Database::setConfig('site_name', trim((string) ($_POST['site_name'] ?? '')));
            // 备案号留空即不显示，因此不做任何格式校验，原样保存
            Database::setConfig('icp_license', trim((string) ($_POST['icp_license'] ?? '')));
            Database::setConfig('police_license', trim((string) ($_POST['police_license'] ?? '')));

            // favicon：可单独删除或替换。替换时先落盘新文件、写库成功后再删旧文件，
            // 避免新图校验失败（超限 / 格式不符）时旧图已被删除、配置却仍指向不存在的文件
            if (isset($_POST['remove_favicon'])) {
                removeSiteAsset(siteFaviconPath());
                Database::setConfig('site_favicon', '');
            }
            if (isset($_FILES['site_favicon']) && (int) ($_FILES['site_favicon']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $previousFavicon = siteFaviconPath();
                Database::setConfig('site_favicon', storeSiteImage($_FILES['site_favicon'], 'favicon'));
                removeSiteAsset($previousFavicon);
            }

            // 站点 logo：同 favicon，允许删除或替换，同样先存新后删旧
            if (isset($_POST['remove_logo'])) {
                removeSiteAsset(siteLogoPath());
                Database::setConfig('site_logo', '');
            }
            if (isset($_FILES['site_logo']) && (int) ($_FILES['site_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $previousLogo = siteLogoPath();
                Database::setConfig('site_logo', storeSiteImage($_FILES['site_logo'], 'logo'));
                removeSiteAsset($previousLogo);
            }

            flash('success', '站点信息已保存。');
        } elseif ($action === 'save_announcement') {
            $id = (int) ($_POST['announcement_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('公告标题不能为空。');
            }
            if (mb_strlen($title) > 200) {
                throw new RuntimeException('公告标题不能超过 200 个字。');
            }
            if ($body === '') {
                throw new RuntimeException('公告正文不能为空。');
            }

            // 新上传的图片：保存后把 Markdown 片段追加到正文末尾，随正文一起渲染
            if (isset($_FILES['announcement_image']) && (int) ($_FILES['announcement_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $body .= ($body === '' ? '' : "\n\n") . announcementImageMarkdown(storeAnnouncementImage($_FILES['announcement_image']));
            }

            if ($id > 0) {
                // 取旧正文：既做存在性校验，也用于比对图片引用差集，清理被移除的旧图
                $previousStatement = $pdo->prepare('SELECT body FROM announcements WHERE id = :id');
                $previousStatement->execute(['id' => $id]);
                $previousRow = $previousStatement->fetch();
                if ($previousRow === false) {
                    throw new RuntimeException('该公告不存在或已被删除。');
                }
                // 显式刷新 updated_at：公告内容/状态变化后指纹随之改变，前台弹窗会重新提醒所有用户
                $pdo->prepare('UPDATE announcements SET title = :title, body = :body, is_active = :is_active, updated_at = NOW() WHERE id = :id')
                    ->execute([
                        'title'     => $title,
                        'body'      => $body,
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'id'        => $id,
                    ]);
                // 旧正文里被删除的图片引用一并清理，避免 storage/announcements 残留孤儿文件
                $orphanImages = array_diff(
                    announcementImagesInBody((string) $previousRow['body']),
                    announcementImagesInBody($body)
                );
                foreach ($orphanImages as $orphan) {
                    removeAnnouncementImage($orphan);
                }
                flash('success', '公告已更新，前台弹窗将重新提醒所有用户。');
            } else {
                $pdo->prepare('INSERT INTO announcements (title, body, is_active) VALUES (:title, :body, :is_active)')
                    ->execute([
                        'title'     => $title,
                        'body'      => $body,
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    ]);
                flash('success', '公告已发布。');
            }
        } elseif ($action === 'toggle_announcement') {
            $id = (int) ($_POST['announcement_id'] ?? 0);
            // 启用/停用同样刷新 updated_at：重新启用后前台会再次提醒
            $toggleStatement = $pdo->prepare('UPDATE announcements SET is_active = 1 - is_active, updated_at = NOW() WHERE id = :id');
            $toggleStatement->execute(['id' => $id]);
            // is_active 必然翻转，影响行数为 0 只可能是该公告不存在
            if ($toggleStatement->rowCount() === 0) {
                throw new RuntimeException('该公告不存在或已被删除。');
            }
            flash('success', '公告状态已切换。');
        } elseif ($action === 'delete_announcement') {
            $id = (int) ($_POST['announcement_id'] ?? 0);
            $statement = $pdo->prepare('SELECT body FROM announcements WHERE id = :id');
            $statement->execute(['id' => $id]);
            $row = $statement->fetch();
            if ($row === false) {
                throw new RuntimeException('该公告不存在或已被删除。');
            }
            // 正文里引用的图片一并清理，避免 storage/announcements 残留孤儿文件
            foreach (announcementImagesInBody((string) $row['body']) as $filename) {
                removeAnnouncementImage($filename);
            }
            $pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute(['id' => $id]);
            flash('success', '公告已删除。');
        } elseif ($action === 'delete_user') {
            $id = (int) ($_POST['user_id'] ?? 0);
            if ($id === (int) $currentUser['id']) {
                throw new RuntimeException('不能删除当前登录账号。');
            }
            $targetStatement = $pdo->prepare('SELECT * FROM users WHERE id = :id');
            $targetStatement->execute(['id' => $id]);
            $target = $targetStatement->fetch();
            if ($target === false) {
                throw new RuntimeException('该用户不存在或已被删除。');
            }

            // 副本可能分布在自有账户或官方账户池中的任意账户，必须用对应账户的 Token 归档，否则会残留孤儿块
            $ownToken = userNotionToken($target);
            $clients = [];
            $clientFor = static function (string $token) use (&$clients): ?NotionClient {
                if ($token === '') {
                    return null;
                }

                return $clients[$token] ??= new NotionClient($token);
            };

            $replicas = $pdo->prepare(
                'SELECT r.account_type, r.official_account_id, r.notion_block_id, a.token_encrypted
                 FROM upload_replicas r
                 JOIN uploads u ON u.id = r.upload_id
                 LEFT JOIN official_accounts a ON a.id = r.official_account_id
                 WHERE u.user_id = :id AND r.notion_block_id IS NOT NULL'
            );
            $replicas->execute(['id' => $id]);
            foreach ($replicas->fetchAll() as $replica) {
                $blockId = (string) ($replica['notion_block_id'] ?? '');
                if ($blockId === '') {
                    continue;
                }
                $token = (string) $replica['account_type'] === 'own'
                    ? $ownToken
                    : officialAccountToken([
                        'id' => (int) ($replica['official_account_id'] ?? 0),
                        'token_encrypted' => (string) ($replica['token_encrypted'] ?? ''),
                    ]);
                $client = $clientFor($token);
                if ($client === null) {
                    Logger::warning('admin', sprintf('删除用户 #%d 时缺少副本所属账户的 Token，跳过归档。', $id), [
                        'user_id' => $id,
                        'block_id' => $blockId,
                        'account_type' => (string) $replica['account_type'],
                    ]);
                    continue;
                }
                // 远端归档失败不能中断本地删除，否则用户会永久残留且无法再次清理，仅记录日志供管理员排查
                try {
                    $client->archiveBlock($blockId);
                } catch (Throwable $exception) {
                    Logger::warning('admin', sprintf('删除用户 #%d 时归档 Notion 块失败：%s', $id, $exception->getMessage()), [
                        'user_id' => $id,
                        'block_id' => $blockId,
                    ]);
                }
            }

            // 用户在各账户下的空间页面同样要清理：自有账户用用户 Token，官方账户用对应账户 Token
            $userPages = [
                ['page_id' => (string) ($target['notion_page_id'] ?? ''), 'token' => $ownToken],
            ];
            $officialPages = $pdo->prepare(
                'SELECT ua.notion_page_id, a.id, a.token_encrypted
                 FROM user_official_accounts ua
                 JOIN official_accounts a ON a.id = ua.account_id
                 WHERE ua.user_id = :id'
            );
            $officialPages->execute(['id' => $id]);
            foreach ($officialPages->fetchAll() as $row) {
                $userPages[] = ['page_id' => (string) ($row['notion_page_id'] ?? ''), 'token' => officialAccountToken($row)];
            }
            foreach ($userPages as $page) {
                if ($page['page_id'] === '') {
                    continue;
                }
                $client = $clientFor($page['token']);
                if ($client === null) {
                    continue;
                }
                try {
                    $client->archivePage($page['page_id']);
                } catch (Throwable $exception) {
                    Logger::warning('admin', sprintf('删除用户 #%d 时归档 Notion 页面失败：%s', $id, $exception->getMessage()), [
                        'user_id' => $id,
                        'page_id' => $page['page_id'],
                    ]);
                }
            }
            removeAvatarFile((string) ($target['avatar_path'] ?? ''));
            $email = trim((string) ($target['email'] ?? ''));
            if ($email !== '') {
                $pdo->prepare('DELETE FROM email_verifications WHERE email = :email')->execute(['email' => $email]);
            }
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            flash('success', '用户及其文件记录已删除。');
        } elseif ($action === 'save_official_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            if (!preg_match('/^[\p{L}\p{N}_.\- ]{1,64}$/u', $label)) {
                throw new RuntimeException('账户名称需为 1–64 个字符，仅使用文字、数字、空格、点、横线或下划线。');
            }
            $priority = (int) ($_POST['priority'] ?? 0);
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $token = trim((string) ($_POST['token'] ?? ''));
            $existing = $accountId > 0 ? findOfficialAccount($accountId) : null;
            if ($accountId > 0 && $existing === null) {
                throw new RuntimeException('该官方账户不存在或已被删除。');
            }
            if ($existing === null && $token === '') {
                throw new RuntimeException('新增官方账户时必须填写集成 Token。');
            }

            // 根页面留空表示沿用原值；新建时不允许留空
            $rootInput = trim((string) ($_POST['root_page'] ?? ''));
            if ($rootInput === '') {
                $rootPageId = $existing === null ? '' : (string) $existing['root_page_id'];
                if ($rootPageId === '') {
                    throw new RuntimeException('请填写该账户下已共享给集成的根页面链接或 ID。');
                }
            } else {
                $rootPageId = extractNotionPageId($rootInput);
                if ($rootPageId === '') {
                    throw new RuntimeException('无法从该链接或 ID 中解析出 Notion 页面，请粘贴页面链接或链接末尾的 32 位 ID。');
                }
            }

            $params = [
                'label' => $label,
                'root_page_id' => $rootPageId,
                'is_enabled' => $isEnabled,
                'priority' => $priority,
            ];
            if ($existing === null) {
                $params['token_encrypted'] = Security::encrypt($token, (string) appConfig()['app_key']);
                $pdo->prepare(
                    'INSERT INTO official_accounts (label, token_encrypted, root_page_id, is_enabled, priority)
                     VALUES (:label, :token_encrypted, :root_page_id, :is_enabled, :priority)'
                )->execute($params);
                flash('success', '官方账户已添加。');
            } else {
                $sql = 'UPDATE official_accounts SET label = :label, root_page_id = :root_page_id,
                        is_enabled = :is_enabled, priority = :priority';
                if ($token !== '') {
                    // 换了 Token 或根页面后，上一次的检测结果不再可信，一并清空
                    $params['token_encrypted'] = Security::encrypt($token, (string) appConfig()['app_key']);
                    $sql .= ', token_encrypted = :token_encrypted, last_check_at = NULL, last_error = NULL';
                }
                $sql .= ' WHERE id = :id';
                $params['id'] = $accountId;
                $pdo->prepare($sql)->execute($params);
                flash('success', '官方账户已更新。');
            }
        } elseif ($action === 'test_official_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $account = findOfficialAccount($accountId);
            if ($account === null) {
                throw new RuntimeException('该官方账户不存在或已被删除。');
            }
            // 允许直接使用表单里刚填写、尚未保存的 Token 与根页面，省去「先保存再测试」的两步操作
            $postedToken = trim((string) ($_POST['token'] ?? ''));
            $token = $postedToken !== '' ? $postedToken : officialAccountToken($account);
            if ($token === '') {
                throw new RuntimeException('该账户还没有 Token，请先填写并保存。');
            }
            $postedRoot = trim((string) ($_POST['root_page'] ?? ''));
            $rootPageId = $postedRoot !== '' ? extractNotionPageId($postedRoot) : (string) $account['root_page_id'];
            if ($rootPageId === '') {
                throw new RuntimeException('无法从该链接或 ID 中解析出 Notion 页面。');
            }

            try {
                (new NotionClient($token))->retrievePage($rootPageId);
            } catch (Throwable $exception) {
                $pdo->prepare('UPDATE official_accounts SET last_check_at = NOW(), last_error = :error WHERE id = :id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 255), 'id' => $accountId]);
                Logger::warning('admin', sprintf('测试官方账户 #%d 失败：%s', $accountId, $exception->getMessage()), [
                    'account_id' => $accountId,
                    'operator' => $currentUser['username'],
                ]);
                throw new RuntimeException('连接失败：' . $exception->getMessage());
            }
            $pdo->prepare('UPDATE official_accounts SET last_check_at = NOW(), last_error = NULL WHERE id = :id')
                ->execute(['id' => $accountId]);
            flash('success', '连接正常，该账户可以访问其根页面。');
        } elseif ($action === 'delete_official_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            if (findOfficialAccount($accountId) === null) {
                throw new RuntimeException('该官方账户不存在或已被删除。');
            }
            $assigned = $pdo->prepare('SELECT COUNT(*) FROM user_official_accounts WHERE account_id = :id');
            $assigned->execute(['id' => $accountId]);
            if ((int) $assigned->fetchColumn() > 0) {
                throw new RuntimeException('仍有用户被分配到该账户，请先改派到其他账户后再删除。');
            }
            // 副本记录没有外键约束，直接删除账户会让这些文件失去可下载的来源，因此一并拦下
            $replicas = $pdo->prepare("SELECT COUNT(*) FROM upload_replicas WHERE official_account_id = :id AND status = 'active'");
            $replicas->execute(['id' => $accountId]);
            if ((int) $replicas->fetchColumn() > 0) {
                throw new RuntimeException('该账户下仍有文件的副本，删除后这些文件将无法下载，请先处理这些文件。');
            }
            $pdo->prepare('DELETE FROM official_accounts WHERE id = :id')->execute(['id' => $accountId]);
            flash('success', '官方账户已删除。');
        } elseif ($action === 'assign_accounts' || $action === 'auto_assign_accounts') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $targetStatement = $pdo->prepare('SELECT id, notion_account_source FROM users WHERE id = :id');
            $targetStatement->execute(['id' => $userId]);
            $targetUser = $targetStatement->fetch();
            if ($targetUser === false) {
                throw new RuntimeException('该用户不存在或已被删除。');
            }
            // 用户当前使用自有 Notion 账户时，官方账户分配不会生效：给出明确提示，避免误导为「已生效」
            $usingOwnAccount = ($targetUser['notion_account_source'] ?? 'own') === 'own';

            if ($action === 'assign_accounts') {
                // 只接受池中真实存在的账户 ID，避免被手工构造的请求写入无效外键
                $validIds = [];
                foreach (allOfficialAccounts() as $account) {
                    $validIds[(int) $account['id']] = true;
                }
                $accountIds = [];
                foreach ([(int) ($_POST['primary_account_id'] ?? 0), (int) ($_POST['backup_account_id'] ?? 0)] as $accountId) {
                    if ($accountId > 0 && isset($validIds[$accountId])) {
                        $accountIds[] = $accountId;
                    }
                }
            } else {
                if (activeOfficialAccounts() === []) {
                    throw new RuntimeException('官方账户池为空或全部未启用，无法自动分配。');
                }
                // 负载最低者优先的挑选逻辑在 helpers 中统一实现，避免与付费开通时的自动分配行为不一致
                autoAssignOfficialAccounts($userId);
                flash('success', $usingOwnAccount
                    ? '已按负载自动分配主备官方账户，但该用户当前使用自有 Notion 账户，切换为官方账户后才会生效。'
                    : '已按负载自动分配主备官方账户。');
                // 回到该用户的详情面板，方便管理员立即核对分配结果
                header('Location: admin.php?tab=' . $activeTab . '&user_id=' . $userId);
                exit;
            }

            assignOfficialAccounts($userId, $accountIds);
            flash('success', $usingOwnAccount
                ? '已保存该用户的主备官方账户，但该用户当前使用自有 Notion 账户，切换为官方账户后才会生效。'
                : '已更新该用户的主备官方账户。');
            header('Location: admin.php?tab=' . $activeTab . '&user_id=' . $userId);
            exit;
        } elseif ($action === 'save_plan') {
            // 「套餐」原属系统设置里的「Notion 连接」面板，该面板已删除（Token/根页面改由账户池按账户管理），
            // 仅把套餐这一全局项迁到「账户池管理」页；它决定官方账户单文件上限是否放宽（见 officialFileLimitBytes()）
            $plan = (string) ($_POST['plan'] ?? '');
            if (!in_array($plan, ['free', 'paid'], true)) {
                throw new RuntimeException('套餐选项无效。');
            }
            Database::setConfig('notion_plan', $plan);
            flash('success', '官方账户套餐已更新。');
        } elseif ($action === 'save_limits') {
            $officialLimitMb = (int) ($_POST['official_single_file_limit_mb'] ?? 0);
            $chunkMb = (int) ($_POST['upload_chunk_size_mb'] ?? 0);
            if ($officialLimitMb < 1 || $officialLimitMb > 1048576) {
                throw new RuntimeException('官方账户单文件上限需为 1 ~ 1048576 MB 之间的整数。');
            }
            if ($chunkMb < 1 || $chunkMb > 1024) {
                throw new RuntimeException('上传分块大小需为 1 ~ 1024 MB 之间的整数。');
            }
            Database::setConfig('official_single_file_limit_bytes', (string) ($officialLimitMb * 1024 * 1024));
            Database::setConfig('upload_chunk_size_mb', (string) $chunkMb);
            $effective = uploadChunkSizeBytes();
            flash('success', $effective < $chunkMb * 1024 * 1024
                ? sprintf('上传与容量设置已保存；受服务器 PHP 上传限制约束，分块实际生效值为 %s。', formatBytes($effective))
                : '上传与容量设置已保存。');
        } elseif ($action === 'save_payment') {
            $gateway = trim((string) ($_POST['epay_gateway'] ?? ''));
            if ($gateway !== '' && !preg_match('#^https?://#i', $gateway)) {
                throw new RuntimeException('易支付接口地址需以 http:// 或 https:// 开头；留空表示暂时停用在线支付。');
            }
            // 查单请求会把商户密钥放在 URL 查询串里，明文 http 会让密钥在链路上泄露；本机调试地址除外
            $gatewayHost = strtolower((string) parse_url($gateway, PHP_URL_HOST));
            if ($gateway !== '' && strncasecmp($gateway, 'http://', 7) === 0
                && !in_array($gatewayHost, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('易支付接口地址必须使用 https://；http:// 仅允许本机地址（商户密钥会随请求出现在 URL 上）。');
            }
            $pid = trim((string) ($_POST['epay_pid'] ?? ''));
            if (mb_strlen($pid) > 64) {
                throw new RuntimeException('商户 ID 不能超过 64 个字符。');
            }
            if ($gateway !== '' && $pid === '') {
                throw new RuntimeException('填写了易支付接口地址时必须同时填写商户 ID。');
            }
            $siteUrlInput = trim((string) ($_POST['site_url'] ?? ''));
            if ($siteUrlInput !== '' && !preg_match('#^https?://#i', $siteUrlInput)) {
                throw new RuntimeException('站点地址需以 http:// 或 https:// 开头；留空表示按访问地址自动识别。');
            }

            // 渠道只接受白名单内的值，避免把任意字符串写进配置后被下游当成 type 使用
            $validTypes = epayPayTypeOptions();
            $types = [];
            foreach ((array) ($_POST['epay_pay_types'] ?? []) as $type) {
                $type = trim((string) $type);
                if (isset($validTypes[$type]) && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }

            $key = trim((string) ($_POST['epay_key'] ?? ''));
            if ($gateway !== '' && (string) Database::config('epay_key', '') === '' && $key === '') {
                throw new RuntimeException('首次配置支付接口时请填写商户密钥。');
            }

            // 价格先全量校验再落库，避免部分档位写成功、部分写失败造成配置不一致
            $prices = [];
            foreach (array_keys(premiumPlans()) as $months) {
                $price = trim((string) ($_POST['premium_price_' . $months] ?? ''));
                if (!is_numeric($price) || (float) $price < 0.01 || (float) $price > 99999) {
                    throw new RuntimeException(sprintf('%d 个月套餐的价格需为 0.01 ~ 99999 之间的数字。', $months));
                }
                $prices[$months] = number_format(round((float) $price, 2), 2, '.', '');
            }

            Database::setConfig('epay_gateway', $gateway);
            Database::setConfig('epay_pid', $pid);
            Database::setConfig('site_url', rtrim($siteUrlInput, '/'));
            Database::setConfig('epay_pay_types', implode(',', $types));
            if ($key !== '') {
                Database::setConfig('epay_key', Security::encrypt($key, (string) appConfig()['app_key']));
            }
            foreach ($prices as $months => $price) {
                Database::setConfig('premium_price_' . $months, $price);
            }

            Logger::info('payment', '易支付配置已更新。', [
                'operator' => $currentUser['username'],
                'gateway' => $gateway,
                'enabled_types' => $types,
                'key_updated' => $key !== '',
            ]);
            flash('success', '支付设置已保存。');
        } elseif ($action === 'grant_premium') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $months = (int) ($_POST['grant_months'] ?? 0);
            if (!isset(premiumPlans()[$months])) {
                throw new RuntimeException('请选择有效的开通时长。');
            }
            $targetUser = findUserById($userId);
            if ($targetUser === null) {
                throw new RuntimeException('该用户不存在或已被删除。');
            }
            if (isAdmin($targetUser)) {
                throw new RuntimeException('管理员账号无需开通高级套餐。');
            }
            // 人工开通同时生成一条「管理员授权」订单，用户端能看到凭据，后台对账也能区分线上支付
            $result = grantManualPremiumOrder($userId, $months, (int) $currentUser['id']);
            Logger::info('payment', '管理员手动开通/续费高级用户。', [
                'user_id' => $userId,
                'months' => $months,
                'operator' => $currentUser['username'],
                'order_no' => $result['order_no'],
                'expires_after' => $result['expires_after'],
            ]);
            // 永久会员时 grantPremium 不设到期时间，需单独提示，否则会显示成 1970-01-01 08:00
            $expiryText = $result['expires_after'] === null
                ? '永久有效'
                : date('Y-m-d H:i', (int) strtotime((string) $result['expires_after']));
            flash('success', sprintf(
                '已为 %s 开通 %d 个月高级套餐，有效期至 %s（授权订单 %s）。',
                (string) $targetUser['username'],
                $months,
                $expiryText,
                $result['order_no']
            ));
            header('Location: admin.php?tab=' . $activeTab . '&user_id=' . $userId);
            exit;
        } elseif ($action === 'read_alerts') {
            $pdo->prepare('UPDATE admin_alerts SET is_read = 1 WHERE is_read = 0')->execute();
            flash('success', '所有告警已标记为已读。');
        } elseif ($action === 'dismiss_alert') {
            // 单条忽略等价于标记已读，保留记录便于日后排查
            $alertId = (int) ($_POST['alert_id'] ?? 0);
            $pdo->prepare('UPDATE admin_alerts SET is_read = 1 WHERE id = :id')->execute(['id' => $alertId]);
            flash('success', '该告警已忽略。');
        } elseif ($action === 'save_moderation') {
            ModerationService::saveSettings($_POST, (string) ($_POST['api_key'] ?? ''));
            flash('success', '内容风控设置已保存。');
        } elseif ($action === 'moderation_reject' || $action === 'moderation_resubmit' || $action === 'moderation_clear') {
            $recordId = (int) ($_POST['record_id'] ?? 0);
            if ($recordId <= 0) {
                throw new RuntimeException('审核记录不存在或已被删除。');
            }
            $moderationService = ModerationService::create();
            $adminId = (int) $currentUser['id'];
            $reviewNote = (string) ($_POST['review_note'] ?? '');
            $moderationResult = match ($action) {
                'moderation_reject'   => $moderationService->reject($recordId, $adminId, $reviewNote),
                'moderation_resubmit' => $moderationService->allowResubmit($recordId, $adminId, $reviewNote),
                default               => $moderationService->clearRecord($recordId, $adminId, $reviewNote),
            };
            Logger::info('moderation', $moderationResult['message'], [
                'record_id' => $recordId,
                'operator' => (string) $currentUser['username'],
            ]);
            flash('success', $moderationResult['message']);
        } elseif ($action === 'save_moderation_exempt') {
            // 豁免指定用户免审核上传：被豁免用户上传时跳过关键词与 AI 识别
            $exemptUserId = (int) ($_POST['exempt_user_id'] ?? 0);
            $exempt = (string) ($_POST['exempt_mode'] ?? 'on') === 'on';
            if ($exemptUserId <= 0) {
                throw new RuntimeException('请选择要设置的用户。');
            }
            ModerationService::create()->setUserExempt($exemptUserId, $exempt);
            Logger::info('moderation', $exempt ? '已豁免用户免审核上传' : '已取消用户免审核上传', [
                'target_user' => $exemptUserId,
                'operator'    => (string) $currentUser['username'],
            ]);
            flash('success', $exempt ? '已豁免该用户免审核上传。' : '已取消该用户的免审核上传。');
        } elseif ($action === 'ban_ticket_user') {
            // R2 禁止提交工单：独立于账户封禁体系，仅限制「新建工单」，不影响已有工单的查看与回复
            $targetUserId = (int) ($_POST['ticket_ban_user_id'] ?? 0);
            if ($targetUserId <= 0) {
                throw new RuntimeException('请选择要禁止提交工单的用户。');
            }
            if ($targetUserId === (int) $currentUser['id']) {
                throw new RuntimeException('不能禁止当前登录账号提交工单。');
            }
            $days = (int) ($_POST['ticket_ban_days'] ?? 0);
            $reason = trim((string) ($_POST['ticket_ban_reason'] ?? ''));
            TicketBanService::create()->ban(
                $targetUserId,
                $reason !== '' ? $reason : '管理员禁止提交工单。',
                $days,
                (int) $currentUser['id']
            );
            Logger::warning('ticket', '管理员禁止用户提交工单。', [
                'target_user' => $targetUserId,
                'days'        => $days,
                'operator'    => (string) $currentUser['username'],
            ]);
            flash('success', '已禁止该用户提交新工单，其已有工单仍可查看与回复。');
        } elseif ($action === 'unban_ticket_user') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            TicketBanService::create()->unban($targetUserId);
            Logger::info('ticket', '管理员解除用户禁止提交工单限制。', [
                'target_user' => $targetUserId,
                'operator'    => (string) $currentUser['username'],
            ]);
            flash('success', '已解除该用户禁止提交工单的限制。');
        } elseif ($action === 'save_ticket_settings') {
            // R5 工单过期档位：全局一个设置，值为有效天数（1~3650）或 0 表示不过期
            $expireDays = (int) ($_POST['ticket_expire_days'] ?? 0);
            if ($expireDays < 0 || $expireDays > 3650) {
                throw new RuntimeException('工单过期天数需为 0 ~ 3650 之间的整数（0 表示不过期）。');
            }
            Database::setConfig(TicketService::EXPIRE_CONFIG_KEY, (string) $expireDays);
            Logger::info('ticket', '更新工单过期档位。', [
                'expire_days' => $expireDays,
                'operator'    => (string) $currentUser['username'],
            ]);
            flash('success', $expireDays > 0
                ? sprintf('工单过期档位已设为 %d 天；客服回复后用户超过该天数未回应，工单会自动过期。', $expireDays)
                : '工单过期已设为「不过期」。');
        } elseif ($action === 'save_survey_url') {
            // R6 外部调研链接：工单评价只在站内使用，外部渠道的用户评价走这个链接（留空即关闭入口）
            $surveyUrl = (string) ($_POST['ticket_survey_url'] ?? '');
            TicketService::saveSurveyUrl($surveyUrl);
            Logger::info('ticket', '更新工单外部调研链接。', [
                'survey_url' => trim($surveyUrl) === '' ? '(已关闭)' : trim($surveyUrl),
                'operator'   => (string) $currentUser['username'],
            ]);
            flash('success', trim($surveyUrl) === ''
                ? '外部调研入口已关闭，工单详情页不再展示调研链接。'
                : '外部调研链接已保存，将在已结束工单的详情页展示。');
        } elseif ($action === 'save_review_enabled') {
            // R12 站内评价总开关：关闭后工单结束不再展示评价区块，调研入口也一并隐藏
            $enabled = (string) ($_POST['ticket_review_enabled'] ?? '') === '1';
            TicketService::saveReviewEnabled($enabled);
            Logger::info('ticket', '更新工单评价开关。', [
                'enabled'  => $enabled ? 'on' : 'off',
                'operator' => (string) $currentUser['username'],
            ]);
            flash('success', $enabled
                ? '工单评价已开启，已结束的工单会展示评价区块。'
                : '工单评价已关闭，工单详情页不再展示评价与外部调研入口（历史评价仍可在「评价统计」查看）。');
        } elseif ($action === 'save_preview_settings') {
            // R11 文件预览：图片 / PDF / 音视频 三个大类开关 + 大文件体积提醒阈值（MB，1~10240）
            $imageOn = (string) ($_POST['preview_image'] ?? '') === '1';
            $pdfOn   = (string) ($_POST['preview_pdf'] ?? '') === '1';
            $avOn    = (string) ($_POST['preview_av'] ?? '') === '1';
            $warnMb  = (int) ($_POST['preview_warn_mb'] ?? 20);
            if ($warnMb < 1 || $warnMb > 10240) {
                throw new RuntimeException('预览体积提醒阈值需为 1 ~ 10240 MB 之间的整数。');
            }
            Database::setConfig(\PREVIEW_IMAGE_KEY, $imageOn ? '1' : '0');
            Database::setConfig(\PREVIEW_PDF_KEY, $pdfOn ? '1' : '0');
            Database::setConfig(\PREVIEW_AV_KEY, $avOn ? '1' : '0');
            Database::setConfig(\PREVIEW_WARN_BYTES_KEY, (string) ($warnMb * 1024 * 1024));
            Logger::info('preview', '更新文件预览设置。', [
                'image'   => $imageOn ? 'on' : 'off',
                'pdf'     => $pdfOn ? 'on' : 'off',
                'av'      => $avOn ? 'on' : 'off',
                'warn_mb' => $warnMb,
                'operator' => (string) $currentUser['username'],
            ]);
            flash('success', "文件预览设置已保存（图片" . ($imageOn ? '开' : '关') . ' / PDF ' . ($pdfOn ? '开' : '关') . ' / 音视频 ' . ($avOn ? '开' : '关') . "，体积提醒阈值 {$warnMb} MB）。");
        } elseif ($action === 'save_space_patrol') {
            // R13 空间巡检开关：默认开启，关闭后不再每天懒触发（CLI 脚本仍可手动执行）
            $patrolOn = (string) ($_POST['space_patrol_enabled'] ?? '') === '1';
            SpacePatrol::savePatrolEnabled($patrolOn);
            Logger::info('patrol', '更新空间巡检开关。', [
                'enabled' => $patrolOn ? 'on' : 'off',
                'operator' => (string) $currentUser['username'],
            ]);
            flash('success', $patrolOn
                ? '空间自动巡检已开启：每天 12:00 后首次访问时自动恢复归档页面、重建丢失页面并补齐副本。'
                : '空间自动巡检已关闭（仍可用 CLI 脚本 bin/scan-space.php 手动执行）。');
        } elseif ($action === 'run_space_patrol') {
            // R13 手动立即巡检一次（真实写 Notion：恢复 / 重建 / 补副本），并刷新当日已执行标记
            $patrolStats = SpacePatrol::run();
            Logger::warning('patrol', '管理员手动执行空间巡检。', [
                'operator' => (string) $currentUser['username'],
                'stats' => $patrolStats,
            ]);
            flash('success', sprintf(
                '巡检完成：扫描空间 %d 个，恢复归档页面 %d，重建丢失页面 %d，补齐副本 %d 份，标记数据丢失 %d 个，失败 %d。',
                $patrolStats['spaces'],
                $patrolStats['restored'],
                $patrolStats['recreated'],
                $patrolStats['filled'],
                $patrolStats['lost'],
                $patrolStats['failed']
            ));
        } elseif ($action === 'restore_personalization') {
            // 还原个性化设置：只重置站点名称 / logo / favicon，不涉及用户数据与密钥
            $restore = RestoreService::create();
            $restore->assertConfirmed(
                (int) $currentUser['id'],
                (string) ($_POST['admin_password'] ?? ''),
                trim((string) ($_POST['confirm_phrase'] ?? '')),
                RestoreService::PHRASE_PERSONALIZATION
            );
            $restore->resetPersonalization();
            Logger::warning('admin', sprintf('管理员 %s 执行了「还原个性化设置」。', $currentUser['username']), [
                'operator' => (string) $currentUser['username'],
            ]);
            flash('success', '个性化设置已还原为默认（站点名称、站点 logo、favicon）。');
        } elseif ($action === 'factory_reset') {
            // 恢复出厂设置：清空整个数据库并回到未安装状态，随后跳转安装向导。不可逆，必须留在最后
            $restore = RestoreService::create();
            $restore->assertConfirmed(
                (int) $currentUser['id'],
                (string) ($_POST['admin_password'] ?? ''),
                trim((string) ($_POST['confirm_phrase'] ?? '')),
                RestoreService::PHRASE_FACTORY_RESET
            );
            Logger::warning('admin', sprintf('管理员 %s 执行了「恢复出厂设置」，全库数据将被清空。', $currentUser['username']), [
                'operator' => (string) $currentUser['username'],
            ]);
            $restore->factoryReset();
            // 锁文件已删除：必须直接跳安装向导，不能再落到后台（后台会因无锁被 bootstrap 重定向）
            header('Location: install.php');
            exit;
        } else {
            throw new RuntimeException('未知操作。');
        }
        header('Location: ' . $adminHome);
        exit;
    } catch (Throwable $exception) {
        // 只回显可安全展示的提示：数据库与 Notion 的原始报错可能包含 SQL、服务器路径或 Token 信息
        $error = match (true) {
            $exception instanceof PDOException && (int) $exception->getCode() === 23000 => '用户名已存在，请换一个名称。',
            $exception instanceof PDOException => '数据库操作失败，请稍后重试。',
            $exception instanceof NotionApiException => 'Notion 接口调用失败，请到「账户池管理」检查对应账户的 Token 与根页面后重试。',
            $exception instanceof RuntimeException => $exception->getMessage(),
            default => '服务器处理请求时发生未知错误，请稍后重试，或凭参考号在「运行日志」中查询详情。',
        };
    }
}

// 工单到期清扫：客服回复后用户长期未回应、或关闭超过重开窗口的工单自动置为「已过期」
TicketService::create()->expireDueTickets();

$users = Database::connection()->query(
    "SELECT u.id, u.username, u.group_id, u.quota_bytes, u.notion_page_id, u.email, u.email_verified_at, u.created_at, u.avatar_path,
            u.notion_token_encrypted, u.notion_parent_page_id, u.notion_account_source, u.premium_expires_at,
            g.name AS group_name, g.is_admin AS group_is_admin, g.quota_bytes AS group_quota_bytes, g.level AS group_level,
            COALESCE(f.file_count, 0) AS file_count, COALESCE(f.used_bytes, 0) AS used_bytes
     FROM users u
     LEFT JOIN `groups` g ON g.id = u.group_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) AS file_count, SUM(file_size) AS used_bytes
         FROM uploads WHERE scope = 'drive' AND status = 'active' GROUP BY user_id
     ) f ON f.user_id = u.id
     ORDER BY g.level DESC, u.id ASC"
)->fetchAll();
$groups = allGroups();
$groupMemberCounts = [];
foreach (Database::connection()->query('SELECT group_id, COUNT(*) AS total FROM users GROUP BY group_id')->fetchAll() as $row) {
    $groupMemberCounts[(int) $row['group_id']] = (int) $row['total'];
}
$officialAccounts = allOfficialAccounts();
$accountAssignmentCounts = [];
foreach (Database::connection()->query('SELECT account_id, COUNT(*) AS total FROM user_official_accounts GROUP BY account_id')->fetchAll() as $row) {
    $accountAssignmentCounts[(int) $row['account_id']] = (int) $row['total'];
}
/** 后台二级菜单链接：保留当前选中的用户，切换分区时不丢上下文。 */
$tabUrl = static fn (string $tab): string => 'admin.php?tab=' . $tab . ($selectedUserId > 0 ? '&user_id=' . $selectedUserId : '');
$selectedUser = null;
$selectedFiles = [];
$selectedAccountIds = ['primary' => 0, 'backup' => 0];
if ($selectedUserId > 0) {
    foreach ($users as $item) {
        if ((int) $item['id'] === $selectedUserId) { $selectedUser = $item; break; }
    }
    if ($selectedUser) {
        $filesStatement = Database::connection()->prepare("SELECT * FROM uploads WHERE user_id = :id AND scope = 'drive' AND status = 'active' ORDER BY id DESC");
        $filesStatement->execute(['id' => $selectedUserId]);
        $selectedFiles = $filesStatement->fetchAll();
        foreach (userOfficialAccounts($selectedUserId) as $account) {
            if ((int) $account['slot'] === 1) {
                $selectedAccountIds['primary'] = (int) $account['id'];
            } elseif ((int) $account['slot'] === 2) {
                $selectedAccountIds['backup'] = (int) $account['id'];
            }
        }
    }
}
$flash = pullFlash();
$plan = (string) Database::config('notion_plan', 'free');
$registrationEnabled = registrationEnabled();
$emailRequired = emailRequired();
// 上传分块与官方账户单文件上限：输入用 MB，实际生效值由 uploadChunkSizeBytes() 按 PHP 限制收敛
$officialLimitMb = (int) round((int) Database::config('official_single_file_limit_bytes', (string) (5 * 1024 * 1024 * 1024)) / 1024 / 1024);
$chunkSizeMb = (int) Database::config('upload_chunk_size_mb', '10');
$effectiveChunkBytes = uploadChunkSizeBytes();
// 公告管理：后台列出全部公告（含已停用），编辑态由 ?edit=<id> 预填表单
$announcements = Database::connection()
    ->query('SELECT id, title, body, is_active, created_at, updated_at FROM announcements ORDER BY updated_at DESC, id DESC')
    ->fetchAll();
$editingAnnouncement = null;
$editingAnnouncementId = (int) ($_GET['edit'] ?? 0);
if ($editingAnnouncementId > 0) {
    foreach ($announcements as $item) {
        if ((int) $item['id'] === $editingAnnouncementId) { $editingAnnouncement = $item; break; }
    }
}
$smtp = [
    'host' => (string) Database::config('smtp_host', ''),
    'port' => (string) Database::config('smtp_port', '587'),
    'encryption' => (string) Database::config('smtp_encryption', 'tls'),
    'username' => (string) Database::config('smtp_username', ''),
    'from' => (string) Database::config('smtp_from', ''),
    'from_name' => (string) Database::config('smtp_from_name', 'Notion Drive'),
    'has_password' => (string) Database::config('smtp_password', '') !== '',
    'alert_email' => (string) Database::config('alert_email', ''),
];
// 后台顶部告警条：只展示未读，读取失败不阻塞页面
try {
    $adminAlerts = unreadAdminAlerts();
} catch (Throwable $alertException) {
    $adminAlerts = [];
}
$payment = [
    'gateway' => (string) Database::config('epay_gateway', ''),
    'pid' => (string) Database::config('epay_pid', ''),
    'site_url' => (string) Database::config('site_url', ''),
    'has_key' => (string) Database::config('epay_key', '') !== '',
];
$epayConfigured = Epay::configured();
$enabledPayTypes = epayPayTypes();
$plans = premiumPlans();
// 内容风控：配置项与最近审核记录（读取失败只影响该页签，不阻塞后台）
$moderationSettings = [
    'enabled'     => (string) Database::config('moderation_enabled', '0') === '1',
    'keywords'    => (string) Database::config('moderation_keywords', ''),
    'scope'       => ['filename', 'text', 'image'],
    'endpoint'    => (string) Database::config('moderation_endpoint', ''),
    'model'       => (string) Database::config('moderation_model', 'gpt-4o-mini'),
    'api_key_set' => (string) Database::config('moderation_api_key', '') !== '',
    'timeout'     => (int) Database::config('moderation_timeout', '8'),
];
$moderationRecords = [];
$moderationUsers = [];
try {
    $moderationService = ModerationService::create();
    $moderationSettings = $moderationService->settings();
    $moderationRecords = $moderationService->recentRecords(100);
    $moderationUsers = $moderationService->moderationUsers();
} catch (Throwable $moderationException) {
    Logger::warning('moderation', '读取风控设置或记录失败', ['error' => $moderationException->getMessage()]);
}
$groupSelectOptions = function (int $selectedId) use ($groups): string {
    // 选中的组可能已被删除（例如 defaultGroupId() 回退失败返回 0），此时选中第一项，避免下拉框空白
    $matched = false;
    foreach ($groups as $group) {
        if ((int) $group['id'] === $selectedId) {
            $matched = true;
            break;
        }
    }
    if (!$matched && $groups !== []) {
        $selectedId = (int) $groups[0]['id'];
    }
    $html = '';
    foreach ($groups as $group) {
        $html .= '<option value="' . (int) $group['id'] . '"' . ((int) $group['id'] === $selectedId ? ' selected' : '') . '>'
            . e($group['name']) . ' · ' . e(quotaLabel($group['quota_bytes'] === null ? null : (int) $group['quota_bytes'])) . '</option>';
    }

    return $html;
};
$accountOptions = function (int $selectedId) use ($officialAccounts): string {
    $html = '<option value="0">未分配</option>';
    foreach ($officialAccounts as $account) {
        $html .= '<option value="' . (int) $account['id'] . '"' . ((int) $account['id'] === $selectedId ? ' selected' : '') . '>'
            . e($account['label']) . ((int) $account['is_enabled'] === 1 ? '' : '（已停用）') . '</option>';
    }

    return $html;
};
$premiumGroupId = premiumGroupId();
$premiumLabel = function (array $managedUser) use ($premiumGroupId): string {
    if (isAdmin($managedUser)) {
        return '管理员';
    }
    if ((int) $managedUser['group_id'] !== $premiumGroupId) {
        return '普通用户';
    }
    $expiresAt = premiumExpiresAt($managedUser);
    if ($expiresAt === null) {
        return '高级用户 · 永久';
    }
    $date = date('Y-m-d', (int) strtotime($expiresAt));

    return strtotime($expiresAt) > time() ? '高级用户 · 至 ' . $date : '高级已到期 · ' . $date;
};
$premiumDuration = function (array $managedUser) use ($premiumGroupId): string {
    if (isAdmin($managedUser)) {
        return '永久';
    }
    if ((int) $managedUser['group_id'] !== $premiumGroupId) {
        return '未开通';
    }
    $expiresAt = premiumExpiresAt($managedUser);
    if ($expiresAt === null) {
        return '永久';
    }
    $daysLeft = premiumDaysLeft($managedUser);

    return $daysLeft > 0 ? '剩余 ' . $daysLeft . ' 天' : '已到期';
};
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?= siteTitleTag('管理后台') ?><?= siteFaviconTag() ?><link rel="stylesheet" href="assets/app.css?v=60"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <?= brandHtml('dashboard.php') ?>
        <nav class="drive-nav"><a href="dashboard.php"><?= navIcon('files') ?>我的文件</a><a href="tickets.php"><?= navIcon('tickets') ?>工单管理</a><a class="active nav-admin-link" href="admin.php"><?= navIcon('admin') ?>管理后台</a><details class="nav-group" open><summary><?= navIcon('admin') ?>管理后台</summary><div class="nav-sub"><?php foreach ($adminTabs as $tabKey => $tabMeta): ?><a class="<?= $tabKey === $activeTab ? 'active' : '' ?>" href="<?= e($tabUrl((string) $tabKey)) ?>"><?= e($tabMeta['label']) ?></a><?php endforeach; ?></div></details><a href="admin_orders.php"><?= navIcon('orders') ?>订单管理</a><a href="bans.php"><?= navIcon('bans') ?>封禁管理</a><a href="logs.php"><?= navIcon('logs') ?>运行日志</a><a href="account.php"><?= navIcon('account') ?>账户管理</a></nav>
        <?= sidebarStorageHtml($currentUser) ?>
        <?= sidebarIcpHtml() ?>
        <?= sidebarPoliceHtml() ?>
        <div class="account-menu"><?= avatarHtml($currentUser) ?><div><strong><?= e($currentUser['username']) ?></strong><small>管理员</small></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button>退出</button></form></div>
    </aside>
    <main class="drive-main admin-main">
        <?= banNoticeHtml($currentUser) ?>
        <header class="drive-header"><div><p><?= e($adminTabs[$activeTab]['desc']) ?></p><h1>管理后台 · <?= e($adminTabs[$activeTab]['label']) ?></h1></div><a class="secondary-button" href="dashboard.php">返回我的文件</a></header>
        <nav class="admin-tabs" aria-label="管理后台分区"><?php foreach ($adminTabs as $tabKey => $tabMeta): ?><a class="<?= $tabKey === $activeTab ? 'active' : '' ?>" href="<?= e($tabUrl((string) $tabKey)) ?>"><?= e($tabMeta['label']) ?></a><?php endforeach; ?></nav>
        <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($adminAlerts): ?>
        <section class="alert warning">
            <div class="alert-head"><strong><?= count($adminAlerts) ?> 条未读告警</strong><form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="read_alerts"><button class="text-action">全部标记已读</button></form></div>
            <ul class="alert-list">
                <?php foreach ($adminAlerts as $alert): ?>
                <li>
                    <div><span class="alert-level <?= $alert['level'] === 'error' ? 'error' : '' ?>"><?= $alert['level'] === 'error' ? '严重' : '提醒' ?></span> <?= e((string) $alert['message']) ?><small><?= e((string) $alert['created_at']) ?></small></div>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="dismiss_alert"><input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>"><button class="text-action">忽略</button></form>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'users'): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>用户与容量</h2><p>容量由权限组决定；单独填写容量会覆盖组容量，留空或 -1 表示继承组容量。每位用户默认只显示概览，展开「编辑」后才能修改密码、权限组与容量。</p></div><span><?= count($users) ?> 位用户</span></div>
            <form method="post" class="create-user-row">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="create_user">
                <label><span>用户名</span><input name="username" minlength="3" required></label><label><span>初始密码</span><input type="password" name="password" minlength="10" required></label><label><span>权限组</span><select name="group_id"><?= $groupSelectOptions(defaultGroupId()) ?></select></label><label><span>单独容量（GB）</span><input name="quota_gb" inputmode="decimal" placeholder="-1"></label><label><span>Notion 来源</span><select name="notion_source"><option value="own">自有账户</option><option value="official">官方账户池</option></select></label><button class="primary-button compact">添加用户</button>
            </form>
            <div class="log-filters user-search"><label><span>搜索用户</span><input id="user-search" type="search" placeholder="输入用户名或邮箱即时筛选"></label></div>
            <div class="admin-user-list">
                <?php foreach ($users as $managedUser): $quotaGb = bytesToInputValue($managedUser['quota_bytes'] === null ? null : (int) $managedUser['quota_bytes'], 1073741824); ?>
                <form method="post" class="admin-user-row user-row-fold" data-user-search="<?= e(mb_strtolower((string) $managedUser['username'] . ' ' . (string) ($managedUser['email'] ?? ''))) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="user_id" value="<?= (int) $managedUser['id'] ?>">
                    <details>
                        <summary>
                            <span class="user-row-summary">
                                <span class="user-identity"><?= avatarHtml($managedUser) ?><strong><?= e((string) $managedUser['username']) ?></strong></span>
                                <small class="user-meta"><?= (int) $managedUser['file_count'] ?> 个文件 · <?= e(formatBytes((int) $managedUser['used_bytes'])) ?><?= emailBound($managedUser) ? ' · ' . e((string) $managedUser['email']) : ' · 未绑定邮箱' ?> · <?= e($premiumLabel($managedUser)) ?> · Notion <?= userNotionSource($managedUser) === 'own' ? (hasOwnNotionAccount($managedUser) ? '自有账户' : '自有账户（未绑定）') : '官方账户' ?> · 到期时长 <?= e($premiumDuration($managedUser)) ?></small>
                            </span>
                            <span class="user-row-actions">
                                <?php if (!isAdmin($managedUser)): ?><button type="button" class="text-action" data-grant-toggle><?= isPremiumUser($managedUser) ? '续费订阅' : '开通订阅' ?></button><?php endif; ?>
                                <span class="text-action user-row-toggle"><span class="fold-open">编辑</span><span class="fold-close">收起</span></span>
                            </span>
                        </summary>
                        <div class="user-row-editor-body">
                            <label><span>用户名</span><input name="username" value="<?= e($managedUser['username']) ?>" required></label>
                            <label><span>新密码</span><input type="password" name="password" placeholder="留空不修改"></label>
                            <label><span>权限组</span><select name="group_id"><?= $groupSelectOptions((int) $managedUser['group_id']) ?></select></label>
                            <label><span>单独容量 GB</span><input name="quota_gb" value="<?= e($quotaGb) ?>"></label>
                            <div class="row-actions">
                                <button class="save-action" name="action" value="update_user">保存</button>
                                <?php if ((int) $managedUser['id'] !== (int) $currentUser['id']): ?><button class="delete-action" name="action" value="delete_user" data-confirm="确认删除用户及其全部文件？">删除</button><?php endif; ?>
                            </div>
                        </div>
                    </details>
                    <?php if (!isAdmin($managedUser)): ?>
                    <div class="user-row-grant" hidden>
                        <label><span>开通时长</span><select name="grant_months"><?php foreach ($plans as $months => $price): ?><option value="<?= (int) $months ?>"><?= (int) $months ?> 个月 · ¥<?= e(number_format((float) $price, 2)) ?></option><?php endforeach; ?></select></label>
                        <button class="primary-button compact" name="action" value="grant_premium" data-confirm="确认为该用户开通高级套餐？将生成一条「管理员授权」订单。">确认开通</button>
                        <button type="button" class="text-action" data-grant-cancel>取消</button>
                    </div>
                    <?php endif; ?>
                </form>
                <?php endforeach; ?>
                <p class="compact-empty" id="user-search-empty" hidden>没有匹配的用户。</p>
            </div>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>权限组</h2><p>组容量对组内用户生效；单文件上限与 Notion 套餐上限取较小值。等级用于排序与后续高级账号扩展。</p></div><span><?= count($groups) ?> 个权限组</span></div>
            <form method="post" class="create-user-row create-group-row">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="group_id" value="0">
                <label><span>组名称</span><input name="group_name" maxlength="64" required></label><label><span>组容量（GB）</span><input name="group_quota_gb" inputmode="decimal" placeholder="-1"></label><label><span>单文件上限（MB）</span><input name="group_single_mb" inputmode="decimal" placeholder="-1"></label><label><span>等级</span><input name="group_level" inputmode="numeric" value="0"></label><label class="checkbox"><input type="checkbox" name="group_is_admin">管理员组</label><button class="primary-button compact">新建权限组</button>
            </form>
            <div class="admin-user-list">
                <?php foreach ($groups as $group): $groupQuotaGb = bytesToInputValue($group['quota_bytes'] === null ? null : (int) $group['quota_bytes'], 1073741824); $groupSingleMb = bytesToInputValue($group['single_file_limit_bytes'] === null ? null : (int) $group['single_file_limit_bytes'], 1048576); ?>
                <form method="post" class="admin-user-row group-row">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                    <div class="user-identity"><span class="avatar"><?= e(mb_strtoupper(mb_substr((string) $group['name'], 0, 1))) ?></span><div><strong><?= e($group['name']) ?><?= (int) $group['is_builtin'] === 1 ? ' · 内置' : '' ?></strong><small><?= (int) ($groupMemberCounts[(int) $group['id']] ?? 0) ?> 位用户 · 容量 <?= e(quotaLabel($group['quota_bytes'] === null ? null : (int) $group['quota_bytes'])) ?></small></div></div>
                    <label><span>组名称</span><input name="group_name" value="<?= e($group['name']) ?>" maxlength="64" required></label><label><span>组容量 GB</span><input name="group_quota_gb" value="<?= e($groupQuotaGb) ?>"></label><label><span>单文件 MB</span><input name="group_single_mb" value="<?= e($groupSingleMb) ?>"></label><label><span>等级</span><input name="group_level" value="<?= (int) $group['level'] ?>"></label><label class="checkbox"><input type="checkbox" name="group_is_admin" <?= (int) $group['is_admin'] === 1 ? 'checked' : '' ?>>管理员组</label>
                    <button class="save-action" name="action" value="save_group">保存</button><?php if ((int) $group['is_builtin'] !== 1): ?><button class="delete-action" name="action" value="delete_group" data-confirm="确认删除该权限组？">删除</button><?php endif; ?>
                </form>
                <?php $groupMembers = array_values(array_filter($users, static fn (array $member): bool => (int) $member['group_id'] === (int) $group['id'])); ?>
                <details class="group-members">
                    <summary><span class="text-action">查看该组用户（<?= count($groupMembers) ?>）</span></summary>
                    <?php if (!$groupMembers): ?>
                    <p class="log-meta">该权限组下暂无用户。</p>
                    <?php else: ?>
                    <ul class="group-member-list">
                        <?php foreach ($groupMembers as $member): ?>
                        <li><a href="admin.php?tab=files&user_id=<?= (int) $member['id'] ?>#user-files"><?= avatarHtml($member) ?><strong><?= e((string) $member['username']) ?></strong></a><small><?= (int) $member['file_count'] ?> 个文件 · <?= e($premiumLabel($member)) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'content'): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>官方账户池</h2><p>全体付费用户共享这里的账户；优先级数值越大越靠前，用户按「已分配的主备账户 → 池内剩余账户」的顺序取用，因此未分配的用户也能直接上传。Token 加密保存，不会回显。套餐决定官方账户的单文件上限：免费版固定 5 MB，付费版按「系统设置 · 上传与容量」里配置的上限生效。</p></div><span><?= count($officialAccounts) ?> 个账户</span></div>
            <form method="post" class="create-user-row slim">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_plan">
                <label><span>官方账户套餐</span><select name="plan"><option value="free" <?= $plan === 'free' ? 'selected' : '' ?>>免费版 · 5 MB</option><option value="paid" <?= $plan === 'paid' ? 'selected' : '' ?>>付费版 · 5 GB</option></select></label>
                <button class="primary-button compact">保存套餐</button>
            </form>
            <form method="post" class="create-user-row create-group-row">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_official_account"><input type="hidden" name="account_id" value="0">
                <label><span>账户名称</span><input name="label" maxlength="64" required placeholder="例如：官方账户 A"></label><label><span>集成 Token</span><input type="password" name="token" autocomplete="off" maxlength="200" required placeholder="ntn_••••••••"></label><label><span>根页面</span><input name="root_page" maxlength="500" required placeholder="https://www.notion.so/..."></label><label><span>优先级</span><input name="priority" inputmode="numeric" value="0"></label><label class="checkbox"><input type="checkbox" name="is_enabled" checked>启用</label><button class="primary-button compact">添加账户</button>
            </form>
            <?php if (!$officialAccounts): ?>
            <div class="empty-state compact-empty">还没有官方账户；添加后购买高级套餐的用户才能使用官方空间。</div>
            <?php else: ?>
            <div class="admin-user-list">
                <?php foreach ($officialAccounts as $account): ?>
                <form method="post" class="admin-user-row group-row">
                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                    <div class="user-identity"><span class="avatar"><?= e(mb_strtoupper(mb_substr((string) $account['label'], 0, 1))) ?></span><div><strong><?= e($account['label']) ?><?= (int) $account['is_enabled'] === 1 ? '' : ' · 已停用' ?></strong><small><?= (int) ($accountAssignmentCounts[(int) $account['id']] ?? 0) ?> 位用户优先使用 · <?= $account['last_check_at'] === null ? '未检测' : '检测于 ' . e(date('Y-m-d H:i', strtotime((string) $account['last_check_at']))) ?><?= $account['last_error'] === null ? '' : ' · ' . e(mb_substr((string) $account['last_error'], 0, 80)) ?></small></div></div>
                    <label><span>账户名称</span><input name="label" value="<?= e($account['label']) ?>" maxlength="64" required></label><label><span>集成 Token</span><input type="password" name="token" autocomplete="off" maxlength="200" placeholder="留空保持不变"></label><label><span>根页面</span><input name="root_page" value="<?= e((string) $account['root_page_id']) ?>" maxlength="500"></label><label><span>优先级</span><input name="priority" value="<?= (int) $account['priority'] ?>"></label><label class="checkbox"><input type="checkbox" name="is_enabled" <?= (int) $account['is_enabled'] === 1 ? 'checked' : '' ?>>启用</label>
                    <div class="row-actions"><button class="save-action" name="action" value="save_official_account">保存</button><button class="text-action" name="action" value="test_official_account">测试连接</button></div><button class="delete-action" name="action" value="delete_official_account" data-confirm="确认删除该官方账户？">删除</button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($selectedUser): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2><?= e($selectedUser['username']) ?> 的官方账户</h2><p>官方账户池为全体付费用户共享，这里的分配只决定优先顺序：先主账户、再备用账户，之后按池内优先级顺延；未分配的用户也能直接使用池内账户。自有账户由用户注册时绑定，Token 加密保存、不在此显示。</p></div><span class="status-badge"><?= userNotionSource($selectedUser) === 'official' ? '当前来源：官方账户' : '当前来源：自有账户' ?></span></div>
            <form method="post" class="admin-user-row assign-row">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                <label><span>主账户</span><select name="primary_account_id"><?= $accountOptions((int) $selectedAccountIds['primary']) ?></select></label>
                <label><span>备用账户</span><select name="backup_account_id"><?= $accountOptions((int) $selectedAccountIds['backup']) ?></select></label>
                <button class="save-action" name="action" value="assign_accounts">保存分配</button>
                <button class="text-action" name="action" value="auto_assign_accounts">自动分配</button>
            </form>
            <?php if (!$officialAccounts): ?><div class="empty-state compact-empty">官方账户池为空，请先在上方添加账户。</div><?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($activeTab === 'files'): ?>
        <?php if ($selectedUser): ?>
        <section class="admin-panel" id="user-files">
            <div class="panel-title"><div><h2><?= e($selectedUser['username']) ?> 的文件</h2><p>管理员可以下载、重命名或删除该用户的文件。</p></div><a href="admin.php?tab=files">关闭</a></div>
            <?php if (!$selectedFiles): ?><div class="empty-state compact-empty">该用户暂无文件</div><?php else: ?><div class="file-table-wrap"><table class="file-table"><thead><tr><th>名称</th><th>大小</th><th>上传时间</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($selectedFiles as $file): $name = (string) ($file['display_name'] ?: $file['filename']); ?><tr data-file-id="<?= (int) $file['id'] ?>"><td><div class="file-name"><span class="file-kind"><?= e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) ?></span><strong class="display-name"><?= e($name) ?></strong></div></td><td data-label="大小"><?= e(formatBytes((int) $file['file_size'])) ?></td><td data-label="上传时间"><?= e(date('Y-m-d H:i', strtotime((string) $file['created_at']))) ?></td><td><div class="file-actions"><a href="api/file.php?id=<?= (int) $file['id'] ?>">下载</a><button data-action="rename">重命名</button><button class="danger" data-action="delete">删除</button></div></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <?php else: ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>用户文件</h2><p>选择一位用户，查看并管理其文件（下载 / 重命名 / 删除）。</p></div><span><?= count($users) ?> 位用户</span></div>
            <div class="log-filters user-search"><label><span>搜索用户</span><input id="user-search" type="search" placeholder="输入用户名或邮箱即时筛选"></label></div>
            <ul class="group-member-list file-user-list">
                <?php foreach ($users as $managedUser): ?>
                <li data-user-search="<?= e(mb_strtolower((string) $managedUser['username'] . ' ' . (string) ($managedUser['email'] ?? ''))) ?>"><a href="admin.php?tab=files&user_id=<?= (int) $managedUser['id'] ?>#user-files"><?= avatarHtml($managedUser) ?><strong><?= e((string) $managedUser['username']) ?></strong></a><small><?= (int) $managedUser['file_count'] ?> 个文件 · <?= e(formatBytes((int) $managedUser['used_bytes'])) ?></small></li>
                <?php endforeach; ?>
            </ul>
            <p class="compact-empty" id="user-search-empty" hidden>没有匹配的用户。</p>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($activeTab === 'risk'): ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>内容风控</h2><p>关键词在上传开始前直接拦截；AI 识别在上传完成后执行，判定违规即隔离文件并生成申诉号。未配置接口地址或 API Key 时只跑关键词。</p></div><span class="status-badge <?= $moderationSettings['enabled'] ? 'granted' : 'closed' ?>"><?= $moderationSettings['enabled'] ? '已启用' : '未启用' ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_moderation">
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="enabled" value="1" <?= $moderationSettings['enabled'] ? 'checked' : '' ?>>启用内容风控</label></div>
                <label><span>违禁关键词</span><textarea name="keywords" rows="4" placeholder="每行一个，也可用逗号分隔"><?= e($moderationSettings['keywords']) ?></textarea><small>命中关键词的文件会在上传前被拒绝，并分配唯一申诉号。</small></label>
                <div class="checkbox-row"><span>AI 识别范围</span><label class="checkbox"><input type="checkbox" name="scope[]" value="filename" <?= in_array('filename', $moderationSettings['scope'], true) ? 'checked' : '' ?>>文件名</label><label class="checkbox"><input type="checkbox" name="scope[]" value="text" <?= in_array('text', $moderationSettings['scope'], true) ? 'checked' : '' ?>>文本内容</label><label class="checkbox"><input type="checkbox" name="scope[]" value="image" <?= in_array('image', $moderationSettings['scope'], true) ? 'checked' : '' ?>>图片内容</label></div>
                <label><span>AI 接口地址</span><input name="endpoint" value="<?= e($moderationSettings['endpoint']) ?>" placeholder="https://api.openai.com/v1"><small>OpenAI 兼容的 /chat/completions 接口；必须为 https，且不能指向内网或本机。</small></label>
                <label><span>模型名称</span><input name="model" value="<?= e($moderationSettings['model']) ?>" maxlength="128" placeholder="gpt-4o-mini"></label>
                <label><span>API Key</span><input type="password" name="api_key" autocomplete="off" placeholder="<?= $moderationSettings['api_key_set'] ? '留空保持不变' : '未设置' ?>"><small>加密保存，不会回显。</small></label>
                <label><span>审核超时（秒）</span><input name="timeout" inputmode="numeric" value="<?= (int) $moderationSettings['timeout'] ?>" placeholder="8"><small>2 - 60 秒。接口超时或异常时文件照常放行并转人工复核，不会阻断上传。</small></label>
                <div class="form-actions"><button class="primary-button compact">保存设置</button></div>
            </form>
        </section>

        <?php $exemptUsers = array_values(array_filter($moderationUsers, static fn (array $u): bool => (int) $u['moderation_exempt'] === 1)); ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>免审核上传</h2><p>被豁免的用户上传时跳过关键词与 AI 识别，直接放行；已豁免用户可在此取消。</p></div><span class="status-badge <?= $exemptUsers ? 'granted' : 'closed' ?>"><?= count($exemptUsers) ?> 位已豁免</span></div>
            <form method="post" class="connection-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_moderation_exempt">
                <label><span>选择用户</span>
                    <select name="exempt_user_id" required>
                        <option value="">请选择用户</option>
                        <?php foreach ($moderationUsers as $moderationUser): ?>
                        <option value="<?= (int) $moderationUser['id'] ?>"><?= e((string) $moderationUser['username']) ?><?= (string) ($moderationUser['email'] ?? '') === '' ? '' : ' · ' . e((string) $moderationUser['email']) ?><?= (int) $moderationUser['moderation_exempt'] === 1 ? '（已豁免）' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions"><button class="primary-button compact" name="exempt_mode" value="on">豁免该用户</button><button class="secondary-button" name="exempt_mode" value="off">取消豁免</button></div>
            </form>
            <?php if ($exemptUsers): ?>
            <ul class="group-member-list">
                <?php foreach ($exemptUsers as $exemptUser): ?>
                <li>
                    <span><?= avatarHtml($exemptUser) ?><strong><?= e((string) $exemptUser['username']) ?></strong></span>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_moderation_exempt"><input type="hidden" name="exempt_user_id" value="<?= (int) $exemptUser['id'] ?>"><input type="hidden" name="exempt_mode" value="off"><button class="text-action" type="submit">取消豁免</button></form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>审核记录</h2><p>关键词拦截与 AI 识别的全部结论。AI 降级（待人工复核）的记录文件仍可正常使用，人工判定违规才隔离；不可用则维持隔离状态，重新上传仅放开同名文件的上传前拦截。</p></div><span><?= count($moderationRecords) ?> 条</span></div>
            <?php if (!$moderationRecords): ?>
            <div class="empty-state compact-empty">还没有审核记录。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>用户 · 文件</th><th>申诉号</th><th>判定</th><th>状态</th><th>时间</th><th>复核</th></tr></thead>
                    <tbody>
                    <?php foreach ($moderationRecords as $record): ?>
                        <?php
                        $recordStatus = (string) $record['status'];
                        $recordDecision = (string) $record['decision'];
                        $providerLabel = match ((string) $record['provider']) {
                            'keyword' => '关键词',
                            'ai' => 'AI',
                            default => (string) $record['provider'] !== '' ? (string) $record['provider'] : '—',
                        };
                        $statusInfo = match ($recordStatus) {
                            ModerationService::STATUS_EXEMPTED => ['label' => '已豁免', 'class' => 'granted'],
                            ModerationService::STATUS_REJECTED => ['label' => '已打回', 'class' => 'void'],
                            ModerationService::STATUS_RESUBMIT => ['label' => '已放行重传', 'class' => 'closed'],
                            ModerationService::STATUS_CLEARED => ['label' => '已清除违规', 'class' => 'closed'],
                            default => ['label' => $recordDecision === 'pending' ? '待人工复核' : '待复核', 'class' => 'pending'],
                        };
                        $username = (string) ($record['username'] ?? '');
                        ?>
                        <tr>
                            <td><div class="file-name"><span class="file-kind"><?= e(strtoupper(pathinfo((string) $record['filename'], PATHINFO_EXTENSION) ?: 'FILE')) ?></span><div><strong><?= e($record['filename']) ?></strong><small><?= $username !== '' ? e($username) : '已注销用户' ?> · <?= $record['stage'] === ModerationService::STAGE_START ? '上传前拦截' : '上传后识别' ?></small></div></div></td>
                            <td data-label="申诉号"><strong><?= e($record['appeal_no']) ?></strong></td>
                            <td data-label="判定"><div><strong><?= e($providerLabel) ?></strong><small><?= e((string) $record['reason']) ?><?= (string) $record['matched'] === '' ? '' : ' · ' . e((string) $record['matched']) ?><?= (string) $record['model'] === '' ? '' : ' · ' . e((string) $record['model']) ?></small></div></td>
                            <td data-label="状态"><span class="status-badge <?= $statusInfo['class'] ?>"><?= e($statusInfo['label']) ?></span></td>
                            <td data-label="时间"><?= e(date('Y-m-d H:i', strtotime((string) $record['created_at']))) ?></td>
                            <td>
                                <?php if ($recordStatus === ModerationService::STATUS_OPEN): ?>
                                <?php $reviewPending = $recordDecision === 'pending'; ?>
                                <form method="post" class="moderation-review">
                                    <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <input name="review_note" maxlength="200" placeholder="复核备注（可选）">
                                    <?php if ($reviewPending): ?>
                                    <div class="row-actions"><button class="delete-action" name="action" value="moderation_reject" data-confirm="确认判定违规？文件将被隔离。">不可用</button></div>
                                    <?php else: ?>
                                    <div class="row-actions"><button class="text-action" name="action" value="moderation_resubmit">重新上传</button><button class="delete-action" name="action" value="moderation_reject" data-confirm="确认打回该申诉？文件将保持不可用。">不可用</button></div>
                                    <?php endif; ?>
                                </form>
                                <?php else: ?>
                                <div class="log-meta"><?= $record['reviewed_at'] === null ? '—' : e(date('Y-m-d H:i', strtotime((string) $record['reviewed_at']))) ?><?= (string) ($record['review_note'] ?? '') === '' ? '' : ' · ' . e((string) $record['review_note']) ?></div>
                                    <?php if ($recordStatus === ModerationService::STATUS_REJECTED): ?>
                                    <form method="post" class="moderation-review">
                                        <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                        <input name="review_note" maxlength="200" placeholder="清除备注（可选）">
                                        <div class="row-actions"><button class="text-action" name="action" value="moderation_clear" data-confirm="确认清除该违规记录？清除后不再计入该用户的违规次数（文件仍保持隔离）。">清除记录</button></div>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'system'): ?>
        <?php $patrolLastDate = SpacePatrol::lastRunDate(); ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>Notion 空间自动巡检与修复</h2><p>开启后，系统每天 <b>12:00（东八区）之后的首次页面访问</b>自动巡检一次（当天只跑一次）：把被移入回收站的空间页<b>恢复</b>、把被彻底删除（404）的空间页按原父页<b>重建</b>；文件只剩一份副本时自动用幸存副本<b>补齐为两份</b>。若文件所有副本都已失效且补不回，会标记为「数据已丢失」（该文件此后只能删除，下载 / 预览 / 重命名都会提示重新上传），并通过管理员告警通知。也可用计划任务执行 <code>php bin/scan-space.php</code> 准点巡检。</p></div><span class="status-badge <?= SpacePatrol::patrolEnabled() ? '' : 'closed' ?>"><?= SpacePatrol::patrolEnabled() ? '已开启' : '已关闭' ?></span></div>
            <form method="post" class="connection-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_space_patrol">
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="space_patrol_enabled" value="1" <?= SpacePatrol::patrolEnabled() ? 'checked' : '' ?>>开启空间自动巡检与修复（默认开启）</label></div>
                <div class="form-actions">
                    <button class="primary-button compact" data-confirm="确认保存空间巡检开关？">保存设置</button>
                </div>
            </form>
            <form method="post" class="connection-form connection-form-next">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="run_space_patrol">
                <div class="checkbox-row"><span>最近一次巡检日期：<?= $patrolLastDate !== '' ? e($patrolLastDate) : '尚未执行' ?></span></div>
                <div class="form-actions">
                    <button class="secondary-button compact" data-confirm="立即巡检会真实访问并写入 Notion（恢复/重建/补副本，可能消耗接口配额）。确认现在执行？">立即巡检一次</button>
                </div>
            </form>
        </section>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>注册与邮箱</h2><p>未配置 SMTP 时无法开启强制绑定邮箱；验证码 6 位数字，10 分钟内有效。</p></div><span class="status-badge"><?= smtpConfigured() ? 'SMTP 已配置' : 'SMTP 未配置' ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_access">
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="registration_enabled" <?= $registrationEnabled ? 'checked' : '' ?>>开放新账号注册</label>
                <label class="checkbox"><input type="checkbox" name="email_required" <?= $emailRequired ? 'checked' : '' ?>>强制所有用户绑定邮箱</label></div>
                <label><span>SMTP 服务器</span><input name="smtp_host" value="<?= e($smtp['host']) ?>" placeholder="smtp.example.com"></label>
                <label><span>端口</span><input name="smtp_port" inputmode="numeric" value="<?= e($smtp['port']) ?>"></label>
                <label><span>加密方式</span><select name="smtp_encryption"><option value="tls" <?= $smtp['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS · 587</option><option value="ssl" <?= $smtp['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL · 465</option><option value="none" <?= $smtp['encryption'] === 'none' ? 'selected' : '' ?>>不加密 · 25</option></select></label>
                <label><span>SMTP 账号</span><input name="smtp_username" value="<?= e($smtp['username']) ?>" autocomplete="off"></label>
                <label><span>SMTP 密码</span><input type="password" name="smtp_password" placeholder="<?= $smtp['has_password'] ? '留空保持不变' : '未设置' ?>" autocomplete="new-password"></label>
                <label><span>发件人邮箱</span><input name="smtp_from" value="<?= e($smtp['from']) ?>" placeholder="noreply@example.com"></label>
                <label><span>发件人名称</span><input name="smtp_from_name" value="<?= e($smtp['from_name']) ?>" maxlength="64"></label>
                <label><span>告警收件邮箱</span><input name="alert_email" value="<?= e($smtp['alert_email']) ?>" placeholder="留空则发给管理员组内已验证邮箱"><small>多个邮箱用逗号分隔；系统产生的严重错误与告警会同时发到这里。</small></label>
                <label><span>测试收件人</span><input name="smtp_test_to" placeholder="you@example.com"></label>
                <div class="form-actions"><button class="primary-button compact" name="action" value="save_access">保存设置</button><button class="secondary-button" name="action" value="test_mail">发送测试邮件</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>上传与容量</h2><p>分块大小决定浏览器每次提交的数据量，实际生效值受服务器 PHP 上传限制约束：upload_max_filesize = <?= e((string) ini_get('upload_max_filesize')) ?>，post_max_size = <?= e((string) ini_get('post_max_size')) ?>。官方账户单文件上限仅在「套餐」为付费版时生效，免费版仍为 5 MB。</p></div><span class="status-badge">实际单块 <?= e(formatBytes($effectiveChunkBytes)) ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_limits">
                <label><span>官方账户单文件上限（MB）</span><input name="official_single_file_limit_mb" inputmode="numeric" value="<?= $officialLimitMb ?>" required></label>
                <label><span>上传分块大小（MB）</span><input name="upload_chunk_size_mb" inputmode="numeric" value="<?= $chunkSizeMb ?>" required></label>
                <label><span>实际生效单块</span><input value="<?= e(formatBytes($effectiveChunkBytes)) ?>" readonly></label>
                <div class="form-actions"><button class="primary-button compact">保存设置</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>支付设置</h2><p>对接易支付（页面跳转收款）。商户密钥加密保存、不会回显；接口地址留空即暂停在线支付。套餐价格修改后立即对新订单生效，不影响已支付的订单。</p></div><span class="status-badge"><?= $epayConfigured ? '已配置' : '未配置' ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_payment">
                <label><span>易支付接口地址</span><input name="epay_gateway" value="<?= e($payment['gateway']) ?>" maxlength="255" placeholder="https://spay.example.com/plugin/EpayApi/GatewayV1/"></label>
                <label><span>商户 ID</span><input name="epay_pid" value="<?= e($payment['pid']) ?>" maxlength="64" autocomplete="off"></label>
                <label><span>商户密钥</span><input type="password" name="epay_key" autocomplete="new-password" placeholder="<?= $payment['has_key'] ? '留空保持不变' : '未设置' ?>"></label>
                <label><span>站点地址</span><input name="site_url" value="<?= e($payment['site_url']) ?>" maxlength="255" placeholder="留空自动识别"></label>
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="epay_pay_types[]" value="alipay" <?= isset($enabledPayTypes['alipay']) ? 'checked' : '' ?>>支付宝</label>
                <label class="checkbox"><input type="checkbox" name="epay_pay_types[]" value="wxpay" <?= isset($enabledPayTypes['wxpay']) ? 'checked' : '' ?>>微信支付</label></div>
                <?php foreach ($plans as $months => $price): ?>
                <label><span><?= (int) $months ?> 个月价格（元）</span><input name="premium_price_<?= (int) $months ?>" inputmode="decimal" value="<?= e(number_format((float) $price, 2, '.', '')) ?>" required></label>
                <?php endforeach; ?>
                <label><span>异步通知地址（回调）</span><input value="<?= e(epayNotifyUrl()) ?>" readonly></label>
                <label><span>同步回跳地址</span><input value="<?= e(epayReturnUrl()) ?>" readonly></label>
                <div class="form-actions"><button class="primary-button compact">保存设置</button></div>
            </form>
            <p class="log-meta">请把上面的两个地址填写到易支付商户后台；支付渠道需与易支付账号实际开通的方式一致，未开通的渠道用户点击后会提示下单失败。历史订单可在「订单管理」中查看与人工处理。</p>
            <?php if ($payment['site_url'] === ''): ?>
            <p class="log-meta">建议把「站点地址」填写为对外可访问的固定地址（如 https://drive.example.com）：留空时系统按本次访问的域名自动识别，域名变化或经反向代理时回调地址可能不准。</p>
            <?php endif; ?>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>站点信息</h2><p>站点名称同时用作浏览器标签标题；favicon 仅支持 .ico（≤1 MB），站点 logo 支持 JPG/PNG/GIF/WebP（≤2 MB），留空则回落「Notion Drive」与内置标识。备案号将显示在登录页与各页面侧边栏（用量信息下侧），留空则不显示。技术支持署名由 SZY创新工作室 固定提供，不可修改。</p></div></div>
            <form method="post" class="connection-form stack-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_site">
                <label><span>站点名称</span><input name="site_name" value="<?= e((string) Database::config('site_name', '')) ?>" maxlength="64" placeholder="留空则显示 Notion Drive"></label>
                <label><span>ICP 备案号</span><input name="icp_license" value="<?= e(icpLicense()) ?>" maxlength="64" placeholder="如 京ICP备2024000000号-1，留空则不显示"></label>
                <label><span>公网安备号</span><input name="police_license" value="<?= e(policeLicense()) ?>" maxlength="64" placeholder="如 京公网安备 11010502030123号，留空则不显示"></label>
                <label><span>favicon（.ico）</span><input type="file" name="site_favicon" accept=".ico,image/x-icon"><small>当前：<?= siteFaviconPath() === '' ? '内置 SVG' : e(siteFaviconPath()) ?></small></label>
                <?php if (siteFaviconPath() !== ''): ?><div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="remove_favicon">删除当前 favicon，回落内置 SVG</label></div><?php endif; ?>
                <label><span>站点 logo</span><input type="file" name="site_logo" accept="image/jpeg,image/png,image/gif,image/webp"><small>当前：<?= siteLogoPath() === '' ? '内置字母标识' : e(siteLogoPath()) ?></small></label>
                <?php if (siteLogoPath() !== ''): ?><div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="remove_logo">删除当前 logo，回落内置字母标识</label></div><?php endif; ?>
                <div class="form-actions"><button class="primary-button compact">保存设置</button></div>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>站点公告</h2><p>支持多条公告，正文可用 Markdown（标题、列表、粗体、斜体、行内代码、链接）并可插入图片。启用后将在用户工作台以弹窗提醒；修改或重新启用后，已读用户会再次收到提醒。</p></div><span class="status-badge">共 <?= count($announcements) ?> 条</span></div>
            <?php if ($announcements): ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>标题</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($announcements as $announcement): ?>
                        <?php $announcementActive = (int) $announcement['is_active'] === 1; ?>
                        <tr>
                            <td data-label="标题"><strong><?= e((string) $announcement['title']) ?></strong></td>
                            <td data-label="状态"><span class="status-badge <?= $announcementActive ? '' : 'closed' ?>"><?= $announcementActive ? '已启用' : '已停用' ?></span></td>
                            <td data-label="更新时间"><?= e(date('Y-m-d H:i', strtotime((string) $announcement['updated_at']))) ?></td>
                            <td data-label="操作">
                                <div class="row-actions">
                                    <a class="text-action" href="<?= e('admin.php?tab=system&edit=' . (int) $announcement['id']) ?>">编辑</a>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>"><button class="text-action" name="action" value="toggle_announcement"><?= $announcementActive ? '停用' : '启用' ?></button></form>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>"><button class="delete-action" name="action" value="delete_announcement" data-confirm="确认删除该公告？正文中引用的图片会一并删除。">删除</button></form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state compact-empty">还没有公告，可在下方创建。</div>
            <?php endif; ?>

            <form method="post" class="connection-form stack-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="announcement_id" value="<?= (int) ($editingAnnouncement['id'] ?? 0) ?>">
                <label><span>标题</span><input name="title" maxlength="200" required value="<?= e((string) ($editingAnnouncement['title'] ?? '')) ?>" placeholder="公告标题（≤200 字）"></label>
                <label><span>正文（Markdown）</span><textarea name="body" rows="10" required placeholder="支持 # 标题、- 无序列表、1. 有序列表、**粗体**、*斜体*、`代码`、[链接](https://…)"><?= e((string) ($editingAnnouncement['body'] ?? '')) ?></textarea><small>图片请通过下方上传，上传后会自动追加到正文末尾。</small></label>
                <label><span>插入图片</span><input type="file" name="announcement_image" accept="image/jpeg,image/png,image/gif,image/webp"><small>JPG/PNG/GIF/WebP，≤5 MB。</small></label>
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="is_active" <?= $editingAnnouncement === null || (int) $editingAnnouncement['is_active'] === 1 ? 'checked' : '' ?>>启用该公告（在工作台弹窗提醒用户）</label></div>
                <div class="form-actions"><button class="primary-button compact"><?= $editingAnnouncement === null ? '发布公告' : '保存修改' ?></button><?php if ($editingAnnouncement !== null): ?><a class="secondary-button" href="admin.php?tab=system">取消编辑</a><?php endif; ?></div>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'tickets'): ?>
        <?php
        $ticketBanService = TicketBanService::create();
        $ticketBans = $ticketBanService->bannedUsers();
        $ticketBanDurations = TicketBanService::durations();
        $ticketExpireDays = TicketService::expireDays();
        ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>工单过期设置</h2><p>全局一个档位。计时规则：只在「客服已回复、等用户回复」时计时——客服回复后用户超过该天数未回应，工单自动置为「已过期」。过期后对话仍可查看，但不可再回复、不可重开，工单内附件会立即从 Notion 删除且不可恢复。</p></div><span class="status-badge <?= $ticketExpireDays > 0 ? '' : 'closed' ?>"><?= $ticketExpireDays > 0 ? $ticketExpireDays . ' 天' : '不过期' ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_ticket_settings">
                <label><span>过期天数</span>
                    <input name="ticket_expire_days" inputmode="numeric" list="ticketExpirePresets" value="<?= (int) $ticketExpireDays ?>" placeholder="0 表示不过期" required>
                    <datalist id="ticketExpirePresets">
                        <?php foreach (TicketService::expirePresets() as $presetValue => $presetLabel): ?>
                        <option value="<?= (int) $presetValue ?>"><?= e($presetLabel) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <small>可从 0 / 1 / 7 / 15 / 30 中选，也可手动输入其他天数；0 表示不过期，正整数表示客服回复后等待用户回复的天数（1 ~ 3650）。</small>
                </label>
                <div class="form-actions"><button class="primary-button compact" data-confirm="确认保存工单过期档位？保存后对所有工单立即生效，已过期的工单不受影响。">保存设置</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>站内评价开关</h2><p>关闭后，已结束的工单详情页<b>不再展示评价区块</b>，外部调研入口也一并隐藏；用户即使构造请求提交评价也会被服务端拒绝。历史已提交的评价不受影响，仍可在「评价统计」页签查看与统计。</p></div><span class="status-badge <?= TicketService::reviewEnabled() ? '' : 'closed' ?>"><?= TicketService::reviewEnabled() ? '已开启' : '已关闭' ?></span></div>
            <form method="post" class="connection-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_review_enabled">
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="ticket_review_enabled" value="1" <?= TicketService::reviewEnabled() ? 'checked' : '' ?>>开启工单评价（用户可在已结束工单上打分与留言）</label></div>
                <div class="form-actions"><button class="primary-button compact" data-confirm="确认保存工单评价开关？">保存设置</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>外部调研链接</h2><p>工单评价只在站内统计，不会共享到外部渠道。若需要在第三方渠道（如问卷平台）收集用户反馈，可在此配置调研链接：配置后会在「已结束工单」的详情页展示「为了提升我们的服务，邀请您参加调研 → 点我参加」，点击新窗口打开。留空表示不展示该入口。注意：站内评价开关关闭时，该入口同样不展示。</p></div><span class="status-badge <?= TicketService::surveyUrl() !== '' ? '' : 'closed' ?>"><?= TicketService::surveyUrl() !== '' ? '已启用' : '未配置' ?></span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_survey_url">
                <label><span>调研链接</span>
                    <input type="url" name="ticket_survey_url" maxlength="500" value="<?= e(TicketService::surveyUrl()) ?>" placeholder="https://…（留空表示不展示调研入口）">
                    <small>需以 http:// 或 https:// 开头；仅本站配置并跳转，评价数据不上传到本站以外的位置。</small>
                </label>
                <div class="form-actions"><button class="primary-button compact">保存链接</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>禁止提交工单</h2><p>被禁止的用户无法新建工单（含文件被拦截后的快捷申诉），但可继续查看与回复已有工单；上传、下载、重命名与新建文件夹均不受影响。与「封禁管理」的账户封禁相互独立。</p></div><span class="status-badge <?= $ticketBans ? 'void' : 'closed' ?>"><?= count($ticketBans) ?> 位已禁止</span></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="ban_ticket_user">
                <label><span>选择用户</span>
                    <select name="ticket_ban_user_id" required>
                        <option value="">请选择用户</option>
                        <?php foreach ($users as $ticketUser): ?>
                        <option value="<?= (int) $ticketUser['id'] ?>"><?= e((string) $ticketUser['username']) ?><?= (string) ($ticketUser['email'] ?? '') === '' ? '' : ' · ' . e((string) $ticketUser['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>禁止期限</span>
                    <select name="ticket_ban_days">
                        <?php foreach ($ticketBanDurations as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) $value === '7' ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>到期后自动解除；选「永久」需管理员手动解除。</small>
                </label>
                <label><span>原因</span><input name="ticket_ban_reason" maxlength="500" placeholder="禁止原因（可留空）"></label>
                <div class="form-actions"><button class="primary-button compact" data-confirm="确认禁止该用户提交新工单？其已有工单仍可查看与回复，上传下载不受影响。">禁止提交工单</button></div>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>已禁止用户</h2><p>共 <?= count($ticketBans) ?> 位 · 到期会自动解除，也可在此手动解除。</p></div></div>
            <?php if (!$ticketBans): ?>
            <div class="empty-state compact-empty">当前没有被禁止提交工单的用户。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>用户</th><th>原因</th><th>期限</th><th>禁止时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($ticketBans as $ban): ?>
                    <tr>
                        <td>
                            <div class="file-name"><div><strong class="display-name"><?= e((string) $ban['username']) ?></strong><small>ID <?= (int) $ban['user_id'] ?><?= (string) ($ban['email'] ?? '') === '' ? '' : ' · ' . e((string) $ban['email']) ?></small></div></div>
                        </td>
                        <td data-label="原因"><?= e((string) ($ban['reason'] ?? '') !== '' ? (string) $ban['reason'] : '—') ?></td>
                        <td data-label="期限"><?= $ban['until'] === null ? '<span class="status-badge void">永久</span>' : '至 ' . e(date('Y-m-d H:i', (int) strtotime((string) $ban['until']))) ?></td>
                        <td data-label="禁止时间"><span><?= ($ban['created_at'] ?? null) !== null ? e(date('Y-m-d H:i', (int) strtotime((string) $ban['created_at']))) : '—' ?></span></td>
                        <td>
                            <div class="file-actions">
                                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="unban_ticket_user"><input type="hidden" name="user_id" value="<?= (int) $ban['user_id'] ?>"><button data-confirm="确认解除该用户禁止提交工单的限制？解除后其可重新新建工单。">解除</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'preview'): ?>
        <?php $previewWarnMb = (int) max(1, round(previewWarnBytes() / 1024 / 1024)); ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>文件在线预览</h2><p>控制用户在「我的文件」里能否直接预览文件。预览走站内同源接口，复用登录、封禁与文件归属校验，不会把文件地址交给任何第三方。可按<b>图片 / PDF / 音视频</b>三个大类分别开关（MP3 等小音频归入「音视频」，不单独设项）。关闭某类后，该类文件列表不再显示「预览」按钮，只能下载。</p></div></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_preview_settings">
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="preview_image" value="1" <?= previewKindEnabled('image') ? 'checked' : '' ?>>允许预览图片（jpg / png / gif / webp 等，不含 SVG）</label></div>
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="preview_pdf" value="1" <?= previewKindEnabled('pdf') ? 'checked' : '' ?>>允许预览 PDF（浏览器内置阅读器，部分手机浏览器可能转为下载）</label></div>
                <div class="checkbox-row"><label class="checkbox"><input type="checkbox" name="preview_av" value="1" <?= previewKindEnabled('av') ? 'checked' : '' ?>>允许预览音视频（mp4 / mov / webm 及 mp3 / m4a / wav 等，支持边下边播）</label></div>
                <label><span>大文件预览提醒阈值（MB）</span><input type="number" name="preview_warn_mb" min="1" max="10240" step="1" inputmode="numeric" value="<?= (int) $previewWarnMb ?>" required><small>文件达到该体积时，先弹窗提示会消耗较多流量，用户确认后才预览。默认 20 MB，范围 1 ~ 10240 MB。</small></label>
                <div class="form-actions"><button class="primary-button compact" data-confirm="确认保存文件预览设置？">保存设置</button></div>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'reviews'): ?>
        <?php
        $reviewService = TicketService::create();
        $reviewStats = $reviewService->reviewStats();
        $reviewList = $reviewService->recentReviews(50);
        $reviewMaxCount = $reviewStats['distribution'] === [] ? 0 : max($reviewStats['distribution']);
        ?>
        <section class="admin-panel">
            <div class="panel-title">
                <div><h2>评价概览</h2><p>数据来自用户对已结束工单的评分（0.5 ~ 5.0）。同一工单重复提交会覆盖上一次，因此每张工单至多计入 1 条。匿名评价不展示用户名。</p></div>
                <span class="status-badge <?= $reviewStats['count'] > 0 ? '' : 'closed' ?>">共 <?= (int) $reviewStats['count'] ?> 条评价</span>
            </div>
            <?php if ($reviewStats['count'] === 0): ?>
            <div class="empty-state compact-empty">还没有任何工单评价。用户在工单结束后可在详情页打分。</div>
            <?php else: ?>
            <div class="review-summary">
                <div class="review-average">
                    <strong><?= e(number_format((float) $reviewStats['average'], 2)) ?></strong>
                    <div class="star-rating static" aria-hidden="true">
                        <div class="star-rating-stars"><span class="star-rating-fg" data-style-width="<?= e(number_format((float) $reviewStats['average'] / TicketService::MAX_SCORE * 100, 2)) ?>%">★★★★★</span>★★★★★</div>
                    </div>
                    <small>平均分（满分 <?= e(number_format(TicketService::MAX_SCORE, 1)) ?>）</small>
                </div>
                <div class="review-distribution">
                    <?php foreach (array_reverse($reviewStats['distribution'], true) as $scoreLabel => $scoreCount): ?>
                    <div class="review-bar-row">
                        <span class="review-bar-label"><?= e($scoreLabel) ?> 分</span>
                        <span class="review-bar"><i data-style-width="<?= $reviewMaxCount > 0 ? e(number_format($scoreCount / $reviewMaxCount * 100, 2)) : '0' ?>%"></i></span>
                        <span class="review-bar-count"><?= (int) $scoreCount ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="admin-panel">
            <div class="panel-title"><div><h2>最近评价</h2><p>按最近提交/修改时间倒序，最多展示 50 条。</p></div></div>
            <?php if (!$reviewList): ?>
            <div class="empty-state compact-empty">还没有评价明细。</div>
            <?php else: ?>
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead><tr><th>工单</th><th>评价人</th><th>评分</th><th>留言</th><th>时间</th></tr></thead>
                    <tbody>
                    <?php foreach ($reviewList as $reviewRow): ?>
                    <?php $anonymousRow = (int) $reviewRow['is_anonymous'] === 1; ?>
                    <tr>
                        <td data-label="工单">
                            <div class="file-name"><div><strong class="display-name"><?= e((string) ($reviewRow['ticket_no'] ?? '—')) ?></strong><small><?= e(mb_strimwidth((string) ($reviewRow['subject'] ?? ''), 0, 40, '…')) ?></small></div></div>
                        </td>
                        <td data-label="评价人"><?= $anonymousRow ? '<span class="status-badge closed">匿名</span>' : e((string) ($reviewRow['username'] ?? '已删除用户')) ?></td>
                        <td data-label="评分"><span class="review-score-line"><?= e(number_format((float) $reviewRow['score'], 1)) ?></span></td>
                        <td data-label="留言"><?= (string) $reviewRow['comment'] === '' ? '—' : nl2br(e((string) $reviewRow['comment'])) ?></td>
                        <td data-label="时间"><span><?= e(date('Y-m-d H:i', (int) strtotime((string) $reviewRow['updated_at']))) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($activeTab === 'restore'): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2>还原个性化设置</h2><p>把站点名称、站点 logo、favicon 恢复为内置默认（站点名回「Notion Drive」，logo / favicon 回内置标识）。不涉及任何用户、文件、工单与密钥。</p></div></div>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="restore_personalization">
                <label><span>管理员密码</span><input type="password" name="admin_password" autocomplete="current-password" placeholder="请输入当前管理员登录密码"></label>
                <label><span>确认短语</span><input name="confirm_phrase" autocomplete="off" placeholder="请输入：<?= e(RestoreService::PHRASE_PERSONALIZATION) ?>"></label>
                <div class="form-actions"><button class="danger-button compact" data-confirm="确认把站点名称、logo、favicon 还原为内置默认？">还原个性化设置</button></div>
            </form>
        </section>

        <section class="admin-panel danger-panel">
            <div class="panel-title"><div><h2>恢复出厂设置</h2><p>清空整个数据库并跳转到初始化安装向导，等同于重新安装本系统。此操作<strong class="danger-text">不可恢复</strong>，执行前请务必备份需要保留的数据。</p></div></div>
            <ul class="restore-impact">
                <li>数据库全部数据表被删除：所有用户账号（含管理员）、权限组、文件记录、工单与评价、公告、订单与支付记录、封禁与违规记录、系统与站点设置全部清空。</li>
                <li>已上传的文件会在 Notion 侧尽力归档，本地头像、站点 logo / favicon、公告图片一并删除。</li>
                <li>执行后站点回到未安装状态并跳转安装向导，需要重新填写数据库、Notion Token 与管理员账号。</li>
            </ul>
            <form method="post" class="connection-form stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="factory_reset">
                <label><span>管理员密码</span><input type="password" name="admin_password" autocomplete="current-password" placeholder="请输入当前管理员登录密码"></label>
                <label><span>确认短语</span><input name="confirm_phrase" autocomplete="off" placeholder="请输入：<?= e(RestoreService::PHRASE_FACTORY_RESET) ?>"></label>
                <div class="form-actions"><button class="danger-button compact" data-confirm="恢复出厂设置将清空整个数据库（含全部用户、工单、订单与设置）并跳转安装向导，数据无法恢复。确认继续？">恢复出厂设置</button></div>
            </form>
        </section>
        <?php endif; ?>
    </main>
    <?= siteCreditHtml() ?>
    <div id="toast" class="toast"></div><script src="assets/app.js?v=29" defer></script><script src="assets/admin.js?v=9" defer></script>
</body></html>
