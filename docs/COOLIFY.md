# LONGOS：Coolify + Caddy 部署指南

本指南适用于全新生产部署，也适用于从旧面板环境迁移后由 Coolify 接管。LONGOS 使用 Coolify 的服务器级 Caddy 作为唯一公网入口，项目 Compose 不映射宿主机 80/443。第一次部署建议先按[生产配置教程](CONFIGURATION.md)准备域名、变量和验收表，再回到本文查看迁移、回滚与故障细节。

## 1. 部署前准备

准备以下内容：

- 一台已安装当前稳定版 Coolify 的独立服务器；集成代理需包含 Caddy 2.11.4 或更新版本，安全组放行 TCP 80、TCP 443 和 UDP 443。
- 一个主站域名，例如 `admin.example.com`。
- 一个租户根域名，例如 `example.com`。
- 一个真实管理员邮箱。
- GitHub 中的 LONGOS 仓库和部署分支。

服务器不需要安装宝塔、宿主机 PHP、Composer、Node.js、MySQL、Nginx 或独立邮件中继。Coolify 只需要正常可用的 Docker 环境。

## 2. 将 Coolify 代理切换为 Caddy

1. 打开 Coolify，进入 **Servers → 当前服务器 → Proxy**。
2. 选择 **Switch Proxy → Caddy**。
3. 启动代理，确认 `coolify-proxy` 状态为 Running。
4. 关闭 **Override default request handler**。LONGOS 需要一个受数据库鉴权的 hostless HTTPS 路由处理租户域名；未知域名仍会在证书签发前被 LONGOS `ask` 接口拒绝。
5. 如果该服务器已有其他应用，重新部署一次那些应用，让 Coolify 生成 Caddy 标签。

此操作是服务器级一次性设置。以后新增、更换或解绑租户域名不需要修改 Caddy，也不需要服务器命令。

> 一台 Caddy 服务器只部署一个 LONGOS 控制平面。其他普通 Coolify 应用可以共存，其精确域名路由优先于 LONGOS 的租户兜底路由。

## 3. 从 GitHub 创建应用

1. 在 Coolify 创建 Project 和 Production Environment。
2. 选择 **New Resource → Public Repository** 或已连接的 GitHub App。
3. 选择 LONGOS 仓库及部署分支。
4. Build Pack 选择 **Docker Compose**，Compose 文件填写 `/docker-compose.yml`。
5. 在 Advanced 的 Docker Compose 设置中选择 **Raw (deploy file as-is)**。
6. 确认资源连接到预定义的 `coolify` 网络。Compose 已声明这个 external network，并用 `caddy_ingress_network=coolify` 固定代理上游选择；数据库和邮件服务仍只存在于私有 `longos` 网络。

不要在 Coolify Domains 页面另外生成一套代理路由。LONGOS 的 Compose 标签已经同时处理主站、平台子域和租户自定义域名。

## 4. 设置环境变量

Coolify 解析 Compose 后会自动生成 `SERVICE_*` 凭据。只需手动填写：

| 变量 | 示例 | 必填 | 说明 |
|---|---|---:|---|
| `DOMAIN` | `admin.example.com` | 是 | LONGOS 控制站域名，不含协议和路径 |
| `SAAS_BASE_DOMAIN` | `example.com` | 是 | 平台租户子域根域名 |
| `ADMIN_EMAIL` | `owner@example.com` | 是 | 首次创建的超级管理员和默认 ACME 联系邮箱 |
| `SAAS_TLS_EMAIL` | `ops@example.com` | 否 | 留空时使用 `ADMIN_EMAIL` |
| `APP_KEY_OVERRIDE` | `base64:...` | 仅旧站迁移 | 完整保留旧站 APP_KEY；全新站必须留空 |
| `APP_TIMEZONE` | `Asia/Shanghai` | 否 | 后台、任务和订单默认时区 |
| `SAAS_DNS_TARGETS` | 源站 IP 或可信入口域名 | 特殊拓扑才需要 | 留空时自动复用 `DOMAIN` 的 DNS 路由；仅在控制站走 Cloudflare、租户域直连源站等分离入口时填写 |
| `MAIL_RELAY_DOMAINS` | `example.com,brand.com` | 推荐 | 需要 DKIM 签名的所有发件域名 |
| `MAIL_RELAY_ALLOWED_SENDER_DOMAINS` | 同上 | 推荐 | 允许租户选择的发件域名，必须是前一项的子集 |
| `MAIL_FROM_ADDRESS` | `no-reply@example.com` | 否 | 平台默认发件地址 |
| `CLOUDFLARE_API_TOKEN` | Cloudflare API Token | 否 | 仅用于可选 DNS 自动发布；应在 Coolify 标记为 Secret |
| `LONGOS_AUTO_CONFIGURE_MAIL_DNS` | `true` | 否 | 有可用 Token 时维护 LONGOS 专用邮件 DNS；不会覆盖已有 SPF/DMARC 策略 |

以下值由 Coolify 自动生成并持久保存，不要手动替换：

- `SERVICE_REALBASE64_32_APPKEY`
- `SERVICE_PASSWORD_64_DBROOT`
- `SERVICE_USER_DBUSER`
- `SERVICE_PASSWORD_64_DBPASSWORD`
- `SERVICE_PASSWORD_64_ADMIN`
- `SERVICE_PASSWORD_64_PROXYTOKEN`
- `SERVICE_HEX_32_EDGEID`
- `SERVICE_PASSWORD_64_MAILRELAY`
- `SERVICE_PASSWORD_64_CLOUDMAIL`

管理员初始密码就是 Coolify 环境变量中解析后的 `SERVICE_PASSWORD_64_ADMIN`。首次登录后可在 LONGOS 后台修改。已安装后即使修改该部署变量，也不会重置现有管理员密码。

## 5. 配置 DNS

假设服务器公网 IP 为 `203.0.113.10`：

| 类型 | 主机 | 记录值 | 用途 |
|---|---|---|---|
| A | `admin` | `203.0.113.10` | 控制站 |
| A | `*` | `203.0.113.10` | 平台租户子域 |

租户自定义域名按后台给出的目标设置 A/AAAA 或 CNAME，并添加 `_longos-challenge` TXT。只有 TXT 所有权、流量 DNS 和站点状态都通过时，Caddy 的 On-Demand TLS `ask` 才返回 204 并允许签发证书。

使用 Cloudflare 时：

- SSL/TLS 模式必须为 **Full (strict)**。
- 可以使用 DNS only 或橙色云；不要使用 Flexible。
- 项目级 Caddy 不需要 Cloudflare API Token 才能为精确域名签证。
- `CLOUDFLARE_API_TOKEN` 仅用于 LONGOS 的可选 DNS 自动化，不用于 Coolify Caddy 证书存储。

## 6. 首次部署

点击 **Deploy**。正常日志顺序为：

1. 构建 PHP 8.2、锁定的 Composer 生产依赖和 Node 22 前端资源。
2. 启动 MySQL 8.4 与内置邮件引擎。
3. 应用等待数据库健康；邮件引擎暂时不健康不会阻断商店上线，队列会在引擎恢复后重试。
4. 自动执行 `longos:install`，创建结构、基础数据、管理员和控制站点。
5. 写入持久卷中的安装标记并构建生产缓存。
6. Web 健康后启动 worker 与 scheduler；scheduler 每分钟写入共享心跳，不再只依赖“容器仍在运行”的表面状态。
7. Coolify Caddy 首次访问域名时自动申请证书。

整个流程不需要打开终端或手动执行迁移。首次构建和初始化可能需要数分钟；`app` 健康检查为数据库初始化预留了 10 分钟。

## 7. 自动更新与回滚

在 Coolify 应用的 Git Source 中开启 **Auto Deploy** 或配置 GitHub webhook。代码推送到部署分支后：

1. Coolify 构建新镜像；依赖严格来自 `composer.lock` 和 `package-lock.json`。
2. 新容器取得共享部署锁，在同一锁内同步系统内置插件并只执行尚未应用的迁移。
3. 内置插件按完整目录替换，旧版本已经删除的 PHP/静态文件不会残留；后来安装的第三方插件目录保持不变。
4. 安装、迁移和缓存构建结束后统一修复运行目录权限，避免旧日志或缓存文件导致 PHP-FPM 写入失败。
5. MySQL、上传、第三方插件、DKIM、Postfix 队列与幂等记录保存在命名卷中。
6. 健康检查通过后完成切换。

应用内旧式“覆盖文件更新器”在 Coolify 模式下默认关闭，避免正在运行的容器修改镜像。回滚时在 Coolify Deployments 中选择上一个成功版本并 Redeploy；数据库回滚必须使用部署前备份，而不是逆向猜测迁移。

建议在 Coolify 为 MySQL 持久卷配置定时备份，并把备份复制到服务器外部存储。持久卷不是备份。

## 8. 邮件引擎

Compose 自动启动 Postfix/OpenDKIM 和内部 HMAC API，不开放公共 SMTP 端口。LONGOS、队列和 Cloud Mail 通过私有网络调用它。

部署后在 LONGOS 邮件设置中查看各发件域名需要添加的 DKIM、SPF 和 DMARC 记录。新增签名域名后更新 `MAIL_RELAY_DOMAINS` 与 `MAIL_RELAY_ALLOWED_SENDER_DOMAINS` 并在 Coolify 点击 Redeploy；已有 DKIM 私钥保存在卷中，不会重新生成。

部分云服务器会封锁出站 TCP 25。若日志显示外部 SMTP 连接超时，需要向服务器提供商申请解除出站 25 限制；这与 Caddy 或 Coolify 无关。

## 9. 旧站迁移

从旧服务器迁移时不要删除原站：

1. 先停止旧站的后台写入并完成数据库备份。
2. 在 Coolify 首次部署一个空环境。
3. 通过 Coolify 的数据库导入/终端把备份恢复到新 MySQL 卷，并复制 `storage`、上传目录、插件数据和邮件 DKIM 数据。
4. 把原站完整的 `APP_KEY`（包含 `base64:` 前缀）填入新资源的 `APP_KEY_OVERRIDE`；否则历史加密配置无法解密。不要修改 Coolify 自动生成的内部变量。
5. 运行一次 Redeploy，入口会自动执行 `saas:upgrade --if-needed`。
6. 用临时测试域名验证后台、结账、支付回调、队列和邮件，再切换正式 DNS。

首次全新部署可以使用 Coolify 自动生成的 APP_KEY；迁移既有数据时绝对不能生成新 KEY。

## 10. 故障排查

### Caddy 返回 404 或证书未签发

- 确认 Coolify 服务器代理类型是 Caddy，不是 Traefik。
- 确认使用 Raw Compose 且 `app` 已连接 external `coolify` 网络。
- 确认关闭了 Coolify 的默认请求覆盖。
- 自定义域名必须先在 LONGOS 中完成 TXT 与流量 DNS 验证。
- 查看 `coolify-proxy` 日志中的 On-Demand TLS `ask` 结果。

### app 一直 unhealthy

- 查看 app 日志中首次安装或迁移的首个错误。
- 检查 `DOMAIN`、`SAAS_BASE_DOMAIN`、`ADMIN_EMAIL` 是否有效。
- 检查 mysql 是否 healthy，并确认共享 `longos-storage` 卷可写。`mail-relay` 异常会影响发信，但不应阻断 `app` 启动。
- 不要删除 `longos-storage` 中的 `storage/installed` 来强制重装；恢复失败部署或数据库备份。

### scheduler 一直 unhealthy

- 新容器最多需等待一分钟才生成首次 `storage/framework/scheduler-heartbeat`，Compose 已预留 180 秒启动宽限。
- 超过宽限仍不健康时，查看 scheduler Logs 中的第一个 Laravel 异常；不要手动在容器内另启一个 `schedule:work`。

### 构建阶段报 `ADMIN_EMAIL is missing a value`

Coolify 会分别生成 build-time 和 runtime 环境文件。当管理员邮箱在界面中已保存、但 build-time 副本短暂为空时，新版 Compose 会用 `admin@DOMAIN` 完成与业务无关的不可变镜像构建，显式 `ADMIN_EMAIL` 仍在运行阶段优先。这个兼容保护从包含修复的 Git 提交起生效；旧提交必须先在 Coolify 中选择最新 `main` 并重新部署。

### Cloudflare 526

- 使用 Full (strict)，确认 DNS 指向当前 Coolify 服务器。
- 暂时切为 DNS only 验证 Caddy 能否签发，再恢复橙色云。
- 不要降级为 Flexible。

Coolify 官方参考：

- https://coolify.io/docs/core/networking/proxy/caddy/overview
- https://coolify.io/docs/applications/builds/docker-compose
- https://coolify.io/docs/core/networking/proxy/overview

