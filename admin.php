<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;
use NotionDrive\Epay;
use NotionDrive\Logger;
use NotionDrive\Mailer;
use NotionDrive\NotionApiException;
use NotionDrive\NotionClient;
use NotionDrive\Security;

$currentUser = Auth::requireAdmin();
$error = '';

/**
 * 后台二级分区：把原先堆在一页的管理面板按功能模块分组。
 * 键名为 ?tab= 取值；label 用于侧边栏二级菜单与移动端分区条，desc 显示在页头。
 */
function adminTabs(): array
{
    return [
        'users' => ['group' => '管理后台', 'label' => '用户管理', 'desc' => '用户账号、容量与权限组'],
        'content' => ['group' => '管理后台', 'label' => '账户池管理', 'desc' => '用户文件、官方账户分配与账户池'],
        'system' => ['group' => '管理后台', 'label' => '系统设置', 'desc' => '注册与邮箱、Notion 连接、上传与支付'],
    ];
}

/** 校验 ?tab= 取值；非法或缺失时返回空串，由页面按上下文决定默认分区。 */
function normalizeAdminTab(mixed $value): string
{
    return is_string($value) && array_key_exists($value, adminTabs()) ? $value : '';
}

$adminTabs = adminTabs();
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
// 当前分区：优先取 ?tab=；未指定时，带 user_id 进入（订单页点「用户」、用户行点「管理文件」）默认展示账户池管理
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
            if ($action === 'test_mail') {
                $recipient = trim((string) ($_POST['smtp_test_to'] ?? ''));
                Mailer::fromConfig()->send($recipient, 'Notion Drive 测试邮件', "这是一封来自 Notion Drive 的测试邮件。\n\n如果你收到了它，说明 SMTP 配置可以正常发信。");
                Logger::info('mail', '已发送 SMTP 测试邮件。', ['to' => $recipient, 'operator' => $currentUser['username']]);
                flash('success', '测试邮件已发送至 ' . $recipient . '，请检查收件箱。');
            } else {
                $emailRequired = isset($_POST['email_required']) ? '1' : '0';
                if ($emailRequired === '1' && !smtpConfigured()) {
                    throw new RuntimeException('开启「强制绑定邮箱」前，请先填写完整的 SMTP 配置。');
                }
                Database::setConfig('registration_enabled', isset($_POST['registration_enabled']) ? '1' : '0');
                Database::setConfig('email_required', $emailRequired);
                flash('success', '注册与邮箱设置已保存。');
            }
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

            // 每个副本可能存放在不同账户（自有账户 / 官方账户池中的任意账户），必须逐一用对应账户的
            // Token 归档，否则官方账户里会残留孤儿文件块，管理员在 Notion 中永远看不到本地记录。
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
            // 顺带清理该用户的本地残留：头像文件与邮箱验证码记录（后者按 email 关联，无 user_id）
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
        } elseif ($action === 'settings') {
            $pageId = preg_replace('/[^a-fA-F0-9-]/', '', (string) ($_POST['page_id'] ?? ''));
            $plan = (string) ($_POST['plan'] ?? 'free');
            if (!in_array(strlen((string) $pageId), [32, 36], true)) {
                throw new RuntimeException('Notion 页面 ID 无效。');
            }
            if (!in_array($plan, ['free', 'paid'], true)) {
                throw new RuntimeException('套餐选项无效。');
            }
            Database::setConfig('default_page_id', (string) $pageId);
            Database::setConfig('notion_plan', $plan);
            $token = trim((string) ($_POST['notion_token'] ?? ''));
            if ($token !== '') {
                $config = appConfig();
                Database::setConfig('notion_token', Security::encrypt($token, (string) $config['app_key']));
            }
            flash('success', 'Notion 连接设置已保存。');
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
            flash('success', sprintf(
                '已为 %s 开通 %d 个月高级套餐，有效期至 %s（授权订单 %s）。',
                (string) $targetUser['username'],
                $months,
                date('Y-m-d H:i', (int) strtotime($result['expires_after'])),
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
            $exception instanceof NotionApiException => 'Notion 接口调用失败，请检查「Notion 连接」设置后重试。',
            $exception instanceof RuntimeException => $exception->getMessage(),
            default => '服务器处理请求时发生未知错误，请稍后重试，或凭参考号在「运行日志」中查询详情。',
        };
    }
}

$users = Database::connection()->query(
    "SELECT u.id, u.username, u.group_id, u.quota_bytes, u.notion_page_id, u.email, u.email_verified_at, u.created_at, u.avatar_path,
            u.notion_token_encrypted, u.notion_parent_page_id, u.notion_account_source, u.premium_expires_at,
            g.name AS group_name, g.is_admin AS group_is_admin, g.quota_bytes AS group_quota_bytes, g.level AS group_level,
            COALESCE(f.file_count, 0) AS file_count, COALESCE(f.used_bytes, 0) AS used_bytes
     FROM users u
     LEFT JOIN `groups` g ON g.id = u.group_id
     LEFT JOIN (
         SELECT user_id, COUNT(*) AS file_count, SUM(file_size) AS used_bytes
         FROM uploads WHERE status = 'active' GROUP BY user_id
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
        $filesStatement = Database::connection()->prepare("SELECT * FROM uploads WHERE user_id = :id AND status = 'active' ORDER BY id DESC");
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
$pageId = (string) Database::config('default_page_id', '');
$plan = (string) Database::config('notion_plan', 'free');
$registrationEnabled = registrationEnabled();
$emailRequired = emailRequired();
// 上传分块与官方账户单文件上限：输入用 MB，实际生效值由 uploadChunkSizeBytes() 按 PHP 限制收敛
$officialLimitMb = (int) round((int) Database::config('official_single_file_limit_bytes', (string) (5 * 1024 * 1024 * 1024)) / 1024 / 1024);
$chunkSizeMb = (int) Database::config('upload_chunk_size_mb', '10');
$effectiveChunkBytes = uploadChunkSizeBytes();
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
/** 用户列表与会员面板中的会员状态文案。 */
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
/** 用户列表中的「到期时长」文案：管理员与永久会员显示「永久」，未开通显示「未开通」。 */
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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>管理后台 · Notion Drive</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/app.css?v=23"></head>
<body class="drive-shell" data-csrf="<?= e(Security::csrfToken()) ?>">
    <aside class="drive-sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">N</span><span>Notion Drive</span></a>
        <nav class="drive-nav"><a href="dashboard.php"><span>▣</span>我的文件</a><details class="nav-group" open><summary><span>⚙</span>管理后台</summary><div class="nav-sub"><?php foreach ($adminTabs as $tabKey => $tabMeta): ?><a class="<?= $tabKey === $activeTab ? 'active' : '' ?>" href="<?= e($tabUrl((string) $tabKey)) ?>"><?= e($tabMeta['label']) ?></a><?php endforeach; ?></div></details><a href="admin_orders.php"><span>¥</span>订单管理</a><a href="logs.php"><span>☰</span>运行日志</a><a href="account.php"><span>◍</span>账户管理</a></nav>
        <div class="account-menu"><?= avatarHtml($currentUser) ?><div><strong><?= e($currentUser['username']) ?></strong><small>管理员</small></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><button>退出</button></form></div>
    </aside>
    <main class="drive-main admin-main">
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
                                <a class="text-action" href="admin.php?tab=content&user_id=<?= (int) $managedUser['id'] ?>#user-files">管理文件</a>
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
                        <li><a href="admin.php?tab=content&user_id=<?= (int) $member['id'] ?>#user-files"><?= avatarHtml($member) ?><strong><?= e((string) $member['username']) ?></strong></a><small><?= (int) $member['file_count'] ?> 个文件 · <?= e($premiumLabel($member)) ?></small></li>
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
            <div class="panel-title"><div><h2>官方账户池</h2><p>购买高级套餐的用户可使用这里的账户；优先级数值越大越靠前，自动分配时优先选用。Token 加密保存，不会回显。</p></div><span><?= count($officialAccounts) ?> 个账户</span></div>
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
                    <div class="user-identity"><span class="avatar"><?= e(mb_strtoupper(mb_substr((string) $account['label'], 0, 1))) ?></span><div><strong><?= e($account['label']) ?><?= (int) $account['is_enabled'] === 1 ? '' : ' · 已停用' ?></strong><small><?= (int) ($accountAssignmentCounts[(int) $account['id']] ?? 0) ?> 位用户 · <?= $account['last_check_at'] === null ? '未检测' : '检测于 ' . e(date('Y-m-d H:i', strtotime((string) $account['last_check_at']))) ?><?= $account['last_error'] === null ? '' : ' · ' . e(mb_substr((string) $account['last_error'], 0, 80)) ?></small></div></div>
                    <label><span>账户名称</span><input name="label" value="<?= e($account['label']) ?>" maxlength="64" required></label><label><span>集成 Token</span><input type="password" name="token" autocomplete="off" maxlength="200" placeholder="留空保持不变"></label><label><span>根页面</span><input name="root_page" value="<?= e((string) $account['root_page_id']) ?>" maxlength="500"></label><label><span>优先级</span><input name="priority" value="<?= (int) $account['priority'] ?>"></label><label class="checkbox"><input type="checkbox" name="is_enabled" <?= (int) $account['is_enabled'] === 1 ? 'checked' : '' ?>>启用</label>
                    <div class="row-actions"><button class="save-action" name="action" value="save_official_account">保存</button><button class="text-action" name="action" value="test_official_account">测试连接</button></div><button class="delete-action" name="action" value="delete_official_account" data-confirm="确认删除该官方账户？">删除</button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($selectedUser): ?>
        <section class="admin-panel" id="user-files">
            <div class="panel-title"><div><h2><?= e($selectedUser['username']) ?> 的文件</h2><p>管理员可以下载、重命名或删除该用户的文件。</p></div><a href="admin.php?tab=content">关闭</a></div>
            <?php if (!$selectedFiles): ?><div class="empty-state compact-empty">该用户暂无文件</div><?php else: ?><div class="file-table-wrap"><table class="file-table"><thead><tr><th>名称</th><th>大小</th><th>上传时间</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($selectedFiles as $file): $name = (string) ($file['display_name'] ?: $file['filename']); ?><tr data-file-id="<?= (int) $file['id'] ?>"><td><div class="file-name"><span class="file-kind"><?= e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) ?></span><strong class="display-name"><?= e($name) ?></strong></div></td><td data-label="大小"><?= e(formatBytes((int) $file['file_size'])) ?></td><td data-label="上传时间"><?= e(date('Y-m-d H:i', strtotime((string) $file['created_at']))) ?></td><td><div class="file-actions"><a href="api/file.php?id=<?= (int) $file['id'] ?>">下载</a><button data-action="rename">重命名</button><button class="danger" data-action="delete">删除</button></div></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($selectedUser): ?>
        <section class="admin-panel">
            <div class="panel-title"><div><h2><?= e($selectedUser['username']) ?> 的官方账户</h2><p>高级用户优先使用主账户，主账户不可用时自动改用备用账户。自有账户由用户注册时绑定，Token 加密保存、不在此显示。</p></div><span class="status-badge"><?= userNotionSource($selectedUser) === 'official' ? '当前来源：官方账户' : '当前来源：自有账户' ?></span></div>
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

        <?php if ($activeTab === 'system'): ?>
        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>注册与邮箱</h2><p>未配置 SMTP 时无法开启强制绑定邮箱；验证码 6 位数字，10 分钟内有效。</p></div><span class="status-badge"><?= smtpConfigured() ? 'SMTP 已配置' : 'SMTP 未配置' ?></span></div>
            <form method="post" class="connection-form">
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
            <div class="panel-title"><div><h2>Notion 连接</h2><p>所有 Token 请求都由服务器发出，不会暴露给浏览器。</p></div><span class="status-badge">已连接</span></div>
            <form method="post" class="connection-form"><input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="settings"><label><span>新 Token</span><input type="password" name="notion_token" placeholder="留空保持不变"></label><label><span>根页面 ID</span><input name="page_id" value="<?= e($pageId) ?>" required></label><label><span>套餐</span><select name="plan"><option value="free" <?= $plan === 'free' ? 'selected' : '' ?>>免费版 · 5 MB</option><option value="paid" <?= $plan === 'paid' ? 'selected' : '' ?>>付费版 · 5 GB</option></select></label><div class="form-actions"><button class="primary-button compact">保存设置</button></div></form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>上传与容量</h2><p>分块大小决定浏览器每次提交的数据量，实际生效值受服务器 PHP 上传限制约束：upload_max_filesize = <?= e((string) ini_get('upload_max_filesize')) ?>，post_max_size = <?= e((string) ini_get('post_max_size')) ?>。官方账户单文件上限仅在「套餐」为付费版时生效，免费版仍为 5 MB。</p></div><span class="status-badge">实际单块 <?= e(formatBytes($effectiveChunkBytes)) ?></span></div>
            <form method="post" class="connection-form">
                <input type="hidden" name="csrf_token" value="<?= e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="save_limits">
                <label><span>官方账户单文件上限（MB）</span><input name="official_single_file_limit_mb" inputmode="numeric" value="<?= $officialLimitMb ?>" required></label>
                <label><span>上传分块大小（MB）</span><input name="upload_chunk_size_mb" inputmode="numeric" value="<?= $chunkSizeMb ?>" required></label>
                <label><span>实际生效单块</span><input value="<?= e(formatBytes($effectiveChunkBytes)) ?>" readonly></label>
                <div class="form-actions"><button class="primary-button compact">保存设置</button></div>
            </form>
        </section>

        <section class="admin-panel connection-panel">
            <div class="panel-title"><div><h2>支付设置</h2><p>对接易支付（页面跳转收款）。商户密钥加密保存、不会回显；接口地址留空即暂停在线支付。套餐价格修改后立即对新订单生效，不影响已支付的订单。</p></div><span class="status-badge"><?= $epayConfigured ? '已配置' : '未配置' ?></span></div>
            <form method="post" class="connection-form">
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
        <?php endif; ?>
    </main>
    <div id="toast" class="toast"></div><script src="assets/app.js?v=14" defer></script><script src="assets/admin.js?v=9" defer></script>
</body></html>
