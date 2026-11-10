# LONGOS 全局翻译 v1.13.0：Cloudflare 国家识别自动本地化

版本：`v1.13.0`；适用于 LONGOS SaaS 多子站翻译插件。本文对应真实代码改动，不代表已经在你的生产服务器上部署或通过 PHP/Laravel/MySQL 联调。

## 一、已实现的行为

前端首次加载时，通过当前子站的动态 `/global-translate/config` 接口决定语言。该接口不使用跨访客共享的 HTML 缓存，现有逻辑已返回 `Cache-Control: private, no-store`。国家识别顺序：

1. **已明确手选的语言**：访客的站点专属安全 Cookie 优先于国家识别；选择 `自动` 后才恢复自动定位。
2. **Cloudflare**：直接来源经校验后，使用 `CF-IPCountry` 识别访客国家。
3. **LONGOS 原有可信定位**：Cloudflare 缺失或无效时回退到已有 `VisitorCurrencyService::countryCode()`。
4. **离线 GeoIP2**：有配置有效的本地数据库时作为下一道回退。
5. **语言决策**：优先使用当前子站有效且启用的国家→语言规则，再根据多语言国家的浏览器语言提示及内置国家语言目录选择。**仅允许切换到该子站已发布且有内容的翻译库**；没有可用翻译库不会强行切换。
6. **浏览器提示与原始语言**：按子站配置的 `accept_language_enabled` 选择或回退至源语言。每个子站的规则、翻译库和启用语言相互独立。

本版本不会翻译 SEO 元数据。现有装修模块翻译只针对正在发布/调用的前台模块；后续新增模块须经原有扫描、翻译、发布流程才成为可用译文。

## 二、Cloudflare 控制台设置

1. 子站域名的 DNS 记录启用 Cloudflare **代理（橙色云）**；DNS-only 不会附带可信的 Cloudflare 边缘头。
2. 在对应 Zone 的 **Rules → Transform Rules → Managed Transforms** 启用 **Add visitor location headers**。Cloudflare 文档也提到，在启用 IP Geolocation 的部分配置下，`CF-IPCountry` 可能已可用；以实际源站收到的请求头为准。
3. 确认网站的 `/global-translate/config` 路由能够收到 `CF-IPCountry: US`、`DE` 等二位国家码，且该路由响应不被 Cloudflare Cache Rules 或其他 CDN 缓存为公共结果。
4. 确保目标语言至少有一批已经成功发布的译文，设置中已启用自动识别，且目标国家有对应的已启用语言/规则。
5. 强烈建议源站防火墙仅允许 Cloudflare 网络来源，或使用 Cloudflare Tunnel / Authenticated Origin Pulls 限制源站直连。

官方资料：

- 头字段定义、`XX` 与 `T1` 特殊值：<https://developers.cloudflare.com/fundamentals/reference/http-headers/#cf-ipcountry>
- Managed Transforms：<https://developers.cloudflare.com/rules/transform/managed-transforms/reference/>
- Cloudflare IP 段与源站访问控制：<https://developers.cloudflare.com/fundamentals/concepts/cloudflare-ip-addresses/>
- IPv4 当前段：<https://www.cloudflare.com/ips-v4>
- IPv6 当前段：<https://www.cloudflare.com/ips-v6>

## 三、关键安全原则

`CF-IPCountry` 是普通 HTTP 头，**仅有这个头不能证明请求经过 Cloudflare**。插件新增 `Services/CloudflareVisitorCountry.php`：

- 首先读取服务器真实直接连接地址 `REMOTE_ADDR`，与 Cloudflare 官方 IPv4 / IPv6 Origin IP 段比对；**不能**改用 `$request->ip()`、`X-Forwarded-For`、`CF-Connecting-IP` 判断来源，因为这些是访客地址或可能经代理改写。
- 只接受有效的双字母国家码；`XX`（未知）、`T1`（Tor）、`EU`、`AP`、`ZZ` 不作为有效国家自动匹配。
- 如果 Cloudflare 发布了新 IP 段，需要在插件 `EDGE_CIDRS` 同步更新。未匹配到的边缘来源会**安全回退**，不会为了可用性盲信请求头。
- 通过 Cloudflare 之后又经过**自有且受控**的反向代理时，可在 LONGOS 应用配置中同时设置：

```php
// 示例：写入 LONGOS 加载的 config/longos.php 的 translation 段；
// 不是让商家在网页表单里输入。替换为真实受控代理源地址。
'cloudflare_country_enabled' => true, // 默认 true
'cloudflare_proxy_headers_sanitized' => true, // 默认 false
'cloudflare_trusted_proxy_cidrs' => [
    '10.20.30.5/32', // 示例：必须替换为你实际的受控入口代理
],
```

**仅当你的入口代理从可信 Cloudflare 请求取得该国家头，并在所有其他入站请求上彻底移除伪造的 `CF-IPCountry` 时才能开启上述代理信任配置。** 不要信任全部私网、全部本机 IP 或任意客户端指定的头。若 Nginx/Apache 的 Real-IP 功能已经把 `REMOTE_ADDR` 改成访客 IP，应先检查真实源连接链路，不能直接关闭来源验证来“修复”。

`cloudflare_country_enabled: false` 会完全跳过 Cloudflare 国家头，仍可使用原有 LONGOS 与离线 GeoIP 逻辑。

## 四、前端判断与故障定位

未手选语言的访客访问 `/global-translate/config`（具体前缀以 LONGOS 自身 shop route 为准），查看：

| 字段 | 含义 |
| --- | --- |
| `country` | 检测到的国家，未识别时为 `null`；若手动锁定语言也可能为 `null` |
| `locale` | 最终前台展示语言 |
| `locale_source` | `explicit` 手动、`geo_rule` 自定义国家规则、`geo_browser` 多语言国家结合浏览器、`geo_catalog` 国家语言目录、`accept_language` 浏览器偏好、`default` 源语言 |
| `published_locales` | 本子站真正可用的已发布语言 |
| `widget_visible` | 是否显示语言切换浮窗，**与自动识别是否启用相互独立** |

排查顺序：

1. `locale_source=explicit`：访客手选过语言，先在浮窗选“自动”。
2. `country=null`：检查 Cloudflare Managed Transform、应用是否真的由 CF IP 直接连接、Real-IP/多级反向代理、已有 LONGOS 定位回退以及可选 GeoIP2 配置。
3. `country=DE`，但仍为英文：确认德语已开启且**发布了实际翻译库**，并且没有自定义规则指向未发布语言。
4. 语言正确但页面未翻译：检查正在调用的装修模块/商品内容是否被发现并包含在已发布翻译字典；新增组件应重新扫描并翻译发布。
5. 不同国家获得相同语言：检查 CDN 是否缓存 `/config`、游客 Cookie、浏览器偏好与当前子站发布情况。

## 五、自动验证与上线回归

在有 Node 的环境中执行：

```bash
node LongosGlobalTranslate/Tests/verify_source_contracts.mjs
node --check LongosGlobalTranslate/Static/public/widget.js
```

在具备 PHP 8.2+ 的 LONGOS 主机上补充：

```bash
php -l LongosGlobalTranslate/Services/CloudflareVisitorCountry.php
php -l LongosGlobalTranslate/Services/PresentationLocaleResolver.php
php LongosGlobalTranslate/Tests/verify_cloudflare_country_contract.php
```

上线前还必须验证：同一子站两个实际来源国家、手选 Cookie 优先、删除翻译库后回退、关闭浮窗但保留自动本地化、多子站隔离、Cloudflare 特殊国家码、伪造国家头直接请求、缓存不串游客、不同浏览器和移动端动态模块翻译。没有生产源站权限时无法替你确认这些结果。

## 六、范围与交付

本次修改文件：

- 新增 `Services/CloudflareVisitorCountry.php`
- 修改 `Services/PresentationLocaleResolver.php`
- 更新 `Services/StorefrontPresentationConfig.php`、`config.json`、`Static/public/widget.js`、`Views/shop/widget.blade.php` 的版本与配置说明
- 修改 `Tests/verify_source_contracts.mjs`
- 新增 `Tests/verify_cloudflare_country_contract.php`
- 本文档

不新增数据库迁移，不改支付和订单核心逻辑，不改变硅基流动服务商配置或计费逻辑。请先在测试环境部署，验证后再应用于生产多子站。
