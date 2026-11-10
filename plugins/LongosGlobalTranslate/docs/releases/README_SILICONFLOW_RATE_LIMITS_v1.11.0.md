# LONGOS 全站翻译插件 v1.11.0 — 硅基流动 RPM / TPM 深度优化

## 适用范围

基于 v1.10.0 完整插件源码进行增量升级；原有翻译库批量删除、服务商归档删除、暂停/取消任务、按公开页面实际调用的装修模块增量翻译、跳过 SEO 元数据等功能保持不变。

**本次重点**：硅基流动多个免费模型的分钟速率配置、跨 worker 限流、429 冷却、原地续跑，不自动切换任何付费模型。

## 平台用量等级（用户提供的模型截图，2026-11-09）

| 用量等级 | 最高 RPM（请求/分钟） | 最高 TPM（Token/分钟） |
| --- | ---: | ---: |
| L0 | 1,000 | 80,000 |
| L1 | 1,200 | 120,000 |
| L2 | 2,000 | 160,000 |
| L3 | 4,000 | 320,000 |
| L4 | 8,000 | 1,000,000 |
| L5 | 10,000 | 5,000,000 |

**重要说明**：上述表格来自一个模型页面。硅基流动官方解释 RPM、TPM 以**账户 + 模型**计算，免费模型的限流通常是固定值，并非账户升级 L1–L5 后所有免费模型就自动提额。务必查看**所选模型**的控制台「Rate Limits」页面，按实值设置。官方参考：https://docs.siliconflow.cn/docs/userguide/faqs/rate-limit-and-upgradation

## 实际落地能力

1. **双层限速**：原有单账户 QPS（每秒 1–10）仍保留；新增硅基流动 RPM/TPM（L0 默认 1000/80000）每模型独立配置。系统并不承诺 QPS=1 时能达到 1000 RPM；每秒限速更严格时优先执行。
2. **跨进程共享计数**：新增 `lgt_siliconflow_rate_models`（模型级互斥锁和冷却状态）、`lgt_siliconflow_rate_calls`（最近 60 秒请求/Token 占用），通过数据库行锁串行化并发派发。在**同一子站数据库**下多个 Key 调用同一模型共用额度；不同模型各自计算。
3. **请求发出前占用，响应后结算**：将系统提示词字节上界 + 用户文本字节上界 + `max_tokens` + 安全余量作为保守预留；成功或明确响应包含 `usage.total_tokens` 时改为实际值。完全没有发送的预留会退回；网络超时/结果不确定时保留占用，避免激进重放。
4. **动态控制输出长度**：短文本不再一律申请 `max_tokens=4096`，按文本字节长度设定 192–4096，减少过度预留。仍保留 `finish_reason=stop` 检测，避免把截断输出写入翻译库。
5. **429 冷却**：支持 `Retry-After`（秒）与指数退避；同模型其它 worker 在冷却期停止派发。客户端出现 429 被视作确定未翻译成功，允许故障转移至另一个已验证、具备可用额度的服务商。
6. **暂停后延时续跑**：若所有可用服务商仅因当前硅基流动分钟限额被挡住，任务暂停为 `SILICONFLOW_RATE_WINDOW`，排入一个延迟恢复任务，冷却后继续处理剩余任务。旧延迟唤醒带随机 nonce，无法误唤醒后来被操作员再次暂停或重新创建的任务。
7. **零重复账单安全优先**：同一字段已完成前半段而后一段遭到限速时，仍不会自动从字段开头重试（避免重复调用）；该条进入人工核对的失败状态。后台的单独账户验证请求也受相同 RPM/TPM 限制。
8. **后台保持简洁**：仅选择「硅基流动」时显示两个数字输入：RPM、TPM；新增、编辑账户沿用已有流程，其它服务商不显示无关字段。不新增复杂的用量等级向导。

## 安装和迁移

1. 在**测试/预发布环境**备份原插件、站点数据库及任务队列状态。升级涉及新增表和字段，请先确保运行中的旧 worker 已平稳退出，不要清除队列中有可能已发送的任务。
2. 用完整 `LongosGlobalTranslate/` 文件夹替换旧版，并按 LONGOS 平台标准部署方式执行**插件数据库迁移**：`2026_11_09_000011_add_siliconflow_rate_pacing.php`。
3. 确认 `lgt_provider_accounts.rpm_limit/tpm_limit` 存在，并能查到新表 `lgt_siliconflow_rate_models`、`lgt_siliconflow_rate_calls`。插件的 `TranslationSchema` 将缺表/缺字段视为未就绪而阻止发出请求。
4. 后台进入「翻译服务商」→ 编辑硅基流动账户，按实际模型页面填写 RPM/TPM；默认采用截图的 L0 值 1000/80000。密钥不需要重新输入；只修改速率不清除验证状态。
5. 在模型控制台再次核对免费价格；对一个小范围的公开页面执行翻译，观察任务进度、翻译库、响应质量与 429 冷却日志。
6. 开启队列延迟任务支持；检查后台 worker 的 `longos.translation.queue` 队列配置。没有正常运行的延迟队列，暂停任务不能自动按时恢复。

## 建议测试矩阵

| 场景 | 预期行为 |
| --- | --- |
| L0 同模型多个 Key、4 个 worker 并发 | 同一子站不突破 1000 RPM / 80000 TPM 的本地配置阈值；实际通常受 QPS 进一步约束 |
| 不同硅基流动模型同时翻译 | 各自独立记录窗口，仍只能选择已核实免费模型 |
| 将 TPM 临时设很低（如 1） | 不发出请求；任务进入 `SILICONFLOW_RATE_WINDOW`；修正配置后可继续 |
| 429 带 Retry-After | 模型进入冷却，任务退避，冷却后恢复，不会高速重试 |
| API 返回 `usage.total_tokens` | 数据库记录实际 token；没有 usage 则保留预留值 |
| HTTP 超时或断连未知 | 用量留存为保守占用，不自动重发不确定结果 |
| 人工取消/暂停后旧延迟唤醒到期 | 不唤醒已取消/其它原因暂停的任务 |
| 商品字段、正在使用的装修模块、SEO 字段 | 维持原有扫描规则；只翻译公开前端显示字段，跳过 SEO 元数据 |

## 已知边界与上线前必须验证的事项

- **跨租户/多服务器账号**：当前插件每个 SaaS 子站的数据独立。若多个子站使用同一个外部硅基流动账号，单子站数据库不能感知其它子站占用；需要 LONGOS 平台级、以外部账号标识和模型为键的统一中央限流，才能做到真正跨子站共享。不能仅凭本插件声称全平台绝不超额。
- **Token 预留是上界近似值，不是官方 tokenizer**：先用 UTF-8 字节上界和输出上限作保守预算，再用 API usage 结算，个别模型特殊 tokenizer、额外隐含 tokens 可能带来偏差。官方返回 429 时，仍以冷却机制作为兜底。
- **不能保证模型持续免费**：当前版的免费模型是受控白名单，官方价格发生变动时需人工核实并更新白名单，停止派发有疑问的模型；不会自动降级到付费 API。
- **没有在真实 LONGOS 服务器进行数据库/队列并发联调**：开发环境没有 PHP CLI 与 Laravel 宿主。已执行 Node 源码契约检查和内嵌后台 JS 语法检查；完整 PHP 语法校验、迁移、并发事务死锁测试、真实 API 429 模拟、队列延迟行为需要你在预发布环境执行后才能宣布生产可用。

## 核心变更文件

- `Services/SiliconFlowRateLimiter.php`：分钟级共享速率闸门、用量结算、429 冷却。
- `Services/TranslationJobService.php`、`Jobs/ResumeSiliconFlowTranslationJob.php`：派发边界、延迟续跑、跨暂停 nonce 校验。
- `Services/ProviderVerificationService.php`：账户验证时也受分钟额度限制。
- `Providers/SiliconFlowProvider.php`、`Providers/TranslationProviderResponse.php`、`Providers/AbstractTranslationProvider.php`：动态输出预算、usage 与 Retry-After 元数据。
- `Migrations/2026_11_09_000011_add_siliconflow_rate_pacing.php`、`Services/TranslationSchema.php`：数据模型及迁移就绪门禁。
- `Services/ProviderAccountService.php`、`Controllers/AdminTranslationController.php`、`Views/admin/index.blade.php`：后台 RPM/TPM 配置与校验。
