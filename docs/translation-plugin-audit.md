# LONGOS Instant Translate audit

`plugins/LongosInstantTranslate` is the only supported LONGOS translation
plugin. The retired `LongosGlobalTranslate` code is not shipped. Its tenant
`lgt_*` tables are read once by the new migration and retained as a rollback
backup; the new runtime uses only `lit_*` tables.

## Security boundaries

- Provider credentials are encrypted at rest and are never returned to the
  browser, diagnostics, job payloads or logs.
- The local builder scans an explicit set of public catalog/page fields,
  published Builder display fields and code-owned English UI strings. It never
  queries customer, address, order, checkout or payment records.
- The public library endpoint is same-origin, CSRF-protected and lookup-only;
  visitor input cannot create queue rows.
- Each tenant owns its own library, provider accounts, usage ledger and queue
  context through LONGOS' existing tenant database isolation.
- Non-SiliconFlow providers cannot dispatch with a zero local character limit.
  Authentication failures disable dispatch; throttling and overload enter a
  bounded cooldown.
- Translation output is plain-text validated, glossary/placeholder protected,
  checked for truncation, repetition, source echo and target-script mismatch,
  then either published to the exact-match cache or held for review.

## Runtime fallback

Ready local translations are loaded into xnx3's tenant-scoped cache before
execution. A miss may use the locked `client.edge` browser channel only for
approved public roots. Forms, customer/account/cart/checkout/payment/order
areas, prices, SKU, inventory and sensitive-looking values are excluded. A
local partial result remains usable if the browser fallback is unavailable.

## SiliconFlow verification

The default model is the dedicated `tencent/Hunyuan-MT-7B`. The server calls
`https://api.siliconflow.cn/v1/chat/completions`, sends an `X-Trace-Id`, parses
usage and trace metadata, honors `Retry-After`, treats 429 as rate limiting and
503 as temporary overload, and never falls back to a non-allowlisted model.
Free status is checked against SiliconFlow's live official price page before a
release; it is not represented as a permanent product guarantee.

Primary review sources: [chat-completions API](https://docs.siliconflow.cn/docs/api/chat-completions-post),
[live pricing](https://siliconflow.cn/pricing),
[rate-limit rules](https://docs.siliconflow.cn/docs/userguide/faqs/rate-limit-and-upgradation),
[error codes](https://docs.siliconflow.cn/en/faqs/error-code), and Tencent's
[Hunyuan-MT model card](https://github.com/Tencent-Hunyuan/Hunyuan-MT/blob/main/README.md).

## Release gates

```bash
php -l plugins/LongosInstantTranslate/Bootstrap.php
node --check plugins/LongosInstantTranslate/Static/public/translate-widget.js
node plugins/LongosInstantTranslate/Tests/verify_source_contracts.mjs
vendor/bin/phpunit tests/Unit/InstantTranslateProviderTest.php
vendor/bin/phpunit tests/Unit/InstantTranslateTextTest.php
vendor/bin/phpunit tests/Feature/InstantTranslateLibraryTest.php
vendor/bin/phpunit tests/Feature/InstantTranslatePluginLifecycleTest.php
```

The normal CI also runs Pint, the complete PHPUnit suite, frontend checks,
dependency audits and Docker/Coolify deployment contracts.
