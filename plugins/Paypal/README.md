# LONGOS PayPal 配置说明

## 推荐配置

新商户请选择 **REST API**。在 PayPal Developer Dashboard 中分别创建或查看 Sandbox、Live 应用，并把同一环境中的凭据填入 LONGOS：

- `Client ID`：前端加载 PayPal Checkout 使用的公开应用标识。
- `Secret`：仅由 LONGOS 服务端换取访问令牌，页面不会回显已保存的值。
- `Merchant ID`：PayPal 商业账户的 13 位商家编号。它不是登录邮箱，也不是 Client ID。此项可留空；填写后 LONGOS 会在创建订单和捕获付款时显式指定并校验收款商户。

不要混用 Sandbox 与 Live 凭据。调试时开启“沙盒模式”，上线收款前关闭“沙盒模式”并填写完整的 Live 凭据。

所有 Secret、API Password 和 API Signature 均使用 Laravel 应用密钥加密后存储；升级时会自动迁移旧的明文凭据。保留好生产环境 `APP_KEY`，不要在已有密文凭据时随意更换。

## 商家 ID 在哪里查看

登录 PayPal 商业账户，在账户设置的企业信息中查看 **PayPal Merchant ID / 商家账户 ID**。Sandbox 商家 ID 应从对应的 Sandbox Business 测试账户中获取。

PayPal 官方定义的 Merchant ID 是 13 位大写字母或数字。LONGOS 会在保存配置时检查格式；填写后还会核对 PayPal 返回的实际收款方，防止付款进入错误账户。

## 支付安全与重试

LONGOS 会把 PayPal Order ID 与本地订单绑定，并在确认付款前核验：

- 当前租户及订单归属；
- PayPal Order ID 与本地订单绑定关系；
- 本地订单号、付款金额和币种；
- 已配置时的 Merchant ID；
- PayPal 最终状态必须为 `COMPLETED`。

创建和捕获请求均带有幂等请求号。用户重复点击、浏览器重复回调或付款成功后本地状态更新中断时，系统会恢复已有付款结果，不会再次向客户扣款。

REST OAuth 访问令牌会按有效期短期复用，减少创建和捕获付款时的额外网络往返。令牌写入缓存前使用 `APP_KEY` 加密；缓存异常时会自动回退到实时获取，不会因缓存故障阻断付款。

本地只保留对账和重试必需的交易号、状态、金额、币种和订单引用；PayPal 返回的付款人姓名、邮箱、地址和资金来源不会写入订单付款记录，也不会再返回给店铺前端。

## 旧版 NVP/SOAP

NVP/SOAP 只用于兼容已有的 Express Checkout API Username、Password、Signature。新接入请使用 REST API；REST 的 Merchant ID 字段不适用于 NVP 凭据。

LONGOS 会自动把租户保留的平台子域名 `https://租户平台子域名/paypal/nvp/notify` 作为付款通知地址，因此更换自定义主域名不会中断在途回调。收到 IPN 后，服务器会把**原始、未修改的请求体**回传 PayPal 验证，只有返回 `VERIFIED` 且订单号、交易号、金额、币种、交易类型及已配置的 Merchant ID 全部匹配时才会更新订单。该路径已经从 CSRF 校验中精确排除，但仍受 PayPal 验真保护；Cloudflare 不应对它启用交互式验证码或缓存。

同一订单的创建、浏览器返回、捕获和 IPN 使用同一把并发锁，避免重复回调同时修改订单。NVP 是 PayPal 的旧版兼容接口，新项目仍应优先选择 REST。

官方参考：[PayPal Orders v2 payee](https://developer.paypal.com/sdk/orders/v2/definitions/payee) · [PayPal REST 幂等请求](https://developer.paypal.com/api/make-api-requests/)
