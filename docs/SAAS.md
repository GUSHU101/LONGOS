# LONGOS SaaS 多站点部署

## 架构

SaaS 模式支持两种数据隔离策略，网页安装默认采用无需 root 的共享库模式：

- `saas_sites` 和 `saas_domains` 保存站点、数据库和域名映射。
- `prefix`（默认）：所有租户复用 Compose 创建的一个物理数据库和普通账号，每个租户使用不可碰撞的独立表前缀；不需要 `tenant_admin`。
- `database`（高级）：每个租户使用独立物理数据库；`saas`、`tenant_admin`、`tenant` 三条连接分别承担控制数据、建库权限和站点运行查询。
- HTTP 请求在 Laravel 服务启动早期按 Host 解析站点，然后把默认连接切换到该站的业务数据库。
- 商品、订单、客户、设置和管理员数据按所选模式隔离，不需要修改 LONGOS 兼容核心模型。
- 缓存前缀、Host-only Session Cookie、Session 存储目录、URL 根路径和时区在站点级别切换。
- 队列任务通过 Laravel Context 携带 `saas_site_id`，Worker 执行前恢复完整站点上下文。
- 店面域名分为 `primary`、`redirect`、`alias` 三种角色；自定义域可作为主域，自动域默认保留为恢复入口。

## 多租户运行时

项目以 [Spatie Laravel Multitenancy v4](https://spatie.be/docs/laravel-multitenancy/v4/introduction) 作为唯一运行时，并吸收 [Tenancy for Laravel 的 bootstrapper 设计](https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/)、[域名识别](https://tenancyforlaravel.com/docs/v3/domains/)和[中央/租户路由边界](https://tenancyforlaravel.com/docs/v3/routes/)：把一次租户切换拆分为可组合、可复原的数据库、缓存和应用运行时任务，并对未知 Host 失败关闭。两套包不会同时接管请求和队列，避免重复切换连接、缓存与上下文。

运行时组成：

- `Site` 是 Spatie `IsTenant` 的自定义租户模型，仍复用现有 `saas_sites` 控制表。
- `DomainTenantFinder` 从规范化后的 Host 查询 `saas_domains`，只允许已启用域名和已上线站点。
- `SwitchDefaultTenantDatabaseTask` 在 Spatie 切换 `tenant` 连接后同步修改 Laravel 默认连接，使 LONGOS 兼容核心模型无需逐个增加租户 Trait。
- `PrefixTenantCacheTask` 在应用原缓存前缀后追加租户 ID，既隔离租户，也避免多个 LONGOS 部署共用 Redis/Memcached 时发生同 ID 碰撞；Laravel 的 `file` 缓存不支持前缀，因此会额外切换到租户独立目录。`ConfigureTenantRuntimeTask` 隔离 URL、时区、Session Cookie、上传目录、邮件、CDN、主题和认证状态。
- 切换动作具有失败回滚：任一任务失败会逆序恢复已完成任务，长驻进程不会停留在“半切换”状态。
- 新建空数据库时先完成迁移，再读取站点设置，避免建站流程在 `settings` 表创建前失败。
- 队列默认自动继承当前租户；控制面发出的中央任务保持中央上下文。数据库队列的传输表与失败任务表始终固定在 `saas` 控制连接，不会因请求中切换默认数据库而落入租户库。目标站点已暂停或被删除时，任务会被拒绝。
- Session 中记录租户 ID；Cookie 名包含租户身份摘要、强制使用 Host-only 作用域，文件型 Session 还会写入 `storage/framework/sessions/tenants/{site-code}`，同一个会话 ID 不能跨平台域名、自定义域名或租户复用。

项目在 `composer.json` 中将依赖解析平台固定为 PHP 8.2，并由 `composer.lock` 锁定已通过回归与安全审计的版本。Composer 依赖和浏览器资源只在不可变 Docker 镜像的构建阶段产生；不要在生产容器中运行 Composer、npm 或手工替换 `vendor`。CI 负责校验锁文件、执行测试和审计生产依赖。

## Coolify 自动生产安装

生产环境从 GitHub 创建 Coolify Docker Compose 资源。只需设置 `DOMAIN`、`SAAS_BASE_DOMAIN` 和 `ADMIN_EMAIL` 并点击 Deploy。Coolify 生成应用、数据库、代理和邮件密钥；容器入口等待 MySQL 后执行可恢复的 `longos:install`，随后启动 Caddy 路由、队列与计划任务。

已安装环境部署新镜像时只执行 `saas:upgrade --no-maintenance --if-needed`，不会清空数据库、重建管理员或轮换 `APP_KEY`。部署锁保存在共享 storage 卷，多实例同时启动也只有一个进程执行迁移。

新部署固定使用 MySQL `8.4.11` LTS。旧 MySQL 数据必须先完成备份并按官方支持路径验证升级；MySQL 不支持原地降级，回退需要恢复对应备份。

内置邮件引擎与 Web 服务一起部署。Coolify 分别生成 LONGOS 与 Cloud Mail HMAC 密钥，DKIM、Postfix 队列和幂等记录保存在命名卷。中继在调用本地 sendmail 前先持久化 pending 记录；状态不确定的同一 `requestId` 不会自动重复投递。

完整操作见 [Coolify + Caddy 部署指南](COOLIFY.md)。

## 从现有单站迁移

迁移前在 Coolify 完成数据库和持久卷备份，并把旧站完整 `APP_KEY`（包含 `base64:` 前缀）填入 `APP_KEY_OVERRIDE`。将旧数据和上传文件导入对应命名卷后点击 Deploy，容器入口会在共享锁下判断当前状态，自动初始化 SaaS 控制面或执行幂等升级。

不要在生产容器中手工运行迁移、`saas:install`、Composer 或 npm。如果入口检测到数据库结构不完整，本次部署会在服务接收流量前失败，保留原持久数据供恢复。完整步骤见 [Coolify 部署指南](COOLIFY.md#9-旧站迁移)。

默认 `prefix` 模式无需配置以下建库账号。只有设置 `SAAS_DATABASE_MODE=database`、要求每个租户使用独立物理数据库时，才需要配置租户数据库服务器和建库权限：

```dotenv
SAAS_DATABASE_MODE=database
SAAS_TENANT_DB_CONNECTION=mysql
SAAS_TENANT_DB_HOST=tenant-mysql.internal
SAAS_TENANT_DB_PORT=3306
SAAS_TENANT_DB_USERNAME=longos_runtime
SAAS_TENANT_DB_PASSWORD=change-runtime-password
# 可选：独立的高权限建库账号；留空即继承上面的运行账号
SAAS_TENANT_ADMIN_USERNAME=longos_provisioner
SAAS_TENANT_ADMIN_PASSWORD=change-provisioner-password
# 建库连接使用的已存在数据库；MySQL 可沿用原业务库，PostgreSQL 常用 postgres
SAAS_TENANT_ADMIN_DATABASE=postgres
```

若未配置独立建库账号，运行账号需要同时具备建库和租户库读写权限；配置后可让运行账号只保留各租户库的业务读写权限。控制库账号不再被隐式用于租户业务查询。PostgreSQL 使用两个账号时，还需在建库后通过数据库级自动化授予运行账号权限。

租户创建会在应用请求内运行迁移和初始化。LONGOS 默认尝试将该请求提升到 `512M` 且取消 PHP 执行时限；如需更高限制，请在 Coolify 调整容器资源。可用下列环境变量调整：

```dotenv
SAAS_PROVISIONING_MEMORY_LIMIT=512M
SAAS_PROVISIONING_MINIMUM_MEMORY_BYTES=268435456
SAAS_PROVISIONING_MAX_EXECUTION_SECONDS=0
```

## 生产发布与升级

SaaS 环境必须保持 `LONGOS_AUTO_UPGRADE=false`。请求结束时自动运行的单库升级无法同时保证控制库和所有租户库的版本一致，因此 SaaS 模式会强制跳过它。每次代码发布使用：

```dotenv
LONGOS_AUTO_UPGRADE=false
CACHE_DRIVER=database
QUEUE_CONNECTION=database
QUEUE_DB_CONNECTION=saas
QUEUE_FAILED_DB_CONNECTION=saas
SAAS_WEB_SERVER=caddy
SAAS_TLS_DRIVER=caddy
SAAS_TLS_EMAIL=ops@your-company.com
LONGOS_PROXY_TOKEN=<至少 32 字节的随机值>
```

数据库缓存默认使用新增的 `cache`/`cache_locks` 表，让多节点 Scheduler 的 `onOneServer()` 锁具备共享语义；使用 Redis 时可改为 `CACHE_DRIVER=redis`。数据库队列则必须显式固定到 `saas` 连接。

正式发布只需推送已审核的 Git 提交并让 Coolify 部署。镜像构建负责锁定依赖与前端资源；容器入口自动执行 `saas:upgrade --no-maintenance --if-needed`、健康门控和生产缓存。任何一步失败时，新容器不会通过健康检查，Coolify 可回滚到上一个已成功镜像。

`saas:upgrade` 默认进入维护模式，串行升级 SaaS 控制面、全部 `active`/`suspended` 租户库和已安装插件，随后逐站执行数据库健康检查、重建域名配置和 Laravel 生产缓存，最后通知队列 Worker 重载。任一站失败时命令返回非零且保持维护模式，避免新代码访问旧结构。修复后直接重新执行该命令；迁移和插件升级必须保持幂等。

`--no-maintenance` 只用于 Coolify 容器入口已经完成健康门控、队列协调和部署锁保护的发布流程；不要手工运行。

## 批量创建站点

### 按数量生成

下列命令会创建 `brand001.shops.example.com` 到 `brand010.shops.example.com`，数据标识为 `longos_brand001` 到 `longos_brand010`。默认 `prefix` 模式会把这些标识映射为同一物理库中的独立表前缀；高级 `database` 模式才会把它们作为物理数据库名：

```bash
php artisan saas:sites:create \
  --count=10 \
  --prefix=brand \
  --name-prefix="品牌站" \
  --base-domain=shops.example.com \
  --admin-password='Change-Me-Immediately'
```

手工指定的管理员密码必须为 12-128 位。不传 `--admin-password` 时，命令会为每站生成一个高强度密码，并仅在命令结果中显示一次。

### CSV

```csv
code,name,domain,database,admin_email,admin_password,locale,timezone
us-store,美国站,https://us-store.shops.example.com,longos_us,admin@us-store.shops.example.com,Change-Me-Now-1,en,America/New_York
jp-store,日本站,https://jp-store.shops.example.com,longos_jp,admin@jp-store.shops.example.com,Change-Me-Now-2,ja,Asia/Tokyo
```

```bash
php artisan saas:sites:create --file=sites.csv
```

`domains` 列可用 `|` 绑定多个域名，第一个是主域名：

```csv
code,name,domains
demo,Demo Site,demo.shops.example.com|www.demo.shops.example.com
```

### JSON

```json
[
  {
    "code": "de-store",
    "name": "Germany Store",
    "domains": ["de-store.shops.example.com", "www.de-store.shops.example.com"],
    "database": "longos_de",
    "admin_email": "admin@de.example.com",
    "admin_password": "Change-Me-Now-3",
    "locale": "de",
    "timezone": "Europe/Berlin"
  }
]
```

```bash
php artisan saas:sites:create --file=sites.json
```

建站流程会分配租户数据空间、执行迁移与 Seeder、写入站点设置和根管理员，最后统一生成 Web 服务配置。默认 `prefix` 模式只需要当前数据库账号对当前库拥有建表和读写权限；高级 `database` 模式才要求建库账号具有 `CREATE DATABASE`/授权权限。高级模式下，为防止误覆盖，未由系统创建的同名数据库会被拒绝。每个站点还会持有独立、可续期的建站租约，不同批次即使使用不同幂等键也不能并发操作同一站点。如某站在迁移或 Seeder 阶段失败，用相同 code 和数据标识重新执行可安全续跑。

完成租户数据初始化后，站点先进入 `ready`，发布域名时进入 `activating`；只有 Web 配置原子写入与重载成功后才改为 `active`。因此即使使用泛 DNS，批次中途中断的站点也只会返回 404，不会提前对外服务。若批次末尾的 Web 配置写入或重载失败，配置文件会回滚，相关新站同时回到 `failed`，修复配置后可用原 code 重试。

`SAAS_MAX_BATCH_SIZE` 会在解析完整批次后、生成任何随机管理员密码前执行硬限制；JSON 项必须为对象，CSV 表头不得为空或重复，字段数量超过表头的行会被拒绝，防止畸形输入被静默截断。

## 域名和 Web 服务

每次域名变更会原子更新 `storage/app/saas/domains.json`。它是审计与恢复快照，不是需要人工加载的 Caddyfile。Coolify Caddy 在 TLS 握手时调用 `/_longos/tls/authorize`，LONGOS 直接依据控制库状态决定该精确域名是否允许签发证书。

域名新增、解绑、切换主域名、验证激活、批量建站和 `saas:upgrade` 共用可重入操作锁，避免升级、建站与域名发布互相覆盖。单站建站还会在控制库取得会话级 advisory lock，长迁移超过心跳租约期时也不会被另一节点重复接管。Compose 将 `storage` 挂载为共享持久卷，因此 Web、队列和计划任务看到同一份安装状态与域名快照。

参照 Shopify，LONGOS 把每个已激活域名暴露为以下角色：

- `primary`：店面唯一规范主域名。
- `redirect`：默认的非主域名，店面 `GET/HEAD` 以 308 跳转到主域并保留路径与查询参数。
- `alias`：不跳转，以当前域名直接服务，适用于明确需要多域名展示的场景。

管理后台、`api` 和 `installer` 路径不做主域跳转，因此平台自动域名仍可用于恢复管理。默认不允许租户删除最后一个平台域名；控制面或 CLI 只能在明确传入 `force`/`--force` 后绕过。可通过以下配置调整：

```dotenv
SAAS_REDIRECT_SECONDARY_DOMAINS=true
SAAS_PRIMARY_REDIRECT_STATUS=308
SAAS_PRIMARY_REDIRECT_EXEMPT_PATHS=api,installer
SAAS_RETAIN_PLATFORM_DOMAIN=true
```

批量建站阶段只允许直接发布 `SAAS_BASE_DOMAIN` 下的平台子域名。客户自有域名不能写入 CSV/JSON 绕过验证；请先完成建站，再通过租户域名页或 `saas:domains:add` 生成 TXT 所有权验证记录。

默认不需要填写 `SAAS_DNS_TARGETS`：LONGOS 会自动使用 `SAAS_CONTROL_DOMAINS`/`DOMAIN` 的 DNS 结果作为同一 Coolify Caddy 入口的可信基线，并校验全部 A/AAAA/CNAME 终点。只有控制站经 Cloudflare、租户域直连源站等分离路由才需要显式设置 `SAAS_DNS_TARGETS`。平台子域应配置 `*.shops.example.com` 泛解析。应用无需写 Nginx 配置、重载代理或执行服务器命令。

### Coolify Caddy 边缘与可选 Cloudflare CDN

应用内 Nginx 只在私有网络承载 Laravel，Coolify 的集成 Caddy 独占公网 TCP 80、TCP/UDP 443。主站、平台子域和租户自有域名都使用受 LONGOS 数据库鉴权的 On-Demand TLS；项目不再构建第二套 Caddy，也不接管宿主机端口。

自有域名验证成功后会先进入 `activating`。此时 Caddy 的 ask 端点只临时放行“已通过 TXT + 流量 DNS 校验”的这一条精确域名；LONGOS 随即发起签名 HTTPS 探测，触发证书签发并验证 Cloudflare/Caddy/源站整条链路。只有收到当前实例返回的签名 204 后才切换为 `active`。未知、未验证、失败、停用或退休域名都不能消耗 ACME 配额。

Compose 标签关闭 0-RTT、要求严格 SNI、限制请求体并配置健康检查；`LONGOS_PROXY_TOKEN` 由 Coolify 自动生成，用于认证入口写入的代理头。应用仍负责对登录、购物车、结账、订单、后台和本地化页面输出 `no-store`，不会让 CDN 缓存租户私有内容。

Cloudflare 不是必需依赖。使用时 SSL/TLS 模式必须选择 **Full (strict)**，不能选择 Flexible；所有权 `_longos-challenge` TXT 保持“仅 DNS”，网站 A/AAAA/CNAME 可按需开启代理。`CLOUDFLARE_API_TOKEN` 只用于可选 DNS 自动化，Caddy 按需签发证书不需要它。客户域名属于客户自己的 Cloudflare 账户时，由客户创建 DNS 记录，LONGOS 不跨账户修改 Zone。

`LONGOS_CLOUDFLARE_MODE=optional` 是默认且受支持的项目级模式。`required` 意味着只允许 Cloudflare 回源，这属于共享 Coolify Caddy 的服务器级安全策略，不能由某个应用的 Compose 标签擅自修改；未在服务器级完成该策略时，生产预检会明确失败。

Coolify Caddy 的证书数据保存在服务器级代理卷，应用重新部署不会重新签发。租户换域名由控制面的 active/retired 状态即时控制 `ask` 授权，不需要修改 Caddyfile、重启代理或执行部署命令。完整说明见 [Coolify 部署指南](COOLIFY.md)。

`SAAS_TLS_DRIVER=external` 仅保留给开发和兼容测试，不是支持的生产路径。无论 TLS 驱动如何，LONGOS 都会使用签名的 `/_longos/domain/probe` 验证真实 HTTPS 路径；未到达当前实例时绝不会显示“已启用”。

## 租户自助自定义域名

租户自定义域名默认启用，并与平台直接添加的可信域名分开处理：租户提交后状态为 `pending`，依次检查 DNS TXT 所有权、A/AAAA/CNAME 流量指向、Web 入口发布与 HTTPS 回源。验证通过后先进入 `activating`，此时普通店铺路由仍拒绝该 Host；只有全部检查成功才原子切换为 `active` 并显示“已启用”。旧版本已误标 active 但没有发布证据的自有域名，升级迁移会安全退回待发布，可在页面一键重新检测。

```dotenv
SAAS_CUSTOM_DOMAINS_ENABLED=true
SAAS_CUSTOM_DOMAINS_MAX_PER_SITE=10
SAAS_DOMAIN_VERIFICATION_TTL_HOURS=72
SAAS_DOMAIN_VERIFICATION_RETRY_MINUTES=15
SAAS_DOMAIN_VERIFICATION_BATCH=100
SAAS_DOMAIN_PROBE_CONNECT_TIMEOUT=3
SAAS_DOMAIN_PROBE_TIMEOUT=8
SAAS_CADDY_PROBE_TIMEOUT=45
```

租户在系统自动生成的站点域名登录后台后，可由该站 Root 管理员调用：

- `GET /admin/saas/domains/manage`：打开可视化域名管理页，可更换平台子域、添加/验证自有域名、设置主域及路由模式。
- `GET /admin/saas/domains`：查看本站域名、DNS 目标和验证状态。
- `PATCH /admin/saas/domains/subdomain`：提交 `{"subdomain":"new-brand"}` 更换平台子域名。保留字会被拒绝；旧子域转为 `retired` 并永久保留占用，不再路由，也不会被其他租户接管。
- `POST /admin/saas/domains`：提交 `{"domain":"shop.customer.com"}`，取得 TXT 验证记录。
- `POST /admin/saas/domains/{domain}/verify`：立即检查 TXT；可提交 `{"primary":true}` 在验证成功后设为主域名。
- `POST /admin/saas/domains/{domain}/rotate`：验证记录过期或泄露时重新生成。
- `PATCH /admin/saas/domains/{domain}/primary`：切换已激活的主域名。
- `PATCH /admin/saas/domains/{domain}/routing`：为非主域提交 `{"mode":"redirect"}` 或 `{"mode":"alias"}`。
- `DELETE /admin/saas/domains/{domain}`：解绑域名；不能删除站点最后一个可用域名或最后一个平台恢复域名。

典型切换顺序：提交自定义域名、在 DNS 服务商添加 `_longos-challenge.<域名>` TXT、将 A/AAAA/CNAME 指向与控制站相同的 Coolify Caddy 入口（分离拓扑则指向 `SAAS_DNS_TARGETS`）、点击“验证并启用”、设为主域名。Caddy 模式会在这次验证中完成 ask 授权、证书签发和真实 HTTPS 探测，不再提前显示“已启用”；原自动域将保留为 `redirect` 恢复入口。Cloudflare 橙色云会返回 Anycast 地址，LONGOS 只接受官方 Cloudflare 网段，并仍以签名 HTTPS 探测作为最终发布依据。

自有域名始终被规范化为 HTTPS；`SAAS_BASE_DOMAIN` 本身及其任意子域只能由平台子域流程分配，不能绕过保留字和租户唯一性检查伪装成“自有域名”。子域切换失败只回滚本次目标，即使同时有另一个自有域名提交验证，也不会被清理。

Scheduler 每五分钟扫描待验证域名；单个域名默认至少间隔 15 分钟重试，过期记录转为 `failed`，租户可通过 rotate 重新生成。验证成功并完成 Web 配置同步后，随机验证令牌和过期时间会立即销毁；随后派发 `App\Saas\Events\DomainVerified` 事件。默认 Caddy 模式无需监听该事件，外部 Cloudflare/负载均衡器方案仍可用它触发证书控制器。证书可用前不应删除仍可正常提供 HTTPS 的原自动域名。

## 后台 JSON API（可选）

默认关闭。要在控制域上开启：

```dotenv
SAAS_HTTP_MANAGEMENT=true
SAAS_CONTROL_DOMAINS=www.example.com
```

仅 `saas:install` 注册的控制站根管理员可访问；即使其他租户也有根管理员，仍不能调用控制面 API：

- `GET /admin/saas/sites`
- `POST /admin/saas/sites/batch`
- `GET /admin/saas/sites/{site}`
- `PATCH /admin/saas/sites/{site}/status`
- `POST /admin/saas/sites/{site}/domains`
- `POST /admin/saas/sites/{site}/domains/{domain}/verify`
- `DELETE /admin/saas/sites/{site}/domains/{domain}`
- `PATCH /admin/saas/sites/{site}/domains/{domain}/primary`
- `PATCH /admin/saas/sites/{site}/domains/{domain}/routing`
- `POST /admin/saas/sites/{site}/health`

POST 请求体：

```json
{
  "sites": [
    {"code": "site-a", "name": "Site A", "domain": "site-a.example.com"},
    {"code": "site-b", "name": "Site B", "domain": "site-b.example.com"}
  ],
  "migrate": true,
  "seed": true
}
```

`POST /admin/saas/sites/batch` 默认必须带 `Idempotency-Key: <UUID>`。同一 UUID 与完全相同的请求在 24 小时内只执行一次，重试会返回原 HTTP 状态和响应，并带 `Idempotency-Replayed: true`；同一键配不同请求会返回 409。响应中可能包含的一次性管理员密码以 `APP_KEY` 加密保存，过期记录由 Scheduler 每小时清理。

```bash
curl -X POST https://control.example.com/admin/saas/sites/batch \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 9f77dfe2-1b34-4c04-9c8a-a8f9a9893e71' \
  --data @sites.json
```

生产环境建议优先使用 CLI 或后台队列触发大批量任务，避免 HTTP 超时。

## 逐站运维

```bash
# 列出站点
php artisan saas:sites:list

# 对所有已启用站点执行命令
php artisan saas:run process:order --continue-on-error

# 只处理指定站点
php artisan saas:run process:order --site=brand001 --site=brand002

# 暂停与重新上线（上线前默认检查数据库）
php artisan saas:sites:status brand001 suspended
php artisan saas:sites:status brand001 active

# 绑定平台子域名（直接发布）
php artisan saas:domains:add brand001 brand001-new.shops.example.com

# 添加客户自有域名（只生成 TXT 验证记录，不会直接接收流量）
php artisan saas:domains:add brand001 shop.example.com
php artisan saas:domains:verify-pending
# 验证并启用后再切换主域名，或在租户域名页操作
php artisan saas:domains:routing brand001 brand001.shops.example.com redirect
php artisan saas:domains:remove brand001 shop.example.com

# 检查所有站点的数据库、核心表和 DNS（仅检查已上线站点可加 --active-only）
php artisan saas:health
```

SaaS 模式下，Laravel Scheduler 已自动改为逐站执行订单处理和汇率更新，每五分钟处理待验证域名，并在每天 03:30 执行健康检查。建站、启停、域名变更、域名验证和健康检查记录在 `saas_site_events`。密码、Token、API Key、Authorization、数据库 URL 凭据以及配置中已知的数据库密码，会在 API 错误、审计消息与上下文、站点 `last_error`、域名验证错误和健康检查结果中统一脱敏。

## 租户文件隔离

新建站点默认启用 `SAAS_ISOLATE_FILES=true`，各站文件分别写入：

```text
public/image/catalog/tenants/{site-code}/
public/upload/tenants/{site-code}/
```

数据库中保存完整的租户路径，缩略图缓存也因路径不同而自然隔离。`saas:install` 注册的存量主站会保持旧的共享路径，避免升级后原图片失效。

## 长驻进程

Spatie 的域名查找器会在 HTTP 请求启动期解析 Host，LONGOS 中间件再执行未知域名策略，并在响应结束后释放数据库、缓存、Session、认证守卫、文件系统、邮件传输器、CDN 和主题视图上下文，可避免 Octane/长驻 Worker 跨站污染。队列通过 Laravel Context 保存 `saas_site_id`，Worker 执行前恢复站点数据库、邮件配置和缓存命名空间，执行后再清理。如果目标站点已暂停或不存在，该任务会失败/重试，不会意外落到控制库执行。

## 安全要点

上线前先执行只读预检：

```bash
php artisan saas:preflight
php artisan saas:preflight --strict
php artisan saas:preflight --strict --json
```

`--strict` 会将配置/路由未缓存、未配置 DNS 预期目标等部署告警也视为失败，适合作为 CI/CD 发布门禁。预检不会写数据库或生成域名配置；它会检查 LONGOS 归属、APP_KEY 密码学长度及退役密钥、HTTPS/Cookie、轮转日志、运行目录权限、更新回滚保留、Caddy/ACME 邮箱/代理认证、Host 白名单、幂等保护、异步队列、控制库迁移、共享库前缀不变式（或高级模式的建库与授权能力）、全部 active 域名 HTTPS，以及每个 active 站点“恰好一个 active 主域名”不变式。

容器、Caddy 和 Worker 使用 `GET /api/health` 作为就绪探针。已安装站点只有在 Laravel 能启动、控制库可查询且 `storage/framework`、`storage/logs`、`bootstrap/cache` 可写时才返回 `200`；任一层异常均返回 `503` 且不泄露连接信息。`/healthz.txt` 仅用于首次安装和证明 DNS 到达指定边缘，不能作为应用健康的依据。

正式上线还必须在 LONGOS 机器之外保留定时备份：控制库、全部租户库（或共享库）、`storage/app`、`public/image/catalog`、`public/upload` 以及 Coolify Caddy 数据卷。Coolify 镜像回滚不能替代数据备份；备份必须与发布版本匹配，并定期在隔离环境做实际恢复演练。

- 必须保持 `SAAS_REJECT_UNKNOWN_DOMAINS=true`，防止未绑定 Host 访问。
- 生产环境建议启用 `TRUST_HOSTS_ENABLED=true`，信任列表会从 `saas_domains` 动态生成。
- 租户提交的域名必须保持 `pending` 直到 TXT 所有权验证成功，禁止绕过验证直接改库为 `active`。
- 不要配置跨子域共享的 `SESSION_DOMAIN`；LONGOS SaaS 会强制使用 Host-only Cookie，并隔离租户 Session 存储。
- 控制库与租户库必须只允许应用网络访问，禁止公网暴露。
- 默认共享库模式不保存 root 或全局建库凭据；高级独立库模式可使用专用建库账号，运行时另行分配权限更小的业务账号。
- 租户上传目录已按站点 code 隔离；如文件本身不应公开，仍应迁移到私有对象存储并使用签名 URL，不要存放在 `public` 目录。
- 应用镜像内部 Nginx 只能执行 `public/index.php`，上传目录和插件发布目录中的其他 PHP 文件必须返回 404/403。
