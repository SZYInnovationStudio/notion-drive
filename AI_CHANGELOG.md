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

### [2026-09-26 21:18:54] 结算白名单修正为「待支付 + 已关闭」并统一加锁顺序，补记 README 的 Nginx 规则

- 修改人：[Beibing]
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

- 修改人：[Beibing]
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

- 修改人：[Beibing]
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

- 修改人：[Beibing]
- 目的：原标签「内容与账户」与该分区实际职责（用户文件 + 官方账户池分配）不符，容易误解，改为「账户池管理」更贴合用途。
- 验证：`php -l admin.php` → `No syntax errors detected`；本地 `http://127.0.0.1:8088` 以 admin 登录访问 `admin.php?tab=content` → HTTP 200，页面标题与侧栏/页签均显示「账户池管理」，页面内已无「内容与账户」字样；**tab 键保持 `content` 不变**，`?tab=content` 链接与 `user_id` 跳转（订单页「用户」、用户行「管理文件」）不受影响。
- 改动明细：
  - `admin.php` L27 — `adminTabs()` 中 `content` 项 `label` 由 `内容与账户` 改为 `账户池管理`（`group` / `desc` / 键名均不变）。
  - `admin.php` L40 — 默认分区注释里「默认展示内容与账户」同步改为「默认展示账户池管理」，避免注释与界面文案不一致。

---

### [2026-09-26 19:56:30] 全站时区改为 UTC+8；作废订单只扣减该笔订单月数（叠加 1+1 撤 1）；用户列表新增「到期时长」

- 修改人：[Beibing]
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

- 修改人：[Beibing]
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

- 修改人：[Beibing]
- 目的：
  1. **开通订阅入口归位**：此前把「开通订阅」放进了订单列表（顶部表单 + 行内按钮），与「订单只读、会员操作归用户」的心智冲突——管理员要开通订阅应当面向「用户」而不是「某笔订单」。现改为：用户管理页每行「编辑」旁新增「开通订阅 / 续费订阅」，点击后在行内展开时长选择，再点「确认开通」提交。
  2. **删除名不副实的「套餐与订单」二级菜单**：该菜单（`?tab=premium`）不含任何订单列表，实际只有「选中用户后的会员与到期」面板，必须先到用户管理选用户才显示，对管理员长期是"不知道干啥用"的死入口。按 [Beibing] 决策（选项 1）整块删除，其中唯一独有的「保存到期时间（含永久有效）」搬到订单管理页成为独立面板。
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
  - `assets/app.css` 原 `.user-premium-actions` 两条样式删除 — 该快捷按钮组已撤销，改为订单页行内操作（其后又按 [Beibing] 要求改为用户页开通 + 订单页仅作废）。
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