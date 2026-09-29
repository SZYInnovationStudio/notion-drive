# Notion Drive

Notion Drive 是一个使用 PHP 与 MySQL / MariaDB 构建、以 Notion 作为存储后端的网盘。用户通过现代化 Web 界面注册登录并上传文件，服务端使用 Notion 集成 Token 将文件上传并附加到对应的 Notion 页面；既支持每位用户绑定自己的 Notion 账号，也支持由管理员统一分配账号池。

## 功能

- Web 安装向导：自动建库、建表、创建管理员并写入安装锁
- 账号体系：可开关的公开注册、邮箱验证码校验、可选的「必须绑定邮箱才能上传」策略、邮箱找回密码、头像上传，以及管理员用户增删改查
- 权限组：内置「管理员 / 高级用户 / 普通用户」，容量与单文件上限可配置，留空或 `-1` 表示不限容量
- Notion Token 使用 AES-256-GCM 加密后存入数据库；支持用户自带账号与管理员账号池自动分配
- 前后端双重文件大小、文件类型与 900 字节文件名校验
- Notion 官方文件上传流程：创建上传对象、传送文件、附加页面
- 超过 20 MiB 时自动使用 10 MiB 多分片上传并完成会话
- 拖放、多文件队列、浏览器上传进度与 Notion 处理状态反馈
- 每位用户独立的 Notion 子页面、文件列表与访问权限边界
- 文件下载、断点续传、显示名称修改、删除归档、在线预览（图片 / PDF / 音视频，后台可开关）与管理员的文件管理
- 副本机制与空间巡检：每日巡检检测 Notion 侧数据丢失并提示，支持单副本自动补齐与归档恢复
- 内容风控：关键词拦截 + AI 识别（可开关），命中即隔离待审，并提供管理员复核与用户申诉
- 可信度与封禁：违规累计自动封禁、到期自动解封并清零，封禁期间保留工单申诉入口
- 工单系统：用户提交与回复工单、管理员处理、评价与统计、禁止提交工单等管理动作
- 收费体系：易支付（Epay）下单、异步回调与回跳对账、会员套餐与到期提醒
- 站点信息：站点名称、favicon / logo、ICP 备案号与公网安备号展示，页脚固定署名 SZY创新工作室
- 物理文件名强制加入用户、时间、哈希与随机前缀，彻底避免同名冲突
- 响应式液态玻璃界面、非线性动效及减少动效偏好支持
- PDO 预处理、CSRF、防 XSS、严格安全响应头（CSP）与安全会话 Cookie

## 环境要求

- PHP 8.1 或更高版本
- PHP 扩展：`pdo_mysql`、`curl`、`fileinfo`、`openssl`、`mbstring`
- MySQL 8.0+ 或 MariaDB 10.5+
- Apache 2.4（启用 `mod_rewrite`）或 Nginx
- HTTPS（生产环境必须）

## 部署

1. 将整个项目上传到站点目录，Web 根目录指向项目根目录。
2. 确保 Web 服务用户可写 `config/` 与 `storage/`。
3. 根据套餐调整 PHP 配置。例如付费版需要同时提高：

   ```ini
   upload_max_filesize = 5G
   post_max_size = 5G
   max_execution_time = 1800
   max_input_time = 1800
   memory_limit = 256M
   ```

   上传文件由 PHP 临时文件流式读取，应用不会把整个文件载入内存。反向代理也必须同步调整请求体大小与超时（Nginx 的 `client_max_body_size`、`proxy_read_timeout` 等）。

4. 在 Notion 中创建内部集成，赋予读取、插入及更新内容权限，并将目标页面共享给该集成。
5. 浏览器访问 `install.php`，填写数据库、Notion Token、默认页面 ID、套餐和管理员账号。
6. 安装成功会生成 `config/database.php` 与 `storage/installed.lock`。两者都已被 Git 忽略；请妥善备份，尤其是配置文件中的应用密钥。
7. 安装完成后，将 `config/` 与 `storage/` 设置为仅 Web 服务用户可读写。Apache 会通过 `.htaccess` 禁止外部访问；Nginx 请添加等效规则（注意别漏掉 `scripts/`，它同样不该被外部访问）：

   ```nginx
   location ~ ^/(config|src|storage|scripts)/ { deny all; }
   location ~ /\. { deny all; }
   ```

## 本地开发

当前 Windows 开发环境使用 PHP 8.4、项目内隔离的 MariaDB 13.0 数据目录，以及 PHP 内置服务器。运行：

```powershell
.\scripts\dev-start.ps1
```

应用地址为 `http://127.0.0.1:8088/`。本机开发账号和数据库凭据保存在被 Git 忽略的 `.dev/credentials.txt`。停止环境：

```powershell
.\scripts\dev-stop.ps1
```

开发安装使用占位 Notion Token 和页面 ID，因此本地登录、用户管理和界面可直接使用；首次测试真实上传前，请在管理后台替换为真实 Token 和目标页面 ID。

## Notion 上传说明

Notion 当前官方接口使用 `POST multipart/form-data` 发送二进制文件，而不是 PUT。单个请求最多 20 MiB，更大的文件会自动切换到多分片模式；应用仍将其呈现为“创建会话 → 上传内容 → 附加页面”的稳定三阶段流程。

免费版在应用层限制为 5 MB，付费版限制为 5 GB。最终限制仍以 Notion 工作区和 API 返回结果为准。上传对象必须在一小时内附加，本应用会在上传完成后立即执行附加操作。

## 目录结构

```text
assets/           样式、交互脚本与图标
api/              已鉴权的接口（上传、文件夹、预览取流、邮箱验证码）
bin/              命令行维护脚本（Notion 空间巡检）
config/           安装后生成的数据库与应用密钥配置
scripts/          Windows 本地开发启停脚本
src/              数据库、认证、安全、风控、封禁、工单、支付与 Notion API 客户端
storage/          安装锁、运行日志与临时分片
account.php       账户管理（资料、邮箱、头像、可信度与会员状态）
admin.php         用户、权限组、系统设置与内容风控管理
admin_orders.php  会员订单管理
bans.php          封禁名单与解封
dashboard.php     文件工作台（列表、上传、预览）
forgot.php        找回密码
index.php         登录
install.php       首次安装向导
logs.php          运行日志查看
pay.php           会员套餐下单与订单列表
register.php      注册
site-asset.php    站点 favicon / logo 代理
tickets.php       工单（提交、回复、评价）
```

## 贡献

欢迎提交 Issue 与 Pull Request。提交代码前请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)，其中规定了代码风格、注释口径与提交前检查。

## 安全运维

- 不要将 `config/database.php` 或安装锁提交到版本库。
- 定期轮换 Notion Token；可在管理后台更新，旧值不会显示到浏览器。
- 对登录端点增加 Web 服务器级限速，并定期备份 MySQL / MariaDB。
- 生产环境关闭 PHP 的 `display_errors`，将错误写入受保护日志。
- 若需迁移，必须同时迁移数据库和 `config/database.php`，否则已加密 Token 无法解密。
