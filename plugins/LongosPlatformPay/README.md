# LONGOS Platform Pay

主站统一托管 PayPal 与 Stripe 收款账号，子站只创建短时中央支付会话，不接触平台密钥。供应商返回地址与 Stripe Webhook 固定使用主站域名；完成付款前会再次核对租户、订单、金额、币种、环境和供应商交易绑定。

同一子站订单只能存在一个活动支付会话。LONGOS 调度器每五分钟重新核对被浏览器关闭、回跳中断或 Webhook 延迟的供应商交易；已完成的交易会幂等回写子站订单，未完成订单返回子站订单状态页继续重试。终态会话默认保留 365 天后清理。

1. 在主站分别完成 PayPal（REST）和/或 Stripe 配置。
2. 在主站启用本插件并选择共享渠道。
3. 子站管理员打开本插件并一键启用。
4. 将 Stripe 平台 Webhook 指向插件设置页显示的主站地址，并订阅 `payment_intent.succeeded`。
5. 确保 Coolify 的 `scheduler` 服务持续健康；自动对账依赖标准 LONGOS 调度器运行。
