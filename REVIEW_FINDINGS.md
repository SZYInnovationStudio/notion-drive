# 代码审查问题清单

> 记录时间：2026-09-27 16:46:04（UTC+8）
> 复审时间：2026-09-27 20:57:06（UTC+8）· 阶段 11 收尾复审
> 审查范围：全量（认证/会话/安全/安装、上传/下载/文件夹/Notion/Schema、支付/订单/admin 权限/前端、风控/封禁/工单），**含本次新功能「清除违规记录」**。
> 排序：按严重度从高到低。
> 状态：**仅记录，尚未修复**。
>
> **阶段 11 复审说明**：对下方全部条目逐条人工复核（不使用子代理），并把复审新增/更正结论追加到文末「阶段 11 复审新增」小节。
> **重要更正**：H1 已在当前代码中修复（`install.php` 已改用 `Schema::create*Table()` 并写入 `Schema::CURRENT_VERSION`），见文末说明。

---

## 高

### H1. 全新安装后文件系统整体不可用（**阶段 11 复审：已修复**）

- 位置：[install.php:108-127](install.php#L108-L127)（内联建 `uploads` 表）、[install.php:153](install.php#L153)（直接写 `schema_version=18`）、[src/Schema.php:29-31](src/Schema.php#L29-L31)（`migrate()` 提前 return）、[src/Schema.php:195-202](src/Schema.php#L195-L202)（v17 补列块）
- 触发条件：全新安装
- 现象/影响：`install.php` 内联建表缺 `scope`、`ticket_id` 两列及索引；同时把 `schema_version` 直接写成 18，导致 `migrate()` 因 `$version >= CURRENT_VERSION` 提前返回，v17 的补列永不执行。而 [api/upload.php:587](api/upload.php#L587) 的 INSERT 显式写 `scope/ticket_id`，[api/folder.php:58](api/folder.php#L58) 按 `scope='drive'` 过滤 → 全新安装站点一上传即报错、文件列表为空。
- 备注：两路子代理结论冲突，已人工读文件核实，此结论正确。
- 修复建议：`install.php` 改用 `Schema::createUploadsTable()`（统一建表口径），或在 `install.php` 内联表补齐 `scope`/`ticket_id` 列与索引。

### H2. `clearRecord()` 经 `forgetHash()` 全站删指纹，跨用户削弱风控

- 位置：[src/Moderation/ModerationService.php:378-397](src/Moderation/ModerationService.php#L378-L397)（`clearRecord()` 调 `forgetHash()`）、[src/Moderation/ModerationService.php:315](src/Moderation/ModerationService.php#L315)（`forgetHash()`）、[src/Schema.php:223](src/Schema.php#L223)（`content_hash` 全站唯一）
- 触发条件：管理员对某条违规记录点「清除记录」
- 现象/影响：内容指纹表按 `content_hash` 全站唯一，清除某用户一条记录 = **全站解封该内容**，其他用户再上传同内容不再被拦截。一次针对单个用户的操作产生跨用户全局副作用。
- 修复建议：清除违规时不要调用 `forgetHash()`（或改为仅解绑该用户，保留全站指纹条目）。

---

## 中

### M1. `clearRecord()` 缺状态前置校验

- 位置：[src/Moderation/ModerationService.php:378-397](src/Moderation/ModerationService.php#L378-L397)、[src/Moderation/ModerationService.php:642](src/Moderation/ModerationService.php#L636-L642)、[admin.php:665](admin.php#L665-L681)
- 触发条件：对 `open` / `pending` / `resubmit` / 已 `cleared` 的记录执行清除
- 现象/影响：可绕过复核；对 `pending`（AI 审核中）执行时文件仍保持可用。
- 修复建议：`clearRecord()` 增加状态前置校验，仅允许 `rejected`。

### M2. 清除违规后不重算/不解除已自动触发的封禁

- 位置：[src/Moderation/ModerationService.php:394](src/Moderation/ModerationService.php#L378-L397)、[src/Bans/BanService.php:70](src/Bans/BanService.php#L67-L75)、[src/Bans/BanService.php:117-141](src/Bans/BanService.php#L117-L141)
- 触发条件：用户已达阈值被自动封禁后，管理员清除其违规记录
- 现象/影响：提示语称「不再计入违规次数」，但已触发的封禁不会自动解除，提示与实态不一致。
- 备注：**推断项** —— 需按产品预期确认「清除后是否应自动解封」。

### M3. 删除文件夹遗留 quarantined 文件

- 位置：[api/folder.php:82-107](api/folder.php#L82-L107)
- 触发条件：删除含隔离文件的文件夹
- 现象/影响：递归删文件时不处理 `quarantined`，遗留指向已删文件夹的 `folder_id`，文件永久雪藏但仍占配额。
- 修复建议：一并处理 `quarantined` 文件（删除或迁移）。

### M4. 上传配额校验 TOCTOU

- 位置：[api/upload.php:177-204](api/upload.php#L177-L204)
- 触发条件：并发上传
- 现象/影响：查用量与写入无行锁/事务，并发可突破配额。
- 修复建议：加行锁或事务保证原子性。

### M5. install.php 已安装判定可绕过 / 写配置成功但写锁失败卡死

- 位置：[install.php:57](install.php#L57)、[install.php:69](install.php#L69)、[install.php:76](install.php#L76)、[install.php:209-212](install.php#L209-L212)、[src/bootstrap.php:13](src/bootstrap.php#L13)
- 触发条件：删除锁文件后重跑安装 / 配置已写但锁写失败
- 现象/影响：前者可被绕过接管管理员；后者站点永久卡死。
- 修复建议：已安装判定改依赖数据库或不可绕过的标记；写锁失败需回滚/自愈。

### M6. 找回密码子系统整体缺失

- 位置：全项目（无 `forgot.php`、无 reset 用途令牌）
- 现象/影响：用户无法自主找回密码。
- 修复建议：补充找回密码流程与 reset 令牌。

---

## 低

- **L1 `dismissNotice()` 兜底静默成功** — [src/Moderation/ModerationService.php:478](src/Moderation/ModerationService.php#L458-L490)、[src/Moderation/ModerationService.php:483](src/Moderation/ModerationService.php#L483)、[dashboard.php:68](dashboard.php#L61-L73)：`noticeExists()` 不校验 `decision/status`，对已处理记录也报「已清除」。
- **L2 `STATUS_EXEMPTED` 死状态** — [src/Moderation/ModerationService.php:22](src/Moderation/ModerationService.php#L25)、[admin.php:1110](admin.php#L1106-L1114)：无写入方，后台徽章分支永不命中。
- **L3 `reconcileOrder()` 对已作废订单仍返回「已完成支付」** — [src/helpers.php:2231-2236](src/helpers.php#L2231-L2236)。
- **L4 `data-confirm` 永久置 `form.noValidate=true`** — [assets/app.js:373-397](assets/app.js#L373-L397)：关闭该表单后原生校验失效；仅绑 click 委托，键盘隐式提交可绕过二次确认。
- **L5 install.php `default_page_id` 未归一化** — [install.php:151](install.php#L151)（对比 [install.php:196](install.php#L196)）。
- **L6 登录无账号级锁定** — 仅 IP 阈值 20（[src/helpers.php:541](src/helpers.php#L541)、[src/helpers.php:583](src/helpers.php#L583)）。
- **L7 已付款但状态「已关闭」的订单无自助对账入口** — [pay.php:240-247](pay.php#L240-L247)、[pay_return.php:50-58](pay_return.php#L50-L58)。
- **L8 重名判定无唯一约束** — 并发同名可产生两条 active（[src/helpers.php:468-501](src/helpers.php#L468-L501)）。
- **L9 finish 先写 Notion 后写库** — 入库失败在 Notion 留孤儿块（[api/upload.php:581-624](api/upload.php#L581-L624)）。
- **L10 index.php catch 回显 Throwable 文本** — 可探测绝对路径（[index.php:23](index.php#L23)、[index.php:31](index.php#L31)、[index.php:43](index.php#L43)）。
- **L11 install.php `db_host` 未过滤** — 可注入 DSN 参数（[install.php:57-76](install.php#L57-L76)）。

---

## 已确认无问题

CSRF 全覆盖、会话固定、令牌一次性/过期、越权面、SQL 注入、XSS、密码存储、支付金额服务端可信、回调验签与幂等、后台鉴权、分块令牌防穿越、quarantined 不可下载、Notion 重试幂等、v18 迁移幂等。

---

## 本次新功能设计取舍（「清除违规记录」）

- **管理员清除 = 软清除**：状态置 `cleared`，保留行以维持审计与工单关联，非物理删除。
- **用户清除 = 独立标记列**（`user_dismissed`，不碰 `status`）：以免管理员失去复核能力；`closeRecord()` 会把 `user_dismissed` 归零，管理员一旦复核（打回 / 放行重传 / 清除），用户隐藏标记自动撤销。

---

## 阶段 11 复审新增（2026-09-27 20:57:06 UTC+8）

> 本节为阶段 11 收尾复审的增量结论，全部由人工逐条读源码核实（不使用子代理）。按严重度降序。

### H1 已修复（原「高」条目作废）

- 复核位置：[install.php:104-134](install.php#L104-L134)（内联建 `uploads` 表已含 `scope`/`ticket_id` 及 `idx_uploads_scope`/`idx_uploads_ticket`）、[install.php:136-154](install.php#L136-L154)（改用 19 个 `Schema::create*Table()` 统一建表口径）、[install.php:165](install.php#L165)（写入 `Schema::CURRENT_VERSION`，不再硬编码 18）、[src/Schema.php:11](src/Schema.php#L11)（`CURRENT_VERSION = 20`）。
- 结论：全新安装的建表口径已与迁移口径一致，原 H1（全新安装后上传即报错、文件列表为空）**已不复现**。保留条目仅为留痕，无需再修。

### H2 仍存在（确认为高）

- `clearRecord()` → `forgetHash()` 会删除**全站唯一**的 `content_hash` 指纹条目（[src/Moderation/ModerationService.php:397-407](src/Moderation/ModerationService.php#L397-L407)、[:308-317](src/Moderation/ModerationService.php#L308-L317)、[src/Schema.php:270-283](src/Schema.php#L270-L283)），一次针对单个用户的清除操作会产生跨用户全局副作用。建议按原 H2 修复建议处理。

### M7（新发现·中）管理员批量改工单状态可「复活」已过期/已关闭工单

- 位置：[src/Tickets/TicketService.php:520-548](src/Tickets/TicketService.php#L520-L548)（`setStatuses()`）
- 触发条件：管理员在后台对一批工单执行非结束态改状态（如改为 `open`/`processing`），其中含 `expired` 或 `closed` 的工单。
- 现象/影响：非结束态分支的 UPDATE 仅排除「原状态 == 目标状态」，**未排除 `expired`/`closed`**，因此会把已过期工单直接改回 `open`/`processing`，绕过 `reopenState()` 的「过期不可重开」校验与 `MAX_REOPEN` 上限；同时该分支不计 `closed_at`/`reopen_count`，状态与时间字段就此失真。
- 说明：**此路径是 H1（工单过期可复活）唯一可达入口**——用户端申诉路径 `appendAppealMessage()`（[:241-292](src/Tickets/TicketService.php#L241-L292)）对 `expired` 有正确分支（另起新工单），不经 `reopenTicket()`，故此前的「申诉可绕过过期」推断**不成立**，予以更正。
- 修复建议：非结束态分支增加守卫，`WHERE` 里排除 `status IN ('closed','expired')`（或仅允许从 `open`↔`processing` 互转）。

### M8（新发现·中）`closeTicket()` 无原状态守卫

- 位置：[src/Tickets/TicketService.php:641-662](src/Tickets/TicketService.php#L641-L662)
- 触发条件：对已结束工单重复执行关闭；或 `expired`/`closed` 互改。
- 现象/影响：UPDATE 未带原状态条件，重复关闭会刷新 `closed_at`，从而无限推迟 30 天重开窗口与自动过期判定；`expired` 与 `closed` 可被互相改写，`expired_at`/`closed_at` 语义被污染。
- 修复建议：`WHERE id = :id AND status NOT IN ('closed','expired')`，并按 `rowCount()` 判定是否真的结束（`setStatuses()` 结束态分支的 `$changed++` 也应改为按 `rowCount()` 统计）。

### M9（新发现·中）`replyAsAdmin()` 缺结束态拦截

- 位置：[src/Tickets/TicketService.php:313-321](src/Tickets/TicketService.php#L313-L321)（`replyAsAdmin()` 无 `isEnded` 校验）对比 [:297-308](src/Tickets/TicketService.php#L297-L308)（`replyAsUser()` 有）
- 现象/影响：管理员可对已关闭/已过期工单继续留言，与 [api/upload.php:294-296](api/upload.php#L294-L296) 对结束态工单返回 409 `ticket_closed` 的行为自相矛盾。
- 修复建议：`replyAsAdmin()` 同步加 `isEnded()` 拦截（如确需允许管理员在结束后补充说明，应显式开关并写清语义）。

### M10（新发现·中）`evaluateAutoBan()` 未先做懒结算

- 位置：[src/Bans/BanService.php:398-422](src/Bans/BanService.php#L398-L422)（`evaluateAutoBan()`）、[:431-464](src/Bans/BanService.php#L431-L464)（`currentScore()`）、[:160+](src/Bans/BanService.php#L160)（`accrueCycle()`）
- 现象/影响：`trustSummary()` 会先 `expireDue()`+`accrueCycle()` 再算分，而 `evaluateAutoBan()` 直接算分，可能用**陈旧周期起点**判定，导致本应随 30 天周期滚动而清零的分数仍参与阈值判断（提前误封），或反之。（`admin.php` 全程未调用 `BanService::expireDue()`，懒结算全靠读页面触发。）
- 修复建议：`evaluateAutoBan()` 开头补 `$this->expireDue($userId); $this->accrueCycle($userId);`，或在后台入口统一触发一次到期结算。

### M11（新发现·中）`unban()` 不重置违规周期

- 位置：[src/Bans/BanService.php:108-117](src/Bans/BanService.php#L108-L117)
- 现象/影响：手动解封只改 `user_bans`，不动 `users.violation_cycle_start`/`violation_ban_until`，分数仍 ≥ 阈值，用户下一次违规会立即再次触发自动封禁。
- 修复建议：解封时一并重置周期起点（或明确「解封不等于清零」的产品语义并在文案中说明）。

### M12（新发现·中）账户页历史违规条数少报

- 位置：[account.php:252-258](account.php#L252-L258)
- 现象/影响：条数 = `count($trust['recent']) + count($manualHistory)`，而 `recent` 是 `LIMIT 10`、`history` 是 `LIMIT 12` 后再筛 `source='manual'`，两处截断叠加导致总数少报（超过上限时永远显示封顶值）。
- 修复建议：由服务端返回真实总数（单独 `COUNT(*)`），或文案改为「最近 N 条」。

### M13（新发现·中）站点资源代理缺图片类型白名单

- 位置：[site-asset.php:38-42](site-asset.php#L38-L42)（`k=media` 直接以 `finfo` 结果作 `Content-Type` 输出）对比 [avatar.php:38](avatar.php#L38)（有 `image/*` 校验）
- 现象/影响：`k=media` 输出未限定 `image/*`，若 `storage/announcements` 中出现非图片文件（或未来复用该代理），会以原 MIME 直出，存在内容嗅探/被当作可执行内容的风险。当前目录仅由 `storeAnnouncementImage()` 写入且已按 `getimagesize` 白名单校验，实际可利用性低。
- 修复建议：输出前校验 `str_starts_with($mime, 'image/')`，否则回退 `application/octet-stream` 并加 `X-Content-Type-Options: nosniff`。

### L12（新发现·低）`appendAppealMessage()` 调 `reopenTicket()` 未传 `$userId`

- 位置：[src/Tickets/TicketService.php:259](src/Tickets/TicketService.php#L259)（对比 [tickets.php:76](tickets.php#L76) 传了 `$userId`）
- 现象/影响：内部调用跳过归属校验，属纵深防御缺失（当前调用链上游已校验归属，不可直接利用）。
- 修复建议：补传 `$userId`。

### L13（新发现·低）已修复图片的旧文件不随正文更新清理

- 位置：[admin.php:430-467](admin.php#L430-L467)（`save_announcement` 更新时不比对旧正文）、[:473-485](admin.php#L473-L485)（仅 `delete_announcement` 清理）
- 现象/影响：编辑公告时删掉正文中的图片引用，旧图片文件仍留在 `storage/announcements`，直到整条公告被删除才清理，形成孤儿文件。
- 修复建议：更新时比对旧正文与新正文的图片引用差集并清理。

### 复审确认「无问题」的补充项

- [api/file.php](api/file.php) 工单附件下载授权（上传者/工单归属者/管理员）、封禁拦截、副本回退与截断校验均正确。
- [api/upload.php](api/upload.php) 分块协议（token 白名单正则、归属校验、顺序校验、字节数校验、shutdown 清理、工单 scope 的 start/finish 双重复核）均正确。
- [src/Auth.php](src/Auth.php) 会话与权限判定、[src/NotionSpaceRepair.php](src/NotionSpaceRepair.php) 自愈边界（仅恢复归档，404 重建）均正确。
- [src/helpers.php](src/helpers.php) 站点标识系列函数（`siteName`/`siteFaviconPath`/`siteLogoPath`/`siteAssetUrl`/`brandMarkHtml` 等）全部先判 `installed.lock`，安装向导阶段不会触碰 `config` 表，正确。
- [src/Moderation/ModerationService.php:502-517](src/Moderation/ModerationService.php#L502-L517) `dismissAllNotices()` 只置 `user_dismissed=1`，与单条清除同口径，不动 `status`/违规计数，正确。
- 公告「已读」以 cookie `nd_ann_<id>` 记录（[assets/app.js:145-202](assets/app.js#L145-L202)）属设计选择，非缺陷。

---

## 修复优先级建议（阶段 11 复审后更新）

1. **H2** —— 清除违规经 `forgetHash()` 全站删指纹，跨用户削弱风控（最高优先）。
2. **M1 / M7 / M8** —— 风控与工单状态机的前置校验缺失（`clearRecord` 缺状态校验；`setStatuses` 可复活已结束工单；`closeTicket` 无原状态守卫，可无限刷新 `closed_at`）。
3. **M9 / M10 / M11 / M12** —— 一致性缺陷（管理员回复缺结束拦截、自动封禁缺懒结算、解封不清周期、账户页条数少报）。
4. **M13** —— 站点资源代理补 `image/*` 白名单 + `nosniff`（防御性）。
5. **M2** —— 「清除后是否自动解封」属产品预期，待拍板。
6. **L12 / L13** 及其他低危项 —— 可择机处理。

> H1 已修复，无需再动。以上**仅记录，尚未修复**；按项目约定不提交、不推送。
