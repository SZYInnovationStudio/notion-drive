# 更新日志

> 记录仓库的历次改动，便于快速掌握改动上下文；人工与其它途径产生的改动都应记录在此。
> 功能说明与使用文档请写 README.md，不要写在这里。

## 记录规范

1. 每完成一次改动（一个任务 / 一个 Bug / 一次重构）就追加一条记录，**最新记录写在最上面**。
   - 条目标题里的时间必须**精确到时分秒**，统一格式 `[YYYY-MM-DD HH:MM:SS]`，**时区固定 UTC+8（北京 / 上海）**，例如 `### [2026-09-26 19:00:53] 标题`。
   - 取时间的可靠来源：改动已提交时用该提交的时间（`git log --date=format:"%Y-%m-%d %H:%M:%S"`，本机即 UTC+8）；未提交时用写日志当下时间；两者都必须按 UTC+8 记录，不要写带时区猜测或只有日期的值。24 小时制。
2. 每条记录必须包含三要素：**改了哪些文件、具体行号、目的是什么**。
   - 行号写**改动后的当前行号**，支持 `L88`、`L88-L90` 形式。
   - 删除代码写成「原 Lxx-Lyy 删除」。
   - 目的写"为什么改"（触发原因、修的是什么问题），不要只复述"改了什么"。
3. 写清**验证方式与结果**，便于后续维护者判断该改动是否可信、能否回退。
4. 若改动依赖数据库结构，须注明受影响的表/列，以及**旧库是否需要手工补列**。
5. 纯环境、临时文件（如 `.dev/`、本地凭据）不属于源码改动，统一记在文末「环境与本地运行」一节。

## 条目模板

```markdown
### [YYYY-MM-DD HH:MM:SS] 标题（时间用 UTC+8，精确到秒）
- 目的：
- 验证：
- 改动明细：
  - `path/to/file.php` L88 — 说明
```

---

### [2026-10-04 14:13:33] 修复异步写入改动的 4 处缺陷（覆盖上传空窗、非 FPM 不秒回、Windows 暂存删不掉、懒清扫被单条记录中断）

- 目的：对上一版「先落服务器暂存、后台异步写入 Notion」自审后修复以下问题。①**严重**：覆盖同名文件时，改造后是「新文件只入库 writing（还没写进 Notion）、旧文件的 Notion 副本却立刻被归档」，出现「新的没进、旧的已删」的空窗——后台写入最终失败时两代内容都不在 Notion。②中：`jsonResponseThen()` 只 `flush()` 未声明 `Content-Length`，非 FPM（mod_php / 内置服务器）环境下浏览器要等连接关闭（即后台写完）才结束请求，「秒回」在非 FPM 环境完全失效。③中：`process()` 全程持有暂存文件的 `flock` 句柄，而 Windows 无法删除仍被本进程打开的文件，导致 `discard()` 静默失败、暂存文件要等下一次懒清扫才被清掉。④轻：`sweep()` 逐条调用 `process()` 未做隔离，单条记录出错（如数据库异常）会中断对其余掉线记录的接管。
- 验证：`php -l` 对仓库全部 48 个 `*.php` 执行，结果 `BAD=0 TOTAL=48`；`node --check assets/app.js` 退出码 0。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP / 上传验证。逻辑自查（覆盖上传的三条出口）：写入成功 → `finalizeOverwrite()` 归档旧副本并清空标记；写入最终失败或新文件在写入期间被删除 → `rollbackOverwrite()` 把旧文件恢复为 `active`（其副本一直在 Notion，立即可下载）；用户在写入失败后手动删除新文件 → `api/file.php` 的删除分支同样调用 `rollbackOverwrite()`，避免两代文件都从列表消失。
- 改动明细（行号为改动后当前行号）：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 22 → 23；L259-L264 — 新增 v23 迁移：`uploads.overwrite_upload_id`（`BIGINT UNSIGNED NULL DEFAULT NULL`），记录被覆盖的旧文件 id。旧库访问即自动补列，无需手工执行。
  - `install.php` L154 — 全新建库同步补 `overwrite_upload_id` 列。
  - `api/upload.php` L721-L742 — INSERT 增加 `overwrite_upload_id`（覆盖时写入被覆盖旧文件的 id，否则 NULL）。
  - `api/upload.php` L763-L768 — 覆盖处理改为**只把旧记录置为 deleted**（从列表移除），不再在此处 `archiveUploadReplicas()`；归档交给队列在新文件写入成功后执行。
  - `src/Uploads/NotionWriteQueue.php` L453-L479、L481-L498、L500-L511 — 新增 `finalizeOverwrite()`（写入成功后归档旧副本并清空标记）、`rollbackOverwrite()`（写入未成功时把旧文件恢复为 `active`，供失败与删除两条路径复用）、`clearOverwriteMark()`、`findUpload()`。
  - `src/Uploads/NotionWriteQueue.php` L286（新文件在写入期间被删除）、L302（写入成功）、L528（`markFailed()` 内）— 三处接入上述覆盖处理。
  - `api/file.php` L364-L370 — 删除「尚未写入存储」的文件时，若该行带 `overwrite_upload_id`，先 `rollbackOverwrite()` 再删暂存文件。
  - `src/helpers.php` L378-L382 — `jsonResponseThen()` 显式声明 `Content-Length`，浏览器收到完整响应即可结束请求，使非 FPM 环境的「秒回」也能成立（FPM 下仍由 `fastcgi_finish_request()` 断连）。
  - `src/Uploads/NotionWriteQueue.php` L129-L177 — `process()` 在释放 `flock` 句柄后补调 `discardIfSettled()`：记录已不是待写入状态（成功 / 隔离 / 删除）时再删一次暂存文件，解决 Windows 下删除被占用文件失败的问题；该补删是幂等的。
  - `src/Uploads/NotionWriteQueue.php` L211-L224 — `sweep()` 逐条 `process()` 包 try/catch，单条出错只记 `Logger::error`，不中断其余记录的接管。
- 数据库结构影响：`uploads` 新增 `overwrite_upload_id BIGINT UNSIGNED NULL DEFAULT NULL`；`status` 列无需改动。旧库由 `Schema::migrate()` 自动补列（`schema_version` 22 → 23），**无需手工执行，也不会清库**。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 13:50:15] 上传改为「先落服务器暂存、后台异步写入 Notion」，文件秒级可见可用

- 目的：写入 Notion 很慢（大文件要几十秒到数分钟），改造前 `finish` 阶段必须同步写完 Notion 再跑内容审核，用户全程只能盯着进度条。现改为：上传完成后文件先暂存在服务器本地并入库为 `writing`（正在写入存储），浏览器立刻拿到成功响应，文件在「我的文件」中立刻可见，并可**预览、下载、重命名、移动、删除**；Notion 写入与内容审核交给后台队列，写完自动转 `active` 并删除服务器上的暂存文件。同时按用户限制服务器暂存占用（后台可配，默认 1 GB），避免大文件并发上传把磁盘占满。
- 验证：`php -l` 对仓库全部 48 个 `*.php` 执行，结果 `BAD=0 TOTAL=48`（全部 `No syntax errors detected`）；`node --check assets/app.js` 退出码 0；`php -r "require 'src/helpers.php';"` 确认两个新常量为 `'active', 'writing', 'write_failed'` / `'active', 'quarantined', 'writing', 'write_failed'`（拼进 SQL 后即为合法的 `IN (...)` 列表）；`grep` 复核 `app.css?v=60` 已无残留（12 处均为 `?v=61`）。遵守「本机禁止功能 / HTTP 测试」约定，**未做 HTTP / 上传验证**——异步写入与懒清扫需在真实环境验证，建议部署后先传一个中等文件确认状态能正常从「正在写入存储」转为副本。逻辑自查：`SpacePatrol::reconcileReplicas()` 只取「恰好 1 份有效副本」的文件，暂存中的 0 副本文件不会被误判为数据丢失；`api/file.php` 在无副本时先抛异常、不会走到 `markLost`。
- 改动明细（行号为改动后当前行号）：
  - **新增** `src/Uploads/NotionWriteQueue.php` L1-L430 — 后台写入队列：`stage()` 把拼接好的文件移入 `storage/staging/{user_id}/{upload_id}.bin`；`process()` 以暂存文件 `flock` 作互斥锁（同一记录同一时刻只有一个进程在写）；`write()` 调 `writeNotionReplicas()` 写 Notion → `commitReplicas()` 回填副本并转 `active` → `moderate()` 跑内容审核（可能置隔离）→ `discard()` 删暂存文件；失败按 `MAX_ATTEMPTS = 3` 重试，仍失败置 `write_failed` 并告警管理员；记录在写入期间被删除时把刚写入的副本归档（`archiveWrittenReplicas`），落库失败同样归档；`sweep()` 懒清扫接管停在 `writing` 超过 1800 秒的记录并清理孤儿暂存文件；`stagedLimitBytes()` / `stagedBytes()` 提供暂存上限与占用口径。
  - `src/Schema.php` L11 — `CURRENT_VERSION` 21 → 22；L251-L257 — 新增 v22 迁移：`uploads.write_attempts`（`TINYINT UNSIGNED NOT NULL DEFAULT 0`）与索引 `idx_uploads_status_id(status, id)`。**旧库访问即自动补列，无需手工执行 SQL**。
  - `install.php` L153、L161 — 全新建库同步补 `write_attempts` 列与 `idx_uploads_status_id` 索引。
  - `src/helpers.php` L16-L23 — 新增全局常量 `UPLOAD_VISIBLE_STATUSES`（可见/可操作）与 `UPLOAD_CAPACITY_STATUSES`（计入容量），统一各处 SQL 口径。
  - `src/helpers.php` L373-L405 — 新增 `jsonResponseThen()` 与 `finishResponseThen()`：先输出响应并调用 `fastcgi_finish_request()` 与浏览器断开，再在后台执行任务；非 FPM 环境没有该函数，退化为「任务跑完才结束请求」。
  - `src/helpers.php` L584（`userUsedBytes`）、L857（`findActiveUploadByName`）、L1992（`folderFileCountMap`）— 状态过滤改用上述常量，使「正在写入存储 / 写入失败」的文件计入容量、参与重名编号、计入文件夹文件数。
  - `src/helpers.php` L1659-L1670 — `writeNotionReplicas()` 新增第 6 个可选参数 `$storageName`：后台写入时复用入库时算好的存储名（`uniqueStorageName()` 含随机数，不能重算），避免前后端看到两个名字。
  - `api/upload.php` L15 — 引入 `NotionWriteQueue`；L182-L204 — `$pendingUploadBytes()` 新增 `$includeTicket` 参数（配额校验仍排除工单附件，暂存校验必须含）。
  - `api/upload.php` L206-L225 — 新增 `$assertStagingBudget()`：暂存占用（已入库的 writing / write_failed + 在途分块会话）加本次大小超过上限即拒绝，错误码 `staging_limit_exceeded`。
  - `api/upload.php` L228、L256（`$assertCapacity`）与 L266、L278、L285、L633（`$assertTicketAttachmentCapacity`）— 接入暂存校验，并把工单附件计数口径换成 `UPLOAD_CAPACITY_STATUSES`。
  - `api/upload.php` L692-L756 — finish 重写：不再同步调用 `writeNotionReplicas()`，改为「锁用户行 → 重名/配额 → INSERT uploads(status=writing, notion_* 占位) → `$queue->stage()` 移入暂存 → commit」；失败时 `discard()` 清理已落位的暂存文件。
  - `api/upload.php` L787-L809 — 移除 finish 内的同步内容审核整段（已迁入队列），返回文案改为「正在写入存储」，并以 `jsonResponseThen()` 在断连后立即 `$queue->process($uploadId)`；`Verdict` 导入随之删除。
  - `api/file.php` L14、L21-L25 — 引入 `NotionWriteQueue`，文件查询状态由 `status = 'active'` 放宽为 `UPLOAD_VISIBLE_STATUSES`。
  - `api/file.php` L60-L114 — 新增 `$stagingPending` 判定与 `$streamLocalFile()`：直接流式输出服务器暂存文件（支持 Range，便于音视频预览拖动进度），暂存文件不存在时回退到 Notion 副本分支。
  - `api/file.php` L156-L182 — 把文件名 / 响应头计算提前，并在副本分支之前插入暂存文件直出，使「正在写入存储」的文件也能下载与预览。
  - `api/file.php` L358-L370 — 删除文件时若尚未写入存储，一并 `discard()` 掉暂存文件（后台队列因状态不符会放弃写入并把已写副本归档）。
  - `api/file.php` L372-L381 — 新增 `action=retry_write`：「写入失败」的文件由本人或管理员重新提交，置回 `writing` 后在后台重试。
  - `api/folder.php` L11、L58-L60 — 移动文件的状态过滤放宽为可见状态；L95-L110 — 文件夹递归删除的状态集合换成 `UPLOAD_CAPACITY_STATUSES`，并为其中的暂存文件 `discard()` 暂存副本。
  - `dashboard.php` L12、L110-L112、L119-L123 — 引入队列类，列表与计数改用 `UPLOAD_VISIBLE_STATUSES`，并查出 `status` 列。
  - `dashboard.php` L325-L344 — 状态徽章与说明：`writing` 显示「正在写入存储」角标与「正在写入存储，完成后自动转为 Notion 副本」；`write_failed` 显示红色「写入失败」角标并追加「重试写入」按钮。预览 / 下载 / 重命名 / 移动 / 删除在新状态下均照常可用。
  - `dashboard.php` L409-L414 — 页面输出完毕后调用 `finishResponseThen()` 触发 `NotionWriteQueue::sweep()` 懒清扫（不占用页面加载时间）。
  - `assets/app.css` L764-L766、L819-L820 — 新增 `.write-badge` / `.write-badge-failed` 及暗色模式配色。
  - `assets/app.js` L1086-L1087、L1096-L1101、L1122-L1128 — 队列文案改为「正在写入存储」/「已提交，正在写入存储」，移除已不再返回的 `moderation` 结果分支与 `reviewing` 计数（`held` 仍用于 `start` 阶段关键词拦截）；L459-L462、L473-L477 — 新增 `retry-write` 动作映射与成功后刷新页面（不再移除该行）。
  - `admin.php` L771-L786 — 「上传与容量」保存新增 `staging_limit_mb` 校验与写入；L1147-L1148 读取该配置；L1601 面板新增「待写入暂存上限（MB）」输入项（默认 1024，0 表示不限）。
  - `admin.php` L1108、L1132 — 后台用户列表的文件数与用户文件明细改用可见状态，避免与「我的文件」显示不一致。
  - `src/Tickets/TicketService.php` L9、L661-L665 — 工单附件查询改用 `UPLOAD_CAPACITY_STATUSES` 并查出 `status`；L1078-L1083 — 关闭工单清理附件时同步 `discard()` 尚未写入存储的附件暂存文件。
  - `.gitignore` L5 — 忽略 `/storage/staging/`（运行时暂存，不入库）。
  - 资源版本号 `assets/app.css?v=60 → ?v=61`（共 12 处）：`dashboard.php` L135、`account.php` L193、`admin.php` L1266、`admin_orders.php` L264、`bans.php` L137、`forgot.php` L76、`index.php` L62、`install.php` L294、`logs.php` L65、`pay.php` L134、`register.php` L141、`tickets.php` L220。
- 数据库结构影响：`uploads` 新增 `write_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0` 与索引 `idx_uploads_status_id`；`status` 列本就是 `VARCHAR(20)`，新状态 `writing` / `write_failed` 无需改类型。旧库由 `Schema::migrate()` 自动补列（`schema_version` 21 → 22），**无需手工执行，也不会清库**。
- 备注：未提交的两项——多账户副本仍串行写、页面访问触发的懒清扫在非 FPM 环境不生效（会退化为「页面加载完才跑」，此时仅上传请求自身的秒回仍有效）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 13:09:30] Notion 写入加速：分片大小提到 20 MB 并改为并发上传

- 目的：用户反馈写入 Notion 时间长。根因是 `NotionClient::sendParts()` 逐片串行——每个分片一次 HTTPS POST，发完一片才发下一片，且每次新建连接（重复 TCP / TLS 握手）；1 GB 文件 = 100 次串行往返，往返延迟被完全累加。Notion 官方允许分片并发、乱序上传（只要在 complete 之前把 1..N 发完即可），故改为固定并发窗口发送；同时把分片大小由 10 MB 提到官方上限 20 MB，请求数直接减半。
- 验证：`php -l src/NotionClient.php` → `No syntax errors detected`（退出码 0）；`php -r` 核对用到的常量与函数均存在（`CURLM_CALL_MULTI_PERFORM`、`CURL_LOCK_DATA_CONNECT`、`CURLSHOPT_SHARE`、`CURLOPT_SHARE`、`CURLE_PARTIAL_FILE`、`curl_share_init`、`curl_multi_getcontent`、`spl_object_id` 全部 ok）。遵守「本机禁止功能 / HTTP 测试」约定，未做实际上传验证。逻辑自查：源文件流仅在 `spoolPart()` 中按分片号顺序前进，重试复用同一临时文件而不重新切片，避免重试读错字节（初版曾误在重试前删除临时文件，已修正）；临时文件占用上界为并发数 × 分片大小（4 × 20 MB）。
- 改动明细（行号为改动后当前行号）：
  - `src/NotionClient.php` L15 — `PART_SIZE` 10 MB → 20 MB（Notion 允许区间 5~20 MB，取上限以最小化请求数）。
  - `src/NotionClient.php` L17 — 新增 `UPLOAD_CONCURRENCY = 4` 并发窗口（并发过高会触发 429）。
  - `src/NotionClient.php` L19、L25-L35 — 新增 `MAX_UPLOAD_ATTEMPTS = 3` 与 `transientCurlErrors()`；后者用方法而非类常量，避免未安装 curl 扩展时在类声明阶段就因常量未定义致命报错（保留构造函数的友好提示）。
  - `src/NotionClient.php` L274-L368 — 重写 `sendParts()` 为 `curl_multi` 并发窗口：`curl_share` 共享连接缓存（省掉每片重复的 TCP / TLS 握手）、固定并发 4、完成一片立即补位；429 / 5xx / 网络瞬时错误按 `Retry-After` 或退避重发，重试复用原临时文件。
  - `src/NotionClient.php` L370-L393 — 新增 `spoolPart()`：按 `PART_SIZE` 从源流切出分片到独立临时文件（`CURLFile` 磁盘流式发送，不占内存）。
  - `src/NotionClient.php` L396-L431 — 新增 `initPartHandle()`：分片句柄选项（含 `CURLOPT_SHARE` 与每句柄独立的 `Retry-After` 记录）。
  - `src/NotionClient.php` L433-L471 — 新增 `partOutcome()`：把分片结果归类为成功 / 可重试（含等待时间）/ 不可重试。
  - `src/NotionClient.php` L247 — `sendFile()` 移除仅供分片使用的 `?int $part` 参数（单分片路径不再传，`part_number` 字段由 `initPartHandle()` 负责）。
  - `src/NotionClient.php` 原 L289-L296 删除 `execute()` 内的局部 `$transientErrors` 数组，改用 `transientCurlErrors()`。
- 数据库结构影响：**无**（仅 Notion 请求策略变更）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 11:51:40] 新增 .user.ini 提高 PHP 上传限制，缓解大文件上传过慢

- 目的：用户反馈上传文件很慢。查明上传是「浏览器逐块串行提交」（`assets/app.js` L755-L760 的 `while (index < total)` 依次 `await sendChunk`），单块大小由 `uploadChunkSizeBytes()` 取「后台配置的分块大小」与 `upload_max_filesize` / `post_max_size` 的较小值（`src/helpers.php` L553-L568）。服务器沿用 PHP 默认限制（常见 2M / 8M）时单块被压到 2 MB 以下，1 GB 文件要串行发出 500 多个请求，耗时被请求往返放大。故新增 `.user.ini` 提高限制，使后台设置的分块大小不再被 ini 收敛。
- 验证：本改动无 PHP 逻辑变更（新增 ini 与文档），未做 `php -l`。核对 `.htaccess` L13-L15 已有 `<FilesMatch "^\."> Require all denied`，`.user.ini` 不会被外部读取。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证；`upload_max_filesize` 实际生效值可在后台「上传与容量」面板（`admin.php` L1588）查看。备注：PHP 内置服务器与 Apache mod_php 不读取 `.user.ini`，届时后台面板仍显示旧值，需改服务器 `php.ini`。
- 改动明细（行号为改动后当前行号）：
  - `.user.ini` L1-L12 — 新增：`upload_max_filesize = 5120M`、`post_max_size = 5120M`、`max_execution_time = 1800`、`max_input_time = 1800`、`memory_limit = 256M`，取值与 README「部署」示例一致；改动后需等 `user_ini.cache_ttl`（默认 300 秒）到期或重启 PHP 才生效。
  - `README.md` L51 — 「部署」第 3 步补充：说明仓库根目录已附带 `.user.ini`、哪些 SAPI 会读取，并提示后台分块大小默认 10 MB 可按需调大。
- 数据库结构影响：**无**（仅 PHP 运行配置与文档）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 11:51:23] 工单对话署名统一为「身份 · 用户名 · 时间」

- 目的：工单对话里管理员消息只显示「管理员」+ 时间，看不出是哪位管理员回复的；用户消息则只显示用户名，双方格式不一致。统一为「身份 · 用户名 · 时间」，其中管理员的用户名取自 `ticket_messages.author_id` 关联的 `users.username`（`src/Tickets/TicketService.php` L637-L649 的 `messages()` 已 `LEFT JOIN users`，无需改查询）。
- 验证：`php -l tickets.php` → `No syntax errors detected`（退出码 0）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。无 CSS / JS 改动，故未调整资源版本号。
- 改动明细（行号为改动后当前行号）：
  - `tickets.php` L441-L449 — 消息头由「管理员消息只显示『管理员』、用户消息只显示用户名」改为统一输出 `管理员|用户 · 用户名 · Y-m-d H:i`；用户名缺失（作者已删除）时回退为身份标签。
- 数据库结构影响：**无**（复用既有 `ticket_messages.author_id` → `users.username` 关联）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 11:27:52] 修复移动文件报「Unexpected token '<'」及非 JSON 响应的异常提示

- 目的：用户反馈点击文件「移动」后 toast 报 `Unexpected token '<', "<html> <h"... is not valid JSON`。根因是前后端参数契约不一致：`assets/app.js` 的文件操作分支用通用 `body = new URLSearchParams({ id, action })` 提交，`move` 分支只补了 `folder_id`，而 `api/folder.php` 的 `move_file` 分支读的是 `$_POST['file_id']`，导致 `$fileId` 恒为 `0`，恒命中「文件不存在或无权访问」返回 **404 JSON**；部署服务器又把 PHP 的 4xx/5xx 响应体替换成 HTML 错误页（形如 `<html>\r\n<head>...`），前端 `response.json()` 直接抛 `Unexpected token '<'`。①修正前端字段名：`move` 分支删除通用 `id`、改发 `file_id`；②新增 `readJson()` 统一解析响应，服务端返回非 JSON（HTML 错误页 / 网关页）时转成带 HTTP 状态码的可读提示，避免用户再看到无从理解的报错。
- 验证：`node --check assets/app.js` → 退出码 0（语法通过）；`grep` 确认操作型 `await response.json()` 已全部替换为 `await readJson(response)`（`app.js` L440 / L468 / L507 / L530 / L557），仅 `findDuplicates` 的 L929 保留（其外层已有 try/catch 兜底，返回空集合）；`grep` 确认 `app.js?v=30` 已无残留（10 处均为 `?v=31`）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.js` L32-L41 — 新增 `readJson(response)` 帮助函数：`JSON.parse(await response.text())`，解析失败时抛「服务器响应异常（HTTP <状态码>），请稍后重试；若持续出现请联系管理员。」。
  - `assets/app.js` L446-L456 — `move` 分支：`body.delete('id')`、`body.set('file_id', id)`，使提交字段与 `api/folder.php` 的 `move_file` 契约一致（`file_id` 指文件、`folder_id` 指目标文件夹）。
  - `assets/app.js` L440、L468、L507、L530、L557 — 文件重命名、文件操作（移动/删除）、文件夹重命名、文件夹删除、新建文件夹五处 `const data = await response.json()` 改为 `const data = await readJson(response)`。
  - 资源版本号 `assets/app.js?v=30 → ?v=31`（共 10 处）：`account.php` L385、`admin.php` L1885、`admin_orders.php` L354、`dashboard.php` L396、`forgot.php` L113、`index.php` L88、`logs.php` L125、`pay.php` L257、`register.php` L185、`tickets.php` L588。
- 数据库结构影响：**无**（仅修正请求字段名与响应解析，不改动表/列）。
- 备注：用户所述「文件夹打不开、点不动」在代码层未发现成因——文件夹入口为普通 `<a href="dashboard.php?folder=...">` 纯链接，无 JS/CSS 阻断（`.folder-link` 无 `pointer-events`/`z-index` 遮挡，所有 `preventDefault` 均不命中该链接）。若仍复现，需用户提供浏览器 Network 面板的实际请求 URL 与状态码以便进一步定位。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 10:40:04] 密码最小长度由 10 位放宽到 6 位

- 目的：将全站密码最小长度限制从 10 个字符下调为 6 个字符，覆盖服务端校验（`mb_strlen() < 10`）、前端 `minlength` 与界面提示文案三处，保证前后端阈值一致。
- 验证：`php -l` 对 5 个改动文件（`account.php` / `admin.php` / `forgot.php` / `install.php` / `register.php`）全部 `No syntax errors detected`；`grep` 确认仓库内 `minlength="10"`、`至少 10`、`至少需要 10`、`mb_strlen($password) < 10` 等无残留。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `account.php` — L100 服务端校验 `< 10 → < 6`、L101 提示文案；L355 面板说明文案；L359-L360 新密码与确认框 `minlength` 及占位符。
  - `admin.php` — L254 新增用户密码校验与 L255 文案；L298 编辑用户密码校验与 L299 文案；L1301 「添加用户」初始密码 `minlength`。
  - `forgot.php` — L38 服务端校验与 L39 文案；L103-L104 新密码与确认框 `minlength` 及占位符。
  - `install.php` — L56 管理员密码校验与 L57 文案；L345 安装向导密码框 `minlength` 与 `<small>` 提示。
  - `register.php` — L48 服务端校验与 L49 文案；L155-L156 密码与确认框 `minlength` 及占位符。
- 数据库结构影响：**无**（仅调整校验阈值，不改变存储与字段）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-04 10:36:37] 修复点击「保存」却弹出「删除用户」二次确认

- 目的：后台「用户与容量」每位用户是同一个表单，里面既有「保存」（`update_user`，无确认）又有「删除」（`delete_user`，带 `data-confirm="确认删除用户及其全部文件？"`）。`assets/app.js` 的 submit 兜底监听原先用「表单内任意 `[data-confirm]` 按钮」作为确认目标：点「保存」时 `event.submitter` 是保存按钮、不匹配 `data-confirm`，于是回退到 `form.querySelector('[data-confirm]')` 命中「删除」按钮，弹出的是删除确认，且确认后会以删除按钮为 trigger 提交（`action=delete_user`），即「保存用户时显示的是删除用户」，严重时误删用户。改为按「本次真正触发的按钮」判断：显式提交取 `event.submitter`，键盘隐式提交取表单默认按钮（第一个提交按钮），只有该按钮自身带 `data-confirm` 才弹确认。同一问题影响所有「保存 / 删除」共存的表单（如官方账户池），一并修复。
- 验证：`node --check assets/app.js` → 退出码 0（语法通过）；`php -l` 对 10 个改动页面（`account.php` / `admin.php` / `admin_orders.php` / `dashboard.php` / `forgot.php` / `index.php` / `logs.php` / `pay.php` / `register.php` / `tickets.php`）全部 `No syntax errors detected`；`grep` 确认 `app.js?v=29` 已无残留（10 处均为 `?v=30`）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.js` L593-L606 — submit 兜底监听重写：`trigger` 由原来的 `form.querySelector('[data-confirm]')` 回退改为 `event.submitter || form.querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]')`，并新增 `!trigger.matches('[data-confirm]')` 即放行；注释同步说明「必须用本次真正触发的按钮判断」的原因。
  - 资源版本号 `assets/app.js?v=29 → ?v=30`（共 10 处）：`account.php` L385、`admin.php` L1885、`admin_orders.php` L354、`dashboard.php` L396、`forgot.php` L113、`index.php` L88、`logs.php` L125、`pay.php` L257、`register.php` L185、`tickets.php` L588。
- 数据库结构影响：**无**。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-03 00:15:40] 修复隔离文件无出口、申诉工单不闭环、封禁到期不解封与告警只增不减

- 目的：全局功能性排查发现 4 组「有状态写入、无状态出口」的缺陷——① 被风控隔离（`uploads.status='quarantined'`）的文件没有任何恢复入口，管理员放行后文件仍不可用，只能让用户重传；② 申诉工单与审核记录是两套状态，记录被放行 / 打回 / 清除后对应工单仍挂起，长期不一致；③ 自动封禁到期的懒结算只在账户页（`trustSummary`）触发，其它页面读到的封禁状态滞后；④ 管理员告警表 `admin_alerts` 只增不减，且勾选「清除违规记录」后若用户此前已因累计违规被自动封禁，可信度回落到阈值以下仍不解封。
- 验证：`php -l` 对 `src/Moderation/ModerationService.php`、`src/Tickets/TicketService.php`、`src/Bans/BanService.php`、`src/helpers.php`、`admin.php`、`bans.php` 逐一执行，均 `No syntax errors detected`（退出码 0）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - 组一 · 隔离文件恢复可用：
    - `src/Moderation/ModerationService.php` L400-L406 — 新增 `restoreUpload(int $uploadId): void`：把 `status='quarantined'` 的文件改回 `active`。
    - `src/Moderation/ModerationService.php` L459-L491 — 重写 `allowResubmit()`：按 `upload_id` 分流——有原文件（`finish` 阶段 AI 判定违规、文件已隔离）时调用 `restoreUpload()` 恢复可用并把记录置 `cleared`（闭环、不再提示重传）；无文件（`start` 阶段关键词拦截、尚未落库）时保持 `resubmit` 由用户重传。返回文案区分两种结果。此前一律置 `resubmit`，会出现「文件已恢复可用、面板仍提示重传」的矛盾。
    - `admin.php` L1484 — 审核记录面板说明文字补充「放行时若原文件仍在会直接恢复可用……上传前拦截（无文件）的记录则由用户重传」。
    - `admin.php` L1526 — 复核按钮文字「放行重传」改为「放行」（`value="moderation_resubmit"` 不变），与「有文件即恢复、无文件才重传」的新语义一致。
  - 组二 · 申诉工单闭环：
    - `src/Tickets/TicketService.php` L718-L738 — 新增 `resolveAppealByRecord(int $recordId, int $adminId, string $message): bool`：按 `related_record_id` + `category='appeal'` 取最新工单，已结束则跳过；否则追加一条管理员回复后 `closeTicket()`。
    - `src/Moderation/ModerationService.php` L891-L898 — 新增私有 `resolveAppealTicket()`：包一层 try/catch 调用上述方法，失败只记日志、不阻断主流程。
    - `src/Moderation/ModerationService.php` L443（`reject()`）、L481-L483（`allowResubmit()`）、L536（`clearRecord()`）— 三处复核完成后各调用一次，同步回复并关闭申诉工单。
  - 组三 · 封禁到期闭环：
    - `src/Bans/BanService.php` L78-L101 — `ban()` 统一在写入封禁记录后更新 `users.violation_ban_until`（`AUTO_BAN_DAYS` 天后），使手动封禁与违规自动封禁口径一致，不再出现「手动封禁 = 永久封禁」；`violation_count` 快照改用 `currentScore()`，与账户页「可信度」同源。
    - `src/Bans/BanService.php` 原 L74-L82 删除 `violationCount()` 方法（改用 `currentScore()` 后无调用者）。
    - `src/Bans/BanService.php` 原 L297（`addViolation()` 内）、原 L437（`evaluateAutoBan()` 内）删除冗余的 `UPDATE users SET violation_ban_until` —— 已由 `ban()` 集中处理。
    - `src/helpers.php` L699-L701 — `userActiveBan()` 读状态前先调用 `BanService::expireDue()` 懒结算到期封禁；该方法被 dashboard / upload / folder / file 等多页面复用，从而扩大自动解封的触发点覆盖。
    - `bans.php` L193 — 注释改为「手动封禁与违规自动封禁一致，到点后访问本页会自动解封」；L210 — 手动封禁按钮 `data-confirm` 补「封禁将于 30 天后自动解除。」。
  - 组四 · 告警与计数口径：
    - `src/helpers.php` L1395-L1404 — `raiseAdminAlert()` 末尾追加告警保留期清理（已读告警保留 30 天、全部告警保留 180 天），懒清理、无 cron，失败只记日志。
    - `src/Bans/BanService.php` L129-L154 — 新增 `reconcileAutoBan(int $userId, ?int $adminId = null): bool`：仅当生效中封禁为自动封禁（`banned_by IS NULL`）且 `currentScore() < threshold()` 时解除，且**不重置违规周期**，避免「清一条记录就洗白全部违规」。
    - `src/Moderation/ModerationService.php` L538-L543 — `clearRecord()` 清除记录后调用 `reconcileAutoBan()`（try/catch 包裹），使自动封禁随可信度回落自动解除。
    - `src/Tickets/TicketService.php` L657-L669 — `attachments()` 查询条件由 `up.status = 'active'` 扩为 `up.status IN ('active', 'quarantined')`，使关闭工单时的 `purgeAttachments()` 能一并清理被隔离的工单附件（全仓仅 `purgeAttachments()` 调用本方法，无展示副作用）。
- 数据库结构影响：**无**（沿用既有 `uploads.status`、`user_bans`、`users.violation_ban_until` / `violation_cycle_start`、`admin_alerts`；旧库无需补列）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-02 23:40:24] 放行重传改为 SHA-256 白名单自动放行，重传成功自动闭环

- 目的：后台「重新上传」原实现只撤销黑名单指纹（`forgetHash`）而**未登记白名单**，用户重传同一文件时仍会重新跑 AI，可能再次被判违规并隔离，形成「放行 → 重传 → 再被拦」的死循环，记录也长期停留在「已放行重传」不闭环。现改为「管理员放行 = 认可该内容」：放行时把内容 SHA-256 登记为白名单，用户重传同一文件时指纹命中直接通过（不再重复审核）；并在重传上传成功且审核通过后，自动把该「已放行重传」记录置为「已清除违规」，用户面板的待处理提示随之消失。
- 验证：`php -l src/Moderation/ModerationService.php`、`php -l api/upload.php`、`php -l admin.php` 均 `No syntax errors detected`（退出码 0）；核对 `ModerationService::STATUS_CLEARED` 常量与 `BanService::currentScore()` 只统计 `status='rejected'`（`resubmit → cleared` 不影响违规计数与自动封禁）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `src/Moderation/ModerationService.php` L353-L362 — `forgetHash()` 重构：改为先经 `hasOtherRejectedHash()` 保护判断，命中即直接返回、不再删除全站指纹。
  - `src/Moderation/ModerationService.php` L364-L386 — 新增私有方法 `hasOtherRejectedHash(string $contentHash, int $exceptRecordId): bool`：查询是否仍有其它 `status='rejected'` 记录引用同一指纹，避免因单条记录的放行 / 清除而误解除对他人的全站拦截。
  - `src/Moderation/ModerationService.php` L472-L479 — 重写 `allowResubmit()` 的指纹处理段（`allowResubmit()` 整体见 10-03 条目 L459-L491）：放行时若无其它已打回记录引用该指纹，则 `forgetHash()` 撤销黑名单并 `rememberHash($contentHash, Verdict::APPROVED)` 登记白名单。
  - `src/Moderation/ModerationService.php` L499-L515 — 新增 `completeResubmit(int $userId, string $filename): int`：把该用户同名文件处于 `resubmit` 的记录批量置为 `cleared`，写 `review_note='用户已重新上传并通过审核，系统自动闭环。'`、`user_dismissed=0`，返回受影响行数。
  - `api/upload.php` L793-L794 — 内容指纹命中白名单（`$known === Verdict::APPROVED`）分支内 `Logger::info` 后新增 `$moderationService->completeResubmit((int) $user['id'], (string) $meta['filename']);`。
  - `api/upload.php` L863-L864 — 上传后识别「审核通过」的 `else` 分支内（`isScanned` 登记白名单之后）新增同一 `completeResubmit()` 调用。判定违规 / 待人工复核分支不调用，避免未通过审核就闭环。
  - `admin.php` L1484 — 审核记录面板说明文字改为「重新上传会把该内容登记白名单，用户重传同一文件时按 SHA-256 自动放行，上传成功后记录自动闭环。」（10-03 条目又据「放行即恢复可用」语义二次调整）。
  - `admin.php` L1526 — 复核按钮文字「重新上传」改为「放行重传」，与状态徽章「已放行重传」及新语义一致（实际由用户重传，非管理员上传）。
- 数据库结构影响：**无**（沿用既有 `moderation_records.status` / `content_hash`、`moderation_hashes` 表；旧库无需补列）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-02 21:30:09] 修复重传哈希锚定校验的凭证生命周期与容错问题

- 目的：对上一版「放行重传哈希锚定校验」自审后修复以下问题。①严重：放行凭证用后不消费，`allowResubmit()` 写入的 `status=resubmit` 记录永不改变（`clearRecord()` 仅允许清 `rejected`），使该文件名被永久锁定为原哈希；且 `resubmitAnchorHash()` 用 `ORDER BY id DESC LIMIT 1` 只认最新一条，日后同名新内容（含合法修改）会被误拒 `resubmit_content_mismatch`。②中：新校验块位于 finish 阶段既有 try/catch 之外，异常会冒泡致 500，违反项目「风控不可用不阻断正常上传」的 fail-open 约定；且未按风控开关 / 用户豁免门控。③潜在：最新一条 resubmit 记录 `content_hash` 为空（start 阶段关键词拦截记录）时直接返回 `null`，把更早的有效锚定一并跳过，形成免校验窗口。④性能：同一文件在锚定校验与内容审核处各做一次全量 SHA-256（可达 5 GB）。⑤冗余：每次 drive 上传多一次 `hasResubmitPass` 查询。
- 验证：`php -l src/Moderation/ModerationService.php`、`php -l api/upload.php`、`php -l admin.php` 均通过（`No syntax errors detected`）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。逻辑自查：合法重传锚定通过后 `consumeResubmitPass()` 把该文件名全部 `resubmit` 记录置为 `consumed`，`resubmitAnchorHash()` / `hasResubmitPass()` 均只认 `resubmit`，因此放行与锚定同时撤销；`userNotices()` 只取 `open` / `resubmit`，`consumed` 记录不再出现在用户面板，`dashboard.php` 亦只对 `resubmit` 渲染特例，无需改动。
- 改动明细（行号为改动后当前行号）：
  - `src/Moderation/ModerationService.php` L26 — 新增状态常量 `STATUS_CONSUMED = 'consumed'`。
  - `src/Moderation/ModerationService.php` L489-L508 — `resubmitAnchorHash()` 查询加 `AND content_hash <> ''`，只取最新**非空**指纹，消除「最新一条为空则跳过更早有效锚定」的免校验窗口；doc 注释同步说明。
  - `src/Moderation/ModerationService.php` L511-L532 — 新增 `consumeResubmitPass(int $userId, string $filename): int`：把该用户该文件名的 `resubmit` 记录批量置为 `consumed`，撤销放行与锚定，返回消费记录数。
  - `api/upload.php` L639-L664 — 锚定校验块重写：加 `scope=drive && !$moderationExempt` 与 `enabled()` 门控、整段包 try/catch（异常记 `Logger::error` 后放行，fail-open）、去掉冗余的 `hasResubmitPass` 调用、把算出的哈希存入 `$contentHash` 供后续复用、新增 `$matchedResubmitAnchor` 标记。
  - `api/upload.php` L779-L784 — 内容审核处复用已算出的 `$contentHash`（为空时才重算），避免对同一文件重复全量哈希；L787 起后续 `lookupHash` / `inspect` / `rememberHash` 沿用同一哈希。
  - `api/upload.php` L756-L764 — 成功落库后（`$message = match` 之前）新增重传凭证一次性消费：`$matchedResubmitAnchor` 为真时调用 `consumeResubmitPass()`，异常记 `Logger::warning` 不阻断。
  - `admin.php` L1506 — 后台审核列表状态标签新增 `ModerationService::STATUS_CONSUMED => ['label' => '已重传', 'class' => 'closed']`，避免新状态落入 default 分支误显示「待复核」。
- 数据库结构影响：**无**（新状态值 `consumed` 复用现有 `moderation_records.status VARCHAR(20) NOT NULL DEFAULT 'open'`，无需手工补列）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-02 21:23:28] 放行重传增加内容哈希锚定校验，防止借「同名不同内容」上传真违规文件

- 目的：管理员「允许重传」误判文件后，原逻辑仅按 `用户 + 文件名 + status=resubmit` 放行（`hasResubmitPass`），且 `allowResubmit()` 会 `forgetHash()` 撤销内容指纹黑名单。攻击者可借这一「重传通行证」上传**同名但内容完全不同**的真违规文件：上传前关键词拦截被跳过，上传后若新内容不在 AI 审核载荷范围内（如图片 / 文本之外的压缩包、音视频）则可能完全躲过审核。改为在重传完成时校验新文件 SHA-256 与放行记录中的原文件 `content_hash` 一致，不一致则拒绝上传。
- 验证：`php -l api/upload.php`、`php -l src/Moderation/ModerationService.php` 均通过（`No syntax errors detected`）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。逻辑自查：`resubmitAnchorHash` 对 `content_hash` 为空（start 阶段关键词拦截记录，无内容指纹）返回 `null`，此类放行重传不校验、维持原行为；有锚定哈希且实际哈希为空或不一致时以 `hash_equals` 恒定时间比对后拒绝。
- 改动明细（行号为改动后当前行号）：
  - `src/Moderation/ModerationService.php` L485-L507 — 新增 `resubmitAnchorHash(int $userId, string $filename): ?string`：取该用户该文件名最新一条 `status=resubmit` 记录的 `content_hash`，经 `normalizeHash()` 归一化后返回非空值，否则返回 `null`（无锚定）。
  - `api/upload.php` L639-L655 — finish 阶段在 `writeNotionReplicas()` 双写之前新增内容锚定校验：`scope=drive` 且有 `hasResubmitPass` 时，取 `resubmitAnchorHash`，用 `hash_equals` 比对拼接完成文件的 SHA-256，不一致（或哈希计算失败）即 `$fail('resubmit_content_mismatch', …)` 拒绝上传，并记录 `anchor_sha256` / `actual_sha256` 到日志上下文。
- 数据库结构影响：**无**（复用现有 `moderation_records.content_hash` 列）。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-01 13:12:20] 侧边栏导航图标由 emoji / 符号字形改为内联 SVG

- 目的：侧边栏原来用 `▣ ✉ ⚙ ¥ ⛔ ☰ ◍ ★` 等字符充当图标，这些字形依赖系统字体与 emoji 字体，在部分浏览器 / 操作系统（尤其是 Linux 服务器截图、无 emoji 字体的环境）会渲染成方块或彩色 emoji，与站点配色不一致。改为内联描边 SVG 后由 `currentColor` 上色（跟随链接文字颜色，含 hover / active 态），不再依赖任何字体，各平台渲染一致；同时折叠分组的箭头 `▾` 也改为纯 CSS 绘制的 chevron，使侧边栏彻底无字形依赖。
- 验证：`php -l` 对仓库全部 `*.php` 执行，结果 `ALL PHP FILES: syntax OK`；`grep` 确认 `*.php` 内已无 `app.css?v=59` 残留（12 处均为 `?v=60`），亦无 `<span>▣</span>` 等旧图标残留。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `src/helpers.php` L124-L143 — 新增 `navIcon(string $name): string`，用名称表返回 8 个内联 SVG（`files` / `tickets` / `admin` / `orders` / `bans` / `logs` / `account` / `premium`），输出 `<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ...>`，未知名称返回空串。
  - `dashboard.php` L140-L144、`account.php` L198-L202、`pay.php` L139-L143、`tickets.php` L225-L229 — 侧边栏 `<span>▣</span>` 等逐个替换为 `<?= navIcon('files') ?>` 等调用。
  - `admin.php` L1270 — 单行导航里 link 与 `<summary>` 两处 `⚙` 均替换为 `navIcon('admin')`。
  - `admin_orders.php` L268、`bans.php` L141、`logs.php` L69 — 同为单行导航，替换 `▣ ✉ ⚙ ¥ ⛔ ☰ ◍`。
  - `assets/app.css` L204-L205 — 新增 `.drive-nav a span.nav-icon svg, .nav-group > summary span.nav-icon svg { display: block; margin: 0 auto; }`，让 SVG 在原有 20px 宽度占位内居中（保留 `<span>` 外壳以复用现有定位与移动端隐藏规则）。
  - `assets/app.css` L210-L211 — 折叠箭头原 `content: '▾'` 改为纯 CSS chevron（`content: ''` + `border-right` / `border-bottom` 旋转 45°），关闭态由 `rotate(-90deg)` 改为 `rotate(-45deg)`（指向右），方向与原来一致。
  - 资源版本号 `assets/app.css?v=59 → ?v=60`：`dashboard.php` L134、`account.php` L193、`admin.php` L1266、`admin_orders.php` L264、`bans.php` L137、`logs.php` L65、`pay.php` L134、`tickets.php` L220、`index.php` L62、`register.php` L141、`forgot.php` L76、`install.php` L292（共 12 处）。项目惯例为「改 CSS 即全站同步 +1」，避免浏览器与 CDN 复用旧样式。
- 数据库结构影响：**无**。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-10-01 10:54:43] 修复 Linux 部署后子目录命名空间类无法自动加载（空间巡检抛 Class not found、tickets.php 500）
- 目的：自动加载器用原始反斜杠拼路径（`__DIR__ . '/' . substr($class, ...) . '.php'`），在 Windows 上 `\` 恰好是目录分隔符所以一直正常，但 Linux 下 `\` 是普通字符，`is_file()` 恒为 false，导致 `src/` 子目录里的全部类（`Space\SpacePatrol`、`Tickets\TicketService`、`Bans\BanService`、`Moderation\*`、`Restore\RestoreService`）都加载不到：表现为运行日志刷 `Class "NotionDrive\Space\SpacePatrol" not found`、`tickets.php` 直接 500（其调 `TicketService` 且无 try/catch）、而根目录类（`Auth`/`Database`/`Schema`）仍正常，故登录与安装看似无恙。
- 验证：`php -l src/bootstrap.php` 通过（`No syntax errors detected`）。遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 验证。
- 改动明细（行号为改动后当前行号）：
  - `src/bootstrap.php` L26-L28 — 自动加载器新增 `$relative = str_replace('\\', '/', substr($class, strlen($prefix)));`，把命名空间分隔符规范为目录分隔符后再拼路径（Windows 同样兼容）；原 L26 的 `$path` 单行拼接删除。
- 数据库结构影响：**无**。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-10-01 10:10:51] 新增根目录高分辨率站点图标 favicon.png / favicon.ico

- 目的：为浏览器对 `/favicon.ico` 的默认请求及需要位图图标的场景，提供与内置 `assets/favicon.svg` 图案一致的高分辨率图标。
- 改动：新增 `favicon.png`（512×512 PNG，真彩+透明）；新增 `favicon.ico`（含 16/24/32/48/64/128/256 七个尺寸，32 位带 Alpha，PNG 压缩条目）。图案与内置 SVG 一致（深色圆角底 + 绿→蓝渐变「N」），以 2048px 母版超采样栅格化生成。
- 验证：`getimagesize()` 确认 `favicon.png` 为 512×512、mime 为 `image/png`；校验 `favicon.ico` 头 `type=1`、`count=7`，各条目尺寸 / 位深 / 偏移与 PNG 签名均正确。
- 数据库结构影响：无。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-30 21:00:49] 全量安全与健壮性排查修复（出厂重置 / 审核指纹 / 上传配额 / 迁移幂等 / 邮箱枚举等 10 项）

- 目的：对整个项目做一轮全量排查后，逐项修复「开源之后会被用户遇到的问题」，涵盖功能性缺陷、并发竞态与安全边界。共 10 处：①恢复出厂设置后无法重装；②审核白名单可被「文件名指纹」投毒；③工单附件绕过单文件 / 数量限制；④分块上传不预留在途容量（并发可超配额）；⑤自动判定违规即写入全站黑名单（误伤面过大）；⑥Notion 空间自检把瞬时错误误判为页面缺失并重建；⑦`bans.php` 服务端未拦截对管理员的处置；⑧注册 / 绑定邮箱可被枚举；⑨`Schema` 申诉迁移非幂等（重跑重复建单）；⑩`clientIp()` 在反向代理后取不到真实客户端 IP，另有后台告警「先查后插」竞态。
- 验证：对仓库全部 `*.php` 执行 `php -l`，结果 `Total failures: 0`（含本轮改动的全部文件）。本轮仅静态改动，遵守「本机禁止功能 / HTTP 测试」约定，未做 HTTP 或浏览器验证。数据库结构影响：**无**（`Schema::CURRENT_VERSION` 未变，不新增 / 修改任何表或列；旧库无需手工补列）。
- 改动明细（行号为改动后当前行号）：
  - `src/Restore/RestoreService.php` L117-L122 — `factoryReset()` 清库、删 `installed.lock` 后，追加删除 `config/database.php`：只删锁会让 `install.php` 因配置文件仍在而「补锁自愈」，安装向导不可达；一并删除才真正回到未安装状态。
  - `src/Moderation/ModerationService.php` L223-L228 — `isScanned()` 由「命中任意已有指纹即视为已扫描」收窄为仅认 `filename+` 前缀的指纹，避免攻击者把含违规关键词的文件名指纹登记进白名单后，同名文件（或其改名变体）整体免检。
  - `api/upload.php` L236-L259、L425、L607 — 新增 `$ticketAttachmentLimit = 20` 与 `$assertTicketAttachmentCapacity()`：工单附件虽不计入个人配额，但仍受单文件上限与「单张工单最多 20 个附件」约束，堵住借工单无限上传 / 囤积超大文件的绕过路径。
  - `api/upload.php` L181-L204、L206-L234 — 新增 `$pendingUploadBytes()` 统计当前用户尚未落库的分块会话占用（按会话声明大小、排除工单 scope），并在 `$assertCapacity()` 中计入：否则并发开启多个大文件上传时每个会话都只按「已落库用量」判定，合计可远超个人容量。`finish` 调用处排除本次会话自身（L607 附近注释说明），避免把本文件重复计入在途占用。
  - `api/upload.php` L770 — 自动审核判定 `flagged` 时不再直接调用「写入全站黑名单」，仅 `quarantine($uploadId)` 隔离当前文件；黑名单改由人工打回时写入（见 `ModerationService::reject()`），避免误判即全站封禁同内容。
  - `src/NotionSpaceRepair.php` L141-L160 — `state()` 仅当 `NotionApiException::status() === 404` 才返回 `missing`，鉴权失败 / 网络中断 / 5xx 等瞬时错误改为向上抛出（由 `repair()` 的 `catch (Throwable)` 记为 failed）：否则会把临时故障误判为页面缺失并重建，覆盖 `notion_page_id`、凭空多建空间页面并孤立旧文件块。
  - `bans.php` L56-L73、L76、L101 — 新增 `$assertDisposable()` 并在 `ban_user` / `add_violation` 分支入口调用：拒绝把当前账号或管理员作为处置对象。页面按钮已隐藏这些入口，但服务端必须同样拦截，否则可伪造请求封禁管理员。
  - `register.php` L66-L76 — 邮箱验证码 `Verification::check()` 移到「邮箱占用查询」之前：反之未认证者可提交任意邮箱，从「已被占用 / 验证码错误」的提示差异枚举已注册邮箱；先过验证码则只有真正持有该邮箱者才知道占用结果。
  - `account.php` L121-L130 — 同上，`bind_email` 分支把 `Verification::check($email, 'bind', $code)` 移到占用查询之前（「已绑定当前账号」这步保留在前，其不泄露他人信息）。
  - `src/Schema.php` L432-L446 — `migrateAppealsToTickets()` 的取数语句追加 `AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.category = 'appeal' AND t.related_record_id = moderation_records.id)`：迁移无事务且失败不推进 `schema_version`，整段可能被重跑，加判重后已建的申诉工单不再重复建单 / 重复留言。
  - `src/helpers.php` L871-L900 — `clientIp()` 在「直连对端为受信任内网 / 本机地址」时改读 `X-Forwarded-For`，从右往左（代理自右侧追加）跳过内网 / 保留地址取第一个公网地址；否则反向代理后所有访客都算作同一代理 IP，按 IP 的登录限流与验证码限流形同虚设。公网直连仍只取 `REMOTE_ADDR`。
  - `src/helpers.php` L1335-L1362 — `raiseAdminAlert()` 由「先查未读同名告警、无则插入」改为条件插入 `INSERT ... SELECT ... WHERE NOT EXISTS`，用 `rowCount() > 0` 判定是否新告警并据此发信：消除并发请求各插一条导致的重复告警与重复邮件。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-30 20:40:01] 全站导航按角色文案一致性排查，修复工单页浏览器标题

- 目的：承接上一处「我的文件」页侧栏工单入口文案错误，对全部带侧栏导航的页面做一次同类问题排查。逐一比对了 `dashboard.php` / `tickets.php` / `account.php` / `pay.php` / `admin.php` / `admin_orders.php` / `bans.php` / `logs.php` 的导航结构：普通用户可见项、管理员专属项、`会员套餐` 的按角色显隐、各项 `active` 态、`管理后台` 桌面折叠组（`.nav-group`）与移动端链接（`.nav-admin-link`，见 `assets/app.css` L214 / L597-L599）的切换均一致，无其他偏差。仅发现 `tickets.php` 的浏览器标签 `<title>` 仍把工单入口写死为「我的工单」，与页面内按角色显示的侧栏、页头「工单管理」不一致，予以修复。
- 验证：`php -l tickets.php` 结果 `No syntax errors detected`。改动沿用同页页头既有表达式 `$isAdmin ? '工单管理' : '我的工单'`（`$isAdmin` 已于文件 L13 定义，早于 `<head>`），管理员无工单时标签页显示「工单管理」，查看工单详情时仍为「工单详情」；遵守「本机禁止功能 / HTTP 测试」约定，未做浏览器验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `tickets.php` L220 — 浏览器标题由 `siteTitleTag($ticket === null ? '我的工单' : '工单详情')` 改为 `siteTitleTag($ticket === null ? ($isAdmin ? '工单管理' : '我的工单') : '工单详情')`，与同页页头（L244）保持一致。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-30 20:33:57] 修复「我的文件」页侧栏工单入口在管理员态文案错误

- 目的：管理员登录后进入「我的文件」（`dashboard.php`）页面，左侧导航的工单入口固定显示「我的工单」，应为「工单管理」。同一入口在 `account.php` / `pay.php` / `tickets.php` 均已按角色区分文案（管理员「工单管理」/ 普通用户「我的工单」），仅 `dashboard.php` 遗漏了条件判断，属开源后可被任意管理员复现的文案不一致问题。
- 验证：`php -l dashboard.php` 结果 `No syntax errors detected`。改动仅将写死文案替换为既有的 `isAdmin($user) ? '工单管理' : '我的工单'` 表达式，与其余三处入口保持一致；遵守「本机禁止功能 / HTTP 测试」约定，未做浏览器验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `dashboard.php` L141 — 侧栏工单入口文案由写死的「我的工单」改为 `<?= isAdmin($user) ? '工单管理' : '我的工单' ?>`，管理员态显示「工单管理」。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-30 20:33:03] 全站控件样式与规格统一，并新增暗色模式

- 目的：对全站输入框 / 按钮 / 选择框做一次完整的样式与规格排查，并逐项修复。此前控件规格各自为政——同一行内字号 `0.8 / 0.82 / 0.85rem` 混杂、圆角 `10 / 11 / 12px` 混用、多处以 `#fff` / `#fafbfd` 等硬编码浅色写死（无法被主题变量统一覆盖），同时缺失键盘焦点样式、缺 `[hidden]` 兜底、暗色系统偏好下配色失效。本轮以「一套控件规格」为目标：控件与按钮统一 44px 高、16px 字号、9px 圆角；卡片 / 面板 12px 圆角；弹窗 16px；胶囊 999px；并把可主题化的表面色（`--surface` / `--surface-soft` / `--line` / `--muted` / `--label` / `--control-text`）与各类 tint 色全部变量化，为暗色模式铺路。
- 验证：`grep` 确认 `*.php` 内已无 `app.css?v=58` 残留（12 处均为 `?v=59`）；对仓库全部 `*.php` 执行 `php -l`，结果 `ALL PHP FILES: syntax OK`。本轮为纯 CSS / 模板版本号改动，不涉及业务逻辑；遵守「本机禁止功能 / HTTP 测试」约定，未做浏览器视觉验证（暗色模式亦未做浏览器验证）。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.css` L18-L19 — `:root` 新增语义变量 `--label`（表单标签色）、`--control-text`（次级控件文字色），替换散落的 `#4c5562` / `#404854`。
  - `assets/app.css` L24 — `[hidden]` 兜底由局部提升为全局 `[hidden] { display: none !important; }`：组件一旦用类选择器设置 `display`，浏览器默认的 `[hidden]` 即失效，故需全局兜底。
  - `assets/app.css` L25、L31 — 新增 `html { color-scheme: light dark; }` 使原生表单控件跟随主题；新增全局键盘焦点样式 `a, button, summary, [tabindex]:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }`。
  - `assets/app.css` L64-L71 — 输入 / 选择 / 文本域背景统一为 `var(--surface)`；`input[type="file"]` 与 `::file-selector-button` 按控件高对齐（外框 `var(--control-h)`，内按钮 34px + 上下各 5px 内边距恰好等高）。
  - `assets/app.css` L125-L126 — `.ticket-form input` 收窄为 `:not([type="checkbox"]):not([type="radio"])`，工单表单控件统一高度 44px、内边距 12px。
  - `assets/app.css` L322、L325 — `.file-section-tools .inline-rename` 高度对齐 44px；`.file-actions .inline-form:not(.violation-form) input` 宽度限定 150px。
  - `assets/app.css` L337 — `.panel-title > a:not([class])`，避免无 class 标题链接样式误伤按钮类链接。
  - `assets/app.css` L348-L349 — `.moderation-review` 改用全局控件高度，删除移动端 40px 覆写。
  - `assets/app.css` L409 — `.inline-form` 增加 `align-items: stretch` 与 `gap: 8px`，修复同行控件高度 / 间距不齐。
  - `assets/app.css` L416-L418 — 验证码 `img` 与 `.captcha-refresh` 统一 `var(--control-h)` 等高，背景变量化。
  - `assets/app.css` L501-L537 — 套餐 / 来源 / 订单卡片统一 `var(--radius)` 圆角与 `var(--surface)` 背景；删除 `.secondary-button`、`.confirm-actions .confirm-danger`、`.pay-type-option`、`.source-option` 等多处 `font-size` 覆写（统一 16px）；`.plan-option input`（L509）改为绝对定位到卡片右上角并显示原生圆点（此前被隐藏且布局错位）。
  - `assets/app.css` L564-L573 — 移动端（≤760px）统一 `input / select / textarea / file` 为 16px（避免 iOS 聚焦缩放），并删除重复的 `[hidden]` 覆写与 `.moderation-review input` 覆写。
  - `assets/app.css` L743-L779 — 文件预览、公告弹窗相关浅色 tint 与圆角变量化（弹窗 16px、媒体 `var(--control-radius)`）。
  - `assets/app.css` L781-L830 — 新增暗色模式 `@media (prefers-color-scheme: dark)`：覆写 `:root` 深色变量，并对品牌标记、导航激活态、状态角标 / 胶囊、提示条、工单消息与状态、邀请卡、星级等浅色 tint 组件逐条给出深色配色。
  - 12 个页面 Link 资源版本号 `assets/app.css?v=58 → ?v=59`：`bans.php` L119、`admin.php` L1266、`account.php` L192、`tickets.php` L220、`admin_orders.php` L264、`install.php` L292、`index.php` L62、`forgot.php` L76、`dashboard.php` L134、`logs.php` L65、`register.php` L139、`pay.php` L134，无逻辑改动。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 22:25:41] 修复工单表单勾选框被撑满整行的问题

- 目的：上一轮把「匿名提交」勾选框的 `display: grid` 恢复为 `flex` 后，勾选框与文案已同行，但输入框本身仍被撑成整行宽（截图可见一个横跨整行的带边框空框）。根因是 `.ticket-form input`（L121）为所有 input 统一设置了 `width: 100%`、内边距与边框，checkbox 也被一并命中。
- 验证：`grep` 确认 `*.php` 内已无 `app.css?v=57` 残留（12 处均为 `?v=58`）。核对选择器：收窄后 `.ticket-form input:not([type="checkbox"]):not([type="radio"])` 仍覆盖文本 / 只读 input，`.star-rating-inputs input`（radio，绝对定位 + `opacity: 0`）与 `.checkbox input`（`flex: 0 0 auto`）不再被强制拉宽，勾选框恢复原生尺寸。纯 CSS 改动、不涉及业务逻辑；遵守「本机禁止功能 / HTTP 测试」约定，未做浏览器视觉验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.css` L121 — `.ticket-form input` 收窄为 `.ticket-form input:not([type="checkbox"]):not([type="radio"])`，不再把 checkbox / radio 拉成整行宽并附加边框与内边距。
  - `bans.php` L119、`admin.php` L1266、`account.php` L192、`tickets.php` L220、`admin_orders.php` L264、`install.php` L292、`index.php` L62、`forgot.php` L76、`dashboard.php` L134、`logs.php` L65、`register.php` L139、`pay.php` L134 — 静态资源缓存版本 `assets/app.css?v=57 → ?v=58`（12 个页面），无逻辑改动。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 22:19:42] 统一工单详情页提示条间距并修复评价表单匿名勾选框换行

- 目的：工单详情页各面板「内容 → 提示条/上传区」的上间距不一致——「工单对话」面板的结束提示与上方消息列表仅 8px，而「工单附件」面板的上传拖放区 / 结束提示为 16px，对比之下附件区显得更宽；统一为 12px（与全站 `.panel-title` 的 `margin-bottom: 12px` 节奏一致）。另修复工单评价表单底部「匿名提交」勾选框与文案被拆成两行的问题：根因是 `.ticket-form label`（优先级 0,1,1）覆盖了 `.checkbox`（0,1,0）的 `display: flex`，使勾选框 label 被强制为 `display: grid`，input 与文字各占一行。
- 验证：`grep` 确认 `*.php` 内已无 `app.css?v=56` 残留（12 处均为 `?v=57`）；`tickets.php` 走 `php -l` 语法检查通过。核对选择器优先级：收窄为 `.ticket-form > label` 后仍命中三处工单表单的直接子级 `<label>`（提交 / 回复 / 评价的评分与留言），而 `.checkbox-row > label.checkbox` 与 `.star-rating-inputs > label` 均非直接子级，不再被覆盖（后者的 input 绝对定位、文案 `.sr-only` 隐藏，由 grid 退化为 flex 子项无视觉影响）。纯 CSS / 类名改动、不涉及业务逻辑；遵守「本机禁止功能 / HTTP 测试」约定，未做浏览器视觉验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.css` L119 — `.ticket-form label` 收窄为 `.ticket-form > label`，不再覆盖 `.checkbox` 的 `display: flex`，修复「匿名提交」勾选框换行。
  - `assets/app.css` L266-L268 — 原 `.ticket-attach-notice { margin-top: 16px; }` 改为通用类 `.ticket-panel-notice { margin-top: 12px; }`；`#ticketAttachments` 上间距 `16px → 12px`，使「内容 → 上传区 / 提示条」间距统一为 12px。
  - `tickets.php` L460、L462 — 「工单对话」面板的两条结束提示（已过期 / 已关闭）增加 `ticket-panel-notice` 类名，与上方消息列表 `.ticket-thread`（`margin-bottom: 8px`）相邻时按外边距取大值得到 12px。
  - `tickets.php` L504 — 附件面板结束提示由 `ticket-attach-notice` 改为 `ticket-panel-notice`。
  - `tickets.php` L531 — 「重新开启工单」面板的失败原因提示同步加 `ticket-panel-notice` 类名（原间距已由 `.panel-title` 的 12px 给出，视觉不变，仅统一类名）。
  - `bans.php` L119、`admin.php` L1266、`account.php` L192、`tickets.php` L220、`admin_orders.php` L264、`install.php` L292、`index.php` L62、`forgot.php` L76、`dashboard.php` L134、`logs.php` L65、`register.php` L139、`pay.php` L134 — 静态资源缓存版本 `assets/app.css?v=56 → ?v=57`（12 个页面），无逻辑改动。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 22:14:30] 修复工单附件区与「已结束」提示条贴合问题

- 目的：工单详情页「工单附件」面板中，当用户无上传权限（非管理员查看已结束工单）时，分支渲染的「该工单已结束，无法继续上传附件。」提示条与上方附件列表之间没有间距，两者贴合在一起。对照上传分支 `#ticketAttachments`（拖放区带 16px 上间距）可知，是 else 分支的提示条缺少同等上间距。
- 验证：`grep` 确认 `*.php` 内已无 `app.css?v=55` 残留（12 处均为 `?v=56`）；`tickets.php` 走 `php -l` 语法检查通过。纯 CSS/类名改动、不涉及业务逻辑；遵守「本机禁止功能/HTTP 测试」约定，未做浏览器视觉验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.css` L268-L269 — 新增 `.ticket-attach-notice { margin-top: 16px; }`，使结束提示条与上方附件列表的间距与上传拖放区一致。
  - `tickets.php` L504 — 结束提示条 `<div class="alert warning">` 增加 `ticket-attach-notice` 类名；不改动 `#ticketAttachments` 结构（`app.js` 依赖其后代元素同时存在才初始化上传逻辑）。
  - `bans.php` L119、`admin.php` L1266、`account.php` L192、`tickets.php` L220、`admin_orders.php` L264、`install.php` L292、`index.php` L62、`forgot.php` L76、`dashboard.php` L134、`logs.php` L65、`register.php` L139、`pay.php` L134 — 静态资源缓存版本 `assets/app.css?v=55 → ?v=56`（12 个页面），无逻辑改动。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 22:10:58] 修复工单评价星级选择器点击后星星错位

- 目的：工单评价页（`tickets.php` L561-L571）的半星选择器点击后，金色前景星形「跑到上面」。根因是 `.ticket-form label span`（优先级 0,1,2）同时命中了前景星形 `<span class="star-rating-fg">`，把它的 `font-size` 从 `1.7rem` 覆盖为 `.78rem`、金色覆盖为灰色；该 span 绝对定位于 `top:0`，缩小后即贴顶显示。此外 `.star-rating` 作为 `label` 网格子项被默认 `justify-self: stretch` 拉满整列宽，使 `--pct` 百分比宽度与实际星形宽度错位，半星命中不准。
- 验证：核对选择器优先级与网格布局计算结果；`grep` 确认 `*.php` 内已无 `app.css?v=54` 残留（12 处均为 `?v=55`）。纯 CSS 改动、不涉及 PHP 逻辑，无需 `php -l`；遵守「本机禁止功能/HTTP 测试」约定，未做浏览器点击验证。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `assets/app.css` L120 — `.ticket-form label span` 收窄为 `.ticket-form > label > span`，不再命中星星前景 span；三处工单表单的说明 `<span>` 均为 `form > label > span` 直接子级，样式不受影响。
  - `assets/app.css` L663 — 移动端媒体查询内的同名选择器同步收窄。
  - `assets/app.css` L146 — `.star-rating` 增加 `justify-self: start`，使选择器收缩为内容宽度，`--pct` 与实际星形对齐。
  - `bans.php` L119、`admin.php` L1266、`account.php` L192、`tickets.php` L220、`admin_orders.php` L264、`install.php` L292、`index.php` L62、`forgot.php` L76、`dashboard.php` L134、`logs.php` L65、`register.php` L139、`pay.php` L134 — 静态资源缓存版本 `assets/app.css?v=54 → ?v=55`（12 个页面），无逻辑改动。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 22:00:54] 清理文档中的内部流程表述与失效链接

- 目的：上一轮更新日志改名后，文内仍残留少量内部流程性措辞，且贡献指南「文档分工」引用了已从仓库删除的文件，形成失效链接；统一清理为面向所有读者的中性表述。
- 验证：全文检索确认相关内部流程关键词无残留（正文中的「AI 内容风控」等产品功能描述保留）；`CONTRIBUTING.md` 内链均指向存在的文件。纯文档改动，不涉及 PHP 源码，无需 `php -l`。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `CHANGELOG.md` L42-L53 — 上一轮改名条目改写为中性表述。
  - `CHANGELOG.md` L148 — 删除一处内部复核方式的括注。
  - `CHANGELOG.md` L196 — 一处面向后续处理者的措辞改为中性说法。
  - `CONTRIBUTING.md` L102 — 删除指向已删除文件的失效链接行。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 21:56:36] 更新日志更名为 CHANGELOG.md，并统一文档表述

- 目的：原更新日志文件名带 `AI_` 前缀，且文中声明仅供内部使用，不符合开源仓库的通用约定；统一为通用更新日志 `CHANGELOG.md`，并明确本文件记录**所有**改动（人工与其它途径产生的改动都记在此处）。
- 验证：全文检索 `AI_CHANGELOG` → 0 匹配（正文中的「AI 内容风控」属产品功能描述，保留）；`git mv` 重命名，尽量保留文件历史。纯文档改动，不涉及 PHP 源码，无需 `php -l`。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `AI_CHANGELOG.md` → `CHANGELOG.md` — 用 `git mv` 重命名。
  - `CHANGELOG.md` L1 — 标题由「AI 更新日志」改为「更新日志」。
  - `CHANGELOG.md` L3-L4 — 删除面向内部使用的说明，改为「记录仓库历次改动」。
  - `CHANGELOG.md` L15 — 记录规范中的一处措辞改为「便于后续维护者判断」。
  - `CHANGELOG.md` L891-L894 — 历史条目内的文件名引用同步替换为 `CHANGELOG.md`。
  - `CONTRIBUTING.md` L66、L95 — 引用链接改为 `[CHANGELOG.md](CHANGELOG.md)`。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-29 21:54:40] 新增贡献指南 CONTRIBUTING.md 并优化 README（修正功能列表、目录结构与 MySQL/MariaDB 表述等过时内容）

- 目的：为开源后引入外部贡献者做规范准备。①缺少统一的贡献指南，新代码容易在风格、注释密度与提交规范上与既有实现脱节，故新建 `CONTRIBUTING.md` 明确「保持既有风格」「注释从简（只留说明为什么的功能性注释）」「最小改动」「不改品牌署名」等原则，并固化 PHP / 数据库 / 前端代码风格、注释规范、安全底线与提交前检查（`php -l`、更新 CHANGELOG、不做破坏性验证、提交信息 `ND-<序号> <中文简述>`）；②README 与当前实现已脱节，需同步修正。
- 验证：纯文档改动，不涉及 PHP 源码，无需 `php -l`；已通读 `README.md` 与新建 `CONTRIBUTING.md` 全文核对交叉引用（README §贡献 → CONTRIBUTING、CONTRIBUTING → README / CHANGELOG / src/helpers.php / src/Schema.php 链接均存在）。数据库结构影响：无。
- 改动明细（行号为改动后当前行号）：
  - `CONTRIBUTING.md` L1-L105 — 新建贡献指南：L5-L14 开始之前（引用 README、`.\scripts\dev-start.ps1`、无 Composer / 框架 / 构建工具，不得引入第三方包）、L16-L21 核心原则、L23-L57 代码风格（PHP 的 `declare(strict_types=1)` / `final class` / Allman 大括号 / 4 空格 / 严格比较 / 工具函数置于 `src/helpers.php`；数据库 PDO 预处理、`utf8mb4`、结构变更走 `src/Schema.php` 版本门控；前端 CSP 禁内联、资源改动递增 `?v=`）、L59-L76 注释规范（禁止逐行复述、变更流水账、死代码、模板头；鼓励解释动机与魔法值；判断标准「删掉后是否仍一眼看懂」）、L78-L85 安全底线（CSRF / 上传校验 / 不回显报错 / 对外请求不跟跳转不进内网）、L87-L97 提交前检查、L99-L105 文档分工。
  - `README.md` L3 — 首段重写：由「团队文件上传入口、共享 Notion 集成 Token」改为「以 Notion 为存储后端的网盘，既支持用户绑定自己的 Notion 账号，也支持管理员统一分配账号池」，与新实现一致。
  - `README.md` L7-L25 — 功能列表重写：删除过时的「无公开注册的账号体系」「收费体系（待补充）」，改为 19 条与实现一致的功能（Web 安装向导 / 可开关公开注册与邮箱验证码 / 权限组 / Token 加密与账号池 / 双重校验 / 三阶段上传 / 多分片 / 在线预览 / 空间巡检 / 内容风控 / 可信度与封禁 / 工单 / 易支付收费体系 / 站点信息 / 防冲突命名 / 液态玻璃界面 / 安全基线）。
  - `README.md` L83-L106 — 目录结构补齐 `bin/`、`scripts/` 与遗漏页面（`account.php` / `admin_orders.php` / `bans.php` / `forgot.php` / `index.php` / `logs.php` / `pay.php` / `register.php` / `site-asset.php` / `tickets.php`）。
  - `README.md` L108-L110 — 新增「## 贡献」节，指向 `CONTRIBUTING.md`。
  - `README.md` L116 — 「定期备份 MySQL」改为「定期备份 MySQL / MariaDB」，与环境要求一致。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

### [2026-09-28 21:54:30] 开源前全量审查修复：预览/下载 SSRF 与内容类型白名单、支付查单绑定、风控防指纹投毒与提示注入、封禁与工单终态、CSP 内联样式、找回密码、登录限流（高/中/低危逐项）

- 目的：面向「开源之后」的真实攻击面做一轮全量修复，安全与数据一致性优先。核心问题：①预览接口按文件名/MIME 猜测输出 `Content-Type`，可被「a.png 实为 HTML」构造同源 HTML 注入（H-S1）；②`bin/` 目录与 CLI 巡检脚本 Web 可达（H-S3）；③`install.php` 可被重跑接管管理员、`app_key` 每次重装重置导致旧库密文全部失效（H-S2/M5）；④内容风控存在指纹投毒（未真正审核却写全站白名单）、`forgetHash` 误撤全站指纹、AI 提示注入、关键词只看文件名（H-S4/H-S9/M1/M2）；⑤支付查单未校验单号/商户号、下单后接口可被重定向（H-S5/H-S6）；⑥Notion 下载/预览未防内网与跳转、`X-Forwarded-Proto`/`Host` 头可伪造（H-S7/H-S8，SSRF + Open Redirect）；⑦封禁「到期不解封」「解封不清零」「自动封禁无到期时间」与工单终态可被批量/回复绕过（H-B1~B5、M7~M11）；⑧上传配额 TOCTOU、落库失败留 Notion 孤儿副本、删文件夹遗留隔离文件（H-B6~B8、M3/M4）；⑨CSP 未放开 `unsafe-inline` 导致内联 `style` 进度条/星级全部失效（前端 CSP 内联样式）；⑩缺找回密码子系统、登录仅 IP 限流可被轮换 IP 撞库、`index.php` 回显数据库原始报错、`db_host` 可注入 DSN、已关闭订单无自助对账等中低危项。
- 验证：对本次改动的全部文件执行 `php -l`（`install.php` / `index.php` / `pay.php` / `pay_return.php` / `forgot.php` / `account.php` / `bans.php` / `admin.php` / `tickets.php` / `logs.php` / `dashboard.php` / `register.php` / `admin_orders.php` / `site-asset.php` / `api/file.php` / `api/folder.php` / `api/upload.php` / `api/verify.php` / `bin/scan-space.php` / `src/helpers.php` / `src/Epay.php` / `src/NotionClient.php` / `src/Bans/BanService.php` / `src/Moderation/ModerationService.php` / `src/Moderation/AiModerator.php` / `src/Tickets/TicketService.php`）全部 `No syntax errors detected`（EXIT=0）。版本号一致性：`grep` 确认 `assets/app.js?v=29` 于 10 个页面（account/admin/admin_orders/dashboard/forgot/index/logs/pay/register/tickets）、无 `?v=28` 残留；`assets/app.css?v=54`（11 页）、`admin.js?v=9`、`verify.js?v=1` 未动。**未做任何功能/HTTP 测试**（遵守「触发即还原/清库」红线，故未执行任何请求）。
- 改动明细（行号为改动后当前行号）：
  - **H-S1 预览内容类型白名单**：`api/file.php` L17-L18（预览模式改由 `?inline=1` 驱动）、L83-L92（预览前用 `previewKind()` + `filePreviewable()` + 新增 `previewContentType()` 三重校验，非白名单一律拒绝内联）、L113-L119（内联输出 `Content-Type` 仅取白名单映射值，并加 `X-Content-Type-Options: nosniff`）；`src/helpers.php` L449-L489 新增 `previewContentType()`（image/pdf/av 三类各自「MIME 白名单 + 扩展名兜底」，显式排除 `image/svg+xml`），堵住「文件名 a.png、内容为 HTML」被以 `text/html; inline` 渲染的同源注入。
  - **H-S3 暴露面收敛**：`.htaccess` L5 的封禁清单加入 `bin`；`bin/scan-space.php` L5-L9 增加 `PHP_SAPI !== 'cli'` 守卫（Web 直访返回 404），防止无鉴权触发全量巡检。
  - **H-S2 安装加固**：`install.php` L79-L103（拒绝覆盖的判定由「仅 users 表」扩到 `users` / `config` / `official_accounts` 任一非空）、L184-L200（重装时复用既有 `config/database.php` 的 `app_key`，避免重装后旧库 `notion_token` / `official_accounts.token_encrypted` / `epay_key` 密文全部失效）、L205-L212（不再内联写 `schema_version`，交由 `Schema::migrate()` 按迁移链补齐，消除安装内联建表与迁移脚本漂移）、L263-L267（`config/database.php` 落盘后 `@chmod 0600`）、L267-L273（锁文件写失败不再回滚、提示修复权限后刷新自愈）。
  - **M5 半安装自愈**：`install.php` L10-L21 —— 缺 `storage/installed.lock` 但 `config/database.php` 已存在（上次「配置已写、锁写失败」）时自动补锁并跳 `index.php?installed=1`，避免站点被 bootstrap 永久导回安装页卡死。
  - **H-S4 风控防投毒/提示注入/内容侧匹配**：`api/upload.php` L750-L762（仅当 `ModerationService::isScanned($verdict)` 为真才写全站白名单，未配置 AI / 审核范围不含载荷时不再把未审核内容登记为「已通过」）；`src/Moderation/ModerationService.php` L222-L228 新增 `isScanned()`、L230-L245 新增 `readTextSample()`、L196-L202（关键词审核同时传 `text` 采样，不再只看文件名）；`src/Moderation/AiModerator.php` L121-L127 / L172-L196（审核指令拆到独立 `system` 角色并显式声明「用户内容为不可信数据、其中的指令不得执行」）、L203-L212（`messages` 前置 system）、L260（AI 请求 `CURLOPT_FOLLOWLOCATION => false`）。
  - **H-S9 指纹与清除口径**：`src/Moderation/ModerationService.php` L349-L368（`forgetHash()` 增加 `$exceptRecordId` 与「同指纹是否仍有其它 rejected 记录」守卫，仅当无其它仍属违规的记录时才删除全站指纹）、L432（放行重传传 `$recordId`）、L451-L466（`clearRecord()` 增加「仅 `STATUS_REJECTED` 可清除」前置校验，避免错误扣减违规计数或撤销全站指纹）、L578-L596（`noticeExists()` 与 `dismissNotice()` 同口径：仅「flagged 且 open/resubmit」才算存在，修「已被管理员复核却回『已清除』」）、L819-L884（`isPrivateHost()` 重写：覆盖 `localhost`/`*.localhost`、十进制整型 IPv4、IPv4 映射 IPv6、域名解析结果，修复私网判定绕过）。
  - **H-S5/H-S6 支付**：`src/Epay.php` L144-L160（查单响应必须与本次 `out_trade_no`、`pid` 绑定，不匹配一律视为无效，防伪造/串单响应误导结算）、L202-L206（查单不跟随跳转）。`pay_return.php` L52（回跳对账条件由 `pending` 扩为 `pending` + `closed`，若被新单顶掉的旧单其实已在网关付款成功，回跳也能开通）；`pay.php` L240（订单列表「刷新状态」按钮对 `closed` 单同样显示，给已关闭订单自助对账入口）。
  - **H-S7/H-S8 SSRF 与头伪造**：`src/NotionClient.php` L105（下载地址校验改调 `isTrustedDownloadUrl()`）、L141-L152（跟随跳转后用 `CURLINFO_EFFECTIVE_URL` 复校最终地址，落在可信域名外则删文件并中止）、L214 / L245（`CURLOPT_FOLLOWLOCATION => false`）、L367-L381（`isTrustedUrl()` 提升为 public static `isTrustedDownloadUrl()`，下载与预览直出复用）；`src/helpers.php` L879-L887 新增 `remoteAddrIsTrustedProxy()`（仅直连对端为内网/本机时才可能位于可信反代之后）、L896-L910（`requestIsHttps()` 仅在该条件下才采信 `X-Forwarded-Proto`，公网直连一律以 Web 服务器 HTTPS 变量为准）；`src/helpers.php` L1964-L1990（`siteUrl()` 的 `Host` 回退分支收紧正则、校验端口 1-65535 越界即不识别，防 Host 伪造出任意回调域名）。
  - **H-B1~B5、M10/M11 封禁逻辑**：`src/Bans/BanService.php` L111-L124（`unban()` 同时清 `violation_ban_until` 并把 `violation_cycle_start` 重置到当前，解封即清零重算，避免解封后下次违规立刻再封）、L414-L418（`evaluateAutoBan()` 先 `expireDue()`+`accrueCycle()` 懒结算，避免用陈旧周期误封/漏封）、L434-L441（自动封禁时写 `violation_ban_until = NOW()+AUTO_BAN_DAYS`，否则该用户永远不会被 `expireDue()` 自动解封）、L243-L252（`expireDue()` 改用 `unban()` 统一归零，删除原先只更新 `users` 的冗余 SQL）、L132 + L157-L174 + L166 新增 `totalViolationCount()`（历史违规真实总条数，不受明细 LIMIT 影响）；`account.php` L258（账户页改为读 `$trust['total']`，修「历史违规条数少报」）；`bans.php` L77（解封提示补充「违规次数已清零并重新开始累计周期」）。
  - **H-B3/H-B4、M7~M9 工单终态**：`src/Tickets/TicketService.php` L146（新增 `isEnded()`）、L316-L321（`replyAsUser()` 打开终态工单时 `reopenTicket(..., $userId)` 传归属）、L332-L339（`replyAsAdmin()` 增加结束态拦截）、L552-L578（`setStatuses()` 非结束态互转限定在待处理/处理中之间，终态不可经此复活；结束态分支按 `closeTicket()` 返回值计数）、L671-L700（`closeTicket()` 改返回 bool，且 `WHERE status NOT IN (closed, expired)`，重复关闭不再刷新时间字段、无限推迟重开窗口与自动过期）、L815（`reopenState()` 配套）；`tickets.php` L68-L70（关闭按钮按返回值给 success/warning 提示）。
  - **H-B6~B8、M3/M4 数据一致性**：`src/helpers.php` L1744-L1801 新增 `archiveWrittenReplicas()`（落库失败时归档已写入 Notion 的副本，避免孤儿文件块无法通过应用删除）；`api/upload.php` L585-L645（`beginTransaction` 后先 `SELECT ... FOR UPDATE` 锁用户行，持锁再重算重名与配额，消除并发同名/超卖 TOCTOU）、L648（事务外层 catch 调 `archiveWrittenReplicas()` 补偿）；`api/folder.php` L89-L103（递归删除文件夹时一并把 `quarantined` 文件置 `deleted`，避免隔离文件永久遗留）。
  - **CSP 内联样式（前端）**：`assets/app.js` L15-L22（新增按 `[data-style-width]` / `[data-style-pct]` 读取 `data-*` 并经 CSSOM 写宽度/`--pct`，`style-src` 未放开 `unsafe-inline` 时内联 `style` 会被丢弃）；`admin.php` L1811 / L1819（评价星级 `style="width:...%"` → `data-style-width`）、`tickets.php` L562（评分选择器 `style="--pct:...%"` → `data-style-pct`）。
  - **L4 二次确认兜底**：`assets/app.js` L561-L614 —— 抽出 `submitConfirmed()`（临时 `noValidate` 提交后立即恢复，不再永久关闭原生校验），并新增 `submit` 阶段委托：键盘 Enter 隐式提交（不经 click 委托）也会先过 `[data-confirm]` 确认，避免绕过二次确认。
  - **M6 找回密码子系统**：`api/verify.php` L24-L28（`purpose` 允许 `reset`）、L48-L56（未登录可请求、账号不存在时同样回统一成功文案，避免沦为邮箱枚举入口）；新建 `forgot.php`（邮箱验证码 → 重置密码）；`index.php` L83-L84（登录页在配置 SMTP 时显示「忘记密码？」链接）。
  - **M12/M13、admin 杂项**：`site-asset.php` L39-L47（`finfo` 探测非 `image/*` 时回退 `application/octet-stream` 并加 `nosniff`，与 `avatar.php` 白名单口径一致）；`admin.php` L414-L431（favicon/logo 改为「先落盘新文件、写库成功后再删旧文件」）、L452-L478（编辑公告先 `SELECT body` 做存在性校验并按图片引用差集清理被移除的旧图）、L490-L497（切换公告状态按 `rowCount()` 判定存在性）、L500-L509（删除公告改为「不存在即抛错」，去掉静默成功）。
  - **低危（L1/L3/L5/L6/L7/L10/L11）**：`src/helpers.php` L917-L957（`loginThrottle()` 叠加账号级锁定：同 IP 或同账号 15 分钟内失败 20 次即锁，且锁定时 `retry_after` 取两者较大值；失败 3 次起要求图形验证码，缓解「故意输错锁号」DoS）；`src/helpers.php` L2727-L2766（`reconcileOrder()` 网关已收款但本地未开通时，按订单真实状态返回 `paid` / `void` / 异常提示，不再笼统回「已完成支付」误导已作废订单）；`index.php` L44-L49（catch 分支按异常类型分流，`PDOException` 与未知异常不再回显含 SQL / 绝对路径的原始报错，`RuntimeException` 业务提示继续展示）；`install.php` L48-L51（`db_host` 白名单字符校验，防 `;` 注入 PDO DSN 参数）；L5（`install.php` 的 `default_page_id`/`notion_parent_page_id`）经核对已由既有 `preg_replace('/[^a-fA-F0-9-]/','',...)` 归一化覆盖，无需改动。
  - **资源版本号**：因改动 `assets/app.js`，全局升 `app.js?v=28→29`（`account.php` L384、`admin.php` L1884、`admin_orders.php` L354、`dashboard.php` L396、`forgot.php` L113、`index.php` L88、`logs.php` L125、`pay.php` L257、`register.php` L183、`tickets.php` L588）；`assets/app.css` / `admin.js` / `verify.js` 未改动，版本号不变。
- 数据库结构影响：**无**。本次不改任何表/列定义、不新增迁移；`install.php` 不再内联写 `schema_version` 属行为调整，旧库无需手工补列。
- 署名：[ShiZhongyan]（Github: ShiZhongyanGH）

---

### [2026-09-28 01:05:00] ND-2 全局审查收尾：后台表单 stack-form 补全 + 工单/评价/公告移动端优化 + JS 空值加固

- 目的：延续上一轮公告表单错乱修复，做一次全局样式/功能审查。①后台仍有多处 `connection-form` 复用 auto-fit 多列网格（`repeat(auto-fit, minmax(230px,1fr))` + `align-items:end`），当表单含 label 堆叠、`readonly`、`file`、多行文本时同样会出现字段高矮错落与按钮错位——统一补 `stack-form`；②对工单评价区、对话、附件、审核提示面板、公告弹窗等新增内容做移动端深度适配（窄屏铺满、触摸目标≥44px、星级不溢出）；③修 JS 两处未加空值守卫的潜在崩溃点。全程未加说明性注释，仅保留功能性注释。
- 验证：`php -l`（admin/tickets/dashboard/account/pay/bans/logs/admin_orders/register/index/install/SpacePatrol/TicketService）全部 `No syntax errors detected`；`node --check` app.js/admin.js/verify.js 退出码 0；版本号统计一致：`assets/app.css?v=54`（11 页）、`assets/app.js?v=28`（9 页）、`admin.js?v=9`、`verify.js?v=1`。**未做任何功能/HTTP 测试**（遵守「触发即还原/清库」红线）。
- 改动明细：
  - `admin.php` L1535 — 空间巡检「立即巡检」表单去掉内联 `style="margin-top:10px;"`，改 `class="connection-form connection-form-next"`。
  - `admin.php` L1545（注册与邮箱）、L1564（上传与容量）、L1575（支付设置）、L1598（站点信息）、L1661（工单过期）、L1687（外部调研）、L1699（禁止提交工单）、L1835（还原个性化）、L1850（恢复出厂）— 九处含 label 堆叠 / readonly / file 字段的表单 `class="connection-form"` 改为 `class="connection-form stack-form"`，与公告表单同一修复口径。
  - `assets/app.css` L389 — 新增 `.connection-form-next { margin-top: 10px; }` 取代内联样式。
  - `assets/app.css` L659-L682（`@media max-width:760px`）— 新增工单对话/表单/评价/审核面板移动端适配：`.ticket-msg` 与 `.ticket-form`/`.review-form` 铺满整行、`>button` 满宽 44px；`.review-summary`/`.review-bar-row` 收窄列宽；`.notice-bulk` 换行、`.appeal-form` 满宽且 textarea 16px 防 iOS 缩放；`.moderation-review input`/`.group-member-list` 单列。
  - `assets/app.css` L766-L776（`@media max-width:640px`）— 星级选择器缩至 1.4rem/字距 2px，保证 5 星在极窄屏不横向溢出；公告弹窗贴边全宽、操作区纵向铺满、主按钮满宽 44px。
  - `assets/app.js` L281 — `row.dataset.fileName.includes()` 改为 `(row.dataset.fileName || '').toLowerCase().includes()`：搜索框过滤时遇到无 `data-file-name` 的行会抛 `TypeError` 中断整表过滤，加固后不再崩。
  - `assets/app.js` L205 — 公告弹窗 `ackButton.addEventListener` 前补 `if (ackButton)` 守卫，避免 `#announceAck` 缺失时解引用 null。
  - `src/Space/SpacePatrol.php` L285 — `$upload['storage_name'] ?: $upload['filename']` 改为 `(($upload['storage_name'] ?? '') ?: ($upload['filename'] ?? ''))`，消除键缺失时 PHP 8 undefined array key 警告并避免空文件名上传。
  - `assets/app.css` 版本号 `?v=53` → `?v=54`（11 页）；`assets/app.js` `?v=27` → `?v=28`（9 页）。
- 审查结论（未改动，已核对为无问题）：SMTP 表单字段名与 `saveSmtpSettings()` 的 `$_POST` 键完全一致（`smtp_username`/`smtp_password` 等）；`TicketService::scoreOptions()` 用 `$score += 0.5` + `round()` 生成，浮点严格比较安全；`SpacePatrol::fillMissingReplica()` 每次调用最多补一份副本（成功即 `return true`），`$survivors[0]` 取值稳定；前端各模块 DOM 引用均有空值守卫，`data-confirm` 破坏性操作全部位于表单内并拦截；CSRF 令牌在全部分支齐全。

---

### [2026-09-28 00:17:34] ND-2 后续修复：公告/风控/预览表单错乱（stack-form）+ 下载重定向加固 + R11 边界加固

- 目的：用户反馈「系统设置里公告那部分公告栏样式出现问题，输入框和按钮大小位置错乱」。根因是这些后台表单复用了 `.connection-form`（`grid-template-columns: repeat(auto-fit, minmax(230px, 1fr))` + `align-items: end`），当表单内同时含单行 `input` 与 `rows=10` 的多行 `textarea` / `file` 域时，各字段被排成多列且底部对齐，导致高矮错落、按钮位置错乱。同类问题还存在于「内容风控」与「文件在线预览」两个表单（均含多行/多类字段）。顺带完成 R11/R13 新增功能的边界复查并加固。
- 验证：`php -l admin.php / src/helpers.php / src/Space/SpacePatrol.php` 全部 `No syntax errors detected`；`node --check assets/app.js` 退出码 0；版本号统计 `assets/app.css?v=53`（11 页一致）。**未做任何功能/HTTP 测试**（遵守「触发即还原/清库」红线）。
- 改动明细：
  - `assets/app.css` L387-L389 — `.connection-form` 后新增 `.connection-form.stack-form { grid-template-columns: 1fr; align-items: start; }`：表单含多行文本域/文件域等高度差异大的字段时逐行堆叠，避免同行字段高矮错落。
  - `admin.php` L1419（内容风控）、L1640（公告发布/编辑）、L1757（文件在线预览设置）— 三处表单 `class="connection-form"` 改为 `class="connection-form stack-form"`，修复输入框/文本域/按钮的大小与位置错乱。
  - `assets/app.css` 版本号 `?v=52` → `?v=53`，同步 11 个 php 页面（account/admin/admin_orders/bans/dashboard/index/install/logs/pay/register/tickets）。
  - `src/NotionClient.php` `downloadBlockToFile()` — 下载副本字节时补 `CURLOPT_MAXREDIRS => 3` 与 `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS`：原 `CURLOPT_FOLLOWLOCATION => true` 未限制重定向次数与协议，存在被降级到 http/内网地址的风险（SSRF 加固）。`api/file.php` 下载流的 curl 已有同款限制。
  - `src/helpers.php` L382 `previewKind()` — `$file['display_name'] ?: ($file['filename'] ?? '')` 改为 `($file['display_name'] ?? '') ?: ($file['filename'] ?? '')`，消除 `display_name` 键缺失时的 PHP 8 undefined array key 警告。
  - `assets/app.js` L326-L331 `openPreview()` — `#previewHeading` / `#previewDownload` 访问补 null 保护（与同函数对 `previewModal` / `previewBody` 的判断保持一致），避免元素缺失时抛错中断预览。
- 复查确认无误（本轮全量检查 R11/R12/R13）：R12 服务端 `submitReview()` 拦截 / `tickets.php` 整块条件渲染（关闭时管理员只读视图与调研入口一并隐藏）/ `admin.php save_review_enabled` 取值口径一致；R11 `previewKind()`（mime 优先、扩展名兜底、排除 SVG）/ `previewKindEnabled()`（默认开，match 映射 default false）/ `filePreviewable()`、`api/file.php` inline 白名单+开关双校验 + `nosniff`、`dashboard.php` 模态框与 `data-warn-bytes` 前后端一致（`previewWarnBytes()` 换算 MB 后回写）；R13 `SpacePatrol` 全文（懒触发 12:00 判定/`reconcileReplicas` 子查询规避 `ONLY_FULL_GROUP_BY`/`fillMissingReplica` target 与 covered 键 `type|account_id` 匹配一致/`markLost` 用 `rowCount()<1` 去重避免重复告警/`api/file.php` 全部副本 404 才 `markLost`+410、POST 非 delete 一律 410）；新增 CSS（`.preview-*` / `tr.file-lost` / `.lost-badge` / `.display-name`）均成对定义且含移动端适配。
- 数据库结构影响：**无**。
- 遗留提醒：`assertAccessible()` 当前未被调用（拦截改在 `api/file.php` / `api/folder.php` 内联实现），保留作后续统一入口，非缺陷。

---

### [2026-09-27 22:36:34] ND-2 新需求三连做：R12 工单评价可选开关 / R11 文件在线预览 / R13 Notion 空间巡检与数据丢失提示

- 目的：实现 ND-2 两张需求卡（与用户逐条问答定稿）。R12 工单评价改为后台可选（默认开，关闭时评价区块+外部调研入口一并隐藏、服务端拒绝提交，历史评价在「评价统计」保留）；R11 文档/图片/视频预览落地为**图片 / PDF / 音视频三大类**（MP3 等小音频并入音视频，不单列），后台各一个开关 + 大文件预览体积提醒阈值（默认 20MB，1~10240MB），列表行「预览」按钮 + 站内模态框，PDF 用同源 iframe，音视频走 Range 边下边播，预览失败统一提示「很抱歉，该文件数据已丢失，请重新上传」；R13 每天 12:00 巡检 Notion 空间，**优先自动修复**（恢复回收站页面 / 重建彻底 404 的空间页 / 单副本自动补齐为两份），全部副本失效且补不回则标记数据丢失（仅允许删除，下载/预览/重命名/移动全部拦截提示），后台开关默认开启，巡检结果走管理员告警（会发邮件）。
- 提交节奏（均**仅本地 commit，未 push**）：`dbad4f3` R12、`d81a22f` 补录前序阶段未跟踪文件、`bc6facb` R11、`a18d62b` R13。
- 数据库结构：`src/Schema.php` L11 `CURRENT_VERSION=21`；L243-L249 v21 迁移给 `uploads` 增列 `data_lost TINYINT(1) NOT NULL DEFAULT 0` + 索引 `idx_uploads_data_lost`；`install.php` L125-L133 全新建表同步含该列。**旧库访问即自动升级，无需手工补列**；`config` 表新增键 `ticket_review_enabled` / `preview_image_enabled` / `preview_pdf_enabled` / `preview_av_enabled` / `preview_warn_bytes` / `space_patrol_enabled` / `space_patrol_last_date`（均无需建表）。
- 改动明细：
  - R12：`src/Tickets/TicketService.php` 新增常量 `REVIEW_ENABLED_CONFIG_KEY` 与静态方法 `reviewEnabled()`（默认开，仅显式 '0' 关）、`saveReviewEnabled()`，`submitReview()` 开头加开关拦截；`tickets.php` 详情用 `$reviewEnabled` 同时门控评价区块与 `$surveyUrl`（关闭时整块不渲染，管理员视角同样隐藏，历史评价仍在后台统计）；`admin.php` 新增 `save_review_enabled` 分支与「站内评价开关」面板（工单设置页签）。
  - R11：`src/helpers.php` L367 起新增预览常量与 `previewKind()`（mime 为主+扩展名兜底，**排除 SVG**）、`previewKindEnabled()`、`previewWarnBytes()`、`filePreviewable()`；`api/file.php` 增 `?inline=1` 预览分支（白名单+开关双校验，`Content-Disposition: inline` + `X-Content-Type-Options: nosniff`，鉴权/封禁/副本回退/Range 全复用）；`assets/app.js` 新增模态框预览逻辑（首字节 Range 预检，410/非 2xx 即提示数据丢失；图片 `<img>`、音频 `<audio>`、视频 `<video>`、PDF `<iframe>`；超阈值二次确认；ESC/点遮罩/关闭按钮收尾并还焦点）；`assets/app.css` 新增 `.preview-*`；`dashboard.php` 列表行加预览按钮与模态框；`admin.php` 新增「预览设置」页签（`$adminTabs` 加 `preview`）与 `save_preview_settings` 分支；`src/bootstrap.php` CSP 增补 `media-src 'self'; frame-src 'self'`。
  - R13：新增 `src/Space/SpacePatrol.php`（空间页巡检 `scanSpaces()` 复用 `NotionSpaceRepair::repair()`、单副本补齐 `reconcileReplicas()`/`fillMissingReplica()`——从幸存副本下载字节再 `uploadAndAttach` 到缺失账户并登记 `upload_replicas`、`markLost()` 仅在 0→1 首次置位时告警避免重复邮件、`assertAccessible()`、`runIfDue()`/`run()`/开关与日期读写）；`src/NotionClient.php` 新增 `downloadBlockToFile()`（可信域名 S3 下载到临时文件）；`api/file.php` GET 在所有副本块均 404 时 `markLost()+410` 输出丢失文案，POST 对 `data_lost=1` 文件除 `delete` 外一律 410；`api/folder.php` 移动文件拦截丢失文件；`dashboard.php` 丢失文件置灰+删除线+「数据已丢失」角标且只留删除按钮；`src/bootstrap.php` L54-L70 登录态 HTML 页面 GET 懒触发（避开 api/* 与二进制接口，12 点后当天一次）；新增 CLI `bin/scan-space.php`；`admin.php` 系统设置页签顶部加「Notion 空间自动巡检与修复」面板（开关 + 显示最近巡检日期 + 立即巡检按钮，对应 `save_space_patrol`/`run_space_patrol`）。
  - 资源版本号：`assets/app.css` 最终 `?v=52`（11 页一致）、`assets/app.js` 最终 `?v=27`（9 页一致），`admin.js?v=9` 未动。
- 自查修复（本轮代码评审直接改）：①`install.php` 增补 `data_lost` 列时一度漏掉 `'active'` 的单引号转义导致 PHP 解析错误，已改回 `\'active\'`；②`markLost()` 改为以 `rowCount()` 判断首次置位，杜绝重复下载触发重复告警邮件；③音频预览改用 `<audio>`（按钮带 `data-preview-mime`）；④丢失角标移入 `.file-name` 弹性容器防错位；⑤`reconcileReplicas` 改子查询，规避 `ONLY_FULL_GROUP_BY` 严格模式。
- 验证：**未做任何功能/HTTP 测试**（遵守「触发即格式化」红线；R13 涉真实 Notion 写操作，无法本机联网验证，需上线观察）。全量 `php -l`（排除 `storage/`）→ `PHP_LINT_ALL_OK`；`node --check assets/*.js` 全部退出码 0；版本号统计确认 css 52×11、js 27×9 无残留。CLI 用法：`php bin/scan-space.php`。
- 待办（不属本次代码缺陷，供后续）：R13 副本补齐依赖真实 Notion，建议上线后用后台「立即巡检一次」小流量观察；此前 REVIEW_FINDINGS 中 H2（`forgetHash` 全局副作用）等历史问题未在本批处理。

### [2026-09-27 20:57:06] ND 需求实现·阶段11：收尾（全量语法校验 + 版本号一致性 + 全量审查）

- 目的：完成 `TASKS_ND.md` 阶段 11（收尾）。本阶段**不改功能代码**，只做三件事：①全量 PHP 语法校验；②前端资源版本号一致性检查；③把全量代码审查（bug / 功能性 / 逻辑，高→低）结论落盘到 `REVIEW_FINDINGS.md`。按项目约定**不 commit、不推送**，且**未做任何功能/HTTP 测试**（用户明确禁止：本机库触发即格式化）。
- 关键结论：
  1. **阶段 11 复审对原有清单逐条人工复核**，并把增量结论追加到 `REVIEW_FINDINGS.md` 文末「阶段 11 复审新增」小节。
  2. **重要更正：原 H1 已修复**。原 H1「全新安装后文件系统整体不可用」在当前代码中**不复现**：`install.php` 的内联 `uploads` 建表已含 `scope`/`ticket_id` 及索引（`install.php` L104-L134），且已改用 19 个 `Schema::create*Table()` 统一建表口径（L136-L154），`schema_version` 写入 `Schema::CURRENT_VERSION`（L165，当前 20）而非硬编码 18。故 `migrate()` 不再提前返回，v17 补列路径不再被跳过。
  3. **H2 仍存在（确认为高）**：`clearRecord()` → `forgetHash()` 删除**全站唯一**的 `content_hash` 指纹条目，一次单用户清除操作产生跨用户全局副作用（其他用户再传同内容不再被拦截）。
  4. **新增 7 条中危**：M7 管理员批量改工单状态非结束态分支未排除 `expired`/`closed`，可「复活」已过期/已关闭工单并绕过 `reopenState()` 与 `MAX_REOPEN`（**此为该问题唯一可达入口**，此前「申诉路径可绕过过期」的推断经复核**不成立**——`appendAppealMessage()` 对 `expired` 有正确分支，另起新工单）；M8 `closeTicket()` 无原状态守卫，重复关闭会刷新 `closed_at`、`expired`↔`closed` 可互改；M9 `replyAsAdmin()` 缺 `isEnded()` 拦截（与 `api/upload.php` 结束态 409 `ticket_closed` 自相矛盾）；M10 `evaluateAutoBan()` 未先做 `expireDue()`+`accrueCycle()` 懒结算；M11 `unban()` 不重置 `violation_cycle_start`/`violation_ban_until`，解封后分数仍 ≥ 阈值；M12 账户页历史违规条数由两个 LIMIT 截断结果相加导致少报；M13 `site-asset.php` `k=media` 输出缺 `image/*` 白名单（`avatar.php` 有）。
  5. **新增 2 条低危**：L12 `appendAppealMessage()` 调 `reopenTicket()` 未传 `$userId`（纵深防御缺失，当前不可利用）；L13 编辑公告删除正文图片引用时旧图片文件不随更新清理（仅整条删除时清理）。
  6. **复审确认无问题**：`api/file.php` 工单附件下载授权/封禁拦截/副本回退与截断校验；`api/upload.php` 分块协议全链路（token 白名单、归属校验、顺序与字节校验、shutdown 清理、工单 scope 双重复核）；`src/Auth.php` 会话与权限；`src/NotionSpaceRepair.php` 自愈边界；`src/helpers.php` 站点标识系列函数（全部先判 `installed.lock`，安装向导阶段不触碰 `config` 表）；`dismissAllNotices()` 与单条清除同口径。公告「已读」以 cookie `nd_ann_<id>` 记录属设计选择，非缺陷。
- 验证：
  - **PHP 全量语法校验通过**：对全仓 `*.php`（排除 `storage/`）逐个 `php -l`，全部 `No syntax errors detected`，EXIT=0，无失败项。
  - **JS 全量语法校验通过**：`node --check assets/app.js`、`assets/admin.js` 等全部 EXIT=0，无失败项。
  - **资源版本号一致性通过**：`grep` 确认 `assets/app.css?v=49`（11 个页面）、`assets/app.js?v=25`（9 个页面）、`assets/admin.js?v=9` 全局一致，无残留旧 `?v=48` / `?v=24`。
  - **未做任何功能/HTTP 测试**（用户明确禁止）。未触发还原/清库等不可逆路径。
- 改动明细：
  - `REVIEW_FINDINGS.md` — 追加「阶段 11 复审新增（2026-09-27 20:57:06 UTC+8）」小节（H1 已修复说明、H2 确认高、M7–M13 中危、L12–L13 低危、复审确认无问题补充项），并在文首补复审时间与说明、在文末更新「修复优先级建议」。H1 条目标题标注「阶段 11 复审：已修复」。
  - `TASKS_ND.md` — 勾选阶段 11 完成项。
- 数据库结构影响：**无**。`Schema::CURRENT_VERSION` 保持 20，旧库无需手工补列。
- 遗留提醒：`REVIEW_FINDINGS.md` 中所有条目**仅记录、尚未修复**；H2、M1、M7、M8、M9 为建议优先处理项；M2（清除后是否自动解封）属产品预期待拍板。后续如需验证任何不可逆功能，务必在**一次性测试库**上操作。

---

### [2026-09-27 20:35:16] ND 需求实现·阶段10：R8 还原/恢复（还原个性化设置 + 恢复出厂设置）

- 目的：完成 `TASKS_ND.md` 阶段 10。后台「还原/恢复」页签由占位面板改为**两项**可执行操作：①**还原个性化设置**（只把 `site_name`/`site_logo`/`site_favicon` 三项重置为内置默认，不触碰用户数据与密钥）；②**恢复出厂设置**（清空整个数据库 → 删 `storage/installed.lock` → 跳 `install.php` 重新安装）。按用户口径收敛为两项（原草案的「保留密钥还原」「清空内容保留账号」两项已作废，见 `TASKS_ND.md` R8 已确认口径修订）。**两项均为不可逆操作，本次全程未做功能测试**（用户明确要求：触发即会格式化数据库）。
- 关键设计：
  1. **新增 `src/Restore/RestoreService.php`** 承载全部还原逻辑与服务端校验，页面只负责渲染与跳转，避免高危代码散落在 `admin.php` 的 POST 分支里。构造器私有，统一 `RestoreService::create()` 获取实例。
  2. **三重校验**（`TASKS_ND.md` R8「Q46=2」）：第一重为站内 `data-confirm` 内置弹窗二次确认；第二重为**管理员密码**（`assertConfirmed()` 内 `SELECT password_hash FROM users WHERE id = :id` + `password_verify`，不复用 `Auth::user()`，因其不含 `password_hash`）；第三重为**逐字输入的确认短语**（两项各自独立常量 `PHRASE_PERSONALIZATION` / `PHRASE_FACTORY_RESET`，必须完全相等）。
  3. **前端弹窗会关闭原生校验**（`assets/app.js` 的 `data-confirm` 处理器会设 `form.noValidate = true`），故 `required` 等 HTML 校验形同虚设，`assertConfirmed()` 是唯一真源——已写成代码注释警示后续维护者。
  4. **恢复出厂的执行顺序刻意把「清库」放最后**：先尽力归档 Notion 侧上传块 → 清本地 `storage/{avatars,site,announcements}` 内文件 → 最后 `SET FOREIGN_KEY_CHECKS=0` + 逐表 `DROP TABLE`（保留数据库本身，`install.php` 会重连并重新建表）→ 删锁。前面任何一步失败都还留有完整数据，不会出现「文件没了但库还在」的半损坏状态。
  5. **Notion 归档走尽力而为**：副本可能分布在自有账户或官方账户池中的任意账户，逐行用对应 Token 建客户端（`$clients[$token] ??= new NotionClient($token)` 缓存），单块失败只 `Logger::warning('restore', ...)` 不阻断——否则一个坏块会卡死整个恢复出厂。**管理员自己的 Notion 根页面属基础设施、不属于「用户上传的文件」，故不归档**（依据 `TASKS_ND.md` R8-C2=1 的措辞口径）。
  6. **`resetPersonalization()` 必须先读路径再删配置**：`siteLogoPath()`/`siteFaviconPath()` 读的是 config 行，顺序颠倒会取到空串导致文件成孤儿——已写注释说明。删文件用 realpath + `str_starts_with` 限定在 `storage/site` 内，防路径穿越。
  7. 恢复出厂跳转用 `header('Location: install.php'); exit;`，**不能再落回后台**（锁已删，`bootstrap.php` 会把后台请求重定向到 `install.php`，依据 `bootstrap.php` L13-L19 与 L15 对 `install.php` 的豁免）。
- 验证：
  - **静态校验通过**：`php -l admin.php`、`php -l src/Restore/RestoreService.php` 均 `No syntax errors detected`（EXIT=0）；VS Code `GetDiagnostics` 返回 `[]`；`grep` 确认 `*.php` 中已无 `app.css?v=48` 残留。
  - **未做任何功能/HTTP 测试**（用户明确禁止：本机库触发即格式化）。未触发两项还原，未调用 `assertConfirmed()`，未执行任何 DROP。
- 改动明细：
  - `src/Restore/RestoreService.php` — **新增**（全文件）。`namespace NotionDrive\Restore`；常量 `PHRASE_PERSONALIZATION`/`PHRASE_FACTORY_RESET`、私有 `PERSONALIZATION_KEYS`（3 项）/`CONTENT_DIRECTORIES`（3 个白名单目录）；方法 `create()`、`assertConfirmed(int $adminUserId, string $password, string $phrase, string $expectedPhrase)`、`resetPersonalization()`、`factoryReset()`、私有 `archiveUploadedBlocks()`/`removeStorageFile()`/`clearStorageDirectory()`；`factoryReset()` 开头 `@set_time_limit(0)`（批量归档可能上千次外部请求）。命名空间内调用全局助手函数一律加前导 `\`（`\siteLogoPath()`/`\userNotionToken()`/`\officialAccountToken()`）。
  - `admin.php` — 新增 `use NotionDrive\Restore\RestoreService;`（`use NotionDrive\NotionClient;` 之后）；POST 分发链末尾 `else { throw new RuntimeException('未知操作。'); }` **之前**插入 `restore_personalization` 与 `factory_reset` 两个分支（各含 `assertConfirmed` + `Logger::warning('admin', ...)` 审计留痕；前者 `flash('success', ...)` 回落本页，后者 `header('Location: install.php'); exit;`）；原「还原/恢复」占位面板替换为两个真实 `.admin-panel`（后者带 `.danger-panel` 与 `.restore-impact` 影响清单：明确列出会清空的表、文件与需重新填写安装信息）；head 内 `assets/app.css?v=48` → `?v=49`。
  - `assets/app.css` L455-L460 — 新增 `.danger-button` / `.danger-button:hover` / `.danger-panel` / `.restore-impact`，与弹窗内危险按钮（`.confirm-danger`）同色系（`--danger: #cf3c3c`、`--danger-soft: #fff1f1`）。
  - 11 个页面（`account.php` / `admin.php` / `admin_orders.php` / `bans.php` / `install.php` / `dashboard.php` / `index.php` / `logs.php` / `pay.php` / `register.php` / `tickets.php`）全局升 `app.css?v=48→49`。未动 `app.js` / `admin.js`。
- 数据库结构影响：**无**。未新增表/列，`Schema::CURRENT_VERSION` 保持 20，旧库无需手工补列。运行时「恢复出厂设置」会删除全部数据表（属功能行为，非结构变更）。
- 遗留提醒：R8 属最高危且**本次刻意未做功能测试**，后续如要验证恢复出厂，务必在**一次性测试库**上操作（生产/本机库一触即格式化）；`install.php` 的「已有用户拒装」校验（`install.php` L69-L78）依赖 `users` 已被清空，故清库必须在跳转之前完成。

---

### [2026-09-27 20:19:18] ND 需求实现·阶段9：R9 用户侧批量清除（全选 + 一键删除）

- 目的：完成 `TASKS_ND.md` 阶段 9。R9 —— `dashboard.php`「未通过内容审核」提示区新增**「全选 + 一键删除」**，用户可一次性把面板上的审核提示全部从自己视角隐藏；语义与既有单条「清除记录」**完全同源**（只置 `moderation_records.user_dismissed = 1`，**不改 `status`、不动风控记录与违规计数**），管理员仍可正常复核，复核时 `closeRecord()` 会把标记归零使提示**重新出现**（依据 `TASKS_ND.md` L356-L357 与 L178「R9-Q1 = 1」）。
- 关键设计：
  1. **一次 UPDATE 覆盖全部，而非循环单条**：新增 `ModerationService::dismissAllNotices(int $userId): int`，一条 `UPDATE ... SET user_dismissed = 1 WHERE user_id = :user_id AND decision = 'flagged' AND status IN ('open','resubmit') AND user_dismissed = 0`，返回 `rowCount()` 作为「已清除 N 条」。与单条 `dismissNotice()` 共用同一过滤口径，避免两套条件漂移。
  2. **勾选框仅作 UI 示意，`一键删除` 以服务端为准作用于面板全部提示**：需求原文为「全选 + 一键删除」，且 R9-Q1 明确一键删除＝对面板上**全部**提示打标记；故「全选」是可见的批量入口（`data-check-all="notice-check"` 驱动同组 `input[name="notice-check"]` 的勾选/反勾选与半选态），**取消勾选不会改变提交结果**（已在 `assets/app.js` 注释中写明该取舍，避免误以为漏了「仅删所选」逻辑）。
  3. **二次确认**：提交按钮沿用既有 `data-confirm` 机制（`assets/app.js` 的站内确认弹窗 `askConfirm`），文案含本次条数；**因 CSP 禁内联脚本**，全选逻辑落在 `assets/app.js`（与工单批量 `[data-check-all]` 同风格，但作用域以 `[data-check-all="notice-check"]` 收窄，不影响工单批量表单）。
  4. **结果提示**：`?cleared=ok`（单条）与新增 `?cleared=all&count=N`（批量）分开展示，批量提示回显实际条数。
- 验证：
  - `php -l dashboard.php`、`php -l src/Moderation/ModerationService.php` — 均 **No syntax errors**；`node --check assets/app.js` — **退出码 0**。
  - 实机 HTTP 冒烟（PHP 内建服务器 `127.0.0.1:8088`）：`dashboard.php → 302`（未登录跳登录页，无 500）；`assets/app.css?v=48 → 200`、`assets/app.js?v=25 → 200`。
  - 全库检索 `app.css?v=47` / `app.js?v=24` 在 `.php` 内**已无残留**（仅历史 CHANGELOG 记录保留旧值）。
  - 未做浏览器端到端复测（勾选/一键删除为登录后交互；风险低、不触发告警邮件，可由用户登录工作台目视确认）。
- 改动明细：
  - `src/Moderation/ModerationService.php` L494-L517 — 新增 `dismissAllNotices(int $userId): int`（批量置 `user_dismissed = 1`，返回受影响行数），置于 `noticeExists()` 之前。
  - `dashboard.php` L68-L93 — `$noticeCleared` 由 bool 改为字符串（取 `$_GET['cleared']`）；新增 `action === 'dismiss_all_notices'` 的 POST 分支，成功后 `302 → dashboard.php?cleared=all&count=N`，失败复用 `$noticeError`。
  - `dashboard.php` L181-L188 — 结果提示分支：`cleared === 'all'`（回显条数）/ `cleared === 'ok'` / 错误。
  - `dashboard.php` L195-L200 — 提示区新增批量表单 `class="notice-bulk"`（csrf + `action=dismiss_all_notices` + `<label class="notice-check-all"><input type="checkbox" data-check-all="notice-check"> 全选</label>` + 带 `data-confirm` 的「一键删除」按钮）。
  - `dashboard.php` L211 — 每条提示 `<li>` 首部新增 `<label class="notice-check"><input type="checkbox" name="notice-check" value="{recordId}"></label>`。
  - `assets/app.js` L695-L710 — 新增 `[data-check-all="notice-check"]` 的全选/半选同步逻辑（纯 UI，作用域限定，不影响工单批量）。
  - `assets/app.css` L113-L116 — 新增 `.notice-bulk` / `.notice-check-all` / `.notice-check` 样式与 `.alert-list li > span:first-of-type { flex: 1 1 auto; }` 以适配插入勾选框后的三列布局（`.alert-list li` 原为 `display:flex` + `space-between`）。
  - 资源版本号全局升位：`assets/app.css?v=47→48`（11 个页面：`admin.php`、`admin_orders.php`、`account.php`、`bans.php`、`dashboard.php`、`install.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）、`assets/app.js?v=24→25`（9 个页面：`admin.php`、`admin_orders.php`、`account.php`、`dashboard.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）；`admin.js?v=9` 未动。
- 数据库：**无结构变化**，沿用已有的 `moderation_records.user_dismissed`（v18 已加列）。旧库无需任何手工操作。
- 回退：删除 `dismissAllNotices()` 与 `dashboard.php` 两处分支/表单、`assets/app.js` 全选块、`assets/app.css` L113-L116，并把两处版本号降回即可。

---

### [2026-09-27 20:07:48] ND 需求实现·阶段8：R10 可信度（违规次数 / 自动封禁 / 30 天周期重置）

- 目的：完成 `TASKS_ND.md` 阶段 8。R10 —— 普通用户「账户管理」新增**可信度**卡片（= 违规次数，`x/5`，数字 + 5 格进度条 + 等级标签），0 良好 / 1 轻微 / 2–3 中等 / 4 高危 / 达阈值「封禁」；达阈值**真封禁**（写 `user_bans`，只拦上传/下载/文件夹，工单申诉入口保留），封禁 30 天后**自动解封并清零**；30 天为一个周期，到期**重新累计**；管理员可在「封禁管理」页**手动给用户加违规次数**。
- 关键设计（与草案的微调，均已实测）：
  1. **分数采用「实时统计」而非累计列**：`BanService::currentScore()` = 本周期内 `moderation_records.status='rejected'` 条数 + `user_violations` 中 `source='manual'` 的 `SUM(points)`，上限 `min(threshold)`。这样管理员「清除违规记录」（`ModerationService::clearRecord()` → status 改 `cleared`）后分数**同步下降**；若以累加列做真源则不会降，故 `users.violation_points` **不再作为分数真源**（列保留，仅历史遗留，无读写）。
  2. **周期判定用 `violation_cycle_start` 起点过滤**：`accrueCycle()` 首次为该用户设起点（取**最早一次违规时间**，避免把存量历史违规误清；无违规则取当前时间），满 30 天后把起点前移到当前时间，`currentScore` 只统计起点及之后的违规——即「30 天周期自动重置」。**草案 R10-Q5 的「每周期最多 +2 分、逐周期叠加」简化为「周期重置后重新累计」**（依据：用户口径原文「30 天周期自动重置」；此简化之处需用户确认）。
  3. **留痕表 `user_violations`（新表，v20）只用于历史展示**，不参与计分，避免与实时口径双重计数。内容风控打回（`ModerationService::reject()` 调 `recordViolation()`）与管理员手动加分（`addViolation()`）都写留痕。
  4. **自动解封懒触发**（无 cron，仿 `TicketBanService::expireDue()`）：`expireDue()` 在 `trustSummary()` / `addViolation()` / `bans.php` 页面加载时调用，把 `violation_ban_until <= NOW()` 的用户解封并把周期起点重置为当前时间（等价清零）。留痕保留。
  5. **幂等**：`BanService::ban()` 首行 `isBanned()` 早退，故已达阈值再次加分不会重复写 `user_bans`。
  6. **管理员账户不展示该卡片**（`$trust = isAdmin($user) ? null : ...`），封禁对其无约束。
- 验证：`php -l` 全通过（`BanService.php` / `Schema.php` / `ModerationService.php` / `account.php` / `bans.php` / `install.php` / `admin.php`）；临时 PDO 脚本 **28 项断言 `ALL PASS`**（等级映射 6 项、实时分数口径、加分/累计/触达阈值自动封禁、封顶、留痕条数、`trustSummary` 结构、到期自动解封清零且留痕保留、跨周期重置归零），断言后脚本已删；未登录访问 `account.php`/`bans.php`/`admin.php` 均 302 跳登录（无 500）。
- 改动明细：
  - `src/Bans/BanService.php` L23-L28 — 新增常量 `CYCLE_DAYS=30`、`AUTO_BAN_DAYS=30`、`MAX_MANUAL_POINTS=100`（`CYCLE_MAX_POINTS` 随 Q5 简化一并移除）。
  - `src/Bans/BanService.php` L121-L155 — 新增 `trustSummary()`：懒结算 + 汇总（分数/阈值/等级/色调/封禁状态/解封时间/留痕/违规文件明细）。
  - `src/Bans/BanService.php` L157-L198 — 新增私有 `accrueCycle()`：首次取最早违规时间为周期起点，满 30 天前移起点实现重置。
  - `src/Bans/BanService.php` L200-L233 — 新增 `expireDue()`：到期自动解封 + 周期起点重置（懒触发）。
  - `src/Bans/BanService.php` L235-L286 — 新增 `addViolation()`：校验 → 懒结算 → 写留痕（只写 `+Δ`）→ 达阈值自动封禁并写 `violation_ban_until = NOW()+30 天`。
  - `src/Bans/BanService.php` L288-L316 — 新增 `recordViolation()`：内容风控打回时写留痕（`kind='record'`、`points=0`）。
  - `src/Bans/BanService.php` L318-L351 — 新增 `violationHistory()` / `recentViolations()`：留痕与打回文件明细查询（账户页折叠区数据源）。
  - `src/Bans/BanService.php` L353-L384 — 新增 `trustLabel()` / `trustTone()`：等级与色调映射（good/warn/high/ban）。
  - `src/Bans/BanService.php` L403-L459 — 重写 `currentScore()`：按周期起点实时统计（打回记录 + 手动加分），上限阈值。
  - `src/Bans/BanService.php` L399-L423 — `evaluateAutoBan()` 改用 `currentScore()`，与账户页 `x/5` 同源口径。
  - `src/Bans/BanService.php` L461-L489 — `bannedUsers()` SQL：去掉 `violation_points`、`violations` 子查询按周期起点过滤。
  - `src/Schema.php` L20 — `CURRENT_VERSION` 19 → 20；L232-L241 — v20 迁移块；L246-L267 — 新增 `createUserViolationsTable()`（新表 `user_violations`，含 `kind/source/record_kind/record_id/upload_id/points/reason/admin_id`，`user_id` 外键级联删除）。
  - `install.php` L154 — 新增 `Schema::createUserViolationsTable($pdo);`；L92 仍有 `users.violation_points` 列（旧库无需手工补列，列已不再使用）。
  - `src/Moderation/ModerationService.php` L359-L370 — `reject()` 内新增 `BanService::create()->recordViolation(...)` 写留痕（在 `evaluateAutoBan()` 之后）。
  - `bans.php` L81-L94 — 新增 POST 分支 `add_violation`（手动加分 + `Logger::warning` + flash）；L104-L105 — 页面加载调用 `$service->expireDue()`；L176 / L183-L185 — 行内 `$autoBanUntil` 与「已达阈值」标记、自动解封时间列；L196-L198 — 新增「加违规」行内表单（`violation_points` 1–100 + `violation_reason` + `data-confirm`）。
  - `account.php` L7 — `use NotionDrive\Bans\BanService;`；L187-L188 — `$trust` 变量；L233-L266 — 新增「可信度」卡片（`status-badge` 等级、`.trust-card` 数字/进度条、封禁提示、`<details>` 历史折叠；历史按「打回文件」与「管理员加计」去重展示）。
  - `assets/app.css` L157-L186 — 新增 `.trust-card` / `.trust-score` / `.trust-progress`（5 格）/ `.tone-warn|high|ban` / `.trust-history` / `.violation-form` 样式。
  - 全局升 `app.css?v=46→47`（11 个页面 head：`admin.php`、`account.php`、`admin_orders.php`、`bans.php`、`index.php`、`dashboard.php`、`install.php`、`register.php`、`pay.php`、`logs.php`、`tickets.php`）；未动 JS，`app.js?v=24` 不变。
- 数据库：新表 `user_violations`（v20 迁移）；实机库 **已迁移至 v20**（`schema_version=20`）。旧库访问任一页面即自动迁移，无需手工补列。`users.violation_points` 保留但不再读写。
- 回退：本阶段不 commit。如需回退，`git checkout --` 上述文件并 `DROP TABLE user_violations`、`UPDATE config SET value='19' WHERE name='schema_version'`。

### [2026-09-27 19:48:37] ND 需求实现·阶段7：R6 工单评价 + 评价统计

- 目的：完成 `TASKS_ND.md` 阶段 7。R6 —— 工单**任一方式结束**（`closed` / `expired`）后邀请用户评价：站内直接打分（0.5 ~ 5.0，步进 0.5，共 10 档，星星展示），**可重复提交覆盖**上次评分，**用户自选匿名与否**，留言可选；评价**只在站内使用、不共享到外部**，外部渠道的用户反馈走管理员配置的**外部调研链接**；后台提供**独立「评价统计」页**（平均分 / 分布 / 最近明细）。
- 关键设计：
  - 评分档位唯一来源 `TicketService::scoreOptions()`（由常量 `MIN_SCORE=0.5` / `MAX_SCORE=5.0` / `SCORE_STEP=0.5` 推导，共 10 档），`submitReview()` 用 `in_array(..., scoreOptions(), true)` 严格校验，非档位分值（如 0.3）直接拒收，避免 `DECIMAL(2,1)` 落库出脏值。
  - 覆盖而非新增：`submitReview()` 用 `INSERT ... ON DUPLICATE KEY UPDATE`（依赖阶段 0 已建的 `uniq_ticket_review(ticket_id)`），重复提交只更新 `score`/`is_anonymous`/`comment`，**不新增行**——这是「每张工单至多计入 1 条」的结构保证，也让统计口径天然去重。
  - 权限与状态校验集中在服务层：`submitReview()` 先 `findForUser($ticketId,$userId)` 确认归属，再要求 `isEnded($status)`；`tickets.php` 的 `submit_review` 分支额外拒绝管理员提交（管理员侧只有只读视图）。
  - 匿名只在「对外展示」环节生效：`is_anonymous` 原样落库（便于本人回看与后台审计），后台明细按标记隐藏用户名，页面用 `status-badge` 显示「匿名」。
  - 星星选择器为**纯 CSS + radio**：`.star-rating` 底层灰星 + 上层金层 `.star-rating-fg` 用宽度百分比裁剪出半星，10 个 `<label>` 各占 10% 宽做点击区；选中后金层宽度由 `.star-rating:has(input[value="x.y"]:checked)` 十条规则更新，**无需任何 JS**（CSP 禁内联脚本，且本次不动 `app.js`）。服务端同时把已有分数写进内联 `--pct`，即使浏览器不支持 `:has()` 也能正确回显历史评分。
  - 调研入口为全局配置 `config.ticket_survey_url`（键 `TicketService::SURVEY_URL_CONFIG_KEY`），`saveSurveyUrl()` 仅接受 `http(s)://`、留空即关闭；渲染处 `target="_blank" rel="noopener noreferrer"`。
  - 后台新增独立页签 `reviews`（`adminTabs()` 第 3 项），含平均分（大号数字 + 静态星级条）、10 档分布条形（以最大档计数为 100% 基准）与最近 50 条明细；分布表 `array_reverse(..., true)` 让 5.0 档显示在最上方，符合阅读习惯。空态与「未配置」态均单独处理，不再出现空白面板。
  - 统计 SQL 只做 `COUNT(*)` / `AVG(score)` / `GROUP BY score` 三件小事，分布数组在 PHP 侧补齐 0 值档位，保证 10 列恒在（前端不用判空）。
- 验证：
  - `php -l` 全通过：`src/Tickets/TicketService.php`、`tickets.php`、`admin.php`、`src/Schema.php`（附带复查）、`src/helpers.php`、`src/NotionClient.php`、`src/NotionSpaceRepair.php`。
  - 临时 PDO 脚本（事务内验证、结束即 `rollBack`，脚本已删除）`PASS 22 / FAIL 0`：档位 10 档且首末为 0.5 / 5.0；非法分值 0.3 被拒；非法调研链接 `ftp://` 被拒；首次提交可读回（分数 / 匿名 / 留言逐项比对）；**覆盖提交后总行数不变而分数、匿名标记、留言均已更新**；统计条目数 / 平均分 / 10 档分布 / 5.0 档计数正确；匿名条目标记可供后台隐藏用户名；事务回滚后评价表零残留；调研链接「保存→读回→空白关闭→恢复原值」往返正确。
  - 真实 HTTP 端到端（自建临时 session 文件 + curl，`save_path` 为空故用系统默认会话目录；验证后已 `session_destroy`）：用户 `tickets.php?id=12`（`closed`）与管理员 `admin.php?tab=reviews` / `?tab=tickets` 均 200；用户侧含 `star-rating`、`submit_review`、`is_anonymous`、恰好 10 个 `name="score"`；表单 POST 返回 302 且落库；再次 POST 覆盖后**主键不变**（id=3）、分数 4.5→2.0、匿名 1→0、`updated_at` 前移、总数仍为 1；回显侧 `--pct: 90.00%` 与 4.5 档 `checked`、匿名勾选均正确；管理员详情页显示只读评价块；后台统计页平均分 `4.50` 与匿名徽标正常。临时链接与评价数据已清理（`remaining_reviews=0`、`survey_url=''`），5 个临时脚本全部删除（`Glob _tmp*.php` 无结果）。
- 受影响表/列：读 `ticket_reviews`（全列）、`tickets`（`id`/`user_id`/`status`）；`config` 新增键 `ticket_survey_url`（首次读取回落空串 = 不展示入口）。**无新增表/列，旧库无需迁移**（`ticket_reviews` 在阶段 0 v19 已建）。
- 前端资源：`assets/app.css` 新增评价卡片 / 半星选择器 / 统计条形样式（L122-L156），故**全局升 `app.css?v=45→46`（11 页：admin / account / admin_orders / bans / index / dashboard / install / register / pay / logs / tickets）**；未动 JS，`app.js?v=24`、`admin.js?v=9` 不变。
- 改动明细：
  - `src/Tickets/TicketService.php` L43-L52 — 新增常量 `MIN_SCORE` / `MAX_SCORE` / `SCORE_STEP` / `MAX_COMMENT_LENGTH` / `SURVEY_URL_CONFIG_KEY`
  - `src/Tickets/TicketService.php` L827-L841 — 新增 `scoreOptions()`（10 档，唯一真源）
  - `src/Tickets/TicketService.php` L843-L861 — 新增 `reviewFor()`（每单至多一条）
  - `src/Tickets/TicketService.php` L863-L902 — 新增 `submitReview()`（归属 + 结束态 + 分值校验 + UPSERT 覆盖）
  - `src/Tickets/TicketService.php` L904-L927 — 新增 `reviewStats()`（总数 / 平均分 / 10 档分布）
  - `src/Tickets/TicketService.php` L929-L946 — 新增 `recentReviews()`（最近明细，匿名不展示用户名）
  - `src/Tickets/TicketService.php` L948-L969 — 新增 `surveyUrl()` / `saveSurveyUrl()`（`config.ticket_survey_url`，仅 `http(s)` 或留空）
  - `tickets.php` L83-L99 — 新增 POST 分支 `submit_review`（管理员拒收、成功后重定向回详情页）
  - `tickets.php` L193 — 初始化 `$review = null;`
  - `tickets.php` L209 — 详情页加载 `$review = $service->reviewFor($ticketId);`
  - `tickets.php` L416-L422 — 详情页计算 `$canReview` / `$reviewScore` / `$reviewComment` / `$reviewAnonymous` / `$surveyUrl`
  - `tickets.php` L536-L585 — 新增「工单评价」面板：用户侧半星打分表单（可覆盖）+ 匿名开关 + 留言；管理员侧只读；末尾按配置渲染「邀请您参加调研 → 点我参加」
  - `admin.php` L30 — `adminTabs()` 新增 `reviews` 页签（`评价统计`）
  - `admin.php` L948-L958 — 新增 POST 分支 `save_survey_url`（含 `Logger::info('ticket', ...)` 审计与空值关停提示）
  - `admin.php` L1566-L1577 — `?tab=tickets` 新增「外部调研链接」配置面板（含启用状态徽标）
  - `admin.php` L1635-L1698 — 新增 `?tab=reviews` 面板：「评价概览」（平均分 + 10 档分布条形）与「最近评价」明细表
  - `assets/app.css` L122-L156 — 评价卡片 / 半星选择器 / 统计条形样式
  - 11 个页面 head — `app.css?v=45→46`

### [2026-09-27 19:36:49] 新增 Notion 空间页自愈模块：归档类错误自动修复并重试一次

- 目的：线上出现用户空间页面被移入 Notion 回收站后，上传与删除持续报错（`Can't edit block that is archived. You must unarchive the block before editing.` / `Can't edit page on block with an archived ancestor. ...`），`upload_replicas` 里还有 active 副本导致 `ensureUserSpacePage()` 的自检（仅在 `accountReplicaCount()===0` 时触发）不会重建页面，故障会一直复现。这类故障不涉及数据丢失（页面及其下文件块都还在，只是被归档），属于可自愈范围，因此新增模块：命中特征明确的错误文案时先自动修复再重试一次，其余错误行为完全不变。
- 关键设计：
  - 判定与修复分离：`isRepairable()` 只按小写错误文案匹配三个特征（`block that is archived` / `archived ancestor` / `you must unarchive`），特征之外一律不修，避免误改用户数据。
  - 修复顺序：先解析空间页与根页（官方账户读 `official_accounts.root_page_id` + `user_official_accounts.notion_page_id`；自有账户读 `users.notion_parent_page_id` / `users.notion_page_id`），再沿祖先链由近及远把 `in_trash`/`archived` 的页面 `restorePage()`（上限 6 层，防父链成环）；页面已彻底删除（`retrievePage` 抛异常）时按原父页面 `createUserSpace()` 重建并回写缓存（官方用 `rememberUserSpacePage()`，自有 `UPDATE users SET notion_page_id`）。
  - 全程不抛异常：`repair()` 内部捕获 `Throwable` 并返回 `array{repaired,action,page_id,detail}`，调用方据此决定是否重试，修复失败仍抛**原始异常**，日志/告警行为与修复前一致。
  - 接入点集中在 `notionWriteWithRepair()` 包装器，上传（`writeNotionReplicas`，含 `ensureUserSpacePage` + `uploadAndAttach`）与删除（`archiveUploadReplicas` → `archiveBlock`）两条路径各接入一次；`role` 由包装前算好传入闭包，保证重试后副本序号不跳号。
  - 每次修复动作写 `Logger::warning('notion', '检测到 Notion 页面被归档，已尝试自动修复。', ...)`，含 `reason` / `repaired` / `action` / `page_id` / `detail`，便于事后核对。
- 验证：`php -l` 通过（`src/NotionSpaceRepair.php` / `src/NotionClient.php` / `src/helpers.php`）。临时脚本（**只调用 `repair()` 与 `NotionClient`，不经过 `writeNotionReplicas` / `archiveUploadReplicas`，以规避 `raiseAdminAlert` 真实发信**）跑通后已删除，结果 `PASS 7 / FAIL 0`：① `isRepairable()` 3 个正例 + 5 个反例（含空串）判定正确；② 对健康空间页跑 `repair()` 返回 `repaired=false / action=none`，不产生多余动作；③ 用临时探针页面端到端复现——建页 → 写入成功 → `archivePage()` → 写入报出真实归档错误且 `isRepairable()===true` → `restorePage()` → **重试写入成功**（核心假设成立）→ 探针块与页面已清理；④ 修复动作日志按预期落 `notion` 通道。注：本机到 Notion 的连接偶发 15s 超时，会掩盖归档错误，此时 `isRepairable()` 判 false 不触发修复，属预期。
- 受影响表/列：读 `official_accounts(root_page_id)`、`user_official_accounts(notion_page_id)`、`users(notion_parent_page_id, notion_page_id)`；重建空间页时写 `user_official_accounts.notion_page_id` 或 `users.notion_page_id`。**无新增表/列，旧库无需迁移。**
- 无前端资源改动（未动 `assets/app.css` / `app.js`），故**不升版本号**（仍为 `app.css?v=45`、`app.js?v=24`、`admin.js?v=9`）。
- 改动明细：
  - `src/NotionSpaceRepair.php`（新建）— 自愈模块：`isRepairable()` / `repair()` + 私有 `resolveSpace()` / `state()` / `ancestorChain()` / `persist()` / `result()`
  - `src/NotionClient.php` L122-L126 — 新增 `restorePage()`（PATCH `/pages/{id}` `in_trash=false`），与 `archivePage()` 对称
  - `src/helpers.php` L11 — 引入 `use NotionDrive\NotionSpaceRepair;`
  - `src/helpers.php` L1383-L1428 — 新增 `notionWriteWithRepair()`：命中可修复特征 → `repair()` → 重试一次；修复未成功时抛原始异常
  - `src/helpers.php` L1437-L1489 — `writeNotionReplicas()` 改用 `notionWriteWithRepair()` 包装上传闭包（`role` 提到循环开头计算）
  - `src/helpers.php` L1546-L1562 — `archiveUploadReplicas()` 改用 `notionWriteWithRepair()` 包装 `archiveBlock()`

### [2026-09-27 19:01:47] ND 需求实现·阶段5+6：R5 工单过期档位 + R7 重开规则

- 目的：完成 `TASKS_ND.md` 阶段 5/6。R5 —— 工单在「客服已回复、等待用户回复」且超期后自动过期（`expired`），过期后仅保留对话记录、附件立即清除、不可回复也不可重开；过期天数设全局默认档位（1/7/15/30 天 + 不过期），后台可设。R7 —— 关闭后的工单可在 30 天窗口内重开，每单最多 5 次，双方均可发起，复用原工单（`status=open`、清空 `closed_at`、`reopen_count+1`）；次数用尽/超窗口仅清附件、对话保留；重开入口带站内确认弹窗。
- 关键设计：
  - 修复 `Schema::createTicketsTable()` 与 v19 迁移的**结构漂移**：全新安装（`install.php` 直写 `schema_version=19`）时 `CREATE TABLE tickets` 缺 `closed_at` / `reopen_count` / `expired_at` 三列，已按迁移块顺序补齐（`closed_at AFTER last_message_at`、`reopen_count AFTER closed_at`、`expired_at AFTER reopen_count`）。**旧库无需手工补列**（v19 迁移已建）。
  - `TicketService` 新增：常量 `MAX_REOPEN=5` / `REOPEN_WINDOW_DAYS=30` / `EXPIRE_CONFIG_KEY='ticket_expire_days'`；`expirePresets()`、`expireDays()`；`expireDueTickets()`（规则一：`status IN(open,processing)` 且最后一条留言为客服且 `COALESCE(last_message_at,created_at) <= now-N天`；规则二：`status=closed` 且 `COALESCE(closed_at,last_message_at,created_at) <= now-30天`；cutoff 用 PHP `date()` 计算避免 INTERVAL 占位符）；私有 `expireTicket()`（`UPDATE ... WHERE status<>expired`，`rowCount()>0` 才 `purgeAttachments()`，幂等）；`reopenTicket()` / `reopenState()` / `reopenInfo()` / 私有 `reopenDeadlinePassed()`。
  - `closeTicket()` 补写时间戳列：`expired → expired_at`，其余（closed 等）→ `closed_at`。
  - `appendAppealMessage()` 改 `ORDER BY id DESC LIMIT 1`：命中可重开的关闭单则**复用原单**（`reopenTicket`），已过期则新建，未结束则追加留言。
  - `appealThreadsByRecords()` SQL 改 `ORDER BY id DESC` 并修正去重（`if (!isset($threads[$recordId]))` 保留首条=最新一张，原先的守卫无效会误留最早单）。
  - 入口接入：`tickets.php`（顶部清理 + POST `reopen_ticket` + 详情页「重新开启工单」面板，含剩余次数/截止时间，可重开时 `data-confirm` 按钮）、`admin.php`（`?tab=tickets` 「工单过期设置」面板，`<input list>` + `<datalist>` 0/1/7/15/30 且支持手输；POST `save_ticket_settings` 校验 0~3650；顶部清理）、`dashboard.php`（顶部清理，失败不阻断文件页）。
  - CSP 禁内联脚本，过期档位改用纯 HTML `<datalist>`，**无需新增 JS**。
  - 过期默认档位取 **0（不过期）**：避免升级后自动把存量「客服已回复」的旧工单批量置过期、误清附件。
- 验证：`php -l` 全通过（Schema / TicketService / tickets.php / admin.php / dashboard.php）。临时 PDO 脚本直插工单与留言（**刻意不调用 `openTicket()`，规避 `raiseAdminAlert` 真实发信**）实跑 26 项断言后已删除：`PASS 26 / FAIL 0`（档位读取/回落、规则一命中与两类对照、幂等、不过期模式、规则二、重开状态/清 `closed_at`/计数/剩余次数、次数用尽/超窗口/已过期不可重开、`closeTicket` 写时间戳、申诉复用原单并计数 +1、同记录多单取最新）。
- 受影响表/列：`tickets`（读 `closed_at` / `reopen_count` / `expired_at`）；`config` 新增键 `ticket_expire_days`（首次读取回落 `0`）。
- 无前端资源改动（未动 `assets/app.css` / `app.js`），故**不升版本号**（仍为 `app.css?v=45`、`app.js?v=24`、`admin.js?v=9`）。

### [2026-09-27 18:50:19] ND 需求实现·阶段4：R2 禁止提交工单

- 目的：完成 `TASKS_ND.md` 阶段 4 —— 新增「禁止提交工单」（`user_ticket_bans`）增删查 + 期限（1/3/7/30 天 / 永久）到期自动失效；拦截**新建工单**入口（不影响查看/回复已有工单，不影响上传/下载/重命名/新建文件夹）；后台工单设置页提供禁止/解除入口。该标记与「封禁管理」的账户封禁（`user_bans`）**完全独立**，封禁不限制提工单。
- 关键设计：
  - 服务门面 `TicketBanService`（仿 `BanService`）：`isTicketBanned`/`activeTicketBan`/`ban`/`unban`/`bannedUsers`/`durations`；读取前先 `expireDue()` 把 `until <= NOW()` 的记录统一置 `is_active=0` 实现到期自动失效。`ban($days)` 仅 1/3/7/30 生效为定时，其余（含 0/null）为永久（`until=NULL`）。
  - 集中拦截：在 `TicketService::openTicket()` 开头判定，覆盖 `tickets.php` 表单与文件页快捷申诉两处新建路径，同时保留已有工单回复能力。
  - 表格 `user_ticket_bans` 阶段 0 已建（含 `is_active`/`lifted_at`，无 `lifted_by`），本阶段**无需迁移**。
  - 后台入口：`admin.php` `?tab=tickets` 面板 = 「禁止提交工单」表单（用户下拉 + 期限 select + 原因，`data-confirm`）+ 「已禁止用户」表格（行内解除）。
- 验证：`php -l` 全通过（admin.php / tickets.php / TicketBanService / TicketService / helpers.php）。临时 PDO 脚本实跑后已删除，断言全 PASS：期限档位 1/3/7/30/0；`ban(days=7)` 写入 `until` 约为 7 天后；`ban(days=0)` 为永久（`until=NULL`）；`bannedUsers()` 能关联出 `username/email`；到期后 `isTicketBanned=false` 且记录置失效；禁止时 `openTicket` 抛「你已被禁止提交工单，请联系管理员。」。（与 bug 修复共用同一临时脚本批次验证。）
- 无前端资源改动（未动 `assets/app.css` / `app.js`），故**不升版本号**（仍为 `app.css?v=45`、`app.js?v=24`、`admin.js?v=9`）。
- 改动明细：
  - `src/Tickets/TicketBanService.php`（新建，143 行）— 禁止提交工单门面：期限档位、增删查、到期自动失效、后台列表
  - `src/Tickets/TicketService.php` L91-L96 — `openTicket()` 开头集中拦截被禁止用户新建工单
  - `admin.php` L16 — 引入 `use NotionDrive\Tickets\TicketBanService;`
  - `admin.php` L901-L931 — POST 分发链新增 `ban_ticket_user` / `unban_ticket_user` 分支（含自我禁止校验、`Logger`、`flash`）
  - `admin.php` L1512-L1567 — `?tab=tickets` 面板：禁止表单 + 已禁止用户表格（替换原空壳面板）

---

### [2026-09-27 18:47:41] Bug 修复：封禁提示条「提交申诉」改为真正提交申诉工单

- 目的：账户被手动封禁后，页面顶端提示条文案「如有疑问请 提交申诉」的链接却跳到 `tickets.php` 普通新建工单：工单类别下拉**跳过「申诉」**，且 POST 会把 `appeal` 类别强制转回 `general`，用户实际无法提交申诉类工单。修复后点击该链接进入**申诉模式**：自动选好「申诉」类别、预填标题「账户封禁申诉」，用户只补申诉理由即可提交。
- 关键设计：
  - 入口带参：提示条链接改为 `tickets.php?appeal=1`（仅 `link` / `detail` 两种提示样式输出该入口，`plain` 不变）。
  - 申诉模式：`tickets.php` 以 GET `appeal=1` 且非管理员判定；该模式下表单用 hidden `appeal_mode=1` + hidden `category=appeal`，类别显示为**只读**「申诉」，标题预填、理由留空待补充。
  - 放行规则：POST `create_ticket` 仅在带 `appeal_mode=1` 时允许 `category=appeal`，其余入口（含构造请求）仍只收普通工单，避免绕过入口直接建申诉。
- 验证：新增临时脚本（PDO）实跑后已删除，9 项断言全 PASS：禁止/解除判定、到期自动失效并置 `is_active=0`、禁止时 `openTicket` 抛「你已被禁止提交工单，请联系管理员。」、未禁止时 `openTicket(...,'appeal')` 建单类别正确、`banNoticeHtml` 输出含 `tickets.php?appeal=1` 与「提交申诉」。相关文件 `php -l` 通过。
- 改动明细：
  - `src/helpers.php` L577-L578 — `banNoticeHtml()` 申诉链接 `tickets.php` → `tickets.php?appeal=1`
  - `tickets.php` L9、L19 — 引入 `TicketBanService` 并实例化 `$ticketBanService`
  - `tickets.php` L23-L24 — 新增 `$appealMode`（GET `appeal=1` 且非管理员）
  - `tickets.php` L39-L43 — POST `create_ticket` 放行规则（带 `appeal_mode=1` 才允许 `appeal` 类别）
  - `tickets.php` L158-L159 — 读取当前用户生效中的禁止提交工单记录 `$ticketBan`
  - `tickets.php` L221-L261 — 用户侧新建区三层分支：被禁止 → 禁止提示面板（含原因/期限，提示仍可查看回复已有工单）；否则按 `$appealMode` 分申诉表单 / 普通表单

---

### [2026-09-27 18:34:54] ND 需求实现·阶段3：R1 工单批量管理 + 归档页

- 目的：完成 `TASKS_ND.md` 阶段 3 —— 工单新增「已过期（`expired`）」终态；后台可按状态批量修改、批量归档、一键清除全部工单；用户侧可批量关闭、逐条移除；两端均新增「归档页（`tickets.php?view=archived`）」并可恢复。归档是 **viewer 维度**（普通用户自己的归档 / 每位管理员各自的归档）的**软隐藏**，不删工单、不删留言。
- 依据取舍（重要）：`TASKS_ND.md` R1 的 Q5 曾写「删除时一并清除附件」，但 Q1-补 定稿为「所有删除（含清除全部工单）都是进入归档，不是全站真删」，二者冲突，**按定稿实现**：归档 / 删除 = 写入 `ticket_archives` 的可恢复软隐藏，**不清附件**；附件仅在工单被置为**结束态（关闭 / 过期）**时由 `closeTicket()` → `purgeAttachments()` 清除。
- 关键设计：
  - 结束态统一判定：新增 `endedStatuses()` / `isEnded()`，把原先散落的 `status !== STATUS_CLOSED` 全部替换，使 `expired` 与 `closed` 行为一致（不可回复、不可上传附件、详情页显示「已结束」）。
  - 归档条件：普通用户 = 本人的工单且为结束态（`closed|expired`）；管理员**行内移除** = 仅 `expired`；管理员**批量归档 / 清除全部**属整理动作，不限状态（`onlyEligible=false`）。
  - 列表排除归档：`ticketsForUser()` / `allTickets()` 用 `NOT EXISTS (SELECT 1 FROM ticket_archives ...)` 过滤，排序改为结束态排最后。
  - 表单嵌套规避：列表的批量表单放在 `<table>` 外（`id="ticketBulkForm"`），行内勾选框用 HTML5 `form="ticketBulkForm"` 关联；每行「移除 / 恢复」用独立小表单放在 `<td>` 内。
- 改动明细：
  - `src/Tickets/TicketService.php` L23 — 新增 `STATUS_EXPIRED='expired'`；L28-L30 — 新增 `VIEWER_USER/VIEWER_ADMIN`；L78-L83 — 新增 `endedStatuses()` / `isEnded()`；L328 — 新增 `archivedTickets()`；L372 — 新增 `archiveTickets()`（`INSERT IGNORE` 幂等）；L408 — 新增 `archiveAllTickets()`；L428 — 新增 `restoreTickets()`；L449 — 新增 `setStatuses()`（目标为结束态时走 `closeTicket` 以清附件）；L483 — 新增私有 `normalizeIds()`；L569 — `closeTicket()` 增第 3 参 `$status`；`replyAsUser()` / `refreshDerivedStatus()` / `ticketsForUser()` / `allTickets()`（增第 4 参 `$viewerId`）同步适配。
  - `api/upload.php` L294-L295 — 工单附件上传的关闭判断改为 `TicketService::isEnded(...)`（过期工单同样禁止上传）。
  - `tickets.php` L15 — 新增 `$viewerKind`；L80-L148 — 新增 6 个批量动作（`batch_status` / `batch_close` / `batch_archive` / `archive_tickets` / `archive_all` / `restore_tickets`），ids 合并 `ids[]` 与 `ticket_id`，重定向保留 `view=archived`；L161/L173 — 新增归档视图判定与 `archivedTickets()` 分支，`allTickets()` 补第 4 参；L234-L345 — 列表 section 重写（标题栏「查看归档 / 返回工单列表」+ 批量栏 + 勾选列 + 行内移除 / 恢复）；L350 `$canReply` 与 L430 结束工单区块改用 `isEnded()`，并同步「已结束」文案（L385 / L391 / L426 附近）。
  - `assets/app.js` L672-L692 — 新增工单批量全选：`[data-check-all]` ↔ `[data-check-item]` 联动 + `[data-check-count]` 已选计数（必须放在上传区提前 `return` 之前，否则工单页不执行）。
  - `assets/app.css` L121 — 新增 `.ticket-status-expired`；L414-L424 — 新增批量栏 / 勾选列样式；L575-L588 — 移动端卡片：勾选列绝对定位到右上角、标题列 `.ticket-title-col` 顶替原首列排版。
  - 资源版本号全局升位：`assets/app.css?v=44→45`（11 页）、`assets/app.js?v=23→24`（9 页）。
- 数据库：使用阶段 0 已建的 `ticket_archives`（`ticket_id` + `viewer_kind` + `viewer_id`，含唯一键，`INSERT IGNORE` 幂等）。**旧库无需手工补列**（迁移在 `src/Schema.php` 建表清单内，首次请求自动 `CREATE TABLE IF NOT EXISTS`）。
- 验证：`php -l tickets.php`、`php -l src/Tickets/TicketService.php`、`php -l api/upload.php` 全部通过；`node --check assets/app.js` 退出码 0。临时 PDO 脚本实跑：`ticketsForUser(1)=2`、`allTickets()=10`、`allTickets(viewer=1)=10`、`archivedTickets(admin/user)=0`、状态键含 `expired`、`isEnded('closed'/'expired')=true`、`isEnded('open')=false`（脚本已删除）。HTTP：未登录访问 `tickets.php` 与 `tickets.php?view=archived` 均 302 跳登录。全库检索 `app.js?v=23` / `app.css?v=44` 在 `.php` 内**已无残留**。未做浏览器端到端复测（批量交互可由用户登录后目视确认）。

---

### [2026-09-27 18:06:52] ND 需求实现·阶段2（下）：R3 公告 · 工作台弹窗 + 提醒档位

- 目的：完成 `TASKS_ND.md` 阶段 2 的下半部分 —— 把后台配置的站点公告推送到用户**工作台（`dashboard.php`）**，以不可关闭的弹窗展示，并提供「每次/永久/3/7/15/30 天/自定义天数」提醒档位。
- 关键设计：
  - CSP 为 `script-src 'self'`，**禁止内联脚本**，故弹窗数据用**服务端渲染的隐藏 DOM**（`.announce-item` 带 `data-announce-id`/`data-announce-sig`，标题与 `renderMarkdown()` 后的正文直接内联），交互逻辑写进 `assets/app.js`。
  - 弹窗**不可 Esc、不可点遮罩关闭**，只能点「我知道了」退出；不复用 `openDialog()`（其自带 Esc/遮罩关闭）。
  - 已读状态用前端 cookie `nd_ann_<id>`，值 `{sig}~{expiresAt}`（`~` 为合法 cookie-octet；`expiresAt=0` 表示永久）。判定：cookie 缺失、或 `sig` 与服务器不一致（公告已更新）、或 `expiresAt` 已过期 → 展示；`sig` 一致且永久 → 跳过。「每次进入都提醒」不写 cookie（并清掉旧的），有限天数写 `max-age`，永久写约 10 年。
- 改动明细：
  - `dashboard.php` L319-L349 — `</main>` 后调用 `announcementList()`，有公告时渲染 `#announceModal.announce-overlay`（内含各条 `.announce-item`、底部 `#announceReminder` 档位下拉 + `#announceCustomDays` 自定义天数 + `#announceAck` 按钮）。
  - `assets/app.js` L145-L209 — 在 `askConfirm`（L142-L143）之后、上传区提前 return（L611）之前插入公告逻辑：读 cookie 判定待展示条目 → 显示弹窗并锁定背景滚动 → 档位切换时显隐自定义天数输入 → 点「我知道了」按档位写入/清除各条 cookie 后隐藏。
  - `assets/app.css` L393-L411 — 新增 `.announce-overlay`（含 `[hidden]` 覆盖，避免 `display:grid` 盖掉 `hidden` 属性）/`.announce-dialog`/`.announce-item`/`.announce-title`/`.announce-body`（含 `img`/`code`/列表排版）/`.announce-actions`/`.announce-remind`/`.announce-custom` 样式。
  - 资源版本号全局升位：`assets/app.css?v=43→44`（11 个页面：`account.php`、`admin.php`、`admin_orders.php`、`bans.php`、`dashboard.php`、`index.php`、`install.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）、`assets/app.js?v=22→23`（9 个页面：`account.php`、`admin.php`、`admin_orders.php`、`dashboard.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）。
- 数据库：无新增，沿用阶段 0 的 `announcements` 表。
- 验证：`php -l dashboard.php`、`php -l admin.php`、`php -l src/helpers.php`、`php -l site-asset.php` 全部通过；`node --check assets/app.js` 退出码 0（语法通过）。全库检索 `app.js?v=22` / `app.css?v=43` 在 `.php` 内**已无残留**（仅历史 CHANGELOG 记录保留旧值）。未做浏览器端到端复测（弹窗为纯前端交互，可由用户登录工作台后目视确认）。

---

### [2026-09-27 18:02:39] ND 需求实现·阶段2（上）：R3 公告 · 后台管理 + 图片代理

- 目的：落实 `TASKS_ND.md` 阶段 2 的前半部分 —— 后台「系统设置」内新增**多条目站点公告**管理（Markdown 正文 + 可插图片），并扩展 `site-asset.php` 支持公告图片代理输出。
- 关键设计：
  - CSP 为 `img-src 'self' data:` 且 `storage/.htaccess` 禁止 Web 直读，故公告图片与站点 logo/favicon 同走 **`site-asset.php` 代理**，新增 `k=media&f=<文件名>` 分支（白名单目录 `storage/announcements`，文件名正则 `^[A-Za-z0-9._-]+$`，`realpath` 防穿越）。
  - 公告更新时间反推「已读指纹」：`updated_at` 在保存/启停时**显式刷新**（`SET updated_at = NOW()`），后续前台据此判断是否需重新弹窗。
- 改动明细：
  - `src/helpers.php` L123-L266 — 新增 `renderMarkdown()`（按行解析标题/无序列表/有序列表/段落，正文标题降级为 h4~h6 以免与页面标题冲突）、`renderMarkdownInline()`（先抽行内代码占位 → `e()` 转义 → 再识别图片/链接/粗体/斜体 → 还原代码；图片与站内链接仅接受 `site-asset.php?k=media&amp;f=…`，外链仅接受 http(s)）、`announcementImageMarkdown()`（生成正文图片 Markdown 片段）、`announcementList()`（查 `is_active=1` 按 `updated_at DESC`，每条附 `sig = substr(md5(id|updated_at),0,16)`，读取失败返回空数组）。
  - `admin.php` L178-L235 — 新增 `storeAnnouncementImage()`（JPG/PNG/GIF/WebP ≤5MB，`getimagesize` 定 MIME，随机名 `ann-<hex>.<ext>` 存 `storage/announcements`）、`removeAnnouncementImage()`（正则 + `realpath` 限定目录内删除）、`announcementImagesInBody()`（正则提取正文引用的图片文件名）。
  - `admin.php` L429-L484 — POST 动作新增 `save_announcement`（标题必填 ≤200 字、正文必填；新图上传后把 Markdown 片段追加正文末尾；新建 INSERT、编辑 UPDATE 并刷新 `updated_at`）、`toggle_announcement`（`is_active = 1 - is_active` 并刷新 `updated_at`）、`delete_announcement`（先按正文引用清理 `storage/announcements` 图片再删行）。
  - `admin.php` L968-L979 — 渲染前载入 `$announcements`（含已停用）与 `$editingAnnouncement`（由 `?tab=system&edit=<id>` 预填）。
  - `admin.php` L1440-L1476 — 「系统设置」页签内、站点信息面板之后新增「站点公告」面板：公告列表（标题/状态/更新时间/编辑·启停·删除）+ 新建/编辑表单（标题、Markdown 正文、图片上传、启用勾选）；删除按钮挂 `data-confirm`。
  - `site-asset.php` L11-L36 — 重构为按 `k` 分流：`logo`/`favicon` 读 `storage/site`；新增 `media` 读 `storage/announcements/<f>`；其余 404。
- 数据库：使用阶段 0 已建的 `announcements` 表（`title`/`body`/`is_active`/`created_at`/`updated_at`），**无需再迁移**。
- 验证：`php -l admin.php`、`php -l src/helpers.php`、`php -l site-asset.php` 均通过。
- 待办（阶段 2 下半）：工作台弹窗 + `assets/app.js` 提醒档位逻辑 + 版本号升级；最后补 `TASKS_ND.md` 勾选与本节说明。

---

### [2026-09-27 17:50:31] ND 需求实现·阶段1：后台分区骨架 + R4 自定义站点标识

- 目的：落实 `TASKS_ND.md` 实现计划阶段 1 —— 为后续「工单设置」「还原/恢复」两个分区预置页签骨架，并实现 R4「自定义站点标识」（站点名称兼浏览器标题 / favicon / logo，留空回落「Notion Drive」）。
- 关键设计：`storage/.htaccess` 禁止 Web 直读，站点 logo/favicon 不能像静态资源一样直链，故**新增公开代理脚本 `site-asset.php`**（仿 `avatar.php`：白名单目录 `storage/site`、`realpath` 防穿越、按 `finfo` 输出真实 MIME）。该脚本**不做登录校验**，因登录页/注册页也需展示品牌标识。
- 改动明细：
  - `src/helpers.php` L42-L122 — 新增站点标识助手：`siteName()`（读 `site_name`，空回落默认，安装前用 `installed.lock` 守卫）、`siteTitleTag()`、`siteFaviconPath()`、`siteAssetUrl()`（生成 `site-asset.php?k=…&v=…` 代理地址 + 按文件基名 md5 破缓存）、`siteFaviconTag()`、`siteLogoPath()`、`brandMarkHtml()`（有 logo 用 `<img class="brand-logo">`，否则站点名首字母方块）、`brandHtml()`。
  - `site-asset.php`（**新增**）— 公开的站点 logo/favicon 输出接口；`k` 仅取 `logo`/`favicon`，路径必须落在 `storage/site` 内，否则 404。
  - `admin.php` L21-L31 — `adminTabs()` 5→7 分区：`files` 后插入 `tickets`（工单设置）、末尾加 `restore`（还原/恢复）；`system` 描述改为「站点信息、注册与邮箱、上传与支付」。页签导航与筛选均按 `$adminTabs` 动态渲染，无需另改。
  - `admin.php` L113-L175 — 新增 `storeSiteImage()`（logo：JPG/PNG/GIF/WebP ≤2MB，按 `getimagesize` 定 MIME；favicon：仅 `.ico` ≤1MB，用扩展名 + ICO 文件头 `\x00\x00\x01\x00` 双校验；随机文件名存 `storage/site`）与 `removeSiteAsset()`（`realpath` 限定 `storage/site` 内删除）。
  - `admin.php` L340-L367（`save_site`）— 新增保存 `site_name`，并处理 favicon/logo 的「删除勾选」与「上传替换」（替换前先删旧文件，避免 `storage/site` 残留）。
  - `admin.php` L963、L966 — head 改 `siteTitleTag('管理后台')`/`siteFaviconTag()`，侧边栏品牌改 `brandHtml('dashboard.php')`。
  - `admin.php` L1299-L1312（站点信息面板）— `<form>` 加 `enctype="multipart/form-data"`，新增「站点名称」输入、favicon 文件选择 + 当前值提示 + 删除勾选、logo 文件选择 + 当前值提示 + 删除勾选。
  - `admin.php` L1315-L1325 — 新增 `tickets`/`restore` 两个占位骨架面板。
  - 10 个页面 head 的硬编码 `<title>… · Notion Drive</title>` + favicon 改读配置，侧边栏品牌改 `brandHtml()`：`account.php` L189/L192、`admin_orders.php` L264/L267、`bans.php` L102/L105、`dashboard.php` L113-L115/L119、`index.php` L54-L55/L62、`logs.php` L65/L68、`pay.php` L134/L137、`register.php` L137-L138/L144、`tickets.php` L98/L101。`install.php` L241-L250 因安装前无 `config` 表，head/品牌**保持静态**（仅随全局版本号改动）。
  - `assets/app.css` L32 — 新增 `.brand-logo { width:32px; height:32px; display:block; border-radius:8px; object-fit:contain; }`。
  - 资源版本号全局升位：`assets/app.css?v=42→43`（11 个页面：`account.php`、`admin.php`、`admin_orders.php`、`bans.php`、`dashboard.php`、`index.php`、`install.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）。`app.js`/`admin.js` 未改动，版本号不变。
- 验证：`php -l` 校验 `src/helpers.php`、`site-asset.php`、`admin.php` 及其余 11 个页面 → **全部无语法错误**。全库检索 `app.css?v=` 11 处**均为 `?v=43`，无 `?v=42` 残留**；检索 `Notion Drive` 确认站点标识类硬编码已全部改读配置（`install.php` 与邮件/告警/NotionClient/支付商品名等**非站点标识**用途的字符串按 R4 范围保持原样）。未做浏览器端到端复测（需人工登录后台并上传 ico/图片，风险低，可由用户在「系统设置 → 站点信息」实测）。
- 影响（数据库结构）：无（复用 `settings` 键值，新增配置键 `site_name`/`site_favicon`/`site_logo`，无需补列）。

---

### [2026-09-27 17:42:19] ND 需求实现·阶段0：DB 迁移 v18→v19（新增 4 表 + tickets/users 加列 + install.php 同步）

- 目的：为 ND 需求清单（`TASKS_ND.md` 第五节实现计划）预置数据结构，属分阶段实现的第一步。本阶段只建表/加列，不含业务逻辑。
- 影响（数据库结构）：
  - `tickets` 新增 `closed_at DATETIME NULL`、`reopen_count TINYINT UNSIGNED NOT NULL DEFAULT 0`、`expired_at DATETIME NULL`（供 R5 过期 / R7 重开 / R6 评价统计使用）。
  - `users` 新增 `violation_points TINYINT UNSIGNED NOT NULL DEFAULT 0`、`violation_cycle_start DATETIME NULL`、`violation_ban_until DATETIME NULL`（供 R10 可信度跨周期累计与自动封禁使用）。
  - 新增表 `ticket_archives`（工单归档，用户/管理员各自折叠）、`ticket_reviews`（每个工单一条评价，支持匿名）、`user_ticket_bans`（禁止提交工单，独立标记、不计入封禁体系）、`announcements`（站点公告，多条）。
  - `Schema::CURRENT_VERSION` 18→19，旧库由 `migrate()` 的 `if ($version < 19)` 块自动补列/建表。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 18→19。
  - `src/Schema.php` L212-L235 — 新增 `if ($version < 19)` 迁移块（tickets/users 加列 + 调用 4 个建表方法）。
  - `src/Schema.php` L316-L387 — 新增 `createTicketArchivesTable()`、`createTicketReviewsTable()`、`createUserTicketBansTable()`、`createAnnouncementsTable()` 四个静态建表方法。
  - `install.php` L90-L92 — 内联 `CREATE TABLE users` 补 `violation_points`/`violation_cycle_start`/`violation_ban_until`。
  - `install.php` L114-L120、L128-L133 — 内联 `CREATE TABLE uploads` 补 `scope`/`ticket_id` 列与 `idx_uploads_scope`/`idx_uploads_ticket` 索引（**修既有漂移**：见 `REVIEW_FINDINGS.md` H1，此前全新安装因 `install.php` 直接写 `schema_version=CURRENT_VERSION` 使 `migrate()` 提前返回，导致这两个 v17 列永远不补，文件系统不可用）。
  - `install.php` L151-L154 — 安装流程补调 4 个新表创建方法。
- 验证：`php -l src/Schema.php`、`php -l install.php` 均无语法错误；用临时 PDO 脚本执行 `Schema::migrate()` 后核对：`schema_version=19`，4 张新表全部存在，`tickets`/`users` 6 个新列全部存在（临时脚本已按约定删除）。**已在本机实机库执行迁移**。
- 旧库补列说明：`migrate()` 幂等（`hasColumn`/`CREATE TABLE IF NOT EXISTS` 守卫），重复执行安全。**未 commit**。

### [2026-09-27 16:30:56] 新增「清除违规记录」：管理员手动清除 + 用户侧清除未通过审核提示

- 目的：**用户明确要求**。用户原话：「违规的话。就是用户违规的话，管理员可以手动清除，或者是管理员允许他重传了之后，也是自动清除这次违规的记录」「然后就是未通过那种审核的话，用户这边加一个清除记录」。核查后发现「允许重传后自动清除违规计数」**本就已实现**——`violationCount()`（`src/Bans/BanService.php` L67-L75）只统计 `status = 'rejected'`，而 `allowResubmit()` 会把状态改成 `resubmit`，自然脱离计数，因此本次只补齐两个缺失入口：①管理员的**手动清除**；②用户侧对自己面板上「未通过审核」提示的**清除（隐藏）**。
- 影响：
  - **新增审核状态 `cleared`（已清除违规）**：管理员清除后该条 `status = 'cleared'`，不再被 `violationCount()` 统计，也不会再触发自动封禁；与「放行重传」对称，会 `forgetHash()` 撤销内容指纹黑名单，避免用户再次上传同内容被直接拦截。**文件仍保持隔离**（内容确实未通过审核，此处不放行可用）。
  - **审核记录列表**新增：状态列出现「已清除违规」徽章；`rejected` 行的「复核」列新增「清除记录」按钮（带 `data-confirm`），仅 `rejected` 行可见（只有 rejected 计入违规）。`open` 行维持原有「不可用 / 重新上传」两条路径。
  - **用户面板（dashboard）**：每条「未通过审核」提示末尾新增「清除记录」按钮，点击后该条从面板隐藏；文件状态与后台记录均不变（软隐藏）。
  - **用户清除采用独立标记列 `moderation_records.user_dismissed`，不改 `status`**：这样用户清除后管理员仍能正常复核该记录（若直接改 status 会让文件永久隔离且无人可改）。`userNotices()` 增加 `AND user_dismissed = 0` 过滤。
  - **联动保护**：`closeRecord()` 一并把 `user_dismissed` 归零——管理员一旦复核（打回 / 放行重传 / 清除），用户此前的隐藏标记即被撤销，新结论会重新出现在用户面板上，避免「用户清除后管理员放行重传、用户却看不到重传提示」的死循环。
  - **数据库结构变化**：`moderation_records` 新增列 `user_dismissed TINYINT(1) NOT NULL DEFAULT 0`（`AFTER appealed_at`）。`Schema::CURRENT_VERSION` 17→18，旧库由 `migrate()` 自动补列（已在实机执行成功）；`moderation_records.status` 是 `VARCHAR(20)` 非 ENUM，新增 `cleared` 状态**无需 ALTER**。
  - 资源版本号升位：`assets/app.css?v=41→42`（11 个页面，因新增 `.notice-dismiss` 样式）。`app.js` / `admin.js` 未改动，版本号不变。
- 验证：
  - `php -l` → `src/Schema.php`、`src/Moderation/ModerationService.php`、`admin.php`、`dashboard.php` 均 **No syntax errors**。
  - 临时脚本（`storage/_verify_g.php`，**已删除**）跑 `Schema::migrate()`：`schema_version=18`、`user_dismissed=tinyint(1) default='0'` 已落库；`ModerationService::create()->userNotices(-1)` 无异常返回 0 行，`STATUS_CLEARED=cleared`。
  - 全库检索 `app.css?v=41` 无残留，11 处均为 `?v=42`。
  - 未做浏览器端到端复测（admin / dashboard 需人工登录，且实测涉及告警邮件路径，按约定规避）；建议人工登录后确认两个按钮的显隐与文案。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 17→18。
  - `src/Schema.php` L204-L211 — 新增 `if ($version < 18)` 迁移块，补 `user_dismissed` 列。
  - `src/Schema.php` L350 — `createModerationRecordsTable()` 建表语句补 `user_dismissed` 列（供全新安装）。
  - `src/Moderation/ModerationService.php` L25 — 新增常量 `STATUS_CLEARED = 'cleared'`。
  - `src/Moderation/ModerationService.php` L378-L397 — 新增 `clearRecord()`：`closeRecord(STATUS_CLEARED)` + `forgetHash()`。
  - `src/Moderation/ModerationService.php` L445 — `userNotices()` 查询增加 `AND user_dismissed = 0`。
  - `src/Moderation/ModerationService.php` L458-L490 — 新增 `dismissNotice()` 与私有辅助 `noticeExists()`。
  - `src/Moderation/ModerationService.php` L636-L642 — `closeRecord()` 的 UPDATE 增加 `user_dismissed = 0`。
  - `admin.php` L665-L681 — POST 分支扩为 `moderation_reject || moderation_resubmit || moderation_clear`，改用 `match` 分发到 `reject()/allowResubmit()/clearRecord()`。
  - `admin.php` L1113 — 审核记录状态徽章 `match` 新增 `STATUS_CLEARED => 已清除违规`。
  - `admin.php` L1138-L1144 — `rejected` 行「复核」列新增「清除记录」表单（`action=moderation_clear`）。
  - `dashboard.php` L61-L73 — 新增 `dismiss_notice` POST 处理（成功后 `302 → dashboard.php?cleared=ok`）。
  - `dashboard.php` L162-L166 — 新增清除成功 / 失败提示。
  - `dashboard.php` L220-L226 — 每条未通过审核提示新增「清除记录」表单按钮。
  - `assets/app.css` L108-L110 — 新增 `.notice-dismiss` 间距与按钮尺寸。
  - 11 个页面（`account.php`、`admin.php`、`admin_orders.php`、`bans.php`、`dashboard.php`、`index.php`、`install.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`）— `assets/app.css?v=41→42`。

---

### [2026-09-27 16:18:57] 删除「系统设置 · Notion 连接」面板，把「套餐」并入账户池管理

- 目的：**这是用户明确要求**。用户原话：「管理后台 · 系统设置 Notion 连接 删除 ，集成到账户池」。「系统设置」里的「Notion 连接」是**全局单例**（一个 Token + 一个根页面 + 一个套餐），而「账户池管理 → 官方账户池」本就按**每个账户**管理 Token 与根页面（保存/测试连接/删除），两者职责重叠；`src/Schema.php` L543-L567 甚至本就把旧的全局 Token 迁移成账户池的第一个账户。经与用户确认（用户选择「移到官方账户池面板」）后删除该面板，仅把其中唯一无处安放的全局项「套餐」并入账户池面板。
- 影响：
  - 「系统设置」页不再有「Notion 连接」面板，该页只剩 注册与邮箱 / 上传与容量 / 支付设置 / 站点信息。
  - 「账户池管理 → 官方账户池」面板顶部新增「官方账户套餐」表单（免费版 · 5 MB / 付费版 · 5 GB），保存写 `notion_plan`。
  - **配置键一律保留、未删除**：`notion_plan` 仍被 `officialFileLimitBytes()`（官方账户单文件上限是否放宽）与 `api/upload.php` 的超限提示读取；`default_page_id` / `notion_token` 仍被 `install.php` 写入、被 `src/Schema.php` 的迁移逻辑读取。**注意**：后台已不再提供修改全局 Token / 根页面 ID 的入口（改由账户池按账户维护），`default_page_id` 与 `notion_token` 现在只剩安装时写入的旧值。
  - 资源版本号升位：`assets/app.css?v=40→41`（11 个页面）。
  - 无数据库结构变化；无新增配置项。
- 验证：
  - `php -l admin.php` → **无语法错误**。
  - Grep 全库：`app.css?v=` 11 处全为 `?v=41`，**无 `?v=40` 残留**；`admin.php` 内已无 `$pageId`、无 `name="page_id"` / `name="notion_token"`、无 `value="settings"` 表单，保存分支 `'settings'` 已不存在（仅剩注释中的历史说明）。
  - Grep 确认其余配置键引用未被波及：`notion_plan` → `src/helpers.php` L150、`api/upload.php` L181；`default_page_id` / `notion_token` → `src/Schema.php` L550-L551、`install.php` L150-L152。
  - **未做端到端实测**：建议登录后台，在「账户池管理」把套餐切到免费/付费各保存一次，确认提示正常且与「系统设置 · 上传与容量」的上限行为一致；同时确认「系统设置」页已无「Notion 连接」面板。
- 改动明细：
  - `admin.php` 原 L1168-L1171 — 删除「系统设置 → Notion 连接」整块面板（新 Token / 根页面 ID / 套餐 + `action=settings` 表单）。
  - `admin.php` L529-L537 — 保存分支 `action === 'settings'` 改为 `action === 'save_plan'`，只校验并写 `notion_plan`；不再写 `default_page_id`、不再加密写 `notion_token`。
  - `admin.php` L980-L985 — 「官方账户池」面板描述补充「套餐决定官方账户的单文件上限…」，并在标题下新增「官方账户套餐」表单（`class="create-user-row slim"`，`action=save_plan`）。
  - `admin.php` 原 L763 — 删除 `$pageId = Database::config('default_page_id', '')`（面板删除后该变量已无使用者）；`$plan` 保留（供新表单回显）。
  - `admin.php` L28 — `adminTabs()` 中 `system` 的 `desc` 由「注册与邮箱、Notion 连接、上传与支付」改为「注册与邮箱、上传与支付」。
  - `admin.php` L702 — Notion 接口异常提示由「请检查「Notion 连接」设置后重试」改为「请到「账户池管理」检查对应账户的 Token 与根页面后重试」（面板已删，避免把管理员指向不存在的入口）。
  - `assets/app.css` L268-L269 — 新增 `.create-user-row + .create-user-row { margin-top: 12px; }`，给上下相邻的「套餐」表单与「添加账户」表单留出间距。
  - 版本号：`assets/app.css?v=40→41` — `account.php` L189、`admin.php` L870、`admin_orders.php` L264、`bans.php` L102、`dashboard.php` L101、`index.php` L57、`install.php` L232、`logs.php` L65、`pay.php` L134、`register.php` L139、`tickets.php` L98。
  - `README.md` L85 — 目录说明 `admin.php 用户与 Notion 连接管理` → `admin.php 用户、账户池与系统设置管理`（同步能力描述）。

---

### [2026-09-27 15:27:04] 封禁提示两处修复：去掉「。，」重复标点 + 弹窗里的「提交申诉」可点击

- 目的：**这是用户明确要求**。用户原话：「您的账户已被封禁（原因：管理员手动封禁。，封禁时间：2026-09-27 13:26）。如有疑问请 提交申诉。 申诉可以点击，和违规分区一样」。拆成两个问题：
  1. **重复标点**：`banNoticeMessage()` 把管理员手填的原因原样嵌进「（原因：X，封禁时间：…）」，而原因本身可能自带句尾句号（本例为 `管理员手动封禁。`），拼出来就成了「。，」。
  2. **申诉点不动**：页面顶端的封禁提示条（`banNoticeHtml`）本来就是 `<a href="tickets.php">提交申诉</a>`，可点击；但**被封禁用户点击上传/下载时弹出的右下角 toast 不是** —— `notify()` 用 `toast.textContent` 赋纯文本（链接被拍平成一串字），且 `.toast` 带 `pointer-events:none`、2.6 秒即消失。用户引用的文本与这条 toast 的内容逐字一致（含「请 提交申诉」之间的空格），故本次让 toast 也能像「违规分区」那样点进申诉。
- 影响：
  - 封禁原因尾部的标点（`。．.，,、；;：:！!？?`）在拼进模板前被剥掉；原因全为标点或空白时按「未说明」处理（沿用原有逻辑）。
  - 被封禁用户点上传/下载/重命名等被拦入口时，弹出的提示改为**渲染 HTML 并保留「提交申诉」链接**，可点击进入 `tickets.php`；停留 2.6 秒 → **8 秒**，且该条 toast 放开 `pointer-events`。
  - `plain` 模式本就没有申诉链接，取到的仍是纯文本，行为与原来一致。
  - 资源版本号升位：`assets/app.js?v=21→22`（9 个页面）、`assets/app.css?v=39→40`（11 个页面）。
  - 无数据库结构变化；无新增配置项。
- 验证：
  - `php -l src/helpers.php` → **无语法错误**；`node --check assets/app.js` → **退出码 0**。
  - 临时脚本实测 `banNoticeMessage()`（`.dev/tmp_punct_check.php`，**用完已删除**）：`管理员手动封禁。` / `管理员手动封禁` / `管理员手动封禁！！` / `   ` / `测试原因.` → 依次输出「（原因：管理员手动封禁，封禁时间：…）」「（原因：未说明，封禁时间：…）」等，**无「。，」**。
  - Grep 全库：`app.js?v=` 9 处全为 `?v=22`；`app.css?v=` 11 处全为 `?v=40`；**无 `?v=21` / `?v=39` 残留**。
  - **未做端到端实测**：建议用被封禁账号（`test1`）点上传/下载，确认弹窗里「提交申诉」可点、点击跳到工单页；再把后台「封禁提示样式」切到 `plain`，复核无链接时仍为纯文本提示。
- 改动明细：
  - `src/helpers.php` L366-L369 — `banNoticeMessage()` 新增原因尾部标点剥离：`preg_replace('/[。．.，,、；;：:！!？?]+$/u', '', $reason)`，避免原因自带句号时拼出「。，」。
  - `assets/app.js` L21-L33 — 新增 `notifyHtml(html, type, duration = 8000)`：用 `innerHTML` 渲染、加 `clickable` 类放开点击、停留 8 秒；`notify()`（纯文本版）保持不变。
  - `assets/app.js` L37-L41、L59、L67 — 封禁拦截模块由读 `#banNotice` 的 `textContent` 改为 `innerHTML`（变量 `banMessage` → `banToastHtml`），两处 `notify(...)` 改为 `notifyHtml(...)`，使弹窗保留可点击的申诉链接。
  - `assets/app.css` L366-L368 — 新增 `.toast.clickable { pointer-events: auto; }` 与 `.toast a { color:#fff; font-weight:600; text-decoration:underline; }`（toast 默认 `pointer-events:none`，不改则链接点不动）。
  - 版本号：`assets/app.js?v=21→22` — `account.php` L342、`admin.php` L1219、`admin_orders.php` L354、`dashboard.php` L295、`index.php` L83、`logs.php` L125、`pay.php` L257、`register.php` L183、`tickets.php` L299；`assets/app.css?v=39→40` — 上述 9 个，再加 `bans.php` L102、`install.php` L232。

---

### [2026-09-27 15:19:08] 工单附件不再受封禁拦截（申诉通道放行：上传 + 下载），个人文件上传/下载仍拦

- 目的：**这是用户明确要求**。用户上传截图（封禁用户 `test1` 打开 `tickets.php?pid=11`，红框圈出「工单附件」上传区与右下角的封禁 toast，批注「？？？？？这里不受限制」）并确认选择「工单附件区**不应该**被封禁限制」。依据：上一条（15:05:18 / 15:09:40）把工单附件上传区一并拦掉，但**封禁提示文案本身就是引导用户「提交申诉」，申诉走工单且需要上传证据**，拦掉附件自相矛盾。本次把工单附件这条申诉通道从封禁拦截中整体放行。
- 影响（属**权限放宽**，已向用户明示后果）：
  - 被封禁用户在工单页可正常**上传**附件（`#ticketDropZone` / `#ticketBrowseButton` / `#ticketFileInput` 从拦截列表移除），也可**下载**工单内附件（下载直链加 `data-ticket-download` 标记，从拦截选择器中排除）。
  - 服务端同步放开：`api/upload.php` 的封禁检查改为**只拦 `scope=drive`**（工单附件 `scope=ticket` 放行）；`api/file.php` 的封禁检查加 `!$allowedTicketDownload` 例外（该变量仅在 `scope=ticket && GET && 请求者为工单归属者` 时为 `true`，因此工单附件的**改名/删除仍被拦**，只有下载放行）。
  - 放开后果：被封禁用户可在**自己未关闭的工单**内上传附件；文件写入其 Notion 空间，不进「我的文件」、不计配额、不走内容风控，工单关闭时随工单从 Notion 一并删除。
  - **行为不变**：个人文件（drive）的上传、下载、重命名、移动、删除、新建文件夹仍全部拦截。
  - 资源版本号升位（因改了 `assets/app.js`）：`app.js?v=20→21`，同样 9 个页面：`account.php`、`admin.php`、`admin_orders.php`、`dashboard.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`。
  - 无数据库结构变化；无新增配置项。
- 验证：
  - `node --check assets/app.js` → **退出码 0**；`php -l api/upload.php` / `php -l api/file.php` / `php -l tickets.php` → **均无语法错误**。
  - Grep 全库检索 `app.js?v=` → 9 处全为 `?v=21`，**无 `?v=20` 残留**。
  - **未做端到端实测**：建议用被封禁账号在工单页点「选择文件 / 拖放附件」确认不再弹封禁 toast、能正常上传；再点附件「下载」确认可下载；同时回「我的文件」页确认上传/下载/重命名仍被拦。
- 改动明细：
  - `assets/app.js` L27-L37 — `blockedEntry` 移除 `'#ticketDropZone'`、`'#ticketBrowseButton'`、`'#ticketFileInput'`；下载选择器由 `a[href^="api/file.php"]` 改为 `a[href^="api/file.php"]:not([data-ticket-download])`；注释说明工单附件整体放行。
  - `tickets.php` L263 — 工单附件下载链接加 `data-ticket-download` 标记，与普通文件下载链接区分。
  - `api/upload.php` L39-L45 — 原 L39-L43 的**无条件**封禁检查改为闭包 `$assertNotBanned(string $scope)`，仅当 `$scope === 'drive'` 且存在有效封禁时 `$fail('account_banned', ...)`。
  - `api/upload.php` L302 — start 分支解析出 `$scope` 后调用 `$assertNotBanned($scope)`（原封禁检查位于此处之前的无条件位置，已下移）。
  - `api/upload.php` L516 — finish 分支解析出 `$scope` 后同样调用 `$assertNotBanned($scope)`，对 `scope=drive` 兜底（防止 start 通过后被封禁、或分块元数据被篡改）。
  - `api/file.php` L56 — 封禁检查条件由 `userActiveBan($user) !== null` 改为 `userActiveBan($user) !== null && !$allowedTicketDownload`（复用 L32-L37 现成的工单附件判定）。
  - 版本号：`assets/app.js?v=20→21` — `admin.php` L1219、`admin_orders.php` L354、`account.php` L342、`dashboard.php` L295、`index.php` L83、`logs.php` L125、`pay.php` L257、`register.php` L183、`tickets.php` L299。

---

### [2026-09-27 15:09:40] 封禁拦截扩大到文件操作按钮：重命名 / 移动 / 删除 / 新建文件夹也弹封禁提示

- 目的：**这是用户明确要求**，原话「其他的也提示啊」。上一条（15:05:18）只拦了上传与下载，被封禁用户点「重命名 / 移动 / 删除 / 新建文件夹」时前端不弹提示（虽然服务端 `api/file.php` / `api/folder.php` 本就会拒绝，但表现为「点了没反应」，用户不知道原因）。本次把这些入口也纳入同一套拦截，点击即弹后台配置的封禁模板文案。
- 影响：
  - 拦截范围扩为：上传区 / 工单附件区 / 下载直链，**加上** `[data-action]`（文件与文件夹的重命名、移动、删除）与 `#newFolderButton`（新建文件夹）。
  - 覆盖页面：`dashboard.php` 文件列表全部操作按钮；`admin.php` L1031 管理员代管文件列表的「重命名 / 删除」（与 `api/file.php` 的判定一致——那里判断的是**当前操作者**是否被封禁，因此管理员自身被封禁时同样被拦）。
  - **仍未拦**：`account.php` 的头像上传与保存资料、`tickets.php` 的提交工单/发消息/关闭工单。原因是这些属于账户设置与申诉通道，封禁用户仍需能改资料、提交申诉；如也要拦请另行告知。
  - 资源版本号升位（因改了 `assets/app.js`）：`app.js?v=19→20`，同样 9 个页面：`account.php`、`admin.php`、`admin_orders.php`、`dashboard.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`。
  - 无数据库结构变化；无新增配置项。
- 验证：
  - `node --check assets/app.js` → **退出码 0**；`php -l` 无改动 PHP 逻辑（仅版本号字符串），未重复全库扫描。
  - Grep 全库检索 `app.js?v=` → 9 处全为 `?v=20`，**无 `?v=19` 残留**。
  - **未做端到端实测**：建议登录一个被封禁账号，在文件列表点「重命名 / 移动 / 删除 / 新建文件夹」确认弹提示、且不进入行内改名或确认删除弹窗。
- 改动明细：
  - `assets/app.js` L22-L40 — 拦截模块重构：原 `uploadEntry` / `downloadEntry` 两个选择器合并为单个 `blockedEntry` 数组并 `.join(', ')`，新增 `[data-action]`、`#newFolderButton`；`isBlockedEntry` 改为单次 `closest(blockedEntry)`。捕获阶段 click + dragenter/dragover/drop 的拦截逻辑不变。
  - 版本号：`assets/app.js?v=19→20` — `admin.php` L1219、`admin_orders.php` L354、`account.php` L342、`dashboard.php` L295、`index.php` L83、`logs.php` L125、`pay.php` L257、`register.php` L183、`tickets.php` L299。

---

### [2026-09-27 15:05:18] 封禁用户禁止点击上传/下载按钮并弹出后台封禁模板文案，直链下载也返回模板文本

- 目的：**这是用户明确要求**，原话「封禁用户直接禁止点击上传和下载按钮，提示封禁（模板），且下载链接也提示返回」。此前被封禁用户仍能点击上传按钮、点下载链接，只有真正发起请求后才由后端报错；且报错文案是写死的两句话，与后台「封禁管理 → 封禁提示样式」（`ban_notice_mode`）里配置的模板不一致。本次让前端在点击/拖放时直接拦下并弹出**后台配置的同一份模板文案**，同时让下载直链被直接访问（JS 无法拦截）时由服务端返回同一份文案的纯文本版。
- 设计取舍（复用单一数据源）：页面顶端提示条 `banNoticeHtml()` 输出的 `<div id="banNotice">` 已按 `ban_notice_mode` 渲染好文案，前端 JS 直接读取它的 `textContent` 作为弹提示内容，后台改样式后三处（提示条 / 前端弹提示 / 接口响应）自动同步，避免模板在多处维护。
- 影响（含不可逆 / 用户体验后果，已向用户说明）：
  - 被封禁用户在**所有加载 `assets/app.js` 的页面**（9 个）点击上传区、浏览按钮、文件输入、工单附件上传区、以及任何 `api/file.php` 下载链接时，一律被 `preventDefault + stopPropagation` 拦下并弹红色提示；拖拽文件到上传区同样被拦。
  - **当时有意不拦**（**已被 15:09:40 的后续改动推翻，见下一条**）：重命名 / 移动 / 删除 / 新建文件夹按钮；`account.php` 的头像上传表单（属资料设置，非文件上传/下载）。
  - 资源版本号升位（因改了 `assets/app.js`）：`app.js?v=18→19`，共 **9 个页面**：`account.php`、`admin.php`、`admin_orders.php`、`dashboard.php`、`index.php`、`logs.php`、`pay.php`、`register.php`、`tickets.php`。
  - `tickets.php` **原先缺少 `#toast`**，导致该页 `notify()` 静默失败（提示不显示），本次一并补上。
  - 无数据库结构变化；无新配置项（沿用既有 `ban_notice_mode`）。
- 验证：
  - `php -l` 全库递归扫描所有 `*.php` → **无报错**；`node --check assets/app.js` → **退出码 0**。
  - Grep 全库检索 `app.js?v=` → 9 处全为 `?v=19`，**无 `?v=18` 残留**。
  - **未做端到端实测**：需人工登录一个被封禁的账号，在页面上点上传/下载确认是否弹提示、直链是否返回文案。建议由用户自测一次。
- 改动明细：
  - `src/helpers.php` L338-L353 — `banNoticeHtml()` 重构：`<div class="alert error" role="alert">` 增加 `id="banNotice"`（供 JS 读取文案），文案改由 `banNoticeMessage()` 生成。
  - `src/helpers.php` L355-L371 — **新增** `banNoticeMessage(array $ban, string $mode): string`：封禁文案正文（不含申诉入口），`plain`/`link` 返回「您的账户已被封禁。」，`detail` 追加原因与封禁时间。
  - `src/helpers.php` L373-L388 — **新增** `banNoticeText(?array $user): string`：纯文本版封禁文案，供接口错误响应（下载直链无法返回 HTML）使用；未封禁返回空串。
  - `api/file.php` L54-L62 — 封禁响应改用 `banNoticeText($user)`：GET 返回 `403` + 纯文本，POST 返回 `jsonResponse(..., 'account_banned', 403)`；替换掉原先写死的「账号已被封禁，暂时无法下载文件…」「账号已被封禁，暂时无法修改文件…」两句。
  - `assets/app.js` L22-L48 — **新增**封禁拦截模块（紧随 `notify` 定义之后、早退守卫之前，保证非 dashboard 页面也生效）：读取 `#banNotice` 文案，非空时在 `document` **捕获阶段**监听 `click`（拦 `#dropZone/#browseButton/#fileInput/#ticketDropZone/#ticketBrowseButton/#ticketFileInput` 与 `a[href^="api/file.php"]`）与 `dragenter/dragover/drop`，命中即 `preventDefault + stopPropagation` 并 `notify(msg, 'error')`。
  - `tickets.php` L298 — **补** `<div id="toast" class="toast" role="status" aria-live="polite"></div>`（原先缺失）。
  - 版本号：`assets/app.js?v=18→19` — `admin.php` L1219、`admin_orders.php` L354、`account.php` L342、`dashboard.php` L295、`index.php` L83、`logs.php` L125、`pay.php` L257、`register.php` L183、`tickets.php` L299。

---

### [2026-09-27 13:49:35] 工单附件：可上传文件并双写 Notion，但不进「我的文件」，工单关闭时从 Notion 删除

- 目的：**这是用户明确要求**，原话「工单系统允许上传文件，文件也放 notion。但是呢，工单系统上传的文件不会到我的文件，只在工单内有效。然后工单关闭的时候，文件就从 notion 里面删除」。此前工单只有文字对话，用户无法在工单里附上截图/日志等证据，只能先传到「我的文件」再发链接。本次让工单内可直接上传附件，复用现有分块上传与 Notion 双写能力，但**与个人网盘隔离**：不进「我的文件」、不计入个人容量配额、只在所属工单内可见可下载，且**工单关闭时从 Notion 真实删除**。
- 策略确认（AskUserQuestion，均采纳推荐项）：① 工单附件**不计入**个人容量配额；② 工单附件**不走**内容风控（关键词/AI/SHA-256 指纹全跳过）；③ **用户和管理员都可以**上传。
- 影响（**含不可逆后果，已向用户明示**）：
  - **关闭工单会真实调用 Notion 删除接口**（`archiveUploadReplicas` → `NotionClient::archiveBlock`），附件在 Notion 侧被归档/删除且**不可恢复**；本地 `uploads.status` 同步置 `deleted`（文件行保留作审计，不再出现在附件列表，因列表只查 `status='active'`）。
  - 数据库结构变化：`uploads` 新增 `scope VARCHAR(20) NOT NULL DEFAULT 'drive'`（区分 `drive`/`ticket`）与 `ticket_id BIGINT UNSIGNED NULL`，并加索引 `idx_uploads_scope`、`idx_uploads_ticket(ticket_id, status)`；`CURRENT_VERSION` **16→17**。**旧库无需手工补列**：`storage/installed.lock` 存在时每次请求自动 `Schema::migrate()` 叠加迁移；已实测确认自动升至 17。
  - 隔离方式：所有「我的文件」入口的查询（容量、重名、列表、文件夹计数、移动校验）统一加 `scope = 'drive'`，因此老数据（默认 `drive`）行为不变，工单附件天然被排除。
  - 权限约定：**下载**放宽为「上传者本人 / 工单提交者 / 管理员」三者皆可（便于用户在工单里看到自己附件、管理员查看用户附件）；**改名/删除**仍限「上传者本人或管理员」（未放宽，避免工单提交者改他人附件）。封禁用户按现有规则**仍不能**在工单内上传/下载（最小改动，非回归）。
  - 资源版本号升位（因改了 `assets/app.js` 与 `assets/app.css`）：`app.css?v=38→39`（11 个页面）、`app.js?v=17→18`（8 个页面）。
- 验证：
  - `php -l` 全库递归扫描所有 `*.php` → **无报错**；`node --check assets/app.js` → **退出码 0**。
  - 临时脚本 `_tmp_check_k.php`（**已删除**）经 `src/bootstrap.php` 触发 `Schema::migrate()` 后实测：`schema_version=17`、`CURRENT_VERSION=17`；`SHOW COLUMNS` 确认 `uploads.scope`（`varchar(20) default='drive'`）与 `uploads.ticket_id`（`bigint(20) unsigned default=NULL`）存在；`SHOW INDEX` 确认 `idx_uploads_scope`、`idx_uploads_ticket` 存在。
  - HTTP 冒烟（PHP 内建服务器 `127.0.0.1:8088`）：`assets/app.js?v=18 → 200`、`assets/app.css?v=39 → 200`；`tickets.php → 302`（未登录跳登录页，无致命错误）。
  - 全库检索确认版本号已无 `app.css?v=38` / `app.js?v=17` 残留。
  - **未做端到端上传/关闭实测**：真实上传会在用户 Notion 空间落文件，关闭工单会真实删除 Notion 文件；且实测需人工登录管理员（受浏览器自动化「不得代填凭据」约束）。建议由用户在页面自测一次完整流程。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` **16→17**。
  - `src/Schema.php` L195-L202 — 新增 v17 迁移块：`addColumn uploads.scope`（`VARCHAR(20) NOT NULL DEFAULT 'drive' AFTER user_id`）、`addColumn uploads.ticket_id`（`BIGINT UNSIGNED NULL DEFAULT NULL AFTER folder_id`）、`addIndex idx_uploads_scope`、`addIndex idx_uploads_ticket(ticket_id, status)`。
  - `src/helpers.php` L203 — `userUsedBytes()` 容量统计加 `scope = 'drive'`，**工单附件不计入个人配额**。
  - `src/helpers.php` L443 — `findActiveUploadByName()`（重名检测）加 `scope = 'drive'`，工单附件不参与「我的文件」重名。
  - `src/helpers.php` L1370 — `folderFileCountMap()`（文件夹内文件数）加 `scope = 'drive'`。
  - `dashboard.php` L77 — 文件总数 COUNT 加 `scope = 'drive'`。
  - `dashboard.php` L87 — 文件列表 SELECT 加 `scope = 'drive'`。
  - `admin.php` L726 — 后台用户「文件数」统计加 `scope = 'drive'`。
  - `admin.php` L750 — 后台用户文件列表加 `scope = 'drive'`。
  - `api/folder.php` L58 — 「移动文件到文件夹」的归属校验加 `scope = 'drive'`，工单附件不可被移动（`folder_id` 为 NULL，也无法落入任何文件夹；文件夹级联删除用 `folder_id IN (...)`，工单附件天然不受影响）。
  - `api/upload.php` L14 — 新增 `use NotionDrive\Tickets\TicketService;`（读 `STATUS_CLOSED`）。
  - `api/upload.php` L282-L296 — 新增闭包 `$resolveTicketScope(int $ticketId)`：查 `tickets` 校验工单存在且「工单提交者本人或管理员」；工单 `status = closed` 时 `409 ticket_closed` 拒绝；查不到 `404 ticket_not_found`。**start 与 finish 两阶段都调用**，防止上传途中工单被关闭仍能落库。
  - `api/upload.php` L299-L303（start）— 解析 `scope`（`POST.scope === 'ticket'` 才为 ticket，否则 drive）与 `ticket_id`，ticket 时执行 `$resolveTicketScope`。
  - `api/upload.php` L316（start）— 关键词风控条件加 `$scope === 'drive' &&`，工单附件跳过文件名关键词拦截。
  - `api/upload.php` L355-L370（start）— 文件夹校验 / `$resolveDuplicate` 重名处理 / `$assertCapacity` 容量校验改为 `if ($scope === 'drive')` 分支；ticket 时 `$folderId = 0` 且 `$resolved` 用固定值（原名、无重名处理、释放 0 字节）。
  - `api/upload.php` L405-L406（start）— 分块元数据新增 `'scope' => $scope, 'ticket_id' => $ticketId`，供 chunk/finish 阶段透传。
  - `api/upload.php` L511-L515（finish）— 从 meta 读回 `scope`/`ticket_id`，ticket 时**再次** `$resolveTicketScope` 复核（工单已关闭则拒绝落库）。
  - `api/upload.php` L543 / L562（finish）— 同 start：ticket 跳过重名与文件夹解析，drive 才走 `$resolveDuplicate`；`$assertCapacity` 亦只在 drive 执行。
  - `api/upload.php` L583-L590（finish）— INSERT 语句扩展为 `(user_id, scope, folder_id, ticket_id, …)`，ticket 时 `folder_id = NULL`、`ticket_id = 工单号`；drive 时 `ticket_id = NULL`。
  - `api/upload.php` L652（finish）— 写 `moderation_records` 等风控动作加 `$scope === 'drive' &&` 守卫，工单附件不落审核记录、不入 SHA-256 指纹库。
  - `api/upload.php` L39-L42 — **未改动**：封禁拦截仍在 scope 判断之前，工单附件同样受封禁限制。
  - `api/file.php` L32-L36 — 新增 `$allowedTicketDownload`：仅 `GET` 且文件 `scope='ticket'` 且 `ticket_id > 0` 时，查 `tickets.user_id`，若为当前用户则允许下载（**工单提交者可下载他人上传到自己工单的附件**）。
  - `api/file.php` L39 — 归属判断由「上传者本人或管理员」放宽为 `|| $allowedTicketDownload`；`POST`（改名/删除）不进入该分支，仍限上传者本人或管理员。
  - `src/Tickets/TicketService.php` L12 — 新增 `use NotionDrive\Logger;`。
  - `src/Tickets/TicketService.php` L341-L354 — 新增 `attachments(int $ticketId): array`：查 `uploads` 中 `scope='ticket' AND ticket_id=:ticket_id AND status='active'`，`LEFT JOIN users` 带出 `username`，按 `id ASC` 返回（供工单详情渲染附件列表）。
  - `src/Tickets/TicketService.php` L374 — `closeTicket()` 末尾新增 `$this->purgeAttachments($ticketId);`（关闭即失效：把该工单附件从 Notion 删除并本地标记 `deleted`）。
  - `src/Tickets/TicketService.php` L381-L412 — 新增私有方法 `purgeAttachments(int $ticketId): void`：遍历 `attachments()`；按 `user_id` 缓存 owner 行（`SELECT id, notion_token_encrypted, notion_account_source FROM users`）；逐个 `archiveUploadReplicas($attachment, $owner)`（**真实删除 Notion 块**，单份失败 `catch` 后记 `Logger::warning`，不中断其余）；随后 `UPDATE uploads SET status='deleted' WHERE id=:id`；owner 行缺失则 `continue`（不误删他人数据）。
  - `tickets.php` L76 / L89 — 新增 `$attachments = []` 初始化；`$ticketId > 0` 分支内 `$attachments = $service->attachments($ticketId);`。
  - `tickets.php` L244-L283 — 新增「工单附件」面板 `<section class="admin-panel">`：附件表格（文件名/大小/上传者/上传时间/下载 `api/file.php?id=X`，复用 `.file-table`/`.file-name`/`.file-kind`/`.file-actions`）+ 上传控件（`$canReply` 时显示 `#ticketAttachments[data-ticket]` / `#ticketDropZone` / `#ticketFileInput` / `#ticketBrowseButton` / `#ticketQueue`；否则提示「该工单已关闭，无法继续上传附件」）。
  - `tickets.php` L292 — 关闭工单按钮 `data-confirm` 文案追加「工单内的附件会从 Notion 一并删除且不可恢复」，让用户在确认框里看到不可逆后果。
  - `tickets.php` L298 — 新增 `<script src="assets/app.js?v=18" defer></script>`（此前 tickets.php **未引入** app.js）。附带副作用：关闭工单按钮原本就带 `data-confirm` 属性，但该属性只由 `app.js` 的站内确认弹窗处理，故此前**并不生效**（点一下就直接提交）；引入后关闭工单会真正弹出站内确认框，与本次「关闭即删附件」的提示文案一致。已通读 `assets/app.js` L1-L348 确认前段代码均为空值防护（`toast`/`fileSearch`/`newFolderButton` 判空、点击处理器 `closest` 后 `if (!row) return`），在无 dashboard 上传区的 tickets.php 上不会抛错。
  - `assets/app.js` L349-L398 — 把 `failureText`、`controlRequest`、`sendChunk` 三个函数**从 `addFiles` 之后上移**到早退守卫 `const dropZone = document.getElementById('dropZone');`（现 L557）**之前**（原位置删除）。原因：tickets.php 无 dashboard 上传区，若沿用原位置会在守卫处 `return` 而取不到这些函数。
  - `assets/app.js` L400-L555 — 新增自包含「工单附件」模块：读 `#ticketAttachments` 等 5 个元素，齐全才启用；`ticketValidate`（仅校验 900 字节文件名、`allowed` 扩展名、非空**，不做内容风控**）、`ticketAddRetry`/`ticketUpload`（`controlRequest({action:'start', scope:'ticket', ticket_id, filename, size})` → 逐块 `sendChunk`（失败重试上限 3、`409 chunk_out_of_order` 自同步断点）→ `controlRequest({action:'finish', upload_token})`）、`ticketRunQueue`（队列串行；仍有失败则 `notify(..., 'error')` 保留重试按钮不刷新，否则 `window.location.reload()`）、`ticketAddFiles`；末尾绑定 click/keydown/change 与 dragenter/dragover/dragleave/drop 监听。
  - `assets/app.css` L200-L201 — 新增 `#ticketAttachments { margin-top: 16px; display: grid; gap: 8px; }`：与上方附件表格留出间距，拖放区与上传队列之间保持 8px 间隔（复用 `.drop-zone`/`.upload-queue`/`.queue-item`/`.progress-track` 既有样式，无其他新增）。
  - 资源版本号升位：`app.css?v=38→39` — `dashboard.php` L101、`tickets.php` L98、`account.php` L189、`admin.php` L879、`admin_orders.php` L264、`bans.php` L102、`index.php` L57、`install.php` L232、`pay.php` L134、`logs.php` L65、`register.php` L139；`app.js?v=17→18` — `dashboard.php` L295、`tickets.php` L298、`account.php` L342、`admin.php` L1219、`admin_orders.php` L354、`index.php` L83、`pay.php` L257、`logs.php` L125、`register.php` L183。

### [2026-09-27 13:31:38] 重命名与新建文件夹：由浏览器 prompt 弹窗改为网页内行内编辑

- 目的：**这是用户明确要求**，原话「将重命名方式改为内嵌网页内，而不是通过浏览器的弹窗重命名」。原先文件重命名走 `window.prompt('输入新的显示名称')`、文件夹重命名走 `window.prompt('输入新的文件夹名称')`，弹窗由浏览器渲染、样式不可控、且在中途无取消之外的反馈；改为**在当前行就地**把名称变成输入框，回车/失焦提交、Esc 取消，交互留在页面内。经确认「新建文件夹」也一并改为内嵌编辑（用户选择「一起改」），故 `window.prompt('输入新文件夹名称')` 同步去除，**`assets/app.js` 中已无任何 `window.prompt`**。
- 影响：仅前端交互方式变化，**接口与校验完全未动**（仍走 `api/file.php?action=rename`、`api/folder.php?action=rename`、`api/folder.php?action=create`，服务端 `basename()`/`normalizeFolderName()` 及长度、控制字符校验照旧）。行为约定：输入框自动聚焦并全选（新建文件夹初值为空，不选中文本）；**Enter=提交、Esc=取消、失焦=提交**；值为空或与初值相同则不发起请求；提交失败由 `notify(..., 'error')` 提示并已自动还原为原显示/按钮。因改了 `assets/app.js` 与 `assets/app.css`，各页面资源版本号同步升位（`app.css?v=37→?v=38`、`app.js?v=16→?v=17`）以确保浏览器不取旧缓存。
- 验证：`node --check assets/app.js` → **退出码 0**（语法通过）。全库检索确认 `assets/app.js` 内已无 `window.prompt`、全库已无 `app.css?v=37` / `app.js?v=16` 残留引用。未做浏览器端到端复测（行内编辑为纯前端交互，风险低、不触发告警邮件，站点登录页需人工登录，可由用户在页面直接确认）。
- 改动明细：
  - `assets/app.js` L108-L145 — 新增行内编辑辅助函数 `startInlineRename(nameEl, onCommit, initial)`：`nameEl.hidden = true` 后在原位置 `insertAdjacentElement('afterend', input)` 插入 `input.inline-rename`（初值取 `initial`，未传时取元素当前文本 `baseline`），`focus()` + `select()`；`finish(commit)` 用 `settled` 标志防重复提交，Enter/Esc/`blur` 三处触发；提交走 `Promise.resolve(onCommit(value)).catch(...)`，失败提示并还原显示。
  - `assets/app.js` L183-L198 — 文件行 `action === 'rename'` 分支：原 `window.prompt('输入新的显示名称', current)`（原 L182-L191 删除）改为取 `row.querySelector('.display-name')` 调用 `startInlineRename` 并**提前 `return`**；提交成功后 `nameEl.textContent = data.name` 就地更新名称（无需刷新）。
  - `assets/app.js` L220-L226 — 原 try 块内处理 rename 的 `if (action === 'rename') {...} else { row.remove(); }` 结构拍平（rename 已在上方 `return`，此处只剩 move 刷新 / delete 移除）：`move` → `window.location.reload()`，其余 → `row.remove(); notify(data.message);`。
  - `assets/app.js` L243-L263 — 文件夹行处理器：`current` 改为删除提示专用取值；`body` 声明收敛为删除用途 `action: 'delete'`；`folder-rename` 分支（原 `window.prompt('输入新的文件夹名称', current)`，原 L235-L247 删除）改为调用 `startInlineRename` 并**提前 `return`**，提交成功后 `notify` 并 `window.location.reload()`（刷新以同步列表与各文件夹文件数）；其后的删除逻辑由原 `if/else` 拍平为直落执行。
  - `assets/app.js` L290-L312 — 新建文件夹按钮：原 `window.prompt('输入新文件夹名称')`（原 L292-L314 删除）改为点击后按钮就地换成输入框（`startInlineRename(newFolderButton, …, '')`，初值空串），回车/失焦提交 `api/folder.php?action=create`，成功 `notify` + `window.location.reload()`；`newFolderButton.hidden` 作重复点击守卫（输入期间按钮已被隐藏）。
  - `assets/app.css` L245-L248 — 新增 `.inline-rename` 样式：`width:100%; max-width:420px; height:30px; padding:0 8px; border:1px solid var(--primary); border-radius:6px; background:#fff; font-size/weight:inherit;`，与行内 `.display-name`/`.folder-link` 视觉一致，避免沿用全局 `input{height:44px}` 撑高表格行；另加 `.file-section-tools .inline-rename { max-width:220px; }`，新建文件夹输入框出现在工具栏按钮位置，收窄以免挤压右侧「已同步」状态文案。
  - 资源版本号升位（`app.css?v=37→38`）：`dashboard.php` L101、`account.php` L189、`admin.php` L879、`admin_orders.php` L264、`bans.php` L102、`index.php` L57、`install.php` L232、`pay.php` L134、`logs.php` L65、`tickets.php` L96、`register.php` L139；`app.js?v=16→17`：`dashboard.php` L295、`account.php` L342、`admin.php` L1219、`admin_orders.php` L354、`index.php` L83、`pay.php` L257、`logs.php` L125、`register.php` L183。
  - `api/file.php`、`api/folder.php` — **未改动**，重命名/新建接口与校验保持不变。

### [2026-09-27 13:23:39] 账户封禁提示：由「上传时报错」改为所有页面顶端统一提示

- 目的：**这是用户明确要求**，原话「账户封禁提示从上传文件提示改为在所有页面顶端提示您的账户已被封禁」。此前被封禁的用户只有在触发上传/下载/改文件时才会看到 `api/*.php` 返回的「账号已被封禁…」消息；改为在**每个页面顶端**统一显示提示条，进站即刻可见。经确认：① 覆盖范围为**全部页面**（含管理员的 admin / bans / logs / admin_orders）；② 三种展示内容**都实现**，由后台「封禁管理」选择。
- 影响：顶端提示条与接口层拦截**并存**——`api/upload.php`、`api/file.php`、`api/folder.php` 的 403/拒绝消息**保留不动**，作为兜底（封禁期间仍禁止上传/下载/修改/重命名）。新增配置项 `config.ban_notice_mode`，取值 `plain`（仅一句文案）/ `link`（文案 + 工单申诉入口，默认）/ `detail`（文案 + 封禁原因与时间 + 工单申诉入口）；取值缺失或非法时回退 `link`。**旧库无需手工补列/迁移**：该配置缺失时函数内回退默认值，安装向导的默认配置数组已同步补入（未升 `schema_version`）。
- 验证：`php -l` 全部 10 个涉及文件（`src/helpers.php`、`bans.php`、`install.php`、`dashboard.php`、`account.php`、`pay.php`、`tickets.php`、`admin.php`、`logs.php`、`admin_orders.php`）→ 均 `No syntax errors detected`。用临时脚本 `.dev/tmp_ban_notice.php`（**已删除**，全程包在事务内并 `rollBack()`）实测三种模式：`plain` → `您的账户已被封禁。`；`link` → 文案 + `<a href="tickets.php">提交申诉</a>`；`detail` → 文案 + `（原因：… ，封禁时间：2026-09-27 13:23）` + 申诉入口；未封禁用户返回空串（`is_empty=1`）；非法配置回退 `link`。回滚后复查 `config.ban_notice_mode=__MISSING__`、`user 2 生效封禁=0`，确认未残留。未做浏览器复测：顶端提示条只对**被封禁用户**显示，而管理员不会被封禁（`bans.php` 限制不能封管理员/自己），要看到效果需真实封禁用户并可能触发告警邮件，故只做单元级实测。
- 改动明细：
  - `src/helpers.php` L329-L360 — 新增全局函数 `banNoticeHtml(?array $user): string`：内部复用现成的 `userActiveBan($user)`（L320-L327），未封禁返回空串；按 `Database::config('ban_notice_mode', 'link')` 输出三种样式（文案固定以「您的账户已被封禁」开头，`detail` 时展开原因与封禁时间）。
  - `bans.php` L9 — 新增 `use NotionDrive\Database;`（用于读写 `ban_notice_mode`）。
  - `bans.php` L38-L52 — 新增 POST 分支 `save_notice_mode`：校验取值 ∈ {plain,link,detail}，写 `Database::setConfig('ban_notice_mode', …)`、记 `Logger::info`、flash 后重定向 `bans.php`。
  - `bans.php` L90 — 读取 `$noticeMode = Database::config('ban_notice_mode', 'link')` 供面板回显。
  - `bans.php` L113 — `<main class="drive-main admin-main">` 起始处输出 `<?= banNoticeHtml($currentUser) ?>`。
  - `bans.php` L128-L139 — 新增「封禁提示样式」面板（`<select name="ban_notice_mode">` 三选项 + 「保存样式」按钮）。
  - `install.php` L161 — 安装默认配置数组新增 `'ban_notice_mode' => 'link',`（与 `ban_threshold` 相邻）。
  - `dashboard.php` L124、`account.php` L211、`pay.php` L156、`tickets.php` L118、`admin.php` L890、`logs.php` L76、`admin_orders.php` L275 — 各自 `<main class="drive-main…">` 起始处插入 `<?= banNoticeHtml($user) ?>`（普通用户页，变量 `$user`）/ `<?= banNoticeHtml($currentUser) ?>`（管理员页，变量 `$currentUser`）。
  - `api/upload.php`、`api/file.php`、`api/folder.php` — **未改动**，接口层封禁拦截保留作兜底。

### [2026-09-27 13:10:59] 内容风控 · 审核记录：删除「豁免」动作，仅保留「重新上传」「不可用」

- 目的：审核记录的复核动作收敛为两个——「重新上传」（放开同名文件的上传前拦截）与「不可用」（判定违规、维持/执行隔离）；删除「豁免」（恢复文件可用），避免管理员一键让违规内容重新可用。**这是用户明确要求**：原话「管理后台 · 内容风控，审核记录，的豁免删除，只有重新上传和不可用」；经确认范围为「两行都删豁免动作」、文案统一为「重新上传」「不可用」。
- 影响（用户已确认的取舍，需知悉）：① 待复核行的动作由「豁免 / 允许重传 / 打回」变为「重新上传 / 不可用」；② 待人工复核（AI 降级）行的动作由「判定通过 / 判定违规」变为仅「不可用」——**AI 降级的记录此后无法再被人工判为通过**，只能判违规或让用户重传；③ 被判违规而隔离的文件**不再有「恢复可用」入口**（历史已豁免记录不受影响，状态仍显示「已豁免」）；④ 判定违规仍会计入违规累计、达阈值自动封禁（逻辑未动）。
- 验证：`php -l admin.php`、`php -l src/Moderation/ModerationService.php` → 均 `No syntax errors detected`。全项目 Grep 复核：已无 `moderation_exempt`（审核动作）与 `->exempt(` 残留；剩余的「豁免」字样均属**另一个功能**（`users.moderation_exempt` 的「免审核上传」面板 / 历史状态标签「已豁免」），本轮未改动。未做浏览器复测：需管理员登录且库里存在待复核记录才能看到按钮，且实操「不可用」会触发违规累计与自动封禁、可能发告警邮件，故只做静态验证。
- 改动明细：
  - `admin.php` L673 — 动作分支由 `moderation_exempt || moderation_reject || moderation_resubmit` 收窄为 `moderation_reject || moderation_resubmit`。
  - `admin.php` L680-L682 — 原 `match` 三分支改为三元判断：`moderation_reject` → `reject()`，否则 → `allowResubmit()`（移除 `exempt()` 分支）。
  - `admin.php` L1093 — 审核记录面板说明文字去掉「豁免后文件恢复可用，打回则维持不可用，允许重传…」，改为「不可用则维持隔离状态，重新上传仅放开同名文件的上传前拦截」。
  - `admin.php` L1130-L1133 — 待人工复核行：删除「判定通过」（`moderation_exempt`），「判定违规」按钮文案改为**不可用**；待复核行：删除「豁免」（`moderation_exempt`），「允许重传」文案改为**重新上传**、「打回」文案改为**不可用**（`data-confirm` 提示语保持）。
  - `src/Moderation/ModerationService.php` — 删除公开方法 `exempt()`（原 L326-L367，含恢复文件可用、修复悬空 `folder_id` 的逻辑）；该方法删除后已无任何调用方。随之移除其中**13:06:19 条目新增的**「exempt → rememberHash(APPROVED) 白名单联动」——白名单此后仅由上传时 AI 判定通过（`api/upload.php`）写入，功能不受影响。`STATUS_EXEMPTED` 常量保留（用于渲染历史记录状态）。
  - `src/Schema.php` L189 — 注释同步：`供管理员改判（豁免/打回/放行重传）` → `供管理员改判（打回/放行重传）`。
- 依赖与影响：无数据库结构变更；仅后台界面与动作收敛。未提交、未推送。

### [2026-09-27 13:06:19] 内容审核加 SHA-256 指纹（全站白/黑名单）+ 用户侧「未通过审核」清单折叠

- 目的：① 用户上传内容只要审核过一次，全站再次上传**同一内容**就不该重复跑 AI——已通过进白名单**免重复审核**直接放行，已违规进黑名单**直接拦截**，节省审核开销并保证同内容结论一致；② `dashboard.php` 的「有 N 个文件未通过内容审核」提示条内嵌完整文件清单，条目多时把上传区挤到首屏之外，改为**默认折叠**、可展开。
- 验证：
  - `php -l` 对 5 个文件（`src/Schema.php`、`install.php`、`src/Moderation/ModerationService.php`、`api/upload.php`、`dashboard.php`）→ 全部 `No syntax errors detected`。（过程备注：首次 `php -l src/Schema.php` 报 L341 解析错误，原因是新加的列写成 `DEFAULT ''` 未转义、提前闭合了单引号字符串，已改为 `DEFAULT \'\'` 后通过。）
  - 临时脚本（`.dev/tmp_hash_check.php`，**已删除**；指纹读写与 `record()` 均包在事务内并 `rollBack()`，库内无残留）实测输出：
    - 迁移生效：`schema_version=16`、`moderation_hashes_table=1`、`moderation_records.content_hash=1`（旧库由 `Schema::migrate()` 自动补表补列，无需手工迁移）。
    - `rememberHash($h, approved)` → `lookupHash($h)` 返回 `'approved'`；改为 `flagged` → 返回 `'flagged'`；`forgetHash($h)` → 返回 `NULL`（白→黑→撤销链路正确）。
    - 非法输入被归一化丢弃：`rememberHash('not-a-hash', …)` → `lookupHash('not-a-hash')` 返回 `NULL`；大写哈希 `strtoupper($h)` 登记后用小写查询仍命中 `'approved'`（`normalizeHash` 统一小写 + 64 位十六进制校验）。
    - `record(..., $hash)` 落库的 `moderation_records.content_hash` 与传入值**完全一致**；不传 hash（start 阶段路径）落库为 `''`。
  - 未做浏览器端到端复测：端到端需真实上传并触发 AI 审核，且违规分支会调用 `raiseAdminAlert` → 本地 SMTP 已配置、会**真实发邮件**（详见 12:56:39 条目副作用说明），故按既往约定不做会发信的真实复测，仅做静态 + DB 层验证。
  - 折叠为纯 CSS/HTML（`<details>`），未登录态无法渲染该区块（仅登录后 `dashboard.php` 可见），本轮未做浏览器截图验证。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` `15` → `16`。
  - `src/Schema.php` L187-L193 — 新增 `if ($version < 16)` 分段：建指纹表 + 给 `moderation_records` 补 `content_hash` 列。
  - `src/Schema.php` L198-L212 — 新增 `public static function createModerationHashesTable(PDO $pdo)`：建 `moderation_hashes`（`content_hash` 唯一键、`decision` 取值 `approved`/`flagged`、`idx_moderation_hash_decision(decision, updated_at)`）。
  - `src/Schema.php` L320 — `createModerationRecordsTable()` 的 CREATE 语句在 `filename` 之后插入 `content_hash CHAR(64) NOT NULL DEFAULT ''`。
  - `install.php` L139 — `Schema::createModerationRecordsTable($pdo);` 之后新增 `Schema::createModerationHashesTable($pdo);`（全新建库时同步建表）。
  - `src/Moderation/ModerationService.php` L216-L225 — `record()` 签名新增 `string $contentHash = ''`，方法体先 `$contentHash = self::normalizeHash($contentHash)`；L232 与 L241 把 `content_hash` 列/占位符接入 INSERT。
  - `src/Moderation/ModerationService.php` L263-L316 — 新增三个公开方法：`lookupHash()`（查全站结论，返回 `approved`/`flagged`/`null`）、`rememberHash()`（UPSERT 进白/黑名单，只接受两个合法 decision）、`forgetHash()`（删除指纹，供放行重传）。
  - `src/Moderation/ModerationService.php` L356 — `exempt()` 在 `closeRecord()` 后新增 `rememberHash($record['content_hash'], Verdict::APPROVED)`：管理员判定通过 → 内容进白名单。
  - `src/Moderation/ModerationService.php` L385 — `reject()` 在 `closeRecord()` 后新增 `rememberHash($record['content_hash'], Verdict::FLAGGED)`：管理员判定违规 → 内容进黑名单。
  - `src/Moderation/ModerationService.php` L412 — `allowResubmit()` 在 `closeRecord()` 后新增 `forgetHash($record['content_hash'])`：放行重传 → 撤销指纹，重传内容重新进入审核而非被指纹直接拦截。
  - `src/Moderation/ModerationService.php` L636-L643 — 新增私有静态 `normalizeHash()`：`strtolower(trim())` 后按 `/^[0-9a-f]{64}$/` 校验，合法返回小写、否则返回 `''`（视为未提供，`record()` 落空串、`lookupHash()` 返回 `null`）。
  - `api/upload.php` L11 — 新增 `use NotionDrive\Moderation\Verdict;`。
  - `api/upload.php` L597-L679 — finish 阶段审核块改为「先指纹、后 AI」：L603 用 `hash_file('sha256', $partPath)` 算全站内容指纹（`$partPath` 在 `register_shutdown_function` 清理前仍存在，可安全读取；哈希同时写入日志上下文 `file.sha256`）→ L607 `lookupHash()`：命中 `approved` **跳过审核**（仅 info 日志）；命中 `flagged` 不调用 AI，构造 `Verdict::flagged(...,'hash','sha256',$hash)` 走原违规分支；未收录才 `inspect()`。违规分支 L629 `rememberHash(..., FLAGGED)` 并入黑名单；待人工复核（pending）分支只 `record()` 带哈希、**不登记指纹**（结论未定）；审核通过分支 L676 `rememberHash(..., APPROVED)` 入白名单。所有 `record()` 调用均带上 `$contentHash`。
  - `dashboard.php` L152-L154 — 把 `<ul class="alert-list">` 前插入 `<details class="alert-fold">` + `<summary>点此展开 / 收起文件清单（N 个）</summary>`（无 `open` 属性，默认收起）。
  - `dashboard.php` L202 — 对应补上 `</details>` 闭合标签。
  - `assets/app.css` L86-L90 — 新增 `.alert-fold` 样式（`summary` 用主色、`[open]` 时留出下间距），仿既有 `.appeal-box`。
- 依赖与影响：**有数据库结构变更** —— 新增表 `moderation_hashes`、`moderation_records` 新增列 `content_hash`；已改动 `schema_version` 15 → 16。旧库由 `Schema::migrate()` 在下次请求时自动补建，无需手工迁移；全新建库走 `install.php`。指纹为**全站共享**（不同用户上传同内容共享同一结论），管理员改判（豁免/打回/放行重传）会联动白/黑名单。未提交、未推送。

### [2026-09-27 12:56:39] 用户提交新工单时告警管理员（后台告警条 + 邮件）

- 目的：用户提交工单后需管理员及时响应，此前无任何提醒，管理员只能主动翻看工单列表。改为：**每张新工单落地时写入一条后台告警**（管理后台顶部「N 条未读告警」可见），并按系统既有告警机制**同时发送告警邮件**（SMTP 已配置且存在收件人时）。
- 验证：
  - `php -l src/Tickets/TicketService.php` → `No syntax errors detected`。
  - 临时脚本（`.dev/tmp_alert_check.php`，已删除；全程在事务内并 `rollBack()`）实测：`openTicket(2, '临时验证：工单提交告警', …)` 返回 `ticket id=6`，`admin_alerts` 计数 11 → 12，新增记录 `alert#12 level=warning read=0 msg=用户「test1」提交了新工单 T-8BH3N5：临时验证：工单提交告警`；回滚后告警表恢复 11 条、临时工单消失（库内无数据残留）。
  - **副作用提示**：本地 `.dev` 已配置 SMTP（收件人 `3628385871@qq.com`），`smtpConfigured()=true`，因此上述实测**真实发出了 1 封告警邮件**（`sendAdminAlertMail` 对新告警发信，成功不记日志、失败才记，日志无失败记录）；日志另留有 1 行 `[WARNING] [alert] 用户「test1」提交了新工单 T-8BH3N5：…`。邮件不可撤回，若非预期请忽略。
  - 后台渲染复用既有告警条代码（`admin.php` L896-L908：`level === 'error'` 显示「严重」，其余显示「提醒」），无需改动即可展示；为避免重复发信，未再以浏览器真实提单复测。
- 改动明细：
  - `src/Tickets/TicketService.php` L116-L136 — `openTicket()` 在写库并落首条留言后新增告警：查提交人 `username`（L117-L119，取不到回退 `#用户ID`）、标题超 80 字截断加省略号（L120），再调用全局函数 `\raiseAdminAlert('warning', '用户「%s」提交了%s工单 %s：%s', ['ticket_id','ticket_no','user_id','category'])`（L121-L136）。级别取 `warning`（界面显示「提醒」）；`raiseAdminAlert` 自带「相同未读消息去重」与内部 catch，不会影响提单主流程。
  - 覆盖范围：`openTicket()` 是新工单的唯一入口（`tickets.php` L37 普通工单、`TicketService::appendAppealMessage()` L170 申诉快捷提单的首次新建），故普通工单与申诉首单都会告警。**仅在新建工单时告警**：申诉对已有工单的追加留言、以及用户对已有工单的回复均不告警。
- 依赖与影响：无数据库结构变更，复用既有 `admin_alerts` 表与 `alert_email` 配置，旧库无需迁移。未提交、未推送。

### [2026-09-27 12:53:43] 禁止管理员提交工单 + 删除「管理后台 · 工单管理」页签

- 目的：① **管理员不应能提交工单**——工单是用户向管理员提交的诉求，管理员只负责回复与关闭，不具备提交者身份；② 后台二级页签里的「工单管理」与侧栏一级页面 `tickets.php`（「工单管理」）功能重复，删除该页签以消除重复入口。
- 验证：
  - `php -l admin.php` → `No syntax errors detected in admin.php`；`php -l tickets.php` → `No syntax errors detected in tickets.php`。
  - Grep 确认 `admin.php` 中已无 `TicketService` / `adminTickets` / `tab=tickets` 任何残留；全项目无指向 `admin.php?tab=tickets` 的外链（删除安全，无死链）。
  - 浏览器实测（`127.0.0.1:8088`，管理员态）`admin.php`：页签导航只剩 5 项（用户管理 / 账户池管理 / 内容风控 / 文件管理 / 系统设置），**「工单管理」页签已消失**；页头 `管理后台 · 用户管理`；侧栏仍保留「✉ 工单管理」（指向 `tickets.php`，未受影响）。
  - 浏览器实测 `admin.php?tab=tickets`（意图访问已删除页签）→ 页头回落为 `管理后台 · 用户管理`、无任何工单列表内容，确认 `normalizeAdminTab()` 对已删除键返回 `''`、`$activeTab` 回落默认页（无 500 / 报错）。
  - 浏览器实测（管理员态）`tickets.php` → 页面**不出现**「提交新工单」面板，DOM 中无 `action="create_ticket"` 表单；仅有「工单列表」+ 状态/类别筛选与「查看」入口，符合「管理员只回复与关闭」的定位。普通用户侧由 `<?php if (!$isAdmin): ?>` 条件渲染，代码路径确认表单仍对其显示（未登录 test1 实测，仅代码核对）。
  - 说明：`tickets.php` 的 `<title>` 仍固定拼「我的工单」（L96），管理员态标题与页头「工单管理」略有不一致，属既有文案、本次未改（不在需求范围）。
- 改动明细：
  - `admin.php` L16 — 删除 `use NotionDrive\Tickets\TicketService;`（删页签后本文件不再引用该类）。
  - `admin.php` L23-L29 — `adminTabs()` 删除 `'tickets' => ['group' => '管理后台', 'label' => '工单管理', 'desc' => '…']` 一行，现存 5 个页签：`users` / `content` / `risk` / `files` / `system`。
  - `admin.php` 原 L812 — 删除 `$adminTickets = [];`。
  - `admin.php` 原 L821-L825 — 删除 `try { $adminTickets = TicketService::create()->allTickets(); } catch (Throwable $ticketException) { Logger::warning('ticket', '读取工单列表失败', …); }` 数据加载块。
  - `admin.php` 原 L1157-L1198 — 整块删除 `<?php if ($activeTab === 'tickets'): ?> … <?php endif; ?>` 渲染块（含「工单管理」说明面板、`$ticketCategories` / `$ticketStatuses` / `$openTicketCount` 预处理、工单列表表格）。现 L1149 之后直接衔接 `<?php if ($activeTab === 'system'): ?>`。
  - `tickets.php` L27-L31 — `create_ticket` 分支开头新增后端拦截：`if ($isAdmin) { throw new RuntimeException('管理员账号不能提交工单，请直接在用户工单里回复。'); }`（由外层 `catch (Throwable $exception)` 转成 `$error` 提示，属纵深防御，即使绕过页面直接 POST 也会被拒）。
  - `tickets.php` L126 / L145 — 「提交新工单」面板整体用 `<?php if (!$isAdmin): ?> … <?php endif; ?>` 包裹，管理员访问时不再渲染；同时 L135 类别下拉对所有人跳过 `CATEGORY_APPEAL`（申诉）。
- 依赖与影响：无数据库结构变更（不涉及 `schema_version` / 建表 / 补列），旧库直接升级无迁移动作。未提交、未推送。

### [2026-09-27 12:47:21] 新增「封禁管理」一级页面：违规累计自动封号 + 手动封禁，封禁期间禁上传/下载/修改/重命名

- 目的：用户要求新增一个**只对管理员可见的一级页面「封禁管理」**（侧栏位置：订单管理与运行日志之间），用于处置上传多次违规文件的用户。规则：① **自动封禁**——用户被管理员打回（判定违规）的文件累计达到可配置阈值时自动封号；② **AI 掉线/无法识别时先转人工审核**（不自动封），人工确认违规才计数、才可能触发封禁；人工审核期间用户可正常使用；③ 人工也可**手动封禁/解封**；④ 封禁生效后**禁止上传、下载、修改、重命名**（含文件夹管理），**仅保留工单申诉入口**，申诉即普通工单，用户可自行提交。
- 验证：
  - `php -l` 对 16 个改动文件（`src/Schema.php`、`src/Bans/BanService.php`、`bans.php`、`install.php`、`src/Moderation/ModerationService.php`、`src/helpers.php`、`api/upload.php`、`api/file.php`、`api/folder.php`、`admin.php`、`admin_orders.php`、`logs.php`、`dashboard.php`、`account.php`、`pay.php`、`tickets.php`）→ 全部 `No syntax errors detected`。
  - 迁移已实际执行：本地库 `schema_version` 由 14 升至 **15**、`user_bans` 表创建成功、`ban_threshold=5`、`BanService::threshold()` 返回 5。
  - 临时 PHP 脚本（已删除，全程在事务内、末尾 `rollBack()`，未写入任何数据）实测：新建用户 `isBanned=false`/`violationCount=0` → 写入 2 条 `rejected` 记录后 `count=2` → `setThreshold(2)` → `evaluateAutoBan()` 返回 `true`、`isBanned=true`、`banned_by=NULL`、reason 含「自动封禁阈值」→ 重复调用返回 `false`（幂等）→ `unban()` 后 `isBanned=false` → 手动 `ban($uid,'手动测试封禁',1)` 后 `banned_by=1` → `bannedUsers()` 能列出该用户且 `violations=2`、`ban_id` 非空 → `userActiveBan()` 对封禁用户返回非 null、对未封禁返回 null → 回滚后 `user_bans` 0 行、阈值仍 5、临时用户消失（无数据残留）。
  - 浏览器实测（`127.0.0.1:8088`，管理员态）`bans.php`：h1「封禁管理」、侧栏出现「⛔ 封禁管理」；「自动封禁阈值」面板（阈值为 5）+「用户违规与封禁状态」面板（`共 2 位用户 · 封禁中 0 位 · 阈值 5 次`）。**完整走通封禁/解封闭环**：对 `test1`(ID 2) 点「封禁」→ 页面刷新出现 flash「用户已封禁：禁止上传、下载、修改与重命名，仅保留工单申诉入口。」、统计变「封禁中 1 位」、该行状态变「已封禁」并给出封禁时间与「解封」按钮；点「解封」→ flash「已解除该用户的封禁，上传与下载恢复可用。」、统计回「封禁中 0 位」、`test1` 状态回「正常」（验证结束已恢复为未封禁，开发库无残留脏数据）。
  - **旧库/生产库需执行迁移**：本改动依赖 `schema_version=15` 新增的 `user_bans` 表与 `ban_threshold` 配置项（默认 `5`）。bootstrap 在 `storage/installed.lock` 存在时会自动跑 `Schema::migrate()`，正常访问一次站点即完成升级；若未迁移，封禁相关页面与上传/下载拦截会因缺表报错。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 由 14 改为 15。
  - `src/Schema.php` L178-L185 — 新增 `if ($version < 15)` 段：调用 `createUserBansTable()` 建表，并在 `ban_threshold` 未设置时写入默认值 `'5'`。
  - `src/Schema.php` L190-L210 — 新增 `public static function createUserBansTable(PDO $pdo): void`：建 `user_bans` 表（`user_id`/`reason`/`violation_count`/`banned_by`/`is_active`/`banned_at`/`lifted_at`/`lifted_by`，`is_active=1` 表示生效中，外键 `user_id → users(id) ON DELETE CASCADE`，含 `idx_user_bans_user`、`idx_user_bans_active`）。
  - `src/Bans/BanService.php` — **新建**（封禁系统核心门面）。常量 `MIN_THRESHOLD=1`/`MAX_THRESHOLD=100`/`DEFAULT_THRESHOLD=5`；`threshold()` L34-L38、`setThreshold()` L42-L45（写 `config.ban_threshold`）；`isBanned()`/`activeBan()`（读生效中的 `user_bans`）；`violationCount()` L~80（统计 `moderation_records` 中 `status='rejected'` 的条数，即「被管理员打回的违规文件数」，AI 转人工也计入）；`ban()`（校验用户存在、幂等，写入 `violation_count` 快照与 `banned_by`）；`unban()`（置 `is_active=0` + `lifted_at`/`lifted_by`）；`evaluateAutoBan()` L117（未封禁且违规数 ≥ 阈值时自动 `ban()`，`banned_by=NULL`，并 `Logger::warning('ban', …)`）；`bannedUsers()`（LEFT JOIN `user_bans` 列出全部用户及其违规数与封禁状态，管理员排前、封禁中排前）。
  - `install.php` L141 — 在 `Schema::createTicketMessagesTable($pdo);` 之后新增 `Schema::createUserBansTable($pdo);`。
  - `install.php` L159 — 配置默认值数组新增 `'ban_threshold' => '5',`。
  - `src/Moderation/ModerationService.php` L7 — 新增 `use NotionDrive\Bans\BanService;`。
  - `src/Moderation/ModerationService.php` L318-L332 — `reject()` 中，`closeRecord(...REJECTED...)` 之后调用 `BanService::create()->evaluateAutoBan((int) $record['user_id'])`（**只有人工判定违规才走这里**，符合「AI 无法识别转人工、人工确认才封」的要求），并在返回 message 末尾追加「该用户违规已累计达阈值，已自动封禁。」提示（`$autoBanned` 为真时）。
  - `src/helpers.php` L5 — 新增 `use NotionDrive\Bans\BanService;`。
  - `src/helpers.php` L314-L330 — 新增 `userActiveBan(?array $user): ?array`：返回当前生效中的封禁记录，未封禁返回 null（供各 API 入口统一判断）。
  - `api/upload.php` L37-L41 — 在 `$fail` 闭包之后新增封禁拦截：封禁用户上传直接返回 `account_banned`（HTTP 403，提示走工单申诉）。
  - `api/file.php` L38-L46 — 在 `$owner` 定义之后新增封禁拦截：`GET`（下载）返回 403 文本，其余方法（重命名/移动/删除等修改）返回 JSON 403。
  - `api/folder.php` L26-L29 — 在 `$userId` 定义之后新增封禁拦截：文件夹的创建/重命名/移动/删除统一返回 JSON 403（均属「修改」范畴）。
  - `bans.php` — **新建**（一级页面，`Auth::requireAdmin()`）。POST 处理 3 个动作：`save_threshold`（L22-L35，校验 1–100、写配置、`flash` 后 302）、`ban_user`（L37-L52，禁止封禁当前登录账号、原因可留空默认「管理员手动封禁。」）、`unban_user`（L54-L63）；页面渲染「自动封禁阈值」表单与「用户违规与封禁状态」表格（列：用户/邮箱/违规次数/状态/封禁时间/原因/操作；管理员或自己显示「—」）。侧栏 L88 含 `封禁管理` 且带 `class="active"`。
  - 后台侧栏新增「⛔ 封禁管理」链接（位于「订单管理」与「运行日志」之间），共 7 处：`admin.php` L893、`admin_orders.php` L268、`logs.php` L69、`dashboard.php` L109、`account.php` L196、`pay.php` L141、`tickets.php` L99（后五处均包裹在各自的 `isAdmin()` 分支内，普通用户不可见）。
- 备注：`bans.php` 的状态徽标复用 `assets/app.css` 现有变体（已封禁/已达阈值用 `.status-badge.void`，正常用默认绿色 `.status-badge`），未新增 CSS。

### [2026-09-27 12:35:54] 后台「申诉管理」改为「工单管理」+ 免审核上传迁入「内容风控」+ 工单状态改为自动判定（仅可关闭）

- 目的：三件事一起改。① 用户指出「申诉管理」页签实质对接的是工单系统，既然已有工单系统，该页签就应直接叫「工单管理」而非重复的「申诉管理」。② 「免审核上传（豁免用户）」原本挂在申诉页签下不搭，用户要求移到「内容风控」页签。③ **工单状态模型的纠错**（用户原话：「什么叫做工单标记为已处理中？你处理中是不可以让用户选的……待处理/处理中是没用的」）：旧实现把状态做成管理员在后台手动「标记为待处理 / 处理中 / 已关闭」三个按钮，语义错误。新模型：**「待处理」= 用户已提交但尚无管理员回复（自动判定）**，**「处理中」= 已有管理员回复（自动判定）**，二者**不允许人工选择**；**「已关闭」是唯一手动动作、也是终态**，用户本人与管理员都可关闭，关闭后双方都无法继续回复。
- 验证：
  - `php -l` 对 `admin.php` / `tickets.php` / `src/Tickets/TicketService.php` → 全部 `No syntax errors detected`。
  - 临时 PHP 脚本（已删除）直连本地库、在事务内跑完再 `rollBack()`（未写入任何数据），实测状态流转全部符合预期：新建=`open` → 用户追问仍=`open` → 管理员回复=`processing` → 用户再追问仍=`processing` → 关闭=`closed` → 关闭后用户回复被拦截（抛「工单已关闭，无法继续回复。」）→ 关闭后管理员留言不会把状态改回（保持 `closed`）→ 申诉快捷提单在已关闭工单上重开并按推导重算状态。
  - 浏览器实测（`127.0.0.1:8088`，管理员态）：`admin.php?tab=tickets` → 侧栏/页签显示「工单管理」，页面 h1「管理后台 · 工单管理」，含「工单管理 / 工单列表」两个面板与「N 条未关闭」徽标；`admin.php?tab=risk` → 面板顺序为「内容风控 / 免审核上传 / 审核记录」，免审核上传已迁入且可用；`tickets.php` → 列表状态徽标（处理中 / 已关闭）正常；`tickets.php?id=4` → 仅有「发送回复 / 关闭工单」按钮，页面**不再出现「标记为…」**、DOM 中 `set_status` 表单已消失、`close_ticket` 表单存在。
  - 旧库无需改结构：本次不涉及建表 / 加列（`tickets.status` 取值不变，仍为 `open/processing/closed`）。
- 改动明细：
  - `admin.php` L16 — 新增 `use NotionDrive\Tickets\TicketService;`。
  - `admin.php` L27 — 「内容风控」页签 desc 改为「关键词拦截、AI 识别、豁免免审核上传与申诉复核」。
  - `admin.php` L29 — 页签键 `'appeals'` 重命名为 `'tickets'`，label 由「申诉管理」改为「工单管理」，desc 改为「用户的申诉与咨询工单，含对话记录与关闭」；已确认全项目无 `tab=appeals` 外链，改名安全。
  - `admin.php` L812 / L822 — 数据加载：删除 `$moderationAppeals = $moderationService->appealRecords(100);`，改为 `$adminTickets = [];` + try/catch `$adminTickets = TicketService::create()->allTickets();`（异常仅 `Logger::warning('ticket', …)`，不阻断后台）。
  - `admin.php` L1073-L1098 — 「免审核上传」面板由申诉页签**迁移**到 `risk` 页签（位置：内容风控设置面板之后、审核记录之前），前面加 `$exemptUsers` 派生变量；表单仍走 `save_moderation_exempt`，未改后端逻辑。
  - `admin.php` L1157-L1198 — 原申诉页签头部替换为 `$activeTab === 'tickets'` 区块：「工单管理」说明面板（含「N 条未关闭」徽标 + 跳 `tickets.php`）+「工单列表」表格（工单号/标题、提交人、类别、状态徽标、留言数、最后更新、查看）。
  - `admin.php` 原 L1200-L1243 — **删除**残留的「用户申诉」section（它引用已删除的 `$moderationAppeals`，且末尾 `<?php endif; ?>` 无匹配 `if`，会导致 PHP 解析错误）。
  - `src/Tickets/TicketService.php` L147-L176 — `appendAppealMessage()`：已存在工单时**先**把 `closed` 重开为 `open`（`UPDATE … WHERE status='closed'`）再追加留言，状态随后交给自动判定；不再单独手工置 `open`。
  - `src/Tickets/TicketService.php` L182-L196 — `replyAsUser()`：移除末尾把状态强制改回 `open` 的 UPDATE；关闭态仍抛「工单已关闭，无法继续回复。」。
  - `src/Tickets/TicketService.php` L198-L207 — `replyAsAdmin()`：移除「若原为 open 则改 processing」的手工 UPDATE，全权交由自动判定。
  - `src/Tickets/TicketService.php` L314-L334 — 删除 `setStatus()`（原允许人工设定任意状态），替换为 `closeTicket(int $ticketId, ?int $userId = null)`：置 `closed`；传入 `$userId` 时经 `findForUser` 校验归属（普通用户只能关闭自己的工单），管理员可不传。
  - `src/Tickets/TicketService.php` L336-L349 — 新增私有 `refreshDerivedStatus()`：统计该工单 `author_role='admin'` 的留言数，>0 则状态=`processing`，否则=`open`；`UPDATE … WHERE id=:id AND status <> 'closed'` 保证**已关闭工单不被改写**（保持终态）。
  - `src/Tickets/TicketService.php` L394-L430 — `insertMessage()` 末尾调用 `refreshDerivedStatus()`，即「每写一条留言就按是否有管理员回复自动判定状态」，这是新模型的唯一状态来源。
  - `tickets.php` 原 L55-L63 — POST 动作 `set_status`（仅管理员可调 `setStatus`）替换为 `close_ticket`：`$service->closeTicket($ticketId, $isAdmin ? null : $userId)`，管理员可关闭任意工单、普通用户只能关闭自己的工单。
  - `tickets.php` 原 L237-L253 — 管理员「工单状态」手动面板（遍历状态生成「标记为待处理/处理中/已关闭」按钮）**整体删除**，替换为对用户与管理员都显示的「关闭工单」面板（未关闭时才渲染），按钮 `data-confirm="确认关闭该工单？关闭后无法继续回复。"`。
- 备注：`assets/app.css` L109 的 `.ticket-status-actions` 随手动面板下线已不再被引用（保留未删，属无副作用的死样式）。

### [2026-09-27 12:25:50] 修复官方账户池误报「没有可用账户」：账户池改为全体付费用户共享

- 目的：生产站用户 ShiZhongyan 在 `account.php` 看到红字「官方账户池暂时没有可用账户，请联系管理员检查账户池状态。」，且文案「已固定分配 0 个官方账户」。用户指出账户池语义应是**多个用户共用一个账户**（全体付费用户共享的池），而非「一个用户固定对应一个账户」。根因：旧 `uploadTargets()` 官方分支**只读 `user_official_accounts`（每用户固定分配）**，用户若没有分配记录（典型成因：先买套餐时池内还没有启用账户 → `grantPremium()` 末尾 `autoAssignOfficialAccounts()` 因 `activeOfficialAccounts()===[]` 提前返回 → 之后管理员才加账户，该用户永久 0 分配；或注册时选「官方账户」不写分配记录，见 `register.php` L83 注释「官方账户待购买高级套餐后由系统分配」）→ 返回空数组 → 前端误判「池内没有可用账户」。修复后「是否有可用目标」不再依赖预分配。
- 验证：
  - `php -l` 对 `src/helpers.php` / `account.php` / `admin.php` → 全部 `No syntax errors detected`。
  - 临时 PHP 脚本（已删除）直连本地库实测：构造「`notion_account_source='official'` + `user_official_accounts` 0 行」的模拟用户 → `userOfficialAccounts` = 0 条，但 `uploadTargets()` 返回 **1** 个目标（池内账户 `ZZR`，`token_len=50`、`root_ok=true`），`notionSourceUsable()` = **true**、`canUseOfficialSource()` = **true**、`uploadBlockedReason()` = 空串。证明 0 分配用户也能正常上传，错误分支不再可达。
  - 浏览器实测（`127.0.0.1:8088`，管理员态）：`account.php` → `.source-note` 渲染为「官方账户池为全体付费用户共享：优先使用这里分配的 1 个账户，池内其他启用账户在需要时自动顺延（最多主备两个）。」，页面无「官方账户池暂时没有可用账户」文本；`admin.php?tab=content` → 面板说明渲染为「全体付费用户共享这里的账户；优先级数值越大越靠前，用户按「已分配的主备账户 → 池内剩余账户」的顺序取用，因此未分配的用户也能直接上传。Token 加密保存，不会回显。」
  - 旧库无需改结构：本次不涉及建表 / 加列。
- 改动明细：
  - `src/helpers.php` L953-L1019 — `uploadTargets()` 官方分支重写：先收集 `userOfficialAccounts()`（管理员分配的主备账户，保持优先顺序），再按 `activeOfficialAccounts()`（`priority DESC, id ASC`）补齐池内尚未分配的启用账户（补入行的 `user_page_id` 置空，交给 `ensureUserSpacePage` 首次上传时创建）；仍沿用「最多主 + 备 2 个目标」上限，避免向整池重复写入放大 Notion 调用。
  - `src/helpers.php` L1021-L1043 — 新增 `rememberUserSpacePage()`：为「池内非分配账户」惰性补建/更新 `user_official_accounts` 行以缓存用户空间页（`INSERT IGNORE` 兼容并发首次上传，随后统一 `UPDATE notion_page_id`），防止每次上传重复创建 Notion 页面。
  - `src/helpers.php` L1064-L1070 — `ensureUserSpacePage()` 官方账户分支由内联 UPDATE 改为调用 `rememberUserSpacePage()`。
  - `account.php` L276 — `.source-note` 文案由「已固定分配 N 个官方账户（按负载选取，主账户优先，失败时自动顺延下一个）。」改为「官方账户池为全体付费用户共享：优先使用这里分配的 N 个账户，池内其他启用账户在需要时自动顺延（最多主备两个）。」。
  - `admin.php` L994 — 「官方账户池」面板说明改为共享池语义（用户取用顺序 = 已分配主备 → 池内剩余账户）。
  - `admin.php` L1006 — 账户行 `<small>` 由「N 位用户 · 」改为「N 位用户优先使用 · 」，弱化「绑定归属」暗示、强调只是优先顺序。
  - `admin.php` L1017 — 「&lt;用户名&gt; 的官方账户」面板说明改为「分配只决定优先顺序；未分配的用户也能直接使用池内账户」。
- 兼容性说明：`autoAssignOfficialAccounts()`（L1763）与后台「自动分配」仍保留，但语义降级为「决定该用户的优先取用顺序」，不再是可上传的前提；`user_official_accounts` 新增的惰性行会同时反映到后台分配计数与负载统计（更贴近真实用量）。

### [2026-09-27 12:04:47] AI 审核稳定性：请求自动重试 + 「待人工复核」操作语义化

- 目的：用户要求「AI 掉线或识别不了就转人工审核，用户照常可上传使用；人工判违规才封禁」。主链路现状已满足（AI 失败 → `Verdict::pending` → 只登记不隔离、文件保持可用，见 `api/upload.php` L632-L646），但有两处缺口：① `AiModerator::request()` 一次 curl 失败即降级，扛不住瞬时抖动，易误降级为人工；② 后台对 pending 记录仍显示「豁免 / 允许重传 / 打回」，语义不清（文件本就可用，谈不上「恢复」）。本次补齐这两点。
- 验证：
  - `php -l` 对 `src/Moderation/AiModerator.php` / `src/Moderation/ModerationService.php` / `admin.php` → 全部 `No syntax errors detected`。
  - 重试逻辑用本地 mock 接口实测（临时脚本与 mock 已删除）：用例 A（首次 503、第二次 200）→ `isApproved=true`、mock 收到 **2** 次请求、耗时 **411ms**（含 400ms 间隔），证明可恢复失败会重试并成功；用例 B（固定 401 鉴权失败）→ `isPending=true`、reason「AI 审核暂时不可用，已转为人工复核」、mock 仅收到 **1** 次请求、耗时 **4ms**，证明 4xx 不重试、快速降级为人工复核。
  - 后台按钮：临时插入一条 `decision='pending'` 记录（验证后已删除，`mock-model` 记录清零），浏览器实测 `admin.php?tab=risk` → pending 行按钮为「判定通过 / 判定违规」，flagged 行仍为「豁免 / 允许重传 / 打回」。
  - 旧库无需改结构：本次不涉及建表 / 加列。
- 改动明细：
  - `src/Moderation/AiModerator.php` L35-L39 — 新增常量 `MAX_ATTEMPTS = 2`、`RETRY_DELAY_MS = 400`。
  - `src/Moderation/AiModerator.php` L186-L232 — `request()` 改为重试循环：调用 `send()`，成功即返回；失败且 `retryable` 时 `usleep(400ms)` 后重试，重试耗尽或不可重试时 `throw RuntimeException($lastError)`（对外仍被 `review()` 捕获为 pending，语义不变）。
  - `src/Moderation/AiModerator.php` L235-L311 — 新增 `send()`：单次 curl 请求，返回 `['ok','content','error','retryable']`；curl 层失败（网络 / 超时）= 可重试、HTTP 429/5xx = 可重试、其它非 2xx（如 401/403）= 不可重试、200 但响应体非 JSON = 可重试；2xx 且内容为空 → `ok=true, content=null`（保持「AI 未返回可解析的结果」pending 语义，不重试）。
  - `src/Moderation/ModerationService.php` L291-L298 — `exempt()`：按记录 `decision` 区分文案，pending → 「已判定通过…文件保持可用」，非 pending → 「已豁免…文件恢复可用」。
  - `src/Moderation/ModerationService.php` L319-L326 — `reject()`：pending → 「已判定违规…文件已隔离」，非 pending → 「已打回…文件维持不可用」。
  - `admin.php` L1069 — 「审核记录」说明补充：AI 降级记录文件仍可正常使用，人工判定违规才隔离。
  - `admin.php` L1102 / L1106-L1110 — 审核记录表格：`decision=pending` 的行渲染「判定通过（moderation_exempt）」/「判定违规（moderation_reject）」两个按钮，隐藏对 pending 无意义的「允许重传」；其余行保持原「豁免 / 允许重传 / 打回」。

### [2026-09-27 11:47:18] 新增工单系统：申诉改为「快捷提单」，用户可自提普通工单，管理员后台对话/改状态

- 目的：用户要求把「一次性申诉文本」升级为**工单型**记录——① 每次提交都要留有**提交记录与时间**，不再反复覆盖同一条文本；② 管理员可在后台与用户**对话**；③ 申诉是「一键快捷提交工单」，用户也能自己提**普通工单**。因此新增 `tickets` / `ticket_messages` 两表，并把历史申诉文本回填为工单+首条留言；`moderation_records.appeal_text` 仅保留「最近一条」摘要供后台列表兼容展示。
- 验证：
  - `php -l` 对 `src/Schema.php` / `src/Tickets/TicketService.php` / `src/Moderation/ModerationService.php` / `tickets.php` / `dashboard.php` / `admin.php` → 全部 `No syntax errors detected`。
  - 迁移（临时脚本已删除）：`schema_version = 14`，`tickets` / `ticket_messages` 均建成，历史申诉（记录 #3 / `R-SS5K8X`）自动回填为工单 `T-6HA8WQ`（category=appeal）+ 首条留言。
  - 浏览器实测（管理员态 `127.0.0.1:8088`）：`tickets.php` 列表出现 `T-6HA8WQ`（申诉 R-SS5K8X · 色情内容测试文件.txt｜admin｜申诉｜待处理｜1 条）；详情页渲染对话线程 + 回复表单；以管理员回复一条 → 留言以 `.ticket-msg.from-admin` 追加、状态自动 `open → processing`；点「标记为已关闭」并在确认弹窗确认 → 状态变为 `closed`（状态按钮随之变为「标记为待处理 / 标记为处理中」）；`dashboard.php` 风控提示条渲染 `.ticket-thread`（含用户原申诉 + 管理员回复两条），summary 显示「继续申诉 / 补充说明」，「查看完整对话 →」指向 `tickets.php?id=1`。
  - 旧库无需手工补列：`storage/installed.lock` 存在时每次请求自动 `migrate()`，v14 块会幂等建表；`install.php` 已同步建表，全新建库亦可用。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 13 → 14。
  - `src/Schema.php` L170-L176 — 新增 v14 迁移块：调用 `createTicketsTable()` / `createTicketMessagesTable()` / `migrateAppealsToTickets()`。
  - `src/Schema.php` L182-L204 — 新增 `createTicketsTable()`：`tickets`（id、ticket_no VARCHAR(16) UNIQUE、user_id、category 默认 'general'、subject VARCHAR(200)、status 默认 'open'、related_record_id NULL、last_message_at NULL、created_at/updated_at；索引 `idx_tickets_user` / `idx_tickets_status` / `idx_tickets_record`；FK `fk_tickets_user → users(id) ON DELETE CASCADE`）。
  - `src/Schema.php` L206-L221 — 新增 `createTicketMessagesTable()`：`ticket_messages`（id、ticket_id、author_role VARCHAR(10) 默认 'user'、author_id、body VARCHAR(2000)、created_at；索引 `idx_ticket_messages_ticket`；FK `fk_ticket_messages_ticket → tickets(id) ON DELETE CASCADE`）。
  - `src/Schema.php` L223-L255 — 新增 `migrateAppealsToTickets()`：把历史 `moderation_records.appeal_text <> ''` 的记录转成申诉工单 + 首条留言。
  - `src/Schema.php` L528-L540 — 新增 `generateTicketNo()`：`T-` + 6 位（字母表去掉易混字符），与申诉号风格一致（此前 v14 块引用但方法缺失，会导致文件不可运行，已补齐）。
  - `install.php` L139-L140 — 在 `createModerationRecordsTable()` 后追加 `createTicketsTable()` / `createTicketMessagesTable()`。
  - `src/Tickets/TicketService.php`（新建）— `NotionDrive\Tickets\TicketService`：类别/状态/角色常量；`openTicket()`（唯一 `ticket_no` 5 次重试）、`appendMessage()`、`appendAppealMessage()`（同一 `related_record_id` 只对应一张申诉工单，存在则追加留言并把 `closed` 回置 `open`）、`replyAsUser()`、`replyAsAdmin()`（open → processing）、`ticketsForUser()`、`allTickets()`、`find()` / `findForUser()`、`messages()`、`setStatus()`、`appealThreadsByRecords()`。
  - `src/Moderation/ModerationService.php` L10 — 新增 `use NotionDrive\Tickets\TicketService;`。
  - `src/Moderation/ModerationService.php` L400-L431 — `submitAppeal()` 重写：先 `findOpenRecord()` 校验存在性（避免 MySQL「更新后值未变化」返回 0 affected rows 被误判为已处理），再 UPDATE 最近摘要 + `appendAppealMessage()` 落地工单留言。
  - `src/Moderation/ModerationService.php` L438-L455 — 新增 `findOpenRecord()`。
  - `dashboard.php` L11 / L37 — 引入 `TicketService`，按当前用户的未处理记录批量取工单线程 `$moderationThreads`。
  - `dashboard.php` L108 — 导航新增 `<a href="tickets.php">我的工单</a>`。
  - `dashboard.php` L174-L195 — 风控提示条：先渲染 `.ticket-thread` 对话，再是 `.appeal-box`（summary 依有无留言显示「继续申诉 / 补充说明」或「填写申诉理由」），末尾若有工单则给 `.ticket-more` → `tickets.php?id=`。
  - `tickets.php`（新建）— 双角色页面：用户提单（强制 general 类别）/ 回复 / 查看自己的工单；管理员看全部（可按状态、类别筛选）、回复、改状态（`data-confirm` 二次确认）。POST 走 PRG。
  - `admin.php` L28 — `'appeals'` 页签描述改为「免审核用户豁免与申诉复核（对话内容在「工单管理」）」。
  - `admin.php` L888、`admin_orders.php` L268、`logs.php` L69 — 左侧一级菜单新增「工单管理」；`account.php` L195、`pay.php` L140 — 按角色显示「工单管理 / 我的工单」。
  - `assets/app.css` L97-L112 — 新增工单样式 `.ticket-thread` / `.ticket-msg` / `.ticket-msg.from-user|from-admin` / `.ticket-msg-meta` / `.ticket-msg-body` / `.ticket-more` / `.ticket-form` / `.ticket-status-actions` / `.ticket-status-open|processing|closed`。
  - 静态资源版本 `assets/app.css?v=36 → v=37`，同步 `admin.php` L884、`admin_orders.php` L264、`account.php` L189、`dashboard.php` L101、`install.php` L228、`logs.php` L65、`pay.php` L134、`index.php` L57、`register.php` L139、`tickets.php` L94。
  - 数据库：新增 `tickets`、`ticket_messages` 两表（v14 迁移，旧库自动建）。

---

### [2026-09-27 11:02:43] 修复：窄屏下进入管理后台后底部导航丢失「管理后台」入口，并清理已删入口的残留文案

- 目的：用户报告「打开页面之后进入下面的管理后台，管理后台会（被）删掉」。根因：`admin.php` 侧栏用 `<details class="nav-group">`（summary 为「⚙ 管理后台」）承载二级页签，而窄屏媒体查询 `@media (max-width: 760px)` 里的 `.nav-group { display: none; }` 是按设计隐藏整个分组（二级菜单改由正文顶部 `.admin-tabs` 分区条承担）；其余页面（`dashboard.php` / `admin_orders.php` / `logs.php`）用的是普通 `<a href="admin.php">管理后台</a>`，窄屏下仍然存在——只有 `admin.php` 自己会缺这一项，于是「在后台页面里底部导航没有『管理后台』」，看起来像入口被删掉了。顺带清理 `files` 页签里指向已删除行内入口的失效文案。
- 验证：`php -l` 对 `admin.php` / `dashboard.php` / `admin_orders.php` / `logs.php` / `account.php` / `pay.php` / `index.php` / `install.php` / `register.php` → 全部 `No syntax errors detected`；全仓检索 `?v=34` → 0 匹配；`admin.php` 内检索 `管理文件` → 0 匹配（仅本文件历史条目提及）。
- 改动明细：
  - `admin.php` L870 — 侧栏 `<nav class="drive-nav">` 在「我的文件」之后新增 `<a class="active nav-admin-link" href="admin.php"><span>⚙</span>管理后台</a>`：窄屏专用入口，桌面端仍由 `<details class="nav-group">` 承担，不产生重复项。
  - `assets/app.css` L112-L113 — 新增注释 + `.drive-nav a.nav-admin-link { display: none; }`（基础隐藏）。
  - `assets/app.css` L448-L449 — 移动端媒体查询内新增注释 + `.drive-nav a.nav-admin-link { display: flex; }`（≤760px 恢复入口）。
  - `admin.php` L1022 — 「用户文件」空态说明由「选择一位用户查看并管理其文件；也可以在『用户管理』里点击用户旁的『管理文件』直接进入。」改为「选择一位用户，查看并管理其文件（下载 / 重命名 / 删除）。」
  - `admin.php` L866、`admin_orders.php` L264、`account.php` L189、`logs.php` L65、`pay.php` L134、`index.php` L57、`install.php` L225、`dashboard.php` L72、`register.php` L139 — 静态资源版本号 `assets/app.css?v=34` → `?v=35`。
  - 无数据库 / 表结构改动。

---

### [2026-09-27 10:57:13] 移除日志中的「修改人」字段：不再标注改动归属

- 目的：项目由两人协作，而历史记录里的「修改人」一律写成同一个人，无法区分实际改动者，用户要求直接删掉该字段，避免错误归属。
- 验证：除本条记录自身提及外，全文检索 `修改人` / `ShiZhongyan` → 0 匹配；22 条既有记录的标题、目的、验证、改动明细均完整保留（仅少一行字段，标题下方的空行与后续内容未受影响）。
- 改动明细：
  - `CHANGELOG.md` 原 L38、L59、L85、L108、L130、L144、L159、L182、L200、L225、L252、L295、L309、L328、L350、L368、L379、L417、L448、L499、L534、L547 — 删除 22 条记录中的 `- 修改人：…` 行。
  - `CHANGELOG.md` 原 L11-L14 — 「记录规范」删除原第 2 条（关于「修改人」的 4 行要求），原第 3-6 条重编号为现 L11-L17 的第 2-5 条。
  - `CHANGELOG.md` 原 L27 — 「条目模板」删除 `- 修改人：[xxx]` 行。
  - `CHANGELOG.md` 原 L451、L483、L503 — 正文内联署名改为「用户」（「按 用户 决策」「其后又按 用户 要求」「已与 用户 确认」）。
  - 无代码 / 数据库改动，不影响任何运行时行为。

---

### [2026-09-27 10:46:29] 后台新增「文件管理」独立页签（与「系统设置」同级、位于其前），用户文件入口从账户池管理拆出并明面化

- 目的：用户要求把「管理员管理用户文件」的功能**明面展示**：① 在「用户管理」每位用户的行内直接给出「管理文件」入口（与「续费订阅 / 编辑」并排，不必展开「编辑」）；② 在后台顶部页签里**单独新增「文件管理」一栏，位置在「系统设置」之前、与「系统设置」同级**，不再只藏在「账户池管理」里。故把原 `?tab=content#user-files` 的用户文件面板整体迁到新的 `?tab=files`；「账户池管理」只保留官方账户池与该用户的账户分配。
- 验证：
  - `php -l admin.php`、`php -l admin_orders.php` → 均 `No syntax errors detected`（exit 0）。
  - 登录态实测（本机 PHP 内建服务器 `127.0.0.1:8088` + `admin` 账号）：`admin.php?tab=files` → HTTP 200，页面含 `tab=files` 页签与用户选择列表「用户文件」；页签顺序满足 `tab=risk` < `tab=files` < `tab=system`（即「文件管理」在「内容风控」之后、「系统设置」之前，同级并列）；`admin.php?tab=files&user_id=1` → 页面含 `id="user-files"` 文件面板，且面板「关闭」链接指向 `admin.php?tab=files`。
  - 未做浏览器点击回归：重命名/删除按钮沿用原 `.file-actions` + `assets/app.js` 逻辑，DOM 结构未变（仅所在页签变化）。
  - 无数据库结构改动（不涉及建表/加列，旧库无需手工迁移）。
- 改动明细：
  - `admin.php` L27 — `adminTabs()` 在 `'risk'` 与 `'system'` 之间新增 `'files' => label「文件管理」`，达成「比系统设置靠前、与系统设置平级」。
  - `admin.php` L25 — `content` 页签描述由「用户文件、官方账户分配与账户池」改为「官方账户池与用户账户分配」（用户文件已迁出）。
  - `admin.php` L40 — 页签默认值注释同步：去掉「用户行点『管理文件』默认进 content」的说明（行内入口已改用 `tab=files`）；默认值逻辑本身未改。
  - `admin.php` L914 — 用户行 `.user-row-actions` 的「管理文件」链接由 `?tab=content` 改为 `?tab=files`（上一轮新增的明面入口，本轮同步页签）。
  - `admin.php` L965 — 权限组「查看该组用户」成员链接同步改为 `?tab=files`。
  - `admin.php` L1013-L1032 — 新增 `if ($activeTab === 'files')` 渲染块：无 `user_id` 时渲染用户选择列表（复用 `#user-search` 即时筛选 + 新增 `.file-user-list`），点击进入该用户文件；带 `user_id` 时渲染原 `#user-files` 文件面板，「关闭」回到 `admin.php?tab=files`。
  - `admin.php` 原 L997-L1004 — 从 `content` 页签删除 `#user-files` 面板（迁至 `files` 页签）。
  - `assets/app.css` L266 — 过滤选择器由 `.admin-user-row.is-filtered-out` 扩展为 `.admin-user-row.is-filtered-out, .file-user-list li.is-filtered-out`，使新页签的用户选择列表也能即时筛选。
  - 资源缓存版本：`assets/app.css?v=33 → v=34`，同步 `admin.php` L866、`admin_orders.php` L264、`account.php` L189、`logs.php` L65、`pay.php` L134、`index.php` L57、`install.php` L225、`dashboard.php` L72、`register.php` L139。
  - 注：`admin_orders.php` L321 订单页「用户」链接仍为 `?tab=content&user_id=`（该入口用于查看账户分配，不在本次需求内，未改动）。

### [2026-09-27 09:36:29] 新增可插拔 AI 内容风控：关键词上传前拦截 + AI 上传后识别 + 申诉号与后台复核

- 目的：用户要求站内新增「增量插件」形式的 AI 审核模块。① 命中**关键词**的文件在**上传开始前**直接拒绝；② 上传**完成后再由 AI 识别**，判定违规则「不让用、暂时保留」（隔离）；③ 拒绝时分配**唯一申诉号**，用户凭号线上/线下联系管理员申诉；④ 管理员在「内容风控」后台查看记录并**豁免 / 允许重传 / 打回**。按用户「默认做」落地以下默认决策：AI 接口沿用 OpenAI 兼容 `/chat/completions`；未配置接口地址或 Key 时**只跑关键词**；AI 超时或异常一律 fail-open（文件照常放行）且仅登记待人工复核、**不隔离**（避免 AI 故障导致全站上传不可用）；pending 记录**不展示给用户**；豁免时才修复悬空 `folder_id`。
- 验证：
  - 临时脚本断言 **65 项全部 PASS / 0 FAIL**（exit 0），覆盖 7 组：Schema（`schema_version=12` / `moderation_records` 表存在且 15 个关键列齐全 / 4 索引 / `uploads.status` 非 ENUM）、`KeywordModerator`（去空去重、大小写不敏感、命中字段）、`saveSettings`（写入回读、API Key `enc:` 加密、7 类非法输入拒绝：http / 内网 IP / localhost / timeout 越界 / 超长 model / 超长 keywords、非法 scope 回退全部）、`guardFilename` 与 `inspect`（关键词优先于 AI、未配置 AI 时跳过）、记录-隔离-复核闭环（申诉号 `R-XXXXXX` 格式、`quarantine` 后用户不可见、`exempt` 恢复 active、`reject` 维持隔离、`resubmit` 放行同名重传且不误放他名）、pending 不泄漏给用户、`recentRecords` 关联用户名。
  - 上述临时脚本执行后已删除；末尾自动恢复风控配置并清理测试数据（`moderation_enabled` 已回到 `0`）。
  - `php -l` 对全部 13 个改动文件（含 5 个新文件）语法检查 0 错误；`node --check assets/app.js` exit=0。
  - **安全审计结论（未发现新增漏洞）**：`admin.php` 复核动作受 `Auth::requireAdmin()`（L17）+ `Security::verifyCsrf()`（L111-L113）双重保护，无 CSRF/越权；后台与首页所有记录字段输出均经 `e()`、前端经 `textContent`，无 XSS；`recentRecords` 的 `LIMIT` 经 `max(1,min(300,...))` 整数收敛，其余全部原生预处理，无注入；**隔离绕过已逐一核对**——下载 [`api/file.php`](api/file.php#L15-L21)、文件夹移动/删除 [`api/folder.php`](api/folder.php#L53)、首页列表 [`dashboard.php`](dashboard.php#L48)、重名探测 `findActiveUploadByName`、`folderFileCountMap`、`accountReplicaCount` 均带 `status='active'`，隔离文件自动 404/不可见/不可下载/不可移动；`userNotices($userId)` 严格按当前登录用户过滤，无 IDOR；申诉号无任何用户端查询接口（仅线下凭号申诉），不存在枚举利用面。
  - **已知残余风险（低，已取舍）**：`isPrivateHost()` 仅对 IP 字面量做内网判断，如管理员填写「解析到内网的非 IP 域名」不会被拦；因该地址只能由管理员在后台配置、用户无法注入，判定为可接受，未额外增加 DNS 解析校验（会引入 CDN/误杀复杂度）。
  - **已知设计取舍**：管理员「允许重传」后，同名文件的**上传前关键词拦截会一直放行**（`hasResubmitPass` 按 用户+文件名+`resubmit` 匹配），但上传后仍会重新跑关键词与 AI 识别并可能再次隔离；被隔离文件无法由用户自行删除，会持续占用配额，需管理员复核后解决。
- 改动明细（数据库：新增表 `moderation_records`，`uploads.status` 追加取值 `'quarantined'`；**旧库无需手工补列**，`Schema::migrate()` 会在请求时自动升级）：
  - `src/Moderation/Verdict.php` L1-L63 — 新增。审核结论值对象（`approved`/`flagged`/`pending` + `provider`/`model`/`reason`/`matched`/`detail` 与 3 个静态工厂）。
  - `src/Moderation/Moderator.php` L1-L21 — 新增。审核器接口 `name()` / `review(array $subject)`。
  - `src/Moderation/KeywordModerator.php` L1-L61 — 新增。大小写不敏感的关键词命中实现。
  - `src/Moderation/AiModerator.php` L1-L303 — 新增。OpenAI 兼容 `/chat/completions` 调用；按扩展名读文本片段、按 MIME 传 base64 图片；**不依赖 `finfo`**（生产 `fileinfo` 曾缺失）；解析失败/超时/HTTP 非 200 一律返回 `pending`。
  - `src/Moderation/ModerationService.php` L1-L477 — 新增。门面：`create()` 读配置（API Key 经 `Security::decrypt`）、`settings()`、`saveSettings()`（https + URL 合法 + 非内网 + 超时 2-60 + 长度与 scope 白名单校验）、`guardFilename()`、`inspect()`、`record()`（`R-XXXXXX`，23000 冲突重试 5 次）、`quarantine()`、`exempt()`/`reject()`/`allowResubmit()`、`hasResubmitPass()`、`recentRecords()`、`userNotices()`。
  - `src/Schema.php` L11（`CURRENT_VERSION` 11→12）、L159（调用建表）、L164-L194（`createModerationRecordsTable()`：`appeal_no` 唯一索引 `uniq_moderation_appeal` + `idx_moderation_user` / `idx_moderation_status` / `idx_moderation_upload` 三个索引 + `user_id` 外键 `fk_moderation_user` `ON DELETE CASCADE`）。
  - `install.php` L137（全新安装即建风控表）、L149-L154（初始配置 6 项 `moderation_*`）。
  - `src/helpers.php` L201 — `userUsedBytes()` 容量统计由仅 `active` 改为 `status IN ('active','quarantined')`，使「暂时保留」的隔离文件仍占配额，避免用户靠违规内容绕过容量限制。
  - `api/upload.php` L280-L318 — start 阶段关键词拦截：命中即拒绝（不建分块会话、不落盘）并写入 `STAGE_START` 记录、`raiseAdminAlert`、返回 `code=moderation_rejected` + `appeal_no`；已被「允许重传」的同名文件跳过拦截；本段异常一律放行并 `Logger::error`。L587-L660 — finish 阶段 `inspect()`：`flagged` → 记录 + `quarantine` + 告警，响应体 `moderation.message` 含申诉号；`pending` → 仅记录 + 告警（**不隔离**）；响应新增 `moderation` 字段。
  - `dashboard.php` L25-L30（取 `userNotices`，失败静默）、L111-L137（申诉提示条，列出文件名/原因/申诉号与处理状态）。
  - `assets/app.js` L309-L312（`held` / `reviewing` 计数）、L495-L498（`fail(detail, retry)`）、L515-L521（start 被拒不重试）、L566-L580（按 `moderation.decision` 分支：`flagged` 记 `held` 不重试、`pending` 记 `reviewing` 且标记「上传完成，等待人工复核」）、L604-L610（汇总提示追加两项文案）。
  - `admin.php` L12（引入 `ModerationService`）、L26（新增 `?tab=risk`「内容风控」）、L669-L687（POST 分发：`save_moderation` 与 `moderation_exempt|moderation_reject|moderation_resubmit`）、L785-L799（视图数据准备，读取失败只影响该页签）、L1021-L1087（风控页签：配置表单 + 审核记录表 + 行内复核表单）、L865（`app.css` 缓存版本 `v=32`→`v=33`）。
  - `assets/app.css` L60-L62（新增 `textarea` 基础样式与 focus/placeholder）、L232（`.checkbox-row > span`）、L236-L238（`.moderation-review` 窄列内纵向排布）；同步 9 个页面静态资源版本号 `v=32`→`v=33`：`admin.php` L865、`account.php`、`admin_orders.php`、`dashboard.php`、`index.php`、`install.php`、`logs.php`、`pay.php`、`register.php`（用于强制刷新旧缓存）。

### [2026-09-27 01:58:30] 「全部文件」新增站内文件夹（多层嵌套 / 移动文件 / 递归删除）

- 目的：用户要求「全部文件内容能不能加一个文件夹的功能，就是正常的文件夹文件可以移动到文件夹，也可以移出文件夹，可以删除重命名新建」。经用户拍板：① 文件夹**只在站内生效**，不同步到 Notion；② **支持多层嵌套**；③ 删除文件夹时**连同其中内容一起删除**（含 Notion 归档）；④ 删除前**必须提示**会连带删除多少个文件、且不可恢复。
- 验证：
  - 临时脚本 `storage/_folder_verify.php` 跑 58 项断言**全部通过**（exit 0），覆盖：迁移（`schema_version=11` / `folders` 表 / `uploads.folder_id` / `idx_uploads_folder`）、6 个辅助函数（越权隔离与排序 / 树形层级 / 递归后代 / 计数上卷 / 面包屑）、根目录 `folder_id <=> NULL` 只命中未归类文件、HTTP 全流程（未登录 401 / 缺 CSRF 422 / 新建（根与嵌套）/ 空名与控制字符拒绝 / `../../etc/passwd` 仅留 `passwd` / 重命名 / 移动文件移入移出 / 深层浏览与面包屑与 `data-folder` 注入 / 越权一律 404 或 302 / 递归删除后子文件夹与内部文件消失且同级与他人不受影响 / 上传 start+finish 的 `folder_id` 注入与失效降级为根）。
  - `php -l` 全量语法检查 0 错误；`node --check assets/app.js` exit=0。
  - 临时脚本已删除；用 `mysql.exe` 直连复核测试数据已清空：`test_users=0 / folders_total=0 / orphan_folders=0 / schema_version=11`。
  - 本次同时完成 bug 与安全审计：IDOR / SQL 注入 / XSS / CSRF 均未发现新增问题。
- 改动明细：
  - `src/Schema.php` L11 — `CURRENT_VERSION` 由 10 提到 11；L149-L154 迁移块（建 `folders` 表 + 加 `uploads.folder_id` + 加索引）；L159-L174 `createFoldersTable()`（`user_id` 外键级联，`parent_id` 为 NULL 表示根目录，`(user_id, parent_id)` 复合索引）。
  - `src/helpers.php` L1191-L1308 — 新增「站内文件夹」分区 6 个函数：`folderRow` L1196、`folderAncestors` L1211、`folderChildren` L1224、`folderTree` L1237、`folderFileCountMap` L1266、`folderDescendantIds` L1291；全部强制 `user_id` 过滤，计数按树倒序上卷（含嵌套子文件夹）。
  - `api/folder.php`（**新增**）— `normalizeFolderName` L13；`Auth::requireLogin(true)` L23、非 POST 405、`Security::verifyCsrf` L30；四 action：`create` L34 / `move_file` L45 / `rename` L70 / `delete` L77；删除为递归（`folderDescendantIds` → 批量置 `status='deleted'` + `archiveUploadReplicas` 归档 Notion → `DELETE FROM folders`）；异常统一 422 且不回显 SQL / 路径。
  - `api/upload.php` — `start` 读取并校验 `folder_id`（L280-L283，越权降级 0）并写入分块 meta（L322）；`finish` 二次校验（L465-L466）并落库（L484-L488，0 存 NULL）。
  - `dashboard.php` — 按 `?folder=` 过滤（L24-L36，越权 302 回根）；列表 `folder_id <=> :folder_id`（L40、L50）；`body data-folder`（L66）；面包屑 L123-L134；工具条「新建文件夹」按钮 L136；空状态条件含文件夹 L137；文件夹行 L144-L150（`data-folder-id` / `data-file-name` / `data-folder-inside` / 含 N 个文件 / 重命名·删除）；分页保留 `&folder=`（L179-L181）；`<template id="folderOptions">` L185。
  - `assets/app.js` — `openDialog` 弹窗骨架 L25-L90（Esc / 遮罩 / 焦点陷阱）；`askConfirm` L92；`askFolderPicker` L95-L106；文件操作新增 `move` 分支 L150-L154（走 `api/folder.php` + `move_file`）；文件夹操作委托 L193-L230（重命名 prompt、删除前按 `data-folder-inside` 提示连带删除数量、删除时防重入）；新建文件夹 L233-L259；上传 start 带 `folder_id` L507。
  - `assets/app.css` L186-L191 — 面包屑与工具条；L209-L212 — `.folder-kind` / `.folder-link` / `.folder-picker`。
  - `install.php` — 新装建表同步：`folder_id` L111、索引 L123、`createFoldersTable` L128、`schema_version` L147。
  - 静态资源版本：`app.css?v=32`、`app.js?v=16`，同步更新 `index.php` L57/L83、`register.php` L139/L183、`account.php` L189/L340、`pay.php` L134/L255、`admin.php` L826/L1053、`admin_orders.php` L264/L353、`logs.php` L65/L124。
- 备注：`uploads.folder_id` **故意不加外键**（删除文件夹时需保留已删文件的归属，靠 `status='active'` 过滤）；旧库升级由 `bootstrap.php` → `Schema::migrate()` 自动完成，**无需手工补列**。已知非安全问题（仅为记录）：CSRF 失败返回 422（与既有 `api/file.php` 一致）、允许同名文件夹（与 Windows 一致）、`folderAncestors` 对理论上的 parent 环无深度上限（当前无「移动文件夹」功能，无法造环）。

---

### [2026-09-27 00:55:45] 全库清理记录性 / 臃肿注释，只保留重要功能性注释

- 目的：用户要求「将代码库中所有文件中因修改而留下的记录性注释删除，以及臃肿注释全部删除，仅保留部分重要功能性注释」。清理口径经用户确认为**标准力度**（删：改动记录类「原为…/改为…/此前…/已废弃…/不再…/兼容旧版本…」、重述代码类、超过 1~2 行的冗长「为什么」铺陈、只重复函数名/参数类型的 docblock；留：并发/加锁/幂等、安全（路径穿越/CSRF/加密/权限）、外部协议兼容（Notion API / 易支付字段差异）、业务规则（配额/套餐/续费顺延/到期降级/订单状态机）、时序要求、前端非直观行为（移动端断点 / 44px 触摸目标 / 焦点陷阱 / 滚动锁）、Schema 迁移意图）与**全部源文件**范围（根目录页面、`api/`、`src/`、`assets/`、`config/`、`scripts/`、`.dev/`）。
- 验证：
  - 改动前把全部 44 个 php/js/css/ps1 源文件快照到 `%TEMP%\nd-cmt-bak`；事后用 PHP `token_get_all` 剥掉 `T_COMMENT` / `T_DOC_COMMENT` / `T_WHITESPACE`（`T_INLINE_HTML` 去掉 HTML 注释并折叠空白）后逐 token 比对代码序列 → 38 个 PHP 文件全部 `OK`，输出 `== ALL PHP CODE-TOKENS MATCH ==`，证明**未改动任何代码 token**。
  - `C:\php-8.5.9\php.exe -l` 全量扫描 → 0 个语法错误。
  - `node --check` → `assets/app.js` / `assets/admin.js` / `assets/verify.js` 均 exit=0。
  - JS/CSS 用 `git diff --no-index`（快照 vs 工作区）逐行复核 → 变更均为「整行注释删除」或「多行注释压缩为一行」，无规则/属性值/字符串/正则/URL 改动；`assets/verify.js`、`config/database.php`、`scripts/dev-start.ps1`、`scripts/dev-stop.ps1` 本身无注释，未改动。
  - 快照目录与临时校验脚本已删除，仓库未新增文件（`1.md` 为用户未跟踪文件，未处理）。
- 改动明细（仅删注 / 压缩注，无功能变更）：
  - `src/helpers.php` — 清理约 80 处（删约 19 处无信息量 docblock + 压缩 14 处业务注释），88026 → 78069 字节；保留并发加锁、结算幂等与「已作废订单不得被回调复活」、续费顺延、到期懒降级、配额与单文件上限、路径穿越、Token 密文容错等注释。
  - 未改动（现有注释均为功能 / 安全 / 迁移意图类）：`src/Schema.php`、`src/Security.php`、`src/Verification.php`、`src/NotionApiException.php`、`avatar.php`、`logout.php`、`logs.php`、`index.php`、`assets/verify.js`、`config/database.php`。
  - `src/` 其余：`Auth.php` −1、`bootstrap.php` 压缩 1、`Database.php` 压缩 1、`Epay.php` −1 / 压缩 1、`Logger.php` −6 / 压缩 1、`Mailer.php` −2、`NotionClient.php` −3 / 压缩 1。
  - 根目录页面：`admin.php` 处理 8 处、`admin_orders.php` 4 处、`account.php` 精简 2、`dashboard.php` −1、`captcha.php` 压缩 1、`install.php` 压缩 2、`register.php` 压缩 2、`pay.php` −1 / 压缩 3、`pay_notify.php` 压缩 2、`pay_return.php` 压缩 1。
  - `api/`：`file.php` −4 / 压缩 3、`upload.php` −15 / 压缩 4、`verify.php` −3 / 压缩 3。
  - `assets/`：`app.css` 删除 48 行注释 / 压缩 3、`app.js` −6 / 压缩 3、`admin.js` −4。
  - `.dev/`：`e2e.php` −10 / 压缩 1、`page-check.php` −3、`pay-check.php` −10 / 压缩 3、`mock-notion/router.php` −2；其余 `.dev` 脚本无改动。
- 备注：**零功能改动**，未触碰任何代码 token、SQL、HTML 结构、CSS 规则、JS 字符串/正则、资源缓存版本号（`?v=31` 保持不变）；未改表结构，旧库无需补列（`schema_version` 仍为 10）。注意：因删注释，仓库多文件**行号整体前移**，本文件更早条目中记录的历史行号已与当前文件不一致（属预期，历史条目不回改）。

---

### [2026-09-26 23:48:40] 侧栏备案号改为居中并调整上下间距

- 目的：用户要求「优化备案号样式，间隔距离适当，侧边栏居中显示」。此前侧栏备案号是左对齐（沿用侧栏正文默认对齐），且 `.sidebar-icp + .sidebar-police` 的上间距只有 2px，两条备案号挤在一起；同时最后一条备案号紧贴账号信息上方的分隔线。本次统一居中，并把上、内、下三处间距调到均衡。
- 验证：`assets/app.css` 单文件 CSS 改动，无 PHP 变更，无需 `php -l`；产品页面渲染出 `<p class="sidebar-icp">` / `<p class="sidebar-police">` 的 DOM 未变（仍由 `sidebarIcpHtml()` / `sidebarPoliceHtml()` 输出），仅样式生效。逻辑核对：`.sidebar-storage` 下内边距 16px 与备案号上内边距 4px 构成上方间距 20px，备案号下内边距 12px 与 `.account-menu` 上内边距 14px 构成下方间距 26px，两条备案号之间间距 4px；由于 `.account-menu` 顶部有 1px 分隔线，视觉上下方留白不会被文字压住。
- 改动明细：
  - `assets/app.css` L138 — `.sidebar-icp, .sidebar-police`：`padding` 由 `12px 10px 0` 改为 `4px 10px 0`（上间距交回 `.sidebar-storage` 的下内边距统管，避免 16px + 12px 过空）；`line-height` 由 `1.5` 提到 `1.6`；新增 `text-align: center`（侧栏居中）。
  - `assets/app.css` L140 — 相邻两条备案号的 `padding-top` 由 `2px` 调到 `4px`，两条之间不再粘连。
  - `assets/app.css` L142（新增）— `.sidebar-icp:has(+ .account-menu), .sidebar-police:has(+ .account-menu) { padding-bottom: 12px; }`：给备案号组最后一条补下内边距，防止文字贴住账号信息上方的分隔线；`:has()` 不被支持时优雅降级为原样（无多余留白）。
  - 资源缓存版本号：`assets/app.css?v=30 → v=31`（`admin.php` L836、`admin_orders.php` L268、`account.php` L189、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L48、`install.php` L217、`register.php` L141）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。改动仅桌面端侧栏生效；移动端（`@media (max-width: 760px)`）备案号仍由 `.site-credit` 页脚居中展示，不受影响。

---

### [2026-09-26 23:46:19] 管理后台 / 订单管理 / 运行日志侧栏也展示「用量信息」

- 目的：用户要求「管理员也要在包含管理面板、日志查看等页面有同样的已用空间 / 不限总容量」。上一条已把用量区块抽成共用 helper 并让「我的文件 / 账户管理 / 会员套餐」三页统一调用，但管理员可见的 `admin.php`（管理后台）、`admin_orders.php`（订单管理）、`logs.php`（运行日志）三页侧栏仍没有该区块，管理员在这几页看不到已用空间。本次把同一 helper 也接入这三页，管理员全站侧栏用量信息保持一致。
- 验证：`php -l admin.php / admin_orders.php / logs.php` → `No syntax errors detected`。grep 确认 6 个侧栏页均已调用 `sidebarStorageHtml()`（`dashboard.php` L59、`account.php` L199、`pay.php` L151、`admin.php` L841、`admin_orders.php` L273、`logs.php` L70）。管理员渲染结果沿用上一条 CLI 实测（id=1 `admin`）→ `<div class="sidebar-storage"><div><span>已用空间</span><strong>11.7 KB</strong></div><small>不限总容量</small></div>`，正是用户要求的形态；`sidebarStorageHtml()` 对管理员不输出「会员套餐」两行。
- 改动明细：
  - `admin.php` L841（新增）— 在 `</nav>` 与 `<?= sidebarIcpHtml() ?>` 之间插入 `<?= sidebarStorageHtml($currentUser) ?>`。
  - `admin_orders.php` L273（新增）— 同上，插入 `<?= sidebarStorageHtml($currentUser) ?>`。
  - `logs.php` L70（新增）— 同上，插入 `<?= sidebarStorageHtml($currentUser) ?>`。
  - `assets/app.css` 原 L137-L138、原 L145-L146 删除 — `.drive-nav + .account-menu` 与 `.drive-nav + .sidebar-icp, .drive-nav + .sidebar-police` 两条 `margin-top: auto` 兜底规则，是「侧栏没有用量区块」时的贴底方案；现在 6 个侧栏页都必然输出 `.sidebar-storage`（其自带 `margin-top: auto`），这两条选择器已不可能命中，属死代码，连同其注释一并删除。
  - 资源缓存版本号：`assets/app.css?v=29 → v=30`（`admin.php` L836、`admin_orders.php` L268、`account.php` L189、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L48、`install.php` L217、`register.php` L139）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。移动端（`@media (max-width: 760px)`）`.sidebar-storage` 仍被 `display: none` 隐藏，底部导航栏不变。

---

### [2026-09-26 23:41:39] 公网安备号改为可跳转 + 统一三页侧边栏「用量信息」区块

- 目的：用户要求「公网安备跳转 `https://beian.mps.gov.cn/#/query/webSearch`，同时让不同页面侧边栏显示的用量信息完全相同」。此前公网安备号是纯文本（ICP 已在上一条改为链接）；且「我的文件 / 账户管理 / 会员套餐」三页侧栏的用量区块是各自内联的重复代码，内容不一致——`dashboard.php` 有「会员套餐」两行，`account.php` / `pay.php` 只有「已用空间」一行且整块依赖 `if (!isAdmin($user))`。本次把用量区块抽成一个共用 helper，三页统一调用，保证不同页面显示逐字节一致。
- 验证：`php -l`（`src/helpers.php` / `dashboard.php` / `account.php` / `pay.php` 及全部 9 个含版本号页面）→ `No syntax errors detected`。CLI 实测（`require src/bootstrap.php`，临时写入 `config.icp_license` / `config.police_license` 后渲染真实用户，跑完已删除该两行、临时脚本 `.dev/render-check.php` 已删除，库中仅剩 id=1 `admin` / id=2 `test`）：
  - 非管理员 id=2 `test`（高级用户）→ `<div class="sidebar-storage"><div><span>已用空间</span><strong>40.5 KB</strong></div><small>不限总容量</small><div><span>会员套餐</span><strong>高级 · 30 天</strong></div><small><a href="pay.php">续费 / 延长</a></small></div>`，正是用户要求的形态。
  - 管理员 id=1 `admin` → 只有「已用空间 11.7 KB + 不限总容量」（无会员套餐行，符合管理员不受套餐限制的语义）。
  - `sidebarPoliceHtml()` → `<p class="sidebar-police"><a href="https://beian.mps.gov.cn/#/query/webSearch" target="_blank" rel="noopener noreferrer">POLICE-TEST-11010502030123</a></p>`；`siteCreditHtml()` 页脚 `<span class="police-license">` 同为该链接。
  → 三页侧栏用量区块由同一 helper 渲染，内容天然一致；公网安备侧栏与页脚两处均为全国互联网安全管理服务平台链接。
- 改动明细：
  - `src/helpers.php` L63-L67（新增）— `policeLink()`：把公网安备号包成指向 `https://beian.mps.gov.cn/#/query/webSearch` 的 `<a>`（`target="_blank" rel="noopener noreferrer"`，与 ICP 链接同一套属性）。
  - `src/helpers.php` L55-L61 — `sidebarPoliceHtml()` 输出由 `e($police)` 纯文本改为 `policeLink($police)`（函数体在本次改动前已就位）。
  - `src/helpers.php` L69-L91（新增）— `sidebarStorageHtml(array $user)`：三页共用的侧栏「用量信息」区块，输出「已用空间 + 占用值 +（有配额则进度条 + `共 X`，否则 `不限总容量`）」，非管理员再追加「会员套餐 + 状态」与 `pay.php` 的「续费 / 延长」（有效高级用户）或「升级高级用户」入口。
  - `src/helpers.php` L101 — `siteCreditHtml()` 页脚 `<span class="police-license">` 内容由 `e($police)` 改为 `policeLink($police)`。
  - `dashboard.php` L59 — 原 L60-L67 内联 `<div class="sidebar-storage">…</div>` 删除，替换为 `<?= sidebarStorageHtml($user) ?>`；原 L21 `$usagePercent`、原 L40 `$planLabel` 删除（替换后已无其它引用；L14-L15 的 `$usedBytes` / `$quotaBytes` 仍被 L17 剩余容量与文件面板使用，保留）。
  - `account.php` L199 — 原 L199-L204（含 `if (!isAdmin($user))` 包裹）删除，替换为 `<?= sidebarStorageHtml($user) ?>`；原 L176 `$usagePercent` 删除（L174-L175 的 `$quotaBytes` / `$usedBytes` 仍被页内面板使用，保留）。
  - `pay.php` L151 — 原 L152-L157（含 `if (!isAdmin($user))` 包裹）删除，替换为 `<?= sidebarStorageHtml($user) ?>`；原 L100 `$usagePercent` 删除（L98-L99 仍被页内面板使用，保留）。
  - `assets/app.css` L143-L144 — 悬停下划线由 `.sidebar-icp a:hover` 扩为 `.sidebar-icp a:hover, .sidebar-police a:hover`，公网安备链接同样有可点击反馈。
  - 资源缓存版本号：`assets/app.css?v=28 → v=29`（`admin.php` L836、`admin_orders.php` L268、`account.php` L189、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L48、`install.php` L217、`register.php` L141）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。`dashboard.php` / `account.php` / `pay.php` 三页不再各自维护用量区块，后续调整只需改 `sidebarStorageHtml()` 一处；若公安备案查询入口变更，只需改 `policeLink()` 一处。

---

### [2026-09-26 23:35:22] ICP 备案号改为可点击跳转工信部备案系统

- 目的：用户要求「ICP备跳转工信部网页」。此前 ICP 备案号是纯文本，按国内备案合规惯例应可点击跳转工信部备案系统（`https://beian.miit.gov.cn/`）。本次只处理 ICP，公网安备号未要求、保持纯文本不动。
- 验证：`php -l src/helpers.php` → `No syntax errors detected`。CLI 实测（`php -r 'require "src/bootstrap.php"; ...'`，临时写入 `config.icp_license` / `config.police_license` 后渲染，跑完已删除该两行、库中仅剩 id=1 `admin` / id=2 `test`）：
  - `sidebarIcpHtml()` → `<p class="sidebar-icp"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">ICP-TEST-2024000000-1</a></p>`
  - `siteCreditHtml()` → `<span class="icp-license"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">ICP-TEST-2024000000-1</a></span><span class="police-license">POLICE-TEST-11010502030123</span>…`
  → 侧栏与页脚两处 ICP 均成为工信部链接（新标签页打开），公网安备保持纯文本。
- 改动明细：
  - `src/helpers.php` L48-L52（新增）— `icpLink()`：把备案号包成指向 `https://beian.miit.gov.cn/` 的 `<a>`（`target="_blank" rel="noopener noreferrer"`，与署名链接同一套属性）。
  - `src/helpers.php` L45 — `sidebarIcpHtml()` 输出由 `e($icp)` 纯文本改为 `icpLink($icp)`。
  - `src/helpers.php` L69 — `siteCreditHtml()` 页脚 `<span class="icp-license">` 内容由 `e($icp)` 改为 `icpLink($icp)`。
  - `assets/app.css` L143-L144（新增）— `.sidebar-icp a:hover { text-decoration: underline; }`，给侧栏 ICP 链接可点击反馈（页脚侧已有 `.site-credit a:hover` 覆盖；全局 `a { color: inherit }`，两处仍为 muted 灰）。
  - 资源缓存版本号：`assets/app.css?v=27 → v=28`（`admin.php` L836、`admin_orders.php` L268、`account.php` L190、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L50、`install.php` L217、`register.php` L141）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。若工信部后续更换备案查询入口，只需改 `icpLink()` 一处。

---

### [2026-09-26 23:30:17] 在 ICP 备案号基础上新增「公网安备号」展示（后台可填、留空不显示）

- 目的：用户要求「原有基础上再加一个公网安备」，即在既有 ICP 备案号之外，再增加一个独立的公网安备号字段，行为与 ICP 完全一致：后台「系统设置 → 站点信息」可填、留空则不显示、有侧栏页显示在侧栏 ICP 之下、无侧栏页显示在页脚 ICP 右侧；两个字段相互独立，只填其一不影响另一个。原 `sidebarIcpHtml()` / `.sidebar-icp` 与 `config.icp_license` 均不改名（保持上一条记录的行号引用仍有效），本次为纯增量。
- 验证：`php -l` 校验 10 个受影响 PHP 文件全部 `No syntax errors detected`。实机冒烟（PHP 8.5.9 内建服务器 `127.0.0.1:8088` + MariaDB 3306，临时脚本 `.dev/smoke-police.ps1` / `.dev/smoke-anon.ps1` 跑完已删除）四阶段结果：
  1. 两条都未填：6 个侧栏页与匿名 `index / register` 全部 `sidebarIcp=N sidebarPolice=N footerIcp=N footerPolice=N`，`credit=Y`。
  2. 两条都填：6 个侧栏页 `sidebarIcp=Y sidebarPolice=Y`；匿名 `index / register`（全新会话，200）`footerIcp=Y footerPolice=Y` 且 `shell=N`（确认无侧栏页走页脚）。
  3. 只填 ICP：`dashboard` 与匿名 `index` 均 `sidebarIcp=Y sidebarPolice=N footerPolice=N`。
  4. 只填公网安备：均 `sidebarIcp=N sidebarPolice=Y footerPolice=Y`。
  → 两条备案号渲染相互独立、「留空不显示」成立。
  5. 上述验证使用的临时账号 `zzsmoke_admin`(group 1) / `zzsmoke_user`(group 3)、测试写入的 `icp_license` / `police_license` 行、`login_attempts` 记录已全部删除还原（库中仅剩 id=1 `admin`、id=2 `test`）。
- 改动明细：
  - `src/helpers.php` L30-L39（新增）— `policeLicense()`，读 `config.police_license`，与 `icpLicense()` 同构（含 `storage/installed.lock` 守卫，安装向导阶段返回空串，避免 `install.php` 因连库失败 500）。
  - `src/helpers.php` L49-L55（新增）— `sidebarPoliceHtml()`，非空时输出 `<p class="sidebar-police">`，未填返回空串。
  - `src/helpers.php` L57-L68 — `siteCreditHtml()` 页脚在 `<span class="icp-license">` 之后插入 `<span class="police-license">`（页脚里公网安备位于 ICP 右侧）。
  - `admin.php` L279 — `save_site` 分支新增 `Database::setConfig('police_license', trim((string) ($_POST['police_license'] ?? '')))`，与 `icp_license` 一样原样保存、不做格式校验。
  - `admin.php` L1051-L1055 — 「站点信息」面板说明文案改为「备案号将显示在登录页与各页面侧边栏（用量信息下侧）」，并在 ICP 输入框下新增「公网安备号」输入框（`maxlength="64"`，placeholder「如 京公网安备 11010502030123号，留空则不显示」）。
  - `admin.php` L842、`dashboard.php` L70、`account.php` L207、`pay.php` L159、`logs.php` L71、`admin_orders.php` L274 — 6 个侧栏页在 `sidebarIcpHtml()` 之后各插入一行 `sidebarPoliceHtml()`（公网安备紧随 ICP 之下）。
  - `assets/app.css` L140 — `.sidebar-icp, .sidebar-police` 合并为同一组样式；L142 新增 `.sidebar-icp + .sidebar-police { padding-top: 2px }`（两条相邻时收窄上间距，视觉归为一组）；L144 新增 `.drive-nav + .sidebar-police { margin-top: auto }`（无用量块的侧栏页里公网安备随 ICP 一起贴底）。
  - `assets/app.css` L150 — 桌面端隐藏页脚备案号的规则扩到 `.police-license`；L456 移动端（`≤760px`）`display:none` 名单加入 `.sidebar-police`；L473 移动端把页脚 `.police-license` 重新 `display:inline`（与 ICP 同规则）。
  - 资源缓存版本号：`assets/app.css?v=26 → v=27`（`admin.php` L836、`admin_orders.php` L268、`account.php` L190、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L50、`install.php` L217、`register.php` L141）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。公网安备复用现有 `config` 表的 KV 行（`config_key = 'police_license'`），**无需迁移**：老库没有该行时 `Database::config()` 返回默认空串，表现为「未填写、不显示」，直到管理员在后台保存才会插入该行。

---

### [2026-09-26 23:22:42] 新增可配置 ICP 备案号展示与 SZY创新工作室技术支持署名，并把用量信息补到账户管理 / 会员套餐页

- 目的：三条需求。① 系统设置里预留 ICP 备案号填写位，留空则不显示，填写后在登录页与所有页面展示；② 所有页面注明「由 SZY创新工作室 提供技术支持」并链接 `https://www.szystudio.cn`（固定不可配置）；③ 非管理员用户的用量信息此前只在「我的文件」侧栏出现，要同时出现在「账户管理」与「会员套餐」页。
  1. **展示位置（已与用户确认）**：无侧栏页（`index` / `register` / `install`）放页面底部居中页脚；有侧栏页（`dashboard` / `account` / `pay` / `admin` / `admin_orders` / `logs`）的 ICP 放侧边栏「用量信息下侧」，署名放页面底部页脚。窄屏（`≤760px`）侧栏被压缩成底部导航条、放不下 ICP，改由页脚展示（`body.drive-shell` 下桌面端隐藏页脚 ICP、移动端再显示回来）。
  2. **署名不可修改**：直接写在 `siteCreditHtml()` 里，不读 `config` 表，后台也没有对应开关，避免被改掉或去掉。链接 `target="_blank" rel="noopener noreferrer"` 新标签页打开。
- 验证：`php -l` 校验 10 个受影响 PHP 文件全部 `No syntax errors detected`。实机冒烟（PHP 8.5.9 内建服务器 `127.0.0.1:8088` + MariaDB 3306，临时脚本 `.dev/smoke-icp.ps1` 跑完已删除）：
  1. 未填写 ICP 时，`dashboard / account / admin?tab=system / logs / admin_orders` 与 `index / register / install` 全部 `sidebarIcp=N`、`footerIcp=N`、`credit=Y`；填写后上述侧栏页 `sidebarIcp=Y`，三个无侧栏页 `footerIcp=Y`，`credit` 恒为 `Y`。
  2. 非管理员在 `account.php` / `pay.php` 的侧栏出现 `class="sidebar-storage"`（用量块），管理员这两页 `storage=N`（`dashboard` 两者都有，属原有行为）。
  3. 上述验证使用的两个临时账号（`zzsmoke_admin` / `zzsmoke_user`）与测试写入的 `icp_license` 行已全部从库里删除还原。
- 改动明细：
  - `src/helpers.php` L18-L50（新增，紧跟 `e()` 之后）— 新增 `icpLicense()`（读 `config.icp_license`，`trim` 后返回，未填返回空串）、`sidebarIcpHtml()`（未填返回空串，否则输出 `<p class="sidebar-icp">`）、`siteCreditHtml()`（输出 `<footer class="site-credit">`，ICP 非空才渲染 `<span class="icp-license">`，署名固定）。
    - **关键点**：`icpLicense()` 先判断 `storage/installed.lock` 是否存在，不存在直接返回 `''`。`Database::config()` 必须连库（依赖 `config/database.php`），而安装向导阶段这两者都还没生成，不加守卫会让 `install.php` 直接 500。
  - `admin.php` L276-L279（新增 `save_site` 分支）、L1048-L1055（「站点信息」面板，位于「支付设置」之后）、L840、L1058 — 后台「系统设置」新增 ICP 备案号输入（`maxlength="64"`，留空即不显示，故不做格式校验）；侧栏与页脚接入两个新 helper。
  - `dashboard.php` L69、L157 — 侧栏用量块之后插 ICP，`</main>` 之后插页脚署名。`account.php` L206、L343 与 `pay.php` L158、L265 同理。
  - `account.php` L176（新增 `$usagePercent`）、L200-L205（新增非管理员 `sidebar-storage` 用量块）；`pay.php` L98（新增 `$usedBytes` 与 `$usagePercent`）、L152-L157（同款用量块）。两处的用量块与 `dashboard.php` L61-L68 完全同构，且都只在 `!isAdmin($user)` 时渲染。
  - `logs.php` L70、L121 与 `admin_orders.php` L273、L353 — 同样的侧栏 ICP + 页脚署名（这两页没有用量块）。
  - `index.php` L82、`register.php` L184、`install.php` L277 — 无侧栏页只在 `</main>` 之后输出页脚（含 ICP + 署名）。
  - `assets/app.css` L36-L40 — 登录 / 安装页由「绝对居中」改成 `display:flex; flex-direction:column`，`.login-layout / .install-layout` 在 shell 内改为 `min-height:auto; flex:1; margin:0 auto`，让页脚自然贴在视口底部。
  - `assets/app.css` L100、L149 — `.drive-shell` 加 `display:flex; flex-direction:column`、`.drive-main` 加 `flex: 1 0 auto`，使有侧栏页的署名页脚在内容较短时也贴底，而不是悬在页面中间。
  - `assets/app.css` L140-L148 — 新增 `.sidebar-icp`、`.drive-nav + .sidebar-icp { margin-top: auto }`（侧栏没有用量块时 ICP 与账号信息一起贴底，否则原先 `.drive-nav + .account-menu` 的 `margin-top:auto` 会因中间插入了 ICP 而失效）、`.site-credit` 系列样式、`body.drive-shell .site-credit .icp-license { display:none }`。
  - `assets/app.css` L454、L471-L472 — 移动端（`≤760px`）把 `.sidebar-icp` 加进 `display:none` 名单，并让 `body.drive-shell .site-credit .icp-license` 重新 `display:inline`，实现「ICP 在桌面端走侧栏、移动端走页脚」。
  - 资源缓存版本号：`assets/app.css?v=25 → v=26`（`admin.php` L835、`admin_orders.php` L268、`account.php` L190、`logs.php` L65、`pay.php` L142、`index.php` L57、`dashboard.php` L50、`install.php` L217、`register.php` L141）。`app.js?v=15` / `admin.js?v=9` 未改动。
- 备注：未改表结构，旧库无需手工补列（`schema_version` 仍为 10）。ICP 复用现有 `config` 表的 KV 行（`config_key = 'icp_license'`），**无需迁移**：老库没有该行时 `Database::config()` 返回默认空串，表现为「未填写、不显示」，直到管理员在后台保存才会插入该行。

---

### [2026-09-26 22:36:58] 全流程功能检查后的 P1/P2 缺陷修复与上传队列、确认弹窗交互优化

- 目的：对「安装 → 注册 → 登录 → 绑定 Notion → 上传/下载 → 购买会员 → 支付回调 → 开通/到期降级 → 后台管理」全链路做一轮功能检查，修掉 12 项缺陷（6 项 P1 + 6 项 P2，其中 1 项核实后撤销），并顺带优化上传队列、自定义确认弹窗的交互与样式。
- 验证：
  1. `php -l` 校验 13 个受影响 PHP 文件，均无语法错误；`node --check assets/app.js` 退出码 0。
  2. 实机冒烟（PHP 8.5.9 内建服务器 `127.0.0.1:8088` + MariaDB 3306）：`install.php → 302`（已安装正常跳转），`index.php / register.php / captcha.php / forgot.php → 200`，`assets/app.css?v=25 → 200`、`assets/app.js?v=15 → 200`、`assets/admin.js?v=9 → 200`。
  3. 结论：主链路无 P0 级阻断性 bug；本次修复为「部分失败被静默丢弃 / 资源残留 / 对账错单 / 提示错误」这类非阻断但会造成数据与体验问题的缺陷。
- 改动明细：

  **P1（功能性缺陷）**
  - `assets/app.js` L469-L490 — `runQueue` 在多文件上传存在失败条目时不再 `location.replace` 刷新。原来「成功若干 + 失败若干」会直接刷新，把失败原因与待重传文件一起冲掉（用户误以为全部成功）；现在有失败项时保留错误文案与「重试」按钮，并 toast 汇总「成功 x 个，失败 y 个」。
  - `api/upload.php` L454-L456 — finish 阶段「分块已齐全且字节数完全一致」之后统一登记 `$cleanupChunkTokens[$token]`。原来只有 `assertUploadAllowed()` 分支登记，导致后续「重新校验文件名 / 配额 / 解析目标账户」抛错时 `.part` 残留（最大 5GB，最长 12 小时才被回收）。
  - `pay_return.php` L16-L28 — 易支付回跳实际携带 `out_trade_no`，改为优先用它精确定位本次订单；参数缺失或不匹配时才退化为「最近 5 笔订单，优先待支付」。原来一律取最近一笔 pending，用户同时开多笔待支付订单时会拿错订单对账（是否真实支付仍由 `reconcileOrder()` 主动查单确认，未降低安全性）。
  - `admin.php` L650-L657 — 人工开通/续费时，若用户原本就是永久会员，`grantPremium()` 不写到期时间，原代码 `strtotime(null)` 被截断为 0，flash 显示「1970-01-01 08:00」；改为 `expires_after === null` 时显示「永久有效」。
  - `admin.php` L253-L277 — `test_mail` 与 `save_access` 统一落库 `registration_enabled` / `email_required`。「发送测试邮件」与「保存设置」共用同一表单，原来点测试只发信、静默丢弃同表单里改动的注册开关与强制邮箱勾选；同时保留「开启强制绑定邮箱前必须已配置 SMTP」的校验（`Database::config()` 每次读库、无内存缓存，故先存 SMTP 再校验是安全的）。
  - `admin_orders.php` L149-L172 — `mark_paid` 按订单状态分支：`paid` 提示「无需重复开通」，`void` 提示「该订单已作废，无法标记为已支付，如需为用户开通请到用户管理手动开通」，其余状态才执行 `settleOrderPayment`。`void` 由 `src/helpers.php` 的 `revokePremium()` 写入、可从「用户管理」触发，故「旧页面 + 另一标签页作废后 POST 回 mark_paid」是真实可达路径。

  **P2（清理与健壮性）**
  - `logs.php` L59-L60（原 L60-L66 删除）— 删除 `$currentFile` 死代码（组装后从未被使用）。
  - `admin_orders.php` L220-L226 — 订单搜索关键字未转义 LIKE 通配符，搜「50%」「a_b」会命中大量无关订单；改用独立变量 `$keywordLike` 转义 `\ % _`（不覆盖 `$keyword`，避免污染输入框回显与分页链接），并把关键字匹配拆成三个不同占位符（原生预处理不允许同名占位符复用）。
  - `install.php` L57-L64 — 安装阶段的 DSN 连接补 `SET time_zone`，与 `Database::connection()` 对齐会话时区。否则安装时由 MySQL `CURRENT_TIMESTAMP` 写入的初始管理员等记录，会与后续记录整体偏移数小时。
  - `captcha.php` L5-L12 — GD 缺失检查由 `issueCaptcha()` 之后移到之前。原来验证码已写入会话、页面却因缺扩展直接 500，用户拿到一个永远看不到答案的验证码。
  - `api/file.php` L45-L56 — `Content-Disposition` 的 ASCII 兜底文件名由写死的 `download` 改为按原名剔除不可打印字符后派生，并保证至少保留扩展名；否则老浏览器/下载工具（不认 `filename*=`）拿到的文件没有后缀、无法用默认程序打开。
  - `src/NotionClient.php` L13 — `API_VERSION = '2026-03-11'` 曾列为可疑项，经 `developers.notion.com`（upload-file / uploading-small-files / sending-larger-files 三处示例）核实为当前最新版本，**本项不做修改**。

  **交互与样式优化**
  - `assets/app.js` L393-L465 — 分块上传失败自动重试：仅 `status === 0`（网络中断）与 `>= 500` 值得重试，最多 3 次、退避 `500ms × attempt`；4xx 不重试（重试也不会成功）。遇 `409 chunk_out_of_order` 时按服务端回传的 `received` 续传，替代原「整块失败即退出」。
  - `assets/app.js` L364-L391 — 新增 `addRetry()`：失败条目附「重试」按钮，点击后清空进度条并重新入队（配合 L382 `pending.push(entry)`），无需重新选择文件。
  - `assets/app.js` L394-L467 — `upload` 由 `new Promise(async …)` 反模式重构为 async 函数，失败统一走 `fail()`（L397）返回 `false`，消除 promise 构造器内吞异常的写法。
  - `assets/app.js` L24-L95、L54、L46、L83 — `askConfirm` 引入 `overlayStack` 栈：Esc/Tab 只作用于最上层弹窗，支持 focus trap（Tab 在弹窗内循环），并在栈空时才移除 `has-overlay` 滚动锁，关闭后恢复原焦点。
  - `assets/app.js` L116、L130 — `[data-action]` 增加 `disabled` 判断；删除操作在已存在 `.confirm-overlay` 时直接忽略，避免快速双击叠出两个确认弹窗。
  - `assets/app.js` L144-L145 — 重命名回填由裸 `.textContent` 改为先 `querySelector('.display-name')` 判空，避免空引用报错。
  - `assets/app.js` L166-L185 — `[data-confirm]` 提交前设 `form.noValidate = true` 再 `requestSubmit(trigger)`。这类按钮所在表单常混有与本次操作无关的字段（如用户行里可编辑的 `required` 用户名），走默认交互式校验时一个空 `required` 就会静默拦下删除/开通，表现为「点了确认却什么都没发生」；确认弹窗已承担确认职责，故关闭浏览器校验（服务端仍有兜底校验，例如 admin_orders 的「设置到期时间」会 flash「请填写要设置到期时间的用户名。」）。
  - `assets/app.css` L172-L173 — 新增 `.queue-retry` 样式，与后台弹窗风格保持一致。
  - `assets/app.css` L322 — `.toast` 的 `z-index` 由 100 提到 300，原来会被 `.confirm-overlay`（`z-index: 200`）盖住。
  - `assets/app.css` L337 — 新增 `body.has-overlay { overflow: hidden; }`：弹窗打开期间锁背景滚动，否则移动端滑动遮罩会把后面整页一起带着滚。
  - `assets/app.css` L453 — 移动端（`≤760px`）`.admin-tabs a` 触摸目标补足 `min-height: 44px`（分区条是移动端的主要切换入口）。
  - 资源缓存版本号：`assets/app.css?v=24 → v=25`（`admin.php` L831、`admin_orders.php` L268、`account.php` L189、`logs.php` L64、`pay.php` L140、`index.php` L57、`dashboard.php` L50、`install.php` L217、`register.php` L141）；`assets/app.js?v=14 → v=15`（`admin.php` L1044、`admin_orders.php` L353、`account.php` L336、`logs.php` L120、`pay.php` L257、`index.php` L82、`dashboard.php` L157、`register.php` L184）。`assets/admin.js?v=9` 本次未改动，保持不变。
- 备注：未改任何表结构，旧库无需手工补列（`schema_version` 仍为 10）；改动全部集中在前端与 PHP 逻辑层，无迁移脚本。

---

### [2026-09-26 21:30:07] 权限组「查看该组用户」入口改为右对齐页脚，消除「位置不对」的观感

- 目的：用户反馈管理后台权限组区块里「查看该组用户（1）」位置不对（截图红框标出它孤零零贴在左栏头像下方）。
  1. **先做实机复现，排除「重复渲染」**：用临时 CLI 脚本伪造 `$_SESSION['user_id']` 后 `include admin.php`（`?tab=users`）统计渲染结果，`查看该组用户` 出现 3 次、`class="group-members"` 3 次、DOM 顺序为「组行 form → 该组 details」交替出现。数据库里确有 3 个权限组（管理员 / 高级用户 / 普通用户），前两个各 1 位成员，因此页面上**本来就会有两处「查看该组用户（1）」**，分属不同组，并非模板重复或 CSS 伪元素复制（全项目 Grep 该文案仅 `admin.php` L912 一处，`assets/` 下无相关 JS 克隆）。
  2. **真正的问题是位置**：`.group-members` 是 `.admin-user-list` 网格里的独立一行，铺满整行却只把文字留在左栏，右侧空出大片，视觉上像不属于上面那个组；而「用户管理」里用户行的操作按钮（开通订阅 / 编辑）都在右侧，此处应与组行的「保存」同侧保持一致。改为页脚右对齐。
- 验证：`php -l` 未涉 PHP 逻辑改动；临时脚本跑完已删除（`storage/_dump_admin.php`）。实机 dump 确认 `查看该组用户` 仍为 3 次、无新增重复；对齐改动为纯 CSS，需人工硬刷新（`app.css?v=24`）后目视确认。
- 改动明细：
  - `assets/app.css` L255-L258 — `.group-members` 的 `margin: -4px 2px 12px` 改为 `2px 2px 12px`（不再负边距上顶，避免按钮压在组行下边框上），`.group-members > summary` 新增 `justify-content: flex-end`（页脚靠右，与「保存」同侧），并补注释说明原因。
  - `admin.php` L822、`admin_orders.php` L261、`account.php` L189、`logs.php` L73、`pay.php` L140、`index.php` L57、`dashboard.php` L50、`install.php` L213、`register.php` L141 — 仅同步缓存版本 `assets/app.css?v=23` → `v=24`，无逻辑改动。
- 备注：未改任何 PHP 模板结构（`admin.php` L902-L924 保持原样），未改表结构；若后续想让入口与「保存」严格同一行，需要把 `<details>` 移进组行表单并让展开的成员清单跨列，本次未做（会牵动 8 列网格与移动端断点）。

---

### [2026-09-26 21:18:54] 结算白名单修正为「待支付 + 已关闭」并统一加锁顺序，补记 README 的 Nginx 规则

- 目的：接着上一轮审计的未修项继续处理（L1 锁顺序、L2、L4、B4），过程中发现上一轮 H1 修得过严，会引入「用户已付款却开通不了」的资金风险，一并纠正。
  1. **修正 H1 过度收窄（重要）**：上一轮把可结算状态收窄成只允许 `pending`。但 `createOrder()` 会用新订单把该用户的旧 `pending` 订单置为 `closed`（`src/helpers.php` L1467-L1468），若用户在下单页与收银台之间反复点击、旧单其实已在网关付款成功，回调到达时订单已是 `closed`，此时会被直接拒绝且网关只收到 `fail`/不再重推，钱就白付了。因此白名单改为 `['pending','closed']`：仍然拒绝 `paid`（幂等）与 `void`（管理员已撤销并回滚过时长，绝不能被复活），但允许 `closed` 被付款回调开通回来。
  2. **L1 死锁**：`settleOrderPayment()` 原先是「先锁 orders 行 → 再等 users 行」，而 `createOrder()`（L1460-L1468）、`revokePremium()`（L1940 起）都是「先锁 users 行 → 再动 orders 行」。当用户点「立即支付」与上一笔订单的支付回调同时到达时，两边互相等待对方持有的行锁，构成真实死锁环。现统一为 `users → orders`。
  3. **L4**：`README.md` 的 Nginx 等效规则漏了 `scripts/`（根 `.htaccess` 是封 `config|src|storage|scripts|.dev|.git` 六项）。
- 验证：
  - `php -l src/helpers.php` → `No syntax errors detected`。
  - 临时脚本连真实数据库跑 5 个场景，全部 PASS 后已回滚数据并删除脚本：A 作废订单重放 → `false`、状态仍 `void`、到期时间不变；B 待支付订单 → `true`、`paid`、按原到期时间顺延 1 个月；C 已支付订单重复回调 → `false`；D 已关闭订单（被新单顶掉）⇒ **新行为** `true`、`paid`、正常顺延；E 永久会员（`premium_expires_at = NULL`）结算 → `true` 且到期时间仍为 `NULL`。
- 改动明细：
  - `src/helpers.php` L2053-L2058 新增 — 在锁 orders 行之前先按 `$order['user_id']` 锁 users 行（`SELECT id ... FOR UPDATE`），统一加锁顺序消除死锁环。
  - `src/helpers.php` 原 L2057-L2059 改为 L2064-L2069 — 幂等白名单由 `status !== 'pending'` 调整为 `!in_array($status, ['pending','closed'], true)`，并补注三种状态的取舍理由。
  - `README.md` L48-L52 — Nginx 规则由 `^/(config|src|storage)/` 扩为 `^/(config|src|storage|scripts)/`，并加一句说明别漏 `scripts/`。
- 备注：
  - 未改数据库结构。
  - 其余未修项本轮判定为「无需改代码」：M1 回调重放已被「非 pending/closed 一律拒绝 + 金额必须一致」实质封堵（重放已支付订单只会命中幂等分支）；L2 订单号可预测没有可利用面（`pay.php`、`pay_return.php`、`pay_notify.php`、`admin_orders.php` 全部按 `user_id` 校验归属，订单号本身不作凭据）；B4「重新下单关闭该用户全部 pending」是刻意设计（避免同一用户并发产生多笔待支付单），且因本次修正 1 已不再造成资金损失，故保留。
  - 上一轮记录里「已关闭订单不再被回调复活」的表述按本条修正 1 作废，以本条为准。

### [2026-09-26 21:12:58] 安全审计后的 4 处修复：作废订单防复活、回调金额核对、永久会员不被降级、月末时长不溢出

- 目的：全项目安全审计发现 1 个高危 + 2 个业务 Bug，本次一并修掉。
  1. **高危**：`settleOrderPayment()` 的幂等判断只挡 `paid`，不挡 `void`/`closed`，管理员作废订单（时长已精确回滚）后，攻击者重放原支付回调即可让订单重新开通，作废白做；易支付签名不含时间戳/nonce，回调可长期重放。
  2. **中危**：`pay_notify.php` 用 `$params['money'] ?? '0'` 兜底，回调一旦不带 `money` 就被当成 0 元判为「金额不符」并拒开，语义是错的（应为「无法核对」）；同时缺失金额与金额不等两种情况在日志里无法区分。
  3. **业务 Bug**：`premium_expires_at = NULL` 在本项目语义是「永久有效」，但 `grantPremium()` 一律按 `now + N 个月` 覆盖，导致管理员手工授予的永久会员一旦续费反而被缩成限时。
  4. **业务 Bug**：`strtotime('+N month')` 在月末溢出到下下月（1 月 31 日 + 1 月 = 3 月 3 日），用户平白多拿几天。
- 验证：
  - `php -l src/helpers.php`、`php -l pay_notify.php` 均 `No syntax errors detected`。
  - `addMonthsClamped()` 独立用例 6/6 通过：`2026-01-31 23:59:59 +1m → 2026-02-28 23:59:59`、`2027-01-31 00:00:00 +1m → 2027-02-28 00:00:00`、`2026-03-31 10:00:00 +1m → 2026-04-30 10:00:00`、`2026-09-26 20:45:31 加 1/3/12 个月` 均按原时分秒顺延。
  - 结算幂等/防复活用临时脚本连真实数据库跑 4 个场景，全部 PASS 后已回滚并删除脚本：A 作废订单重放 → 结算返回 `false`、订单仍为 `void`、用户到期时间不变；B 待支付订单 → 正常 `paid` 并顺延；C 已支付订单重复回调 → 幂等 `false`；D 高级组 + `premium_expires_at = NULL` 的用户续费 → `expires_before`/`expires_after`/库内值全部保持 `NULL`。
- 改动明细：
  - `src/helpers.php` 原 L2024-L2025 改为 L2057-L2059 — 幂等判断由 `status === 'paid'` 收窄为白名单 `status !== 'pending'`，只有待支付订单可结算，已作废/已关闭订单不再被回调复活。
  - `src/helpers.php` L1720-L1744 新增 — 新增全局函数 `addMonthsClamped(int $timestamp, int $months): int`，按月加并收敛到目标月最后一天（`min(原日号, 目标月天数)`），保持原 `H:i:s`。
  - `src/helpers.php` L1780-L1791 — `grantPremium()` 新增 `$keepPermanent` 判定（到期时间为 NULL 且 `!$isAdminGroup` 且已在高级组）：命中时 `$after = null` 且不写 `users.premium_expires_at`/`premium_reminder_sent_at`；未命中时改用 `addMonthsClamped($base, $months)` 替代 `strtotime('+N month')`。
  - `src/helpers.php` L1749、L1834 — 两处 `@return` 注解的 `expires_after` 由 `string` 改为 `?string`（永久会员路径会返回 null）。
  - `pay_notify.php` L57-L67 — 金额核对改为先取 `$receivedMoney = $params['money'] ?? null`，`null` 或空串与差额超标同样拒绝；日志 `received` 在缺失时记 `(缺失)`；告警文案改为「金额缺失或不一致」。
- 备注：
  - 未改数据库结构：`orders.expires_after` 本就是 `DATETIME NULL`（`src/Schema.php` L294），可直接存 null；`reach` 到永久路径时 `orderContributedSeconds()` 返回 null，`revokePremium()` 对 `premium_expires_at = NULL` 走「保持永久、不扣减」分支，作废行为自洽。
  - 审计报告中未修项（已知、待拍板）：M1 回调重放时间窗（网关不支持时间戳，需产品侧取舍）、L1 `settleOrderPayment` 与 `revokePremium` 锁顺序相反、L2 订单号可预测、L4 Nginx 部署下 `.htaccess` 不生效（`config/`、`storage/` 需在 Nginx 层 deny）、B4 重新下单会关闭该用户全部 pending 订单。

### [2026-09-26 21:05:11] 用户管理页两处 UI 修正：搜索框对齐、点「续费订阅」不再连带展开编辑区

- 目的：
  1. 用户管理页「搜索用户」把 `.log-filters` 直接套在 `<label>` 上，而该样式是「横排 flex + `align-items: flex-end`」的**容器**样式，导致「搜索用户」四个字与输入框被排成并排且底部对齐，看起来错位；本页所有其它字段都是「文字在上、控件在下」。
  2. 行内「开通订阅 / 续费订阅」面板 `<div class="user-row-grant">` 原先放在 `<details>` **内部**（`</summary>` 之后），因此打开面板必须先展开折叠，会把「编辑」区一起摊开；点「取消」只把面板置 `hidden`，折叠状态仍为展开，必须再点「收起」才能关掉——与「编辑」「续费订阅」本应是两个并列入口的预期不符。
- 验证：`php -l admin.php`、`php -l admin_orders.php`、`php -l logs.php`、`php -l account.php` 均 `No syntax errors detected`；`node --check assets/admin.js` 通过。改动后结构复查：`.user-row-grant` 位于 `</details>` 之后、`</form>` 之前（仍在表单内，`grant_months` 与 `action=grant_premium` 照常一并提交，`isAdmin` 用户依旧不渲染该面板）。未做浏览器端点击回归，需人工硬刷新（`admin.js?v=9`）确认。
- 改动明细：
  - `admin.php` L855 — 搜索框由 `<label class="log-filters user-search">` 改为 `<div class="log-filters user-search"><label>…</label></div>`，让 `<label>` 回到 `display: grid` 的「文字在上、输入框在下」布局；`.log-filters.user-search input { min-width: 240px }` 仍通过后代选择器生效。
  - `admin.php` 原 L871-L877 删除 — `.user-row-grant` 从 `<details>` 内移出。
  - `admin.php` L883-L889 — `.user-row-grant` 重新插入到 `</details>`（L882）之后、`</form>`（L890）之前，条件仍是 `!isAdmin($managedUser)`。
  - `assets/admin.js` L31-L34 — `setGrantOpen()` 删除 `if (open) fold.open = true;` 及其注释，因为面板已不再依赖折叠展开。
  - `assets/admin.js` L44-L45 — 新增 `fold.addEventListener('toggle', () => setGrantOpen(false));`，折叠状态一变化就收起开通面板，避免「编辑」与「续费订阅」两块同时展开。
  - `admin.php` L1035、`admin_orders.php` L346、`logs.php` L128、`account.php` L337 — 缓存版本 `admin.js?v=8` → `v=9`（`app.css`、`app.js` 未改动，版本号不变）。
- 备注：本次只动 HTML 结构与 JS，未改 CSS 与数据库。

---

### [2026-09-26 20:06:20] 管理后台二级菜单「内容与账户」改名为「账户池管理」

- 目的：原标签「内容与账户」与该分区实际职责（用户文件 + 官方账户池分配）不符，容易误解，改为「账户池管理」更贴合用途。
- 验证：`php -l admin.php` → `No syntax errors detected`；本地 `http://127.0.0.1:8088` 以 admin 登录访问 `admin.php?tab=content` → HTTP 200，页面标题与侧栏/页签均显示「账户池管理」，页面内已无「内容与账户」字样；**tab 键保持 `content` 不变**，`?tab=content` 链接与 `user_id` 跳转（订单页「用户」、用户行「管理文件」）不受影响。
- 改动明细：
  - `admin.php` L27 — `adminTabs()` 中 `content` 项 `label` 由 `内容与账户` 改为 `账户池管理`（`group` / `desc` / 键名均不变）。
  - `admin.php` L40 — 默认分区注释里「默认展示内容与账户」同步改为「默认展示账户池管理」，避免注释与界面文案不一致。

---

### [2026-09-26 19:56:30] 全站时区改为 UTC+8；作废订单只扣减该笔订单月数（叠加 1+1 撤 1）；用户列表新增「到期时长」

- 目的：
  1. **时区修正（订单创建时间显示成 UTC+0）**：项目此前没有任何时区设置，PHP 默认 `UTC`；`Database::connection()` 会把 MySQL 会话时区设成 PHP 时区偏移，于是 `orders.created_at`（`TIMESTAMP` 列）被按 UTC 显示，比北京时间少 8 小时。框架要求全站时间统一 UTC+8，故在 `bootstrap.php` 设置 `date_default_timezone_set('Asia/Shanghai')`，PHP 与 MySQL 会话时区同时变为 `+08:00`（17 个入口文件都 `require` 该文件，一处生效全站）。
  2. **作废语义修正（作废 1+1 应只撤 1）**：因购买可叠加（`grantPremium` 以 `max(现有到期时间, now)` 为基准再 `+months`），原先 `revokePremium()` 直接 `UPDATE users SET premium_expires_at = NOW()`，把叠加出来的会员时长**全部清空**——表现为"看着只作废了一条，实际会员全没了"。现改为按该笔订单的 `months` 反向扣减：从当前到期时间 `strtotime('-N month')`，扣减后仍晚于当前时刻则保持高级用户（只缩短到期时间），**扣完才**降回默认用户组；`premium_expires_at IS NULL`（永久有效）无具体时间可扣，保持原状；管理员组不降级，避免把管理员移出管理组。
  3. **用户列表补充「到期时长」**：`admin.php` 用户行 meta 末尾（「Notion 官方账户」之后）新增「到期时长」；管理员显示「永久」，永久会员显示「永久」，有到期时间显示「剩余 N 天」（不足一天按一天计）或「已到期」，普通用户显示「未开通」。
- 验证：
  - `php -l src/bootstrap.php src/helpers.php admin_orders.php admin.php` → 四个文件均 `No syntax errors detected`。
  - 时区（CLI + HTTP 双重验证）：临时脚本打印 `php_tz=Asia/Shanghai`、`mysql_session_tz=+08:00`、`now_php == now_mysql == 2026-09-26 19:53:56`；`orders.created_at` 形如 `2026-09-26 19:49:05`，与订单号内嵌时间戳 `ND20260926114905...` 一致。HTTP 侧登录 admin 打开 `admin_orders.php`，「创建时间」列显示 `2026-09-26 19:49`（修复前为 `11:49`）。
  - 叠加扣减（事务内回滚，未污染数据）：对 `user_id=2` 连开 1+1 个月 → 到期 = 基准 +2 月；撤第 1 笔 → `months=1`、`still_premium=true`、到期恰早 1 个月、另一笔仍 `paid`、该笔转 `void`；重复作废同一笔 → 抛「该订单已作废」；撤第 2 笔 → `still_premium=false`、`group_id` 回到默认组（3）。共 12 项断言 `ALL PASS`。
  - 端到端（真实 HTTP，admin 登录，`http://127.0.0.1:8088`）对 `test1` 复现用户描述的 1+1 场景：
    - 初始 `普通用户 · Notion 官方账户 · 到期时长 未开通`；
    - 连开 2 笔 1 个月 → `高级用户 · 至 2026-11-26 · 到期时长 剩余 61 天`；
    - 作废第 1 笔（`ND202609261955456248`）→ flash「已作废订单 ND202609261955456248，已从 test1 的会员时长中扣减 1 个月，剩余有效期至 2026-10-26 19:55。」，列表变 `高级用户 · 至 2026-10-26 · 到期时长 剩余 30 天` → **正是"作废 1+1 只撤 1"**；
    - 作废第 2 笔（`ND202609261955453071`）→ flash「……高级套餐已失效并降回普通用户组。」，列表回到 `普通用户 · 到期时长 未开通`。
    - 管理员行显示 `到期时长 永久`。
- 改动明细：
  - `src/bootstrap.php` L10-L12 — 新增 `date_default_timezone_set('Asia/Shanghai');`（含注释），使 PHP 时区与 `Database::connection()` 设置的 MySQL 会话时区同为 `+08:00`。
  - `src/helpers.php` L1858-L1864 — `revokePremium()` 文档注释重写，说明"只作废指定这一笔订单 + 按订单月数回退会员时长，扣完才降级"。
  - `src/helpers.php` L1866-L1868 — 返回值由 `bool`/`void` 改为 `array{voided_orders:int, months:int, expires_at:?string, still_premium:bool}`，供调用方区分「永久会员 / 扣减后仍有效 / 已降级」三种提示。
  - `src/helpers.php` L1877-L1882 — 用户行锁查询由 `SELECT group_id` 改为 `SELECT group_id, premium_expires_at`，`FOR UPDATE` 内拿到当前到期时间；`fetch()` 非数组时抛「该用户不存在或已被删除。」
  - `src/helpers.php` L1884-L1891 — 新增：以 `status='paid'` + `FOR UPDATE` 取出该笔订单 `months`，取不到（`fetchColumn() === false`）即抛「该订单已作废，无需重复操作。」，避免重复作废还重复扣时长。
  - `src/helpers.php` L1898-L1919 — 新增会员时长回退核心逻辑：`premium_expires_at IS NULL` → `stillPremium=true` 且**不写库**；否则 `strtotime('-'.$months.' month', strtotime($currentExpiry))`，`> time()` 则写回缩短后的到期时间并保持高级；否则写 `date('Y-m-d H:i:s')`（留下失效时间点）并置 `premium_reminder_sent_at=NULL`。
  - `src/helpers.php` L1921-L1928 — 降级条件由无条件改为 `!$stillPremium && !$isAdminGroup` 才 `UPDATE users SET group_id = 默认组`。
  - `src/helpers.php` L1930-L1937 — 单笔作废 SQL 保持按 `id + user_id + status='paid'`，`rowCount() === 0` 时抛异常触发事务回滚（防并发重复作废）。
  - `src/helpers.php` L1949-L1963 — 日志改为 `Logger::info('payment', '作废单笔订单并扣减对应会员时长。', [...])`，新增 `months` / `still_premium` / `expires_at` 字段；并按新结构返回数组。
  - `admin_orders.php` L37 — 动作分支注释改为"只作废这一笔订单，并从该用户的会员时长中扣减这笔订单对应的月数"。
  - `admin_orders.php` L56-L85 — 消费 `revokePremium()` 新返回值，按 `expires_at === null`（永久会员不受影响）/ `still_premium`（已扣减 N 个月，剩余有效期至 …）/ 其余（已失效并降回普通用户组）输出三种 flash 文案；日志同步补 `months` / `still_premium`。
  - `admin_orders.php` L294 — 列表说明文字改为"「作废订阅」只作废当前这一笔订单，并从该用户的会员时长中扣减这笔订单对应的月数（购买可叠加），扣完才降回普通用户组，已作废的订单不能重复作废"。
  - `admin_orders.php` L317 — 行内「作废订阅」按钮 `data-confirm` 文案改为扣减时长口径（渲染条件 `user_id > 0 && !isAdmin && isPremiumUser && status === 'paid'` 保持不变）。
  - `admin.php` L803-L818 — 新增 `$premiumDuration` 闭包（紧随 `$premiumLabel`）：管理员 / 永久会员 → `永久`，非高级组 → `未开通`，有到期时间 → `剩余 N 天`（`premiumDaysLeft() > 0`）否则 `已到期`。
  - `admin.php` L864 — 用户行 `small.user-meta` 末尾追加 `· 到期时长 <?= e($premiumDuration($managedUser)) ?>`，位置在「Notion 官方账户」之后。
- 数据库相关：**无需改表**。`orders.created_at` 为 `TIMESTAMP`，由 MySQL 按会话时区自动换算，改时区后历史值显示即自动变为 UTC+8，无需迁移；`users.premium_expires_at` / `orders.paid_at` / `orders.voided_at` 为 `DATETIME`，存字面值，**改时区前写入的旧值按 UTC 字面值存**，`strtotime()` 现在按 `+08:00` 解读，会比真实时间早 8 小时（历史数据一次性偏移，是否需要刷数据待确认）。
- 遗留/后续（待拍板，未处理）：`test1`(user_id=2) 名下累积的测试订单（含本轮新增并已作废的 `ND202609261955456248`、`ND202609261955453071`）是否清理；本轮改动未提交。

---

### [2026-09-26 19:48:02] 修复「作废一笔连作废全部」+ 已作废不可重复作废 + 自定义确认弹窗替换浏览器原生 confirm

- 目的：
  1. **修 Bug（作废溢出）**：管理员点「作废订阅」时，原 `revokePremium()` 是按 **userId** 把该用户**所有**已支付订单一次性全部作废，因此出现"作废一条就把所有都给作废了"。改为按 **orderId** 只作废当前这一笔，同一用户的其他已支付订单保持原状（`revokePremium($userId, $orderId)`）。
  2. **已作废订单不可重复作废**：前端在状态不是 `paid` 时不再渲染「作废订阅」按钮；后端在动作分支先判 `status === 'void'` 直接拒绝并提示「该订单已作废，无需重复操作。」；`revokePremium()` 内 UPDATE 追加 `status = 'paid'` 条件并用 `rowCount() === 0` 回滚兜底，防并发下重复作废。
  3. **自定义确认弹窗**：`data-confirm` 的确认框此前用浏览器原生 `window.confirm`，样式与后台风格不一致、移动端体验差。现改为 `app.js` 内自绘模态弹窗 `window.askConfirm(message)`（返回 `Promise<boolean>`），全站 `[data-confirm]` 按钮统一接管。
- 验证：`php -l` 对 `src/helpers.php`、`admin_orders.php`、`admin.php`、`pay.php` 全部 `No syntax errors detected`；`node --check assets/app.js`、`node --check assets/admin.js` 均 exit 0。本地 `http://127.0.0.1:8088` 以 admin 登录做端到端校验：
  - 对 `test1`(user_id=2) 连续开通 2 次各 1 个月 → 生成 2 笔「管理员授权」订单 `ND202609261147037481`、`ND202609261147032237`；
  - 作废 `ND202609261147037481` → flash 提示「已作废订单 ND202609261147037481，test1 的高级套餐已失效并降回普通用户组。」；列表结果为 `7481 => 已作废 [无作废按钮]`、`2237 => 管理员授权 [保留]`，其余历史作废订单不受影响 → **确认「只作废单笔」修复成功**；
  - 重复作废同一笔 / 作废历史已作废订单 `ND202609261142089204` → 均返回错误块「该订单已作废，无需重复操作。」（前端也不再显示按钮）→ 确认重复作废被拦；
  - `test1` 套餐状态回到「普通用户」；资源就绪 `app.css?v=23` / `app.js?v=14` / `admin.js?v=8`，`app.js` 中已无 `window.confirm` 且存在 `window.askConfirm`。
- 改动明细：
  - `src/helpers.php` L1866 — `revokePremium()` 签名由 `(int $userId)` 改为 `(int $userId, int $orderId)`，并重写文档注释说明只改动指定这一笔订单。
  - `src/helpers.php` L1900-L1907 — 订单作废 SQL 由"按 userId 全量"改为"按 id + user_id + status='paid' 单笔"，`rowCount() === 0` 时 `throw RuntimeException('该订单已作废，无需重复操作。')` 回滚套餐调整。
  - `src/helpers.php` L1919 — 日志改为 `Logger::info('payment', '作废单笔订单并撤销高级套餐。', ...)`，新增 `order_id` / `voided_orders` 字段。
  - `admin_orders.php` L36-L68 — `revoke_subscription` 动作分支改为按 `order_no` 定位订单（`findOrderByNo`）；依次拦截：订单不存在 / `void` 已作废 / 非 `paid` / 目标用户不存在 / 目标是管理员；通过后调用 `revokePremium($target['id'], $order['id'])` 并写日志、flash、回跳。
  - `admin_orders.php` L297-L299 — 行内「作废订阅」按钮：条件追加 `$orderStatus === 'paid'`（已作废不渲染），提交字段由 `user_id` 改为 `order_no`，`data-confirm` 文案改为单笔口径。
  - `admin_orders.php` L275 — 列表说明文字更新为"「作废订阅」只作废当前这一笔订单并撤销该用户的会员资格，已作废的订单不能重复作废"。
  - `admin_orders.php` L242（`app.css?v=23`）、L327（`app.js?v=14` + `admin.js?v=8`）— 静态资源缓存版本号递增。
  - `assets/app.js` L22-L61 — 新增 `askConfirm(message)` 自定义确认弹窗（Promise 化；含确定/取消按钮、Esc 关闭、点遮罩空白取消、关闭后焦点归还触发元素），挂到 `window.askConfirm`。
  - `assets/app.js` L102 — 文件删除确认由 `window.confirm` 改为 `await askConfirm('确认删除这个文件？删除后将从 Notion 页面归档。')`（文件重命名的 `window.prompt` 本次未改）。
  - `assets/app.js` L135-L159 — 新增全局 `[data-confirm]` 点击委托：先弹 `askConfirm`，确认后用 `form.requestSubmit(trigger)` 提交以保留按钮 `name/value`（如 `action=grant_premium`）；旧浏览器无 `requestSubmit` 时注入 hidden input 后 `form.submit()`。**该监听器置于上传 UI 的早退语句之前**，确保无上传控件的管理页也能生效。
  - `assets/app.js` L61 — 新增全局 `window.askConfirm = askConfirm` 导出。
  - `assets/admin.js` L1 — 删除原顶部用 `window.confirm` 拦截 `[data-confirm]` 的 5 行，改为一行注释（确认框统一由 `app.js` 接管）。
  - `assets/app.css` L331-L338 — 新增 `.confirm-overlay` / `.confirm-dialog` / `.confirm-message` / `.confirm-actions` / `.confirm-danger` 样式，风格与后台其它弹窗一致。
  - 其余页面静态资源版本号统一递增（仅替换版本串）：`admin.php` L806/L1019、`logs.php` L73/L128、`account.php` L189/L336/L337、`dashboard.php` L50/L157、`index.php` L57/L82、`install.php` L213、`pay.php` L140/L257、`register.php` L141/L184。
  - 依赖数据库：沿用上一版新增的 `orders.voided_at` 列，无新表结构变更，旧库无需手工补列。

---

### [2026-09-26 19:40:15] 会员操作入口归位 + 用户/权限组折叠与搜索 + 删除「套餐与订单」二级菜单

- 目的：
  1. **开通订阅入口归位**：此前把「开通订阅」放进了订单列表（顶部表单 + 行内按钮），与「订单只读、会员操作归用户」的心智冲突——管理员要开通订阅应当面向「用户」而不是「某笔订单」。现改为：用户管理页每行「编辑」旁新增「开通订阅 / 续费订阅」，点击后在行内展开时长选择，再点「确认开通」提交。
  2. **删除名不副实的「套餐与订单」二级菜单**：该菜单（`?tab=premium`）不含任何订单列表，实际只有「选中用户后的会员与到期」面板，必须先到用户管理选用户才显示，对管理员长期是"不知道干啥用"的死入口。按 用户 决策（选项 1）整块删除，其中唯一独有的「保存到期时间（含永久有效）」搬到订单管理页成为独立面板。
  3. **用户管理页减噪**：列表每行默认只显示头像、用户名与概览，展开「编辑」后才出现可修改控件；新增搜索框按用户名 / 邮箱即时筛选，用户多时不必长滚动。
  4. **权限组可下钻**：3 个权限组每块下方新增折叠「查看该组用户（N）」，展开列出组内成员（头像、用户名、文件数、会员状态），解决"权限组看不到到底有谁"。
  5. **作废订阅可追溯**：新增 `orders.voided_at`，一键作废后该用户已支付订单标记为「已作废」并记录作废时间，金额与订单号保留以便对账。
- 验证：`php -l` 对 `admin.php`、`admin_orders.php`、`pay.php`、`pay_return.php`、`src/helpers.php`、`src/Schema.php` 全部 `No syntax errors detected`。本地 `http://127.0.0.1:8088` 以 admin 登录做端到端校验：
  - `admin.php?tab=users` → 200，含「开通订阅」按钮、`user-row-grant` 面板、`user-row-toggle` 折叠（2 行）、`查看该组用户` 折叠（3 组，与权限组数量一致）、`#user-search` 搜索框。
  - 实际提交开通：为 `test1` 开通 1 个月 → 提示「已为 test1 开通 1 个月高级套餐，有效期至 2026-12-26 10:09（授权订单 ND202609261139521000）」；刷新后该行按钮变为「续费订阅」。对管理员账号提交被正确拦截（「管理员账号无需开通高级套餐」）。
  - `admin_orders.php` → 200，已不含任何「开通订阅」入口；含「作废订阅」与「设置套餐到期时间」面板；人工授权订单显示为「管理员授权」徽章。
  - 实际提交作废：`test1` → 提示「已作废 test1 的高级套餐，账号已降回普通用户组（影响 3 笔已支付订单）」，订单页出现「已作废」状态，用户行按钮回退为「开通订阅」。
  - `admin.php?tab=premium`（旧地址）→ 200 且不再渲染「套餐与订单」菜单与「会员与到期」面板，菜单已彻底移除。
- 数据库影响：`orders` 新增列 `voided_at DATETIME NULL DEFAULT NULL`，`schema_version` 由 9 升到 10。**旧库无需手工补列**：`Schema::migrate()` 会在访问时自动 `ADD COLUMN`；新装由 `createOrdersTable()` 直接建列。`orders.status` 原本即 `VARCHAR(20)`，新增取值 `'void'` 不涉及改列。
- 改动明细：
  - `admin.php` L28 — 原 L28 删除 `'premium' => [...]` 菜单项，二级菜单仅剩 用户管理 / 内容与账户 / 系统设置。
  - `admin.php` L622-L652 — 新增 `grant_premium` 分支：按 `user_id` 定位用户（`findUserById`），管理员账号直接拒绝，调用 `grantManualPremiumOrder()` 生成一条 `trade_no` 以 `MANUAL-` 开头、金额 ¥0.00 的授权订单，提交后回跳 `admin.php?tab=<原分区>&user_id=<该用户>`。
  - `admin.php` L850-L862 — 用户行 `summary` 内新增 `.user-row-actions`：「开通订阅 / 续费订阅」（文案随 `isPremiumUser()` 切换，管理员行不渲染）+「编辑 / 收起」；`details` 内新增 `.user-row-grant` 面板（时长下拉取自 `$plans` + 「确认开通」`action=grant_premium` + 「取消」）。
  - `admin.php` L807 — 用户列表上方新增搜索框 `<input id="user-search">`（原 L808）。
  - `admin.php` L811-L835 — 用户行改为 `<details>` 折叠结构，行上带 `data-user-search="用户名 邮箱"`，行尾新增 `#user-search-empty` 空结果提示。
  - `admin.php` L846-L860 — 权限组循环内新增 `<details class="group-members">`，按 `group_id` 过滤出成员并列出组员清单。
  - `admin.php` 原 L1044-L1075 删除 — 整个 `?tab=premium` 分区（未选用户提示 + 「XXX 的会员与到期」面板，含开通续费表单、到期日期表单、一键作废表单）。
  - `admin.php` 原 `parsePremiumExpiry()`、`findUsernameOrFail()` 函数删除 — 随开通/到期时间逻辑一起迁到 `admin_orders.php`；原 `revoke_premium`、`set_premium_expiry` 分支删除（作废改在订单页、到期时间改在订单页）。
  - `admin.php` L806 / L1019 — 缓存版本 `app.css?v=21` → `v=22`、`admin.js?v=6` → `v=7`。
  - `admin_orders.php` L16-L29 — 新增页面级 `parsePremiumExpiry()`（严格校验 `Y-m-d`，留空 = 永久有效）。
  - `admin_orders.php` 原 L37-L78 删除 — `grant_manual` / `grant_subscription` 分支删除（开通入口不再属于订单页）。
  - `admin_orders.php` L38-L58 — 保留 `revoke_subscription` 分支：按订单所属 `user_id` 作废会员并降回普通用户组。
  - `admin_orders.php` L62-L97 — 新增 `set_premium_expiry` 分支（按用户名定位用户）：留空或未来时间进入高级用户组、已过去的时间回普通组，管理员组不动，同时清空 `premium_reminder_sent_at` 以便重新提醒，官方账户来源用户自动补配账户。
  - `admin_orders.php` 原 L287-L295 删除 — 顶部「手动为用户开通订阅」面板整块删除。
  - `admin_orders.php` 原 L340-L341 删除 — 订单行内「开通订阅（含时长下拉）」表单删除；L288 保留「作废订阅」，显示条件收紧为 `user_id > 0 && !isAdmin($order) && isPremiumUser($order)`。
  - `admin_orders.php` L245-L252 — 新增「设置套餐到期时间」面板（用户名 + 到期日期），承接原 premium 分区的独有功能。
  - `admin_orders.php` L207 — 注释同步为「行内『作废订阅』按钮据此显示」。
  - `admin_orders.php` L232 / L317 — 缓存版本 `app.css?v=21` → `v=22`、`admin.js?v=6` → `v=7`。
  - `assets/admin.js` L28-L52 — 新增 `[data-grant-toggle]` 逻辑：点击「开通订阅」先 `preventDefault()+stopPropagation()`（否则会连带触发 summary 的折叠切换），再展开折叠并显示 `.user-row-grant`；「取消」收起面板。
  - `assets/app.css` L243-L249 — 新增 `.user-row-actions`（「开通订阅」与「编辑」并排）、`.user-row-grant`（面板样式）、`.user-row-grant[hidden]`。
  - `assets/app.css` 原 `.user-premium-actions` 两条样式删除 — 该快捷按钮组已撤销，改为订单页行内操作（其后又按 用户 要求改为用户页开通 + 订单页仅作废）。
  - `assets/app.css` L258-L259 — 新增 `.status-badge.void`（危险色，已作废）与 `.status-badge.granted`（主色，管理员授权），与线上支付成功色区分。
  - `assets/app.css` L233-L255 — 用户行折叠（`.user-row-fold`）、搜索框（`.log-filters.user-search`）、过滤隐藏（`.is-filtered-out`，用类而非 `hidden` 以免被行内 `display` 覆盖）、组员清单（`.group-member-list`）等样式。
  - `src/helpers.php` L1358 — `payTypeLabel()` 增加 `'manual' => '管理员授权'`。
  - `src/helpers.php` — `orderStatusLabel()` 增加 `'void' => '已作废'`；新增 `isManualOrder()` / `orderDisplayLabel()` / `orderDisplayClass()`（人工订单渲染为「管理员授权」）、`findUserByUsername()`、`findUserById()`（含 `g.is_admin AS group_is_admin`）、`grantManualPremiumOrder()`（事务内插订单 + 顺延套餐）、`revokePremium()`（到期时间写当前时间、非管理员组降级、已支付订单置 `void` 并记 `voided_at`）。
  - `src/Schema.php` — `CURRENT_VERSION` 9 → 10，新增 `if ($version < 10) { addColumn(orders, voided_at, ...) }`；`createOrdersTable()` 同步加列。
  - `pay.php` L140 — 缓存版本 `app.css?v=20` → `v=22`；订单记录徽章改用 `orderDisplayClass()` / `orderDisplayLabel()`，用户端能看到「管理员授权 / 已作废」。
  - `pay_return.php` — 未支付回跳提示改用 `orderStatusLabel()`，文案含实际状态。
  - `account.php`、`index.php`、`dashboard.php`、`install.php`、`logs.php`、`register.php` — 仅同步缓存版本 `app.css?v=20` → `v=22`（`account.php`、`logs.php` 的 `admin.js?v=5` → `v=7`），无逻辑改动。
- 备注（测试副作用）：端到端验证时对测试用户 `test1` 执行过「开通 → 作废」，因此该用户原有的 2 笔历史已支付订单被一并标为「已作废」（当前作废语义 = 该用户全部已支付订单）。如需只作废"当前有效的那一笔"，需另行调整 `revokePremium()` 的 `UPDATE orders` 条件。

---


### [2026-09-26 19:00:53] 管理后台二级菜单分区，并修复「管理文件」点进去看不到文件

- 目的：
  1. **修 Bug（管理员查看任意用户文件）**：此后端一直放行管理员操作他人文件（`api/file.php` 的 `isAdmin()` 分支），但 `admin.php` 里「管理文件」是无锚点链接 `admin.php?user_id=N`，点击后只回到页面顶部；而真正的文件面板（`id="user-files"`）被「权限组」「官方账户池」夹在页面最底部，管理员视觉上等于"点了没反应"，误以为功能没实现。
  2. **治理后台杂乱**：`admin.php` 单页平铺 10 块面板（约 1080 行），管理员要靠长滚动找入口；改为侧边栏可折叠二级菜单 + `?tab=` 服务端分区，一屏只渲染一个分区。
- 方案（已与 用户 确认）：单页面板 + 锚点自动定位（`#user-files` + CSS `:target` 高亮）；侧栏 `<details>` 二级菜单；四分组 = 用户管理 / 内容与账户 / 套餐与订单 / 系统设置。不引入 JS，不改表单提交与权限逻辑。
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

- 目的：`install.php` 建 `users` 表时未包含 `premium_reminder_sent_at`，却在安装流程里把 `config.schema_version` 直接写成 `Schema::CURRENT_VERSION`（9），导致 `Schema::migrate()` 中 v8 的加列迁移被判定为「已是最新」而跳过；而 `Auth::user()` 的 SELECT 又包含该列，于是全新安装后一登录就抛 `Unknown column 'u.premium_reminder_sent_at'`，管理端所有页面 500。属于"新装即坏"的阻断性 Bug。
- 验证：删库并删除 `storage/installed.lock`、`config/database.php` 后重装，`SHOW COLUMNS FROM users` 可见该列；admin 登录后 `dashboard.php`、`account.php`、`admin.php`、`logs.php`、`admin_orders.php` 均返回 200，PHP 内置服务器日志无新增报错。
- 改动明细：
  - `install.php` L88 — 在 `users` 建表语句中 `premium_expires_at` 之后新增一行 `premium_reminder_sent_at DATETIME NULL DEFAULT NULL,`。使全新安装产生的表结构与 `CURRENT_VERSION = 9` 完全一致，不再依赖 v8 迁移补列（该迁移 `Schema.php` L134-L137 只在旧库升级时生效）。
  - 影响面：仅新建库的初始结构；**已存在的旧库不受影响**（它们本就走迁移路径）。除 `premium_reminder_sent_at` 外，v4~v9 各迁移新增的列在安装脚本中均已存在，无需其它改动。

---

## 环境与本地运行（非源码改动）

### [2026-09-26 17:59:57] 初始化本地开发环境

- 目的：仓库克隆后 `.dev/` 不存在，`scripts/dev-start.ps1` 会因缺少 `.dev/mariadb-data/my.ini` 直接报「MariaDB 数据目录尚未初始化」，无法本地运行。
- 改动明细（均在 `.dev/`，已被 `.gitignore` 忽略，不进版本库）：
  - `.dev/mariadb-data/data` — 用 `C:\Program Files\MariaDB 13.0\bin\mysql_install_db.exe` 初始化的隔离数据目录。
  - `.dev/mariadb-data/my.ini` — 新建，指定 `basedir`/`datadir`、`port=3306`、`bind-address=127.0.0.1`、`skip-name-resolve`、`utf8mb4`。
  - `.dev/credentials.txt` — 新建，记录数据库与管理员账号（`admin` / `DevAdmin_NotionDrive_2026`）。
  - 数据库：`notion_drive`，账号 `notion_drive` / `DevOnly_NotionDrive_2026`。
- 注意：`.dev/mariadb.pid`、`.dev/php.pid` 需与真实在跑的进程号一致，否则 `dev-start.ps1` 会重复拉起并因端口占用失败。
- 注意：安装向导用的是**占位 Notion Token / 页面 ID**，界面与权限逻辑可用，但真实上传/下载前须在管理后台替换为有效凭据。