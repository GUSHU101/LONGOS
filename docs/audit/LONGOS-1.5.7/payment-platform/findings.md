# LONGOS v1.5.7 平台代收结账恢复审计

基线：`8ab7ff4`  
范围：订单确认后至 PayPal/Stripe 服务商交接，以及服务商返回后的未付款恢复界面。  
依据：`AGENTS.md` 与《LONGOS v1.5.7 Codex Stability Security Remediation Plan》第 3.3、12、13 节。

## LPP-UX-01：未知支付结果被前端标记为失败并尝试恢复购物车

- 严重度：P1
- 证据：代码已证实
- 代码证据：
  - `CheckoutController::confirmationResponse()` 曾把 `payment_recovery_url` 固定为 `payment_status=error` 的结果页。
  - `themes/default/checkout.blade.php` 在订单已经创建后，只要服务商初始化抛出异常，就无条件跳转该地址。
  - `themes/default/checkout/success.blade.php` 在短暂轮询后自动调用购物车恢复接口。
- 可复现后果：PayPal 建单请求超时、响应丢失或浏览器网络异常时，顾客被带到“付款失败/订单已保存”页；前端同时尝试恢复购物车，而服务商资金状态仍可能未知。
- 违反不变量：浏览器错误不是资金失败证明；未知支付状态不得释放订单商品或创建新的支付归属。

## 最小修复

1. 将订单确认响应中的恢复地址改为签名的原订单支付入口 `order_payment_url($order)`。
2. 已生成未付订单后再次打开空结账页时，返回同一支付入口，不再伪造 `payment_status=error`。
3. 结果页仅轮询服务端订单状态；未确认时保留订单和“重试付款”入口，不自动恢复购物车。
4. 保留 `OrderCartRestoreService` 及其中央会话安全检查，供明确、受控的取消/恢复流程使用；本补丁不削弱其资金保护。

## 失败场景与兼容影响

- 服务商初始化瞬时失败：重新进入原订单支付入口，复用已有 session/幂等键；不会重新提交购物车。
- 服务商已经创建远端订单但响应丢失：原订单支付入口和后台对账继续查询/复用原 attempt，不把未知状态改成失败。
- 明确取消或服务商错误返回：结果页展示真实提示并允许重试，但不自动恢复购物车。
- 已付款订单：仍以服务端订单状态为准，原成功页、库存和邮件行为不变。
- 旧订单链接：现有 `checkout.success`、`orders.pay`、`orders.restore-cart` 路由均保留，未删除兼容接口。

## 通过证据

- 红色测试：修复前目标测试 `14 tests` 中 3 项失败。
- 修复后：支付恢复、结账展示、支付发起目标测试 `20 tests / 389 assertions` 全部通过。
- 支付专项回归：`104 tests / 1074 assertions` 全部通过。
- 全量 PHPUnit：`973 tests / 15340 assertions` 通过，仓库既有 1 项跳过，无失败。
- 店铺前端 Node 回归：`14 tests` 全部通过。
- PHP 语法：`CheckoutController.php` 通过 `php -l`。

## 仓库级门禁说明

`npm run test:quality` 的店铺政策、设置加固、Builder TypeScript 检查与 16 项 Builder JavaScript 测试均通过；随后在仓库既有 `tests/BuilderCoreAssertions.php:272` 的 `shopify-page` 本地页面边界断言停止。本次变更未修改 `DesignService` 、Shopify Builder 渲染器或该断言，故将其记为与本补丁无关的现有门禁问题，不为了伪造绿灯扩大修改范围。

## 回滚

回滚本次提交即可恢复旧前端控制流，无数据库迁移、无数据重写。若回滚，必须同时恢复测试断言；不建议仅恢复自动购物车回填，因为这会重新引入未知资金状态下的竞态。
