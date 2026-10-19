# LONGOS 内置邮件服务器

LONGOS 的 Coolify Compose 会把 Postfix、OpenDKIM 和 Cloud Mail HTTPS 中继作为网站栈的一部分自动部署。无需另外克隆项目，也无需在服务器上再次执行 `docker compose`。

该能力使用项目唯一支持的 Coolify 部署模式；邮件容器不公开 SMTP 入口，只通过私有 `longos` 网络接受 HMAC 请求。

## 自动部署行为

点击 Coolify Deploy 后，Compose 会自动：

1. 通过 Coolify `SERVICE_PASSWORD_64_*` 生成两条独立中继共享密钥；
2. 构建并启动 `mail-relay` 容器；
3. 在 Docker 私有网络向 LONGOS 提供 HMAC 签名 API，Postfix 仅接受容器内本地投递；
4. 生成并持久化 2048 位 DKIM 密钥；
5. 通过 LONGOS 应用网关发布 `/_longos/mail-relay`，对外仅接受有限权的系统集成令牌；
6. 把 LONGOS 后台默认邮件引擎设为“内置邮件服务器”；
7. 如果提供了拥有 Zone:Read + DNS:Edit 权限的 Cloudflare Token，自动发布邮件主机 A（仅 DNS）、`MAIL_RELAY_DOMAINS` 中每个签名域的 DKIM，并在尚无策略时创建 SPF/DMARC。

默认值根据网站域名生成：

```text
MAIL_RELAY_HOSTNAME=mailout.<SAAS_BASE_DOMAIN>
MAIL_RELAY_DOMAINS=<SAAS_BASE_DOMAIN>
MAIL_RELAY_PLATFORM_SENDER_DOMAIN=<SAAS_BASE_DOMAIN>
MAIL_RELAY_PUBLIC_URL=https://<DOMAIN>/_longos/mail-relay
MAIL_FROM_ADDRESS=no-reply@<SAAS_BASE_DOMAIN>
```

需要自定义时，在 Coolify 环境变量中设置同名值并 Redeploy。`MAIL_RELAY_DOMAINS` 和 `MAIL_RELAY_ALLOWED_SENDER_DOMAINS` 支持逗号分隔的多个域名；留空时 Laravel 和 Compose 从 `SAAS_BASE_DOMAIN` 推导。首次打开后台邮件设置时，默认、订单、账户、退货/客服和营销五类发件人会按当前租户自动填充。

Cloudflare 自动发布默认由 `LONGOS_AUTO_CONFIGURE_MAIL_DNS=true` 开启。应用只更新 LONGOS 专用的邮件主机和每个签名域的 DKIM selector；如果域名已有 SPF 或 DMARC，会保留商家策略，不会盲目覆盖。多签名域模式下 Token 必须对每个域所属的 Cloudflare Zone 都有权限。Token 作为 Coolify 的 Secret 环境变量保存，不写入仓库；若不希望容器获得 DNS 权限，留空并按后台展示的记录手工添加即可。

升级已有站点时只需让 Coolify 部署新的 Git 提交。新镜像会从锁文件重新构建依赖和前端资源，容器入口识别持久卷中的 `storage/installed`，取得部署锁后自动升级控制库、租户库和插件并重建缓存。原域名、数据库、SaaS 配置、DKIM 私钥、队列和密钥都保留，不需要运行 `install.sh`、`migrate` 或其他服务器命令。

## Cloud Mail 自动连接

只在 LONGOS **主站控制域名**的后台“系统设置 → 系统集成令牌”创建一枚 Cloud Mail 令牌。普通租户后台不再提供重复绑定入口；这一枚主站令牌会服务全部有效租户。令牌原文使用 `APP_KEY` 加密保存，可在该页面随时显示、隐藏和复制；鉴权只比对 SHA-256 哈希。令牌仍可设置过期时间、查看最后使用/显示时间并随时撤销。旧版仅保存哈希的令牌无法恢复原文，需要重新创建。务必备份并保护 `APP_KEY`。轮换密钥时先把旧值加入逗号分隔的 `APP_PREVIOUS_KEYS`；令牌下一次安全显示时会自动改用当前 `APP_KEY` 重新加密。过早移除旧密钥会导致尚未轮换的令牌仍可鉴权但无法显示原文。

新字段由 LONGOS 的标准数据库迁移创建。Coolify 每次部署的新 Web 容器会在对外健康前自动执行尚未应用的迁移，不需要管理员另外输入数据库命令。

然后在 Cloud Mail GitHub 仓库设置中配置：

```text
Variable: LONGOS_RELAY_URL=https://<DOMAIN>/_longos/mail-relay
Secret:   LONGOS_INTEGRATION_TOKEN=<LONGOS 后台创建后显示的令牌>
```

Cloud Mail 的部署工作流会自动把令牌写入 Worker Secret；后台保持“自动”即可优先使用 LONGOS 内置引擎。Worker 无法读取令牌原文，网页和 D1 也不保存它。

使用 `LONGOS_INTEGRATION_TOKEN` 时，Worker 会强制使用部署变量 `LONGOS_RELAY_URL`，不接受网页修改的其他主机，且不跟随 HTTP 重定向，避免 Bearer 令牌被转发到不可信站点。

`CLOUD_MAIL_RELAY_SHARED_SECRET` 现在只是 LONGOS 应用网关到私有 `mail-relay` 容器的内部 HMAC 密钥。不要将它复制到 GitHub、Cloudflare 或浏览器。`RELAY_SHARED_SECRET` 仅用于 Cloud Mail 连接旧版或独立 HMAC 中继的兼容模式，新 LONGOS 部署不再使用它。

必须把 `LONGOS_RELAY_URL` 指向主站控制域名；同一令牌经租户子域名调用会被拒绝。发信时网关不信任 Worker 传入的 `tenantId`/`tenantCode`，而是从 `<purpose>+<site-code>@<platform-domain>` 的稳定站点 code 解析活跃租户，再用控制库校验发件权。暂停、删除或不存在的租户会失败关闭。店铺自定义域名变更不改变站点 code，因此无需重新创建令牌、修改 Worker 或重建邮箱。

令牌轮换时不要先撤销旧令牌：先创建新令牌并更新 GitHub Secret，重新部署 Worker，在 Cloud Mail 系统设置中点击“测试连接”，成功后再撤销旧令牌，可避免发信中断。

## 上线检查与后台诊断

先在 Coolify 的资源页面确认 `app`、`mail-relay`、`worker`、`scheduler` 和 `mysql` 都是 Running/Healthy；异常时直接打开对应服务的 Logs，无需 SSH 到服务器。

然后进入 LONGOS 后台“系统 → 基础设置 → 邮件设置”，保存邮件引擎和默认发件人，再点击“立即检测”。检测会逐项检查：

- 默认发件身份及租户授权；
- LONGOS 内部 HMAC 密钥是否完整且不与站内密钥复用；
- 中继 API、Postfix 与持久化去重回执是否健康；
- `MAIL_RELAY_HOSTNAME` 的 A/AAAA 解析；
- 每个签名域的 SPF、DKIM 与 DMARC。

检测只返回状态，不显示任何密钥。完成后使用“发送测试邮件”验证真实投递链路。后台的“已发送”准确含义是“邮件已被当前传输层或 Postfix 队列接受”，最终是否进入收件箱仍取决于公网 25 端口、PTR、DNS 身份、目标服务商策略和 IP 信誉。

Cloud Mail 的“测试连接”会携带已创建的集成令牌调用主站 `/_longos/mail-relay/health`。返回健康状态才表示应用网关、中继和 Postfix 当前可用；接口不会泄露队列内容、地址或密钥。

## 租户和分类发件身份

LONGOS 后台可分别设置默认、订单、账户、退货/客服和营销五类发件人，每类都支持独立的 `From`、显示名和 `Reply-To`。业务分类留空时只会继承本租户默认身份。

使用共享平台签名域时，普通租户必须使用 `<purpose>+<site-code>@<platform-domain>`，例如 `orders+shop-a@mail.example.com`。该约束在设置保存时和真正投递时各校验一次，不能使用其他租户或平台的地址。

默认使用平台签名域，因此租户店铺域名变更时不需重建邮件服务，也不需修改 `MAIL_RELAY_*` 变量。

只有选择使用租户独立发件域时，才必须同时满足：

- 运营者已将它加入 `MAIL_RELAY_DOMAINS` 和 `MAIL_RELAY_ALLOWED_SENDER_DOMAINS`，重建中继并发布新的 DKIM 记录。
- LONGOS 主站管理员已在“租户站点”中将该已签名发件域唯一分配给对应租户。

网站访问域与邮件发件域是两类独立资源：店铺可使用 `www.example.com`，同时使用 `orders@example.com` 发信。店铺换域名不会自动改写发件地址，避免已验证的邮件身份突然失效。如果要同时换发件域，先完成 DNS/DKIM 和邮局签名，再由主站管理员分配给租户，最后进入该租户的“系统设置 → 邮件设置”修改默认或分类发件邮箱。界面会只列出“邮局已签名”且“已分配给当前租户”的域名，保存和实际投递时都会再次校验。整个过程不需要为子站创建或更换 Cloud Mail 集成令牌。

升级时安装流程会自动创建 `saas_mail_sender_domains` 分配表，并尽可能迁移旧版中能明确匹配到租户的已签名域。无法安全推断归属的域名不会自动授权，需在主站“租户站点”中分配一次。

## 弃单邮件召回

后台“订单 → 邮件召回订单”只列出超过等待时间且仍处于 `unpaid` 的订单。租户可以在同一页面配置等待时间、发送冷却期、每单发送上限、私密链接有效期和纯文本模板，并可在发送时选择一个当前有效的折扣码。

- 不附带折扣码时使用“订单”发件身份；附带折扣码时使用“营销”发件身份；
- 每次投递均写入独立召回记录和通用邮件日志，使用稳定投递 ID，并在发送中、失败保护期、冷却期和次数上限内拒绝重复操作；
- 召回访问令牌只以 SHA-256 哈希保存，邮件中的链接使用限时相对签名；SaaS 模式优先使用平台分配的租户域名，因此更换自定义主域名不会使有效期内的链接失效；
- 折扣码不会直接改写已生成订单的金额。顾客确认恢复时，系统在一个数据库事务内取消原未支付订单、释放它的库存预留、恢复仍有库存的商品，并把仍有效的折扣码写入新结账购物车；任一步骤失败都会整体回滚；
- 顾客可以关闭该订单后续提醒；系统同时记录打开、恢复购物车和新订单转化时间。

升级现有安装时，Coolify 部署入口会自动执行迁移，并把 `abandoned_order_recoveries` 纳入数据库就绪检查；无需运营者补执行 Artisan 命令。

## 无法由网站代码代办的基础设施

以下两项由服务器运营商控制，安装器无法越权修改：

- 开放出站 TCP 25；
- 将服务器公网 IP 的 PTR/rDNS 设置为 `MAIL_RELAY_HOSTNAME`。

如果提供了可写 Cloudflare Token，A、DKIM 和缺失的 SPF/DMARC 会自动发布。未提供 Token、关闭自动发布或使用其他 DNS 服务商时，在 LONGOS 后台邮件诊断页复制生成的 DKIM、SPF 和 DMARC 记录并手工发布。

建议配置：

```text
mailout.example.com  A     <服务器公网 IPv4>                 # DNS Only
example.com          TXT   "v=spf1 ip4:<服务器公网 IPv4> -all"
_dmarc.example.com   TXT   "v=DMARC1; p=none; rua=mailto:dmarc@example.com"
```

## 安全边界

- SMTP 端口不映射、不对其他容器开放；Postfix 仅监听中继容器 loopback；
- Cloud Mail 公网请求使用可撤销的 Bearer 集成令牌，按 `cloud-mail:send` / `cloud-mail:health` 最小权限鉴权；LONGOS 网关验证租户发件域后，再使用 HMAC-SHA256、时钟窗口和一次性 nonce 访问私有中继；
- 对发件域、租户身份、单个发件人速率、附件大小和 To/Cc/Bcc 总人数进行限制；
- 队列通知的中继失败会最多尝试 5 次并延迟重试，同一通知使用稳定投递 ID，避免“已接收但响应丢失”导致重复邮件；
- 已接收投递的去重回执默认最长保留 24 小时，并保存在独立 Docker 持久卷中；网站或邮件容器重启后仍会复用回执。活跃回执达到 10 万条上限时会失败关闭地暂停新投递，不会提前删除去重记录而造成重复发信；
- DKIM 私钥、Postfix 队列和去重回执分别保存在 Docker 持久卷中。

## 邮件审计、重试与自动清理

LONGOS 会把同一队列通知的所有尝试合并到同一个稳定投递 ID，并记录尝试次数。中继也会把“客户端身份 + 投递 ID + 完整请求摘要”作为去重键：相同内容的网络重试返回原回执，不会再次提交；同一 ID 携带不同内容会被拒绝，避免错误覆盖旧邮件。

在邮件设置中可将“邮件日志保留天数”设为 1–365 天，默认 30 天。调度器每天自动：

1. 将超过 24 小时仍未获得传输层确认的 `pending` 记录标记为失败；
2. 删除超过保留期的已发送/失败记录及其正文、链接和附件元数据；
3. 保留仍可能在投递中的记录，后台手工清理也不会删除 `pending` 记录。

SaaS 模式的生产调度由独立 `scheduler` 容器逐租户执行清理，不要另外为每个子站创建系统 Cron。保留天数可直接在后台修改。

## 常见故障定位

- 后台诊断显示“无法连接内置中继”：在 Coolify 检查 `mail-relay` 是否健康、应用与中继是否同属私有 `longos` 网络，以及 `MAIL_RELAY_ENDPOINT=http://mail-relay:3000`。
- 中继健康但外部收不到：先确认云服务器允许出站 TCP 25，并让服务商把公网 IP 的 PTR/rDNS 设置成 `MAIL_RELAY_HOSTNAME`。
- DKIM 失败：重新读取 `/var/lib/dkim` 中生成的 TXT，完整发布到 `<selector>._domainkey.<domain>`；长公钥在 DNS 控制台可能被分段展示，这是正常的。
- Cloudflare 用户：邮件主机 A/AAAA 必须使用“仅 DNS”，不能开橙色云；LONGOS 网站域名仍可使用橙色云和 Full (strict)。Cloudflare 不会代理服务器到收件方的 SMTP 投递。
- 只有队列邮件不发送：检查 `worker` 容器和 `failed_jobs`；修复后重试失败任务时会复用稳定投递 ID，已被中继接受的邮件不会重复提交。
- 邮件状态长期为 `pending`：检查 worker/中继日志与调度器；超过 24 小时的中断记录会由每日清理任务改为失败。
