# LONGOS Stripe 支付配置

本插件使用 Stripe PaymentIntent 与 Stripe.js 完成银行卡支付，支持需要 3D Secure / SCA 验证的卡片。LONGOS 服务端只接收 PaymentIntent ID，不接触或保存卡号、有效期和 CVC。

结账页会在用户选择 Stripe 后懒加载 Stripe.js 安全输入框。用户填完收货与卡片信息后，点击一次即创建订单、完成 3DS 并付款；无需再进入中间确认页。订单详情中的“重试付款”仍保留独立付款页作为恢复通道。

## 配置步骤

1. 在 Stripe Dashboard 的 Developers → API keys 获取同一环境的一组密钥。
2. 测试时开启“测试模式”，填写 `pk_test_...` 与 `sk_test_...`；正式收款时关闭测试模式，填写 `pk_live_...` 与 `sk_live_...`。LONGOS 会拒绝环境不匹配的密钥和回调。
3. 在 Developers → Webhooks 为租户**保留的平台子域名**创建 HTTPS Endpoint：`https://租户平台子域名/callback/stripe`。不要使用可能被商家更换或删除的自定义主域名。
4. 至少订阅 `payment_intent.succeeded`。仅在需要接收旧版 Charge 支付结果时再订阅 `charge.succeeded`。
5. 打开 Endpoint，复制以 `whsec_` 开头的 Signing secret 到插件设置。测试端点和正式端点的 Signing secret 不可混用。

多租户中，每个租户应在自己的 Stripe 账户/端点中配置该租户的平台子域名回调 URL。平台域名可以不是店铺主域名，POST Webhook 不会被主域名跳转中间件重定向。Cloudflare 必须允许 Stripe 对 `/callback/stripe` 的 POST 请求，不要对该路径启用交互式验证码或缓存。

## 稳定性与安全

- 每个本地订单复用同一个 PaymentIntent；页面刷新、重复点击和网络重试不会重复创建扣款。
- Stripe API 请求启用网络重试并使用确定性的幂等键。
- 浏览器完成 3DS 后，LONGOS 会从 Stripe 服务端重新读取 PaymentIntent，并核对租户订单、订单 ID、金额、币种和测试/正式环境。
- Webhook 使用原始请求体和 `Stripe-Signature` 验签；重复事件可安全确认，不会重复扣库存或发送订单通知。
- Webhook 在浏览器关闭时仍可把已成功的付款更新为已支付；因此生产环境必须保持队列 Worker、HTTPS 和 Webhook Endpoint 正常。

上线前建议分别使用 Stripe 测试卡验证：普通成功、需要 3DS、余额不足、重复点击、支付后关闭浏览器，以及同一 Webhook 的手动重放。
