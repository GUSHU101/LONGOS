# LONGOS 生产配置教程

本教程从一个空的 Coolify 项目开始，完成 LONGOS 主站、租户子站、租户自定义域名、内置 Postfix/OpenDKIM 邮件引擎以及 Cloud Mail 对接。生产环境只支持 **GitHub + Coolify Raw Docker Compose + Coolify 集成 Caddy**；不需要宝塔、宿主机 PHP/Nginx、`install.sh` 或单独的 Caddy 容器。

## 1. 上线前准备

准备以下信息，并先记录在自己的密码管理器中：

| 项目 | 示例 | 用途 |
|---|---|---|
| 服务器公网 IPv4 | `203.0.113.10` | 网站入口和邮件出口 |
| LONGOS 控制站域名 | `admin.example.com` | 主站后台、系统令牌和 Cloud Mail 网关 |
| 租户平台根域 | `shops.example.com` | 自动生成 `tenant.shops.example.com` |
| 邮件主机名 | `mailout.example.com` | SMTP EHLO、A 记录和 PTR/rDNS |
| 平台发件域 | `example.com` | SPF、DKIM、DMARC 和租户共享发件身份 |
| 管理员邮箱 | `owner@example.com` | 首次超级管理员和 ACME 联系邮箱 |

服务器应安装可用的 Coolify，安全组放行 TCP 80、TCP 443 和 UDP 443。完全自建外发邮件还要求固定公网 IP、服务商允许出站 TCP 25，并允许在服务器控制台设置 PTR/rDNS。Cloudflare 不能代替这些邮件服务器条件。

## 2. 先配置 DNS

以表中的示例为例：

| 类型 | 主机 | 记录值 | Cloudflare 代理 |
|---|---|---|---|
| A | `admin` | `203.0.113.10` | 可开启橙云 |
| A | `*.shops` | `203.0.113.10` | 可开启橙云 |
| A | `mailout` | `203.0.113.10` | 必须 DNS only |

再到服务器供应商控制台设置反向解析：

```text
203.0.113.10 -> mailout.example.com
```

`mailout.example.com` 的正向 A 记录必须解析回同一个公网 IP。租户自定义域名不需要预先写入 Caddy；租户在后台绑定后，按页面提示添加 `_longos-challenge` TXT 以及 A/AAAA/CNAME，LONGOS 验证通过后才允许 Caddy 按需签发证书。

使用 Cloudflare 时将 SSL/TLS 模式设为 **Full (strict)**，不要使用 Flexible。

## 3. 配置 Coolify 的 Caddy

1. 进入 **Servers → 当前服务器 → Proxy**。
2. 将代理切换为 **Caddy** 并确认 `coolify-proxy` 为 Running。
3. 关闭 **Override default request handler**。
4. 若服务器上已有其他 Coolify 应用，重新部署一次这些应用，让它们生成精确域名路由。

Coolify Caddy 是唯一监听公网 80/443 的进程。LONGOS 的 `app` 容器只在 Docker 网络内暴露 HTTP 80；数据库、队列、调度器和邮件中继都不会映射公网端口。

## 4. 从 GitHub 创建 LONGOS 应用

1. 在 Coolify 创建 Project 和 Production Environment。
2. 选择 **New Resource → Public Repository**，仓库填 `https://github.com/GUSHU101/LONGOS`；私有化后则使用已授权的 GitHub App。
3. 部署分支选择 `main`。
4. Build Pack 选择 **Docker Compose**，Compose 路径填写 `/docker-compose.yml`。
5. 启用 **Raw Compose / deploy file as-is**。
6. 保持项目声明的 external `coolify` 网络，不要在 Domains 页面重复创建另一套路由。

## 5. 填写环境变量

第一次部署只必须手动填写三项：

```dotenv
DOMAIN=admin.example.com
SAAS_BASE_DOMAIN=shops.example.com
ADMIN_EMAIL=owner@example.com
```

推荐同时明确以下业务值：

```dotenv
APP_TIMEZONE=Asia/Shanghai
ADMIN_NAME=admin
MAIL_RELAY_HOSTNAME=mailout.example.com
MAIL_RELAY_DOMAINS=example.com
MAIL_RELAY_ALLOWED_SENDER_DOMAINS=example.com
MAIL_RELAY_PLATFORM_SENDER_DOMAIN=example.com
MAIL_RELAY_IPS=
MAIL_RELAY_AUTO_DETECT_IPS=true
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=LONGOS
```

变量含义：

| 变量 | 是否必填 | 说明 |
|---|---:|---|
| `DOMAIN` | 是 | 主站控制域名，不含 `https://`、端口或路径 |
| `SAAS_BASE_DOMAIN` | 是 | 平台租户子域根域；不能填完整租户域名 |
| `ADMIN_EMAIL` | 是 | 首次管理员邮箱，也作为默认 ACME 联系邮箱 |
| `ADMIN_NAME` | 否 | 首次管理员名称，默认 `admin` |
| `APP_TIMEZONE` | 否 | 后台、订单和计划任务的默认 IANA 时区 |
| `SAAS_DNS_TARGETS` | 通常否 | 特殊分离入口才填写源站 IP/主机；普通部署留空自动推导 |
| `SAAS_DATABASE_MODE` | 否 | 默认 `prefix`；生产上线后不要随意切换 |
| `MAIL_RELAY_HOSTNAME` | 推荐 | 邮件 EHLO 与 PTR 对应的 DNS-only 主机名 |
| `MAIL_RELAY_DOMAINS` | 推荐 | 需要生成 DKIM 的域名，多个用逗号分隔 |
| `MAIL_RELAY_ALLOWED_SENDER_DOMAINS` | 推荐 | 实际允许发信的域名，必须是签名域集合的子集 |
| `MAIL_RELAY_PLATFORM_SENDER_DOMAIN` | 推荐 | 租户共享的稳定平台发件域 |
| `MAIL_RELAY_IPS` | 特殊网络才需要 | 默认留空并由服务器多源一致性自动识别公网 IPv4；仅在独立 SMTP 出口、特殊 NAT/策略路由、多公网 IP 或已验收的 IPv6 发信时填写逗号分隔的明确 IPv4/IPv6 |
| `MAIL_RELAY_AUTO_DETECT_IPS` | 否 | 默认 `true`；空 `MAIL_RELAY_IPS` 时要求 3 个独立 HTTPS 回显源中至少 2 个结果一致，否则拒绝修改 A/AAAA |
| `APP_KEY_OVERRIDE` | 仅迁移 | 旧站迁移时填原站完整 `APP_KEY`；全新部署必须留空 |

Coolify 会自动生成并持久保存所有 `SERVICE_*` 值，包括 APP_KEY、数据库密码、管理员初始密码、边缘鉴权密钥和邮件内部 HMAC 密钥。不要手工改写这些值，也不要把它们提交到 GitHub。首次登录密码在 Coolify 的 `SERVICE_PASSWORD_64_ADMIN` 变量中；登录后可在后台修改，后续重新部署不会重置密码。

`ADMIN_EMAIL` 仍应显式填写为可接收邮件的地址。为避免 Coolify 在“构建变量”与“运行变量”同步瞬间用空值解析 Compose，部署文件会在构建解析阶段安全回退为 `admin@DOMAIN`；容器首次安装仍会校验最终邮箱，显式值始终优先。

邮件域名列表只允许逗号分隔的完整 DNS 域名；可以在逗号两侧留空格，但域名内部不能有空格。邮件中继对所有数值限制采用严格解析；如果自定义 `MAIL_RELAY_MAX_*`、`MAIL_RELAY_SIGNATURE_WINDOW_SECONDS` 或 `MAIL_RELAY_IDEMPOTENCY_RETENTION_SECONDS` 时输入了非正整数，容器会直接拒绝启动并在日志中指出变量，不会静默改用另一个限制。`MAIL_RELAY_MAX_REQUEST_BYTES` 默认为 32 MiB，必须能容纳附件的 Base64 膨胀和邮件元数据；旧版持久化的 25 MiB/20 MiB 默认组合会在启动时自动迁移为 32 MiB/20 MiB。其他自定义的无效组合仍会拒绝启动。幂等记录保留时间至少应为签名时间窗的两倍。

### 可选：Cloudflare DNS 自动化

如果不使用 Cloud Mail GitHub 工作流、仍希望直接从 LONGOS 后台一键发布邮件 DNS，可在 Coolify 增加并标记为 Secret：

```dotenv
LONGOS_CLOUDFLARE_MODE=optional
LONGOS_AUTO_CONFIGURE_MAIL_DNS=true
CLOUDFLARE_API_TOKEN=<只授权所需 Zone 的 DNS:Edit、Zone:Read Token；Email Routing 锁定记录时再加 Zone Settings:Edit>
```

常规一体化部署无需在 Coolify 重复保存该 Token：Cloud Mail 工作流会在收信路由完成后，把 GitHub 中已有的受限 Token 临时交给 LONGOS 执行同一次同步，LONGOS 不持久化也不返回它。Token 必须覆盖 `MAIL_RELAY_DOMAINS` 中涉及的每个 Zone。LONGOS 保留已有 SPF 的所有发信机制并合并为一条，保留已有 DMARC 策略；危险的 `+all`、`redirect=`、冲突修饰符或明显超过 10 次 DNS 查询时会停止并提示。`MAIL_RELAY_IPS` 留空时，邮件主机 A/AAAA 使用服务器端至少两个独立 HTTPS 源一致识别的公网出口 IP；不会从网站 DNS 或 Cloudflare Anycast 地址猜测。自动探测不一致时失败关闭，必须手工填写。

Coolify 保存了 Token 时，系统会在 Web 容器启动后尝试首次同步，并由 scheduler 每 5 分钟重试；管理员也可在邮件设置点击“一键配置邮件 DNS”立即执行。常规无持久 Token 模式则在每次 Cloud Mail 工作流部署时同步。DKIM 私钥始终只位于邮件中继卷；Laravel 只能读取可公开的 TXT 值。

## 6. 首次部署会自动完成什么

点击 **Deploy** 后，镜像会按 `composer.lock` 和 `package-lock.json` 构建，随后自动执行：

1. 启动 MySQL 8.4 与 Postfix/OpenDKIM；
2. 等待数据库健康并取得串行部署锁；
3. 全新站执行 `longos:install`，已安装站只执行 `saas:upgrade --if-needed`；
4. 创建管理员、控制站、数据库结构和生产缓存；
5. Web 健康后启动 queue worker 与 scheduler；
6. Coolify Caddy 在首次 HTTPS 访问时签发主站和已授权租户域名证书。

不需要进入容器执行 Composer、npm、迁移、权限或 Caddy 命令。不要删除 `storage/installed`、命名卷或自动生成的 `SERVICE_*` 变量来强制重装。

## 7. 首次登录与基础设置

1. 打开 `https://admin.example.com/admin`。
2. 使用 `ADMIN_EMAIL` 和 Coolify 中的 `SERVICE_PASSWORD_64_ADMIN` 登录。
3. 立即修改管理员密码，检查系统时区、默认语言和默认货币。
4. 在“系统设置 → 邮件设置”选择内置邮件服务器，检查中继、Postfix、OpenDKIM 和发件域诊断。
5. 点击“立即检测”；Cloudflare 自动化未开启时，复制页面展示的 DKIM/SPF/DMARC 记录到 DNS，再确认测试邮件原文中三项均为 pass。
6. 创建一个测试租户，确认平台子域 HTTPS、后台隔离、下单邮件和队列任务都正常。

## 8. 连接 Cloud Mail

LONGOS 业务邮件与 Cloud Mail 网页邮件共用同一个私有 Postfix/OpenDKIM，但使用不同鉴权入口：

```text
LONGOS 下单/召回 -> 私有 HMAC -> mail-relay
Cloud Mail Worker -> HTTPS Bearer Token -> LONGOS 主站网关 -> 私有 HMAC -> mail-relay
```

1. 只在 LONGOS **主站**后台进入“系统设置 → 系统集成令牌”。
2. 创建一枚 Cloud Mail 令牌，权限为 `cloud-mail:send` 和 `cloud-mail:health`。
3. 在 `GUSHU101/longos-cloud-mail` 的 GitHub Actions 设置中添加：

```text
Variable LONGOS_RELAY_URL=https://admin.example.com/_longos/mail-relay
Secret   LONGOS_INTEGRATION_TOKEN=longos_it_...
```

4. 按 Cloud Mail 仓库的 `doc/QUICK_START.md` 部署 Worker。
5. 在 Cloud Mail“系统设置”执行连接测试，再测试 LONGOS 下单通知和弃单召回。

不要把 `CLOUD_MAIL_RELAY_SHARED_SECRET` 或 `MAIL_RELAY_SHARED_SECRET` 复制到 GitHub/Cloudflare；它们只用于 LONGOS 私有网络。一个主站 Cloud Mail 令牌服务全部活跃租户，子站无需重复创建；店铺域名变更也不需要更换令牌。

## 9. 租户域名与发件地址

店铺访问域和邮件发件域是两种独立资源：

- 租户更换访问域名：在租户域名管理中完成新的 TXT 与流量 DNS 验证，Caddy 自动签证；无需服务器命令。
- 使用共享平台发件域：地址采用 `<purpose>+<site-code>@example.com`，换店铺域名不会影响发信。
- 使用租户独立发件域：先把域加入 `MAIL_RELAY_DOMAINS` 和 `MAIL_RELAY_ALLOWED_SENDER_DOMAINS`，Redeploy 生成 DKIM，再由主站管理员将该签名域唯一分配给租户，最后由租户选择具体发件地址。

更新邮件域环境变量后只需在 Coolify 点击 Redeploy。DKIM 私钥、Postfix 队列和幂等回执位于命名卷中，不会因容器重建丢失。

## 10. 更新、备份和回滚

- 更新：把审核后的代码推送到 Coolify 绑定分支，使用 Auto Deploy 或点击 Deploy。
- 备份：在每次发布前备份 MySQL 和所有 LONGOS 命名卷，并把备份保存到服务器之外。
- 回滚：在 Coolify Deployments 选择上一个成功镜像；若本次包含不可逆迁移，同时恢复与旧镜像匹配的数据库备份。

生产容器内的 `LONGOS_UPDATER_ENABLED=false` 是强制边界。不要在运行中的容器覆盖源码，也不要手工执行 Composer/npm/migrate。

## 11. 上线验收清单

- Coolify 的 `app`、`mysql`、`mail-relay` 为 healthy，worker 和 scheduler 持续运行；
- 主站、平台租户子域和一个已验证自定义域均为有效 HTTPS；
- 未登记域名无法取得证书或进入租户应用；
- 管理后台、商品、购物车、结账和支付回调烟雾测试通过；
- 下单、账户、客服、营销和弃单召回邮件能进入 Postfix 队列；
- 测试邮件 SPF、DKIM、DMARC 均为 pass，PTR 与 `MAIL_RELAY_HOSTNAME` 匹配；
- Cloud Mail 连接测试使用系统集成令牌并返回健康；
- MySQL、3000 和 SMTP 监听端口没有暴露到公网；
- 已完成一次可恢复的数据库与卷备份。

更多故障定位、旧站迁移与证书问题见 [Coolify + Caddy 部署指南](COOLIFY.md)，邮件投递和租户发件规则见[内置邮件引擎](MAIL.md)。
