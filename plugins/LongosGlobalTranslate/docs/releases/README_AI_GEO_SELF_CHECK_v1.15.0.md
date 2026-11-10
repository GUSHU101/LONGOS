# LONGOS 全球翻译 v1.15.0：AI 自检与 IP 本地化完善

## 目标

本版本强化 AI 模型译文的发布前自检，并让 storefront 的国家识别直接复用 LONGOS 已认证的 Coolify Caddy / Cloudflare 信任边界。用户手动语言选择仍然优先；任何未发布语言都不会因 IP 或浏览器提示被展示。

## AI 翻译优化

- SiliconFlow 请求携带 `X-Trace-Id`，响应正文没有请求 ID 时使用官方 `x-siliconcloud-trace-id`，便于在不记录原文或密钥的情况下排查请求。
- 系统提示要求模型在输出前静默核对目标语言、完整性、术语及源文回显，并继续只允许纯译文响应。
- 本地质量门禁新增模型解释前缀、Markdown/JSON 包装、短文本目标文字缺失和大段源语言残留检测。
- 机器译文保存时检查一次；自动审核发布前按当前规则再次检查一次。旧版本留下的 `quality_code = null` 译文也不能绕过新规则。
- 新发现的问题会转为 `needs_review`、写入质量代码和版本历史，不会进入自动发布词典，也不会自动重发已计费请求。

## IP 自动本地化

- `CF-IPCountry` 只在 LONGOS `TrustProxies` 已验证 Cloudflare/Caddy 边缘属性，或直接 TCP 对端属于共享 Cloudflare IP 范围时使用。
- Coolify Compose 显式传递 `CURRENCY_TRUST_CDN_COUNTRY_HEADERS`，并提供可选的持久化 GeoLite2 路径。
- 解析顺序保持为：访客手动选择 → 可信国家规则/国家建议 → 浏览器语言回退 → 英语源语言。
- 国家建议、显式国家规则和浏览器语言都只能选择“已启用且存在非空已发布词典”的语言。
- 后台自检会报告国家识别是否就绪；无法验证 Cloudflare 国家头且没有可用 GeoLite2 时给出可操作提示。

## 验证重点

1. 伪造 `CF-IPCountry` 且没有可信边缘属性时必须被拒绝。
2. Coolify Caddy 内网对端带有 LONGOS 已验证属性时必须识别国家。
3. 已启用但未发布的地区语言必须回退英语；发布后才能自动切换。
4. AI 返回解释、JSON 包装、目标文字缺失或大段英文残留时必须进入人工审核。
5. 自动发布必须重新检查历史机器译文，不能只信任旧的空质量代码。

完整上线验收仍需在预发布站使用真实服务商测试密钥、数据库队列 worker、Cloudflare 访客位置头和至少两个国家出口进行端到端验证。
