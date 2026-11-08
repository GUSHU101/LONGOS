# 平台代收结账恢复测试矩阵

| 场景 | 预期 | 自动化证据 |
|---|---|---|
| 订单创建成功，PayPal 初始化成功 | 直接进入 PayPal 官方审批地址 | `PaymentInitiationFlowTest` |
| 订单创建成功，服务商初始化抛错 | 跳到原订单支付入口，不进入伪失败结果页 | `PlatformPaymentRecoveryContractTest` |
| 返回前浏览器/网络丢失响应 | 保留未付订单和原 session，允许安全重试/对账 | `PlatformPaymentRecoveryContractTest` |
| 明确取消或错误返回 | 展示准确提示，短轮询服务端状态，不自动回填购物车 | `PlatformPaymentRecoveryContractTest` |
| 服务端状态已确认付款 | 结果页刷新为付款成功 | `PlatformPaymentRecoveryContractTest` |
| 空购物车但当前会话有最近未付确认单 | 进入该订单支付入口，不显示空结账或重复创建订单 | `CheckoutPresentationTest` + 静态契约 |
| 恢复 URL 对外返回 | 仅暴露订单号和短期签名支付 URL，不暴露地址/邮箱等订单数据 | `CheckoutPresentationTest` |
| 旧受控恢复购物车接口 | 路由与安全服务保留，不能绕过中央 session 的资金状态检查 | `PlatformPaymentRecoveryContractTest` |

## 尚需沙盒验证

- PayPal 远端建单已成功、响应在本地绑定前丢失时，只产生一个远端订单。
- PayPal 浏览器返回、Webhook、中央对账的三种顺序组合最终收敛为同一订单状态。
- Stripe `processing`、3DS 返回和 Webhook 迟到时不恢复购物车、不重复扣款。
- Coolify 多实例部署下，相同 checkout token、相同 payment session 的并发重放只产生一笔业务订单及一项资金归属。

这些项目需要官方沙盒或故障注入环境，不能用生产资金或真实消费者数据验证。
