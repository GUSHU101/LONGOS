# LONGOS Platform Pay

Version 1.7.0 starts every available platform channel from the original store
checkout. PayPal redirects straight to its official approval page, while
Stripe card details are collected by Stripe Elements before the order is
submitted. Mixed-channel checkouts let the shopper choose in advance, so the
hosted LONGOS page is now a recovery path instead of a mandatory extra step.

Version 1.6.1 first removed the intermediate page for PayPal-only checkout.
Version 1.7.0 extends that behavior to Stripe and mixed-channel sessions by
moving the required card or provider choice to the original checkout. The
recovery page remains available if provider initialization fails, and large
order numbers are serialized as strings so JavaScript cannot round them before
session creation.

Provider return and recovery URLs now use short-lived relative signatures, so
cross-domain callbacks do not depend on a guest session cookie and cannot be
altered to open a different order. Payment initialization scripts also use
explicit statement boundaries after server-rendered values to remain valid in
optimized Blade output.

主站统一托管 PayPal 与 Stripe 收款账号。仅开启 PayPal 时，顾客提交订单后会直接进入 PayPal；开启 Stripe 或同时开启多个渠道时，必要的银行卡输入或渠道选择仍直接渲染在子站支付页。子站只会收到供应商的公开 SDK 密钥和短时中央支付会话，不接触任何私钥。供应商返回地址与 Stripe Webhook 固定使用主站域名；完成付款前会再次核对租户、订单、金额、币种、环境和供应商交易绑定。

`LONGOS Platform Pay` 是独立支付插件：它拥有自己的 PayPal/Stripe 账号设置、加密凭据和 Webhook，不读取、不修改也不要求启用原有的 PayPal 或 Stripe 店铺插件。

同一子站订单只能存在一个活动支付会话。LONGOS 调度器每五分钟重新核对被浏览器关闭、回跳中断或 Webhook 延迟的供应商交易；已完成的交易会幂等回写子站订单，未完成订单返回子站订单状态页继续重试。进程在创建供应商交易前意外中断的未绑定占用也会自动释放。终态会话默认保留 365 天后清理。

子站浏览器 API 始终使用当前支付页的同源根路径，因此通过自定义域名、平台域名或保留别名访问时都不会误变成跨域请求；主站托管回退页的 API 根路径则显式包含短时会话令牌。PayPal 捕获或 Stripe 完成回写遇到网络超时时，页面会先通过会话状态端点确认是否已完成，避免将“已扣款但响应丢失”误报为支付失败并诱导重复付款。

前端不会把“SDK 下载完成”误当成“支付方式可用”：PayPal 必须通过当前买家、地区、币种和设备的资格检查后才显示，Stripe 必须等到安全卡片输入框发出就绪事件后才允许提交。某一渠道不可用时，另一健康渠道仍可继续完成付款。

已绑定供应商交易的订单在对账完成前会保留活动锁，避免顾客重新发起造成双重扣款。PayPal 已批准订单可由定时对账幂等捕获；过期且未完成的 Stripe PaymentIntent 必须先由主站成功取消，才会释放子站订单锁。

为避免资金已进入主账号但子站订单无法入账，当 PayPal/Stripe 仍有进行中或四天对账窗口内的交易时，插件会暂停更换对应账号密钥；删除仍有待对账交易的子站也会被拒绝。

1. 在主站的本插件设置页填写 PayPal（REST）和/或 Stripe 平台账号。
2. 在主站启用本插件并选择共享渠道；原 PayPal/Stripe 插件可保持关闭。
3. 子站管理员打开本插件并一键启用；顾客随后会直接在子站支付页完成 PayPal 或银行卡付款。
4. 将 Stripe 平台 Webhook 指向插件设置页显示的主站地址，并订阅 `payment_intent.succeeded`。
5. 确保主站的当前主域名已列入 `SAAS_CONTROL_DOMAINS`；支付、回跳和 Webhook 始终使用该规范主域名。
6. 确保 Coolify 的 `scheduler` 服务持续健康；自动对账和中断恢复依赖标准 LONGOS 调度器运行。
