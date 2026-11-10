# LONGOS Global Translation

LONGOS 的租户隔离型店面翻译与本地化插件。当前版本为 **v1.16.0**。

## 主要能力

- 扫描公开商品、分类、页面、页面分类、已发布 Builder 内容、店面 chrome 与受控商务界面文本。
- 使用不可变发布快照、版本化审核、人工锁定和回滚，避免草稿或过期原文进入店面。
- 支持阿里云、火山引擎、百度、DeepL、Google Cloud、Azure Translator 与硅基流动。
- 免费额度优先调度，持久化记录 `reserved`、`sending`、`committed`、`definite_failed` 与 `uncertain`，不盲目重试结果不明的请求。
- 通过专用队列、多 worker 领取令牌、任务暂停/恢复和维护互斥锁安全执行批量翻译。
- 提供受保护术语、翻译记忆、质量门禁、批量维护、自动发布与可操作诊断。
- 可按受信任的 Cloudflare 访客国家或离线 GeoLite2 规则选择展示语言，同时保留访客手动切换权。

## 安全边界

- 源语言固定为英语；所有数据使用当前租户连接，不接受前端传入的租户标识。
- 店面 GET 请求只读取配置和已发布词典，绝不触发外部翻译或计费。
- 密钥加密保存，API 响应和店面配置不返回密钥；服务商验证必须由管理员显式发起。
- 订单、结账、支付、账户、聊天、评价及其他交易性或私密内容不进入自动扫描范围。
- 只有明确证明“尚未发送”的失败才可重试；发送结果不确定时保留额度占用并交由人工核对。

## 运维

插件随 LONGOS 应用镜像发布。生产环境仅通过 Git + Coolify 的正常部署流程升级；入口程序会在共享部署锁内执行所需迁移和静态资源发布，不要在生产容器内手动运行 Composer、npm 或迁移。

常用无凭据验证命令：

```powershell
php vendor/bin/phpunit tests/Unit/GlobalTranslationConfigurationTest.php tests/Unit/GlobalTranslationProviderTest.php tests/Unit/GlobalTranslationTextTest.php tests/Unit/GlobalTranslationWidgetSafetyTest.php tests/Feature/GlobalTranslationWorkflowTest.php
php plugins/LongosGlobalTranslate/Tests/verify_builder_display_contract.php
php plugins/LongosGlobalTranslate/Tests/verify_cloudflare_country_contract.php
node plugins/LongosGlobalTranslate/Tests/verify_source_contracts.mjs
node --check plugins/LongosGlobalTranslate/Static/public/widget.js
```

历史版本说明已归档到 [`docs/releases`](docs/releases/README.md)，完整实现与回滚审计见 [`docs/translation-plugin-audit.md`](../../docs/translation-plugin-audit.md)。
