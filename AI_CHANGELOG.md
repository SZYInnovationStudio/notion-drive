# AI 更新日志（AI_CHANGELOG.md）

> 本文件**只给 AI / 代码代理看**，用于快速掌握仓库的改动上下文。
> 面向人类的功能说明、使用文档请写 README.md，不要写在这里。

## 记录规范

1. 每完成一次改动（一个任务 / 一个 Bug / 一次重构）就追加一条记录，**最新记录写在最上面**。
   - 条目标题里的时间必须**精确到时分秒**，统一格式 `[YYYY-MM-DD HH:MM:SS]`，**时区固定 UTC+8（北京 / 上海）**，例如 `### [2026-09-26 19:00:53] 标题`。
   - 取时间的可靠来源：改动已提交时用该提交的时间（`git log --date=format:"%Y-%m-%d %H:%M:%S"`，本机即 UTC+8）；未提交时用写日志当下时间；两者都必须按 UTC+8 记录，不要写带时区猜测或只有日期的值。24 小时制。
2. **每条记录必须写「修改人」，用于区分谁改了什么。**
   - 人名统一用方括号包裹，如 `[Beibing]`；**当前默认/第一位修改人为 `[Beibing]`**。
   - 若改动由 AI 代理执行但由某人决策，写成 `[Beibing]`（不在括号里塞 "AI" 之类的词）。
   - 多人协作时各自具名，例如 `[Beibing]`、`[张三]`；不要留空、不要写"我""作者"。
3. 每条记录必须包含三要素：**改了哪些文件、具体行号、目的是什么**。
   - 行号写**改动后的当前行号**，支持 `L88`、`L88-L90` 形式。
   - 删除代码写成「原 Lxx-Lyy 删除」。
   - 目的写"为什么改"（触发原因、修的是什么问题），不要只复述"改了什么"。
4. 写清**验证方式与结果**，方便后续代理判断该改动是否可信、能否回退。
5. 若改动依赖数据库结构，须注明受影响的表/列，以及**旧库是否需要手工补列**。
6. 纯环境、临时文件（如 `.dev/`、本地凭据）不属于源码改动，统一记在文末「环境与本地运行」一节。

## 条目模板

```markdown
### [YYYY-MM-DD HH:MM:SS] 标题（时间用 UTC+8，精确到秒）
- 修改人：[xxx]
- 目的：
- 验证：
- 改动明细：
  - `path/to/file.php` L88 — 说明
```

---

### [2026-09-26 19:00:53] 管理后台二级菜单分区，并修复「管理文件」点进去看不到文件

- 修改人：[Beibing]
- 目的：
  1. **修 Bug（管理员查看任意用户文件）**：此后端一直放行管理员操作他人文件（`api/file.php` 的 `isAdmin()` 分支），但 `admin.php` 里「管理文件」是无锚点链接 `admin.php?user_id=N`，点击后只回到页面顶部；而真正的文件面板（`id="user-files"`）被「权限组」「官方账户池」夹在页面最底部，管理员视觉上等于"点了没反应"，误以为功能没实现。
  2. **治理后台杂乱**：`admin.php` 单页平铺 10 块面板（约 1080 行），管理员要靠长滚动找入口；改为侧边栏可折叠二级菜单 + `?tab=` 服务端分区，一屏只渲染一个分区。
- 方案（已与 [Beibing] 确认）：单页面板 + 锚点自动定位（`#user-files` + CSS `:target` 高亮）；侧栏 `<details>` 二级菜单；四分组 = 用户管理 / 内容与账户 / 套餐与订单 / 系统设置。不引入 JS，不改表单提交与权限逻辑。
- 验证：`php -l admin.php`、`php -l admin_orders.php` 均无语法错误。本地跑起后以 admin 登录做端到端校验：
  - `?tab=users` → 仅「用户与容量 + 权限组」；`?tab=content` → 仅「官方账户池 + 用户文件 + 用户官方账户」；`?tab=premium` → 仅「会员与到期」；`?tab=system` → 仅「注册与邮箱 / Notion 连接 / 上传与容量 / 支付设置」。四个分区互不泄漏。
  - `admin.php?user_id=1`（不带 tab）→ 自动落到 `content` 分区并渲染 `id="user-files"` 面板。
  - `?tab=users&user_id=1` → 不渲染文件面板，但保留了带 `#user-files` 的「管理文件」跳转链接。
  - 侧栏 `nav-sub` 二级菜单渲染正常，当前分区带 `active`；窄屏（≤760px）侧栏二级菜单隐藏、改用正文顶部横向分区条。
- 改动明细：
  - `admin.php` L23-L35 — 新增 `adminTabs()`：定义 4 个分区（users/content/premium/system）及其分组名、标题、描述，作为菜单与页头的唯一数据源。
  - `admin.php` L36-L38 — 新增 `normalizeAdminTab()`：白名单校验 `?tab=`，非法值回退空串，避免任意参数穿透。
  - `admin.php` L39-L45 — 解析 `$selectedUserId` / `$requestedTab` / `$activeTab`（带 `user_id` 时默认落 `content`，否则 `users`）与 `$adminHome`。
  - `admin.php` L545、L553、L673、L726 — 4 处表单处理后的 `header('Location: admin.php?user_id=...')` 改为带 `&tab=' . $activeTab . '`，**保存/删除等提交后回跳到原分区**，不再被甩回默认页。
  - `admin.php` L739 — `header('Location: admin.php')` 改为 `header('Location: ' . $adminHome)`，同上保留分区。
  - `admin.php` L777 — 新增 `$tabUrl` 闭包：生成分区链接时**保留当前选中用户**，切换分区不丢上下文。
  - `admin.php` L885 — 侧栏 `<nav class="drive-nav">` 内新增 `<details class="nav-group" open>` + `.nav-sub`，遍历 `$adminTabs` 生成二级菜单，当前分区加 `active`。
  - `admin.php` L889 — 页头标题改为跟随分区：`管理后台 · {分区名}` + `{分区描述}`。
  - `admin.php` L890 — 新增 `<nav class="admin-tabs">` 横向分区条（桌面端隐藏，窄屏显示）。
  - `admin.php` L907 / L946 / L993+L997 / L1022 — 用 `<?php if ($activeTab === '...'): ?> ... <?php endif; ?>` 把 10 块面板包成 4 组，仅渲染当前分区；`premium` 分区在未选用户时显示引导空状态。
  - `admin.php` L921 — **Bug 修复点**：「管理文件」链接由 `admin.php?user_id={id}` 改为 `admin.php?tab=content&user_id={id}#user-files`，点击后直接落到文件面板。
  - `admin.php` L970 — 「用户文件」面板新增 `id="user-files"` 作为锚点。
  - `admin.php` L881 — 样式缓存版本 `assets/app.css?v=19` → `v=20`。
  - `assets/app.css` L107-L120 — 新增 `.nav-group` / `.nav-sub` 二级菜单样式（折叠箭头、hover、当前项高亮），及 `.admin-tabs { display: none }` 的桌面端隐藏。
  - `assets/app.css` L205-L206 — 新增 `.admin-panel { scroll-margin-top: 24px }` 与 `.admin-panel:target` 高亮，使锚点跳转后目标面板描边提示、不被页头遮挡。
  - `assets/app.css` L398-L402 — 760px 媒体查询内：隐藏侧栏 `.nav-group`，显示 `.admin-tabs` 横向分区条（可横滑、隐藏滚动条）。
  - `admin_orders.php` L179 — 订单列表「用户」链接改指 `admin.php?tab=content&user_id=...`；L135 样式版本 `v=19` → `v=20`。
  - 其余页面 `account.php`、`index.php`、`dashboard.php`、`install.php`、`logs.php`、`pay.php`、`register.php` — 仅同步 `app.css?v=19` → `v=20`，无逻辑改动。
  - 影响面：纯展示层与路由参数，**无数据库结构变更**；旧书签 `admin.php?user_id=N` 仍可用（自动落 `content` 分区）。

---

### [2026-09-26 18:29:57] 修复全新安装后登录任意页面即 500（users 表缺列）

- 修改人：[Beibing]
- 目的：`install.php` 建 `users` 表时未包含 `premium_reminder_sent_at`，却在安装流程里把 `config.schema_version` 直接写成 `Schema::CURRENT_VERSION`（9），导致 `Schema::migrate()` 中 v8 的加列迁移被判定为「已是最新」而跳过；而 `Auth::user()` 的 SELECT 又包含该列，于是全新安装后一登录就抛 `Unknown column 'u.premium_reminder_sent_at'`，管理端所有页面 500。属于"新装即坏"的阻断性 Bug。
- 验证：删库并删除 `storage/installed.lock`、`config/database.php` 后重装，`SHOW COLUMNS FROM users` 可见该列；admin 登录后 `dashboard.php`、`account.php`、`admin.php`、`logs.php`、`admin_orders.php` 均返回 200，PHP 内置服务器日志无新增报错。
- 改动明细：
  - `install.php` L88 — 在 `users` 建表语句中 `premium_expires_at` 之后新增一行 `premium_reminder_sent_at DATETIME NULL DEFAULT NULL,`。使全新安装产生的表结构与 `CURRENT_VERSION = 9` 完全一致，不再依赖 v8 迁移补列（该迁移 `Schema.php` L134-L137 只在旧库升级时生效）。
  - 影响面：仅新建库的初始结构；**已存在的旧库不受影响**（它们本就走迁移路径）。除 `premium_reminder_sent_at` 外，v4~v9 各迁移新增的列在安装脚本中均已存在，无需其它改动。

---

## 环境与本地运行（非源码改动）

### [2026-09-26 17:59:57] 初始化本地开发环境

- 修改人：[Beibing]
- 目的：仓库克隆后 `.dev/` 不存在，`scripts/dev-start.ps1` 会因缺少 `.dev/mariadb-data/my.ini` 直接报「MariaDB 数据目录尚未初始化」，无法本地运行。
- 改动明细（均在 `.dev/`，已被 `.gitignore` 忽略，不进版本库）：
  - `.dev/mariadb-data/data` — 用 `C:\Program Files\MariaDB 13.0\bin\mysql_install_db.exe` 初始化的隔离数据目录。
  - `.dev/mariadb-data/my.ini` — 新建，指定 `basedir`/`datadir`、`port=3306`、`bind-address=127.0.0.1`、`skip-name-resolve`、`utf8mb4`。
  - `.dev/credentials.txt` — 新建，记录数据库与管理员账号（`admin` / `DevAdmin_NotionDrive_2026`）。
  - 数据库：`notion_drive`，账号 `notion_drive` / `DevOnly_NotionDrive_2026`。
- 注意：`.dev/mariadb.pid`、`.dev/php.pid` 需与真实在跑的进程号一致，否则 `dev-start.ps1` 会重复拉起并因端口占用失败。
- 注意：安装向导用的是**占位 Notion Token / 页面 ID**，界面与权限逻辑可用，但真实上传/下载前须在管理后台替换为有效凭据。