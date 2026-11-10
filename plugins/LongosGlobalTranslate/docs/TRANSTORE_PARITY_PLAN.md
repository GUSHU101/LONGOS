# LONGOS 翻译插件与 Transtore：功能差距及落地顺序

更新：2026-11-10。对照来源：[Transtore 官方](https://transtore.app/)、[Shopify App Store](https://apps.shopify.com/transtore-language-and-currency)、[Transtore 自动/人工翻译说明](https://help.transtore.app/faq/language/auto-translation-vs-manual-translation/)。这是一份**功能对照与实施建议**，不是复制 Transtore 源码，也不代表获得了 Transtore API/SDK 授权。

## 接入判断

Transtore 是 Shopify 应用：它的人工译文持久化使用 Shopify Native Translation API，官方常规 Auto Translation 使用 Google Translate API，AI 优化支持 GPT / DeepSeek。公开材料未证实存在可供 LONGOS 直接安装或复用的独立 SaaS API；未经协议或授权不应复制私有服务实现。LONGOS 建议继续维护本地 `LongosGlobalTranslate`，保留硅基流动免费模型白名单和翻译库。

## 功能矩阵

| 功能 | Transtore 对外介绍 | LONGOS v1.18.1 现状 | 顺序 |
|---|---|---|---|
| 商品/分类/页面批量翻译 | 支持 | 支持公开内容扫描、队列翻译、审核/发布 | 保持、实测 |
| AI 引擎 | GPT/DeepSeek 可优化翻译 | 免费白名单 `tencent/Hunyuan-MT-7B`、`XingChenAGI/Xing4.0-29B` | 优先稳定性与质量评估 |
| 源站新内容自动同步 | 支持 | 已有源哈希增量扫描，仍由管理员发起任务 | P1：审计触发器后实现安全增量事件队列 |
| 翻译记忆、术语/品牌词、人工编辑 | 支持 | 已有翻译记忆、受保护术语与人工审核锁定 | 保持、实测 |
| 国家/语言自动定位 | 支持 | 受信任 Cloudflare 国家及 GeoLite2、浏览器语言、手动优先 | 保持、实测 |
| 多语言 SEO、URL、alt、图片替换 | 支持 | **明确排除** SEO/meta/OG/URL/alt、图片翻译 | P2：需单独设计可索引 SSR 合约、canonical/hreflang、迁移与回滚 |
| 多货币/本地价格 | 支持 | LONGOS 主站已有独立货币服务，不属于翻译插件 | P2：验证展示/结算一致性，禁止前端改写支付金额 |
| 第三方组件翻译 | 支持多种 Shopify app | 支持受控 commerce UI 与已发布 Builder 文本；不扫描未知私有组件 | P2：仅通过显式适配器扩展白名单 |
| RTL/组件与浮窗定制 | 支持 | 前端浮窗、手动切换可用；完整 RTL 测试待补 | P1：移动端、键盘、RTL 与隐藏浮窗回归 |
| 独立多租户共用免费模型账号 | Shopify 场景 | v1.18.1 控制面集中 RPM/TPM、429 冷却 | **P0：先验证上线** |

## P0：禁止带风险上线

1. 在独立的两个租户数据库分别保存同一官方 SiliconFlow 账户、不同 API Key，但使用同一计费身份标签，确认每模型 RPM/TPM 的全局窗口相加不超过实际限额。
2. 模拟 429、连接失败、超时、取消、暂停及 worker 重启：确认没有重复发送、不明费用仍留账本、恢复不会突破速率门禁。
3. 对多个店铺做公开词典隔离、原文变更失效、Builder 发布/撤回、Cloudflare 伪造头、移动端浮窗、隐藏浮窗自动定位测试。
4. 验证生产中央连接迁移、权限、隔离及 Coolify 自动升级/回滚。验收前不应自动打开大规模批量翻译。

## P1：提升实际翻译体验

- 增量内容队列：只在商品/分类/页面/Builder **正式发布**后生成候选差异，采用短期去重、可手动暂停，不允许请求链路直接调用模型。
- 提供“首次扫描 / 翻译 / 质量复核 / 发布”四阶段进度，并列出失败项、缺译项、模型选择、最近 429 冷却及可重试条件。
- 建立多语言基准测试集，覆盖真实商品描述、手机壳型号、长短文案、占位符、数字、RTL；质量不合格一律进入人工审核。
- 前端仅从已发布词典读取并安全回退英文；减少首屏切换闪烁、避免重复请求与语言混杂。

## P2：新增功能的安全条件

多语言 SEO 必须是一个**独立可回滚**的模块：发布后生成服务端可索引的独立语言版本、`hreflang`、正确的 canonical、sitemap 和 slug 映射；保留旧 URL 301 规则与审计。绝不把随机浏览器浮窗译文当成搜索引擎索引页。图片 alt、本地化促销图与货币价格都需要各自的可信数据源、审核和缓存失效策略。

**截至本次变更**：已实现 P0 的“跨租户中央限流”代码及测试用例；没有凭真实 API Key 调用，也没有运行 PHP/Laravel/生产数据库的完整验收。其余 P1/P2 是后续阶段，不属于已交付功能。
