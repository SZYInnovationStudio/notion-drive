# AI 更新日志（AI_CHANGELOG.md）

> 本文件**只给 AI / 代码代理看**，用于快速掌握仓库的改动上下文。
> 面向人类的功能说明、使用文档请写 README.md，不要写在这里。

## 记录规范

1. 每完成一次改动（一个任务 / 一个 Bug / 一次重构）就追加一条记录，**最新记录写在最上面**。
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
### [YYYY-MM-DD] 标题
- 修改人：[Beibing]
- 目的：
- 验证：
- 改动明细：
  - `path/to/file.php` L88 — 说明
```

---

### [2026-09-26] 修复全新安装后登录任意页面即 500（users 表缺列）

- 修改人：[Beibing]
- 目的：`install.php` 建 `users` 表时未包含 `premium_reminder_sent_at`，却在安装流程里把 `config.schema_version` 直接写成 `Schema::CURRENT_VERSION`（9），导致 `Schema::migrate()` 中 v8 的加列迁移被判定为「已是最新」而跳过；而 `Auth::user()` 的 SELECT 又包含该列，于是全新安装后一登录就抛 `Unknown column 'u.premium_reminder_sent_at'`，管理端所有页面 500。属于"新装即坏"的阻断性 Bug。
- 验证：删库并删除 `storage/installed.lock`、`config/database.php` 后重装，`SHOW COLUMNS FROM users` 可见该列；admin 登录后 `dashboard.php`、`account.php`、`admin.php`、`logs.php`、`admin_orders.php` 均返回 200，PHP 内置服务器日志无新增报错。
- 改动明细：
  - `install.php` L88 — 在 `users` 建表语句中 `premium_expires_at` 之后新增一行 `premium_reminder_sent_at DATETIME NULL DEFAULT NULL,`。使全新安装产生的表结构与 `CURRENT_VERSION = 9` 完全一致，不再依赖 v8 迁移补列（该迁移 `Schema.php` L134-L137 只在旧库升级时生效）。
  - 影响面：仅新建库的初始结构；**已存在的旧库不受影响**（它们本就走迁移路径）。除 `premium_reminder_sent_at` 外，v4~v9 各迁移新增的列在安装脚本中均已存在，无需其它改动。

---

## 环境与本地运行（非源码改动）

### [2026-09-26] 初始化本地开发环境

- 修改人：[Beibing]
- 目的：仓库克隆后 `.dev/` 不存在，`scripts/dev-start.ps1` 会因缺少 `.dev/mariadb-data/my.ini` 直接报「MariaDB 数据目录尚未初始化」，无法本地运行。
- 改动明细（均在 `.dev/`，已被 `.gitignore` 忽略，不进版本库）：
  - `.dev/mariadb-data/data` — 用 `C:\Program Files\MariaDB 13.0\bin\mysql_install_db.exe` 初始化的隔离数据目录。
  - `.dev/mariadb-data/my.ini` — 新建，指定 `basedir`/`datadir`、`port=3306`、`bind-address=127.0.0.1`、`skip-name-resolve`、`utf8mb4`。
  - `.dev/credentials.txt` — 新建，记录数据库与管理员账号（`admin` / `DevAdmin_NotionDrive_2026`）。
  - 数据库：`notion_drive`，账号 `notion_drive` / `DevOnly_NotionDrive_2026`。
- 注意：`.dev/mariadb.pid`、`.dev/php.pid` 需与真实在跑的进程号一致，否则 `dev-start.ps1` 会重复拉起并因端口占用失败。
- 注意：安装向导用的是**占位 Notion Token / 页面 ID**，界面与权限逻辑可用，但真实上传/下载前须在管理后台替换为有效凭据。