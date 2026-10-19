# 多渠道像素与服务端追踪

本插件在 **您的 LONGOS 应用服务器** 上直接调用 Meta Conversions API 和 TikTok Events API；不会把事件转发到本插件作者或其他追踪中转服务器。它实现了类似 Parkour Pixel 的核心能力：多像素配置、浏览器像素 + 服务端事件、UTM/点击 ID 保留、订单购买服务端回传，以及同一 `event_id` 的浏览器/服务端去重。

## 配置与启用

1. 在后台插件页安装并启用本插件。
2. 在插件设置中填写一个或多个 Meta 和 TikTok 配置（每个渠道最多 10 个）。JSON 示例：

```json
[{"pixel_id":"123456789012345","access_token":"EAAB...","api_version":"v26.0"}]
```

```json
[{"pixel_code":"C123ABC456DEF","access_token":"your_tiktok_events_api_token"}]
```

3. 需要测试 Meta CAPI 时，在 Meta 配置中加上 `"test_event_code":"TEST123"`。
4. 启用浏览器像素与服务端追踪。支付状态变为 `paid` 时，网站服务器会自动发送 `Purchase`；浏览器成功页使用相同 `purchase_{order_id}` 事件 ID，以便渠道进行去重。
5. 如使用 Cookie 同意横幅，启用“要求访客同意后才追踪”，并将横幅成功后的 Cookie 名称和值设置为 `tracking_consent=1`（名称可在插件配置修改）。
6. 配置页会显示每个服务端通道的最近结果、成功/失败次数与连续失败次数。HTTP 200 不再直接视为成功，Meta 错误体和 TikTok 非零响应码都会明确显示为失败。
7. 生产环境保持 LONGOS 队列 Worker 运行。服务端事件会进入租户感知队列，失败后按 1、5、15 分钟退避重试；Docker 部署内置的 `worker` 服务会处理这些任务。使用同步队列的本地环境会在响应结束后执行作为兼容回退。

订单创建时会保存该订单的授权与点击归因快照，PayPal、Stripe 等异步支付回调也能使用原结账访客的 `fbclid`、`ttclid`、`_fbp`、`_fbc` 和 `_ttp`。商品浏览、加购和购买统一使用商品 ID，事件金额统一换算为订单或访客当前币种，避免广告目录匹配和 ROAS 统计偏差。

## 已追踪事件

- `PageView`：所有页面
- `ViewContent`：商品详情页
- `AddToCart`：购物车新增请求成功（失败的 HTTP 请求不会计入）
- `InitiateCheckout`：访问结账链接
- `Purchase`：支付状态变为 `paid`（服务器端）及成功页（浏览器端）
- `Search`：带搜索关键词的搜索结果页
- `CompleteRegistration`：客户账号真实创建后（仅由服务端账号钩子发送）

## 服务端与隐私

- 服务端事件会附带访问 IP、User-Agent、页面 URL、UTM、`fbclid`/`ttclid` 及渠道所需的事件数据。TikTok 商品和搜索参数使用 `content_ids` 与 `search_string`。
- 浏览器像素与服务端使用相同 `event_id`。服务端成功投递后还会在应用端保留 72 小时去重标记，阻止支付回调或请求重试造成重复 API 投递。
- 公开浏览器端点只接受浏览、加购、结账和搜索事件；购买与注册只能由 LONGOS 的真实订单/账号钩子产生。
- “高级匹配”关闭时，不会提交客户邮箱或电话；开启时，插件仅发送 SHA-256 规范化哈希值。
- 浏览器像素开关与服务端追踪开关彼此独立：可关闭前端脚本而继续发送订单等权威服务端事件。关闭插件或服务端追踪后，尚未执行的队列事件会在处理时停止发送。
- Meta/TikTok API 凭证使用 LONGOS 的 Laravel 应用密钥加密保存，绝不会输出到店铺 HTML；浏览器只获取像素 ID。升级迁移会将每个租户的旧版明文令牌自动转换为密文，也可在下次保存设置时兼容转换。订单归因和浏览器标识快照也使用同一应用密钥加密落库。
- 队列任务中的邮箱、电话、IP、点击 ID 与 Cookie 标识同样使用 Laravel 应用密钥整体加密，中央 `jobs` 表不会保存可读的访客上下文。提供商错误日志只保留状态和经过清理的简短消息，不写入事件载荷或访问令牌。
- 请在启用前更新隐私政策，并确保满足适用的 Cookie、广告与数据保护法规。启用同意模式时，未同意之前不会加载渠道脚本，也不会保存 UTM、点击 ID 或浏览器标识。同意组件可在放行后触发 `longos:tracking-consent-granted` 或 `longos:consent-updated` 事件，无需刷新页面。若后续撤回同意，LONGOS 会停止新事件、清空待发队列和会话归因标识，并通知 Meta/TikTok 停止 Cookie。

## 免费说明

插件不要求订阅、网关、代理服务器或额外 SaaS。Meta 与 TikTok 的广告账户、像素和 API 访问令牌由商家自行申请，且平台可能自行调整其 API、配额或条款。
