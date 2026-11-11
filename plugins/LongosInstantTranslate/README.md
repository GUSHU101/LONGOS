# LONGOS Instant Translate

`LongosInstantTranslate` is LONGOS' single storefront translation plugin. It
combines a tenant-local translation library with the self-hosted xnx3/translate
browser engine:

1. use an exact local translation when one is available;
2. use xnx3 `client.edge` for an immediate browser-only fallback;
3. build missing local entries slowly in the background through a configured
   server-side provider.

The browser never receives a provider API key and never creates translation
jobs. Library construction is fed only by an administrator-initiated scan of
explicitly public catalog, page, published Builder and code-owned UI strings.
Customer, address, order, payment, authentication and form data are outside
the scanner contract.

## Providers

The provider-neutral adapter layer supports SiliconFlow, Alibaba Cloud,
Volcengine, Baidu, DeepL, Google Cloud Translation Basic v2 and Microsoft
Azure Translator. SiliconFlow defaults to `tencent/Hunyuan-MT-7B`; the
currently free `XingChenAGI/Xing4.0-29B` is also allowlisted.

Credentials are encrypted with Laravel's application key and are never
returned by the admin page or storefront endpoints. Accounts must be saved,
explicitly verified, and explicitly enabled for dispatch. Non-SiliconFlow
accounts default to a local monthly character limit of zero, preventing paid
usage until the merchant sets a budget. Provider priority, QPS, RPM and TPM
limits are tenant-local. Authentication failures disable dispatch; 429 and
503 responses enter cooldown and are retried by a later queue run.

SiliconFlow uses the official OpenAI-compatible endpoint
`https://api.siliconflow.cn/v1/chat/completions` with `X-Trace-Id`. As of
2026-11-10 the official price page marks Hunyuan-MT-7B input and output as
free, but LONGOS treats that as current provider state, not a permanent
guarantee. The allowlist must be reviewed against the official price page
before each release.

Release reviewers should re-check the primary sources instead of relying on
this README's snapshot:

- [SiliconFlow chat-completions API](https://docs.siliconflow.cn/docs/api/chat-completions-post)
- [SiliconFlow live model pricing](https://siliconflow.cn/pricing)
- [SiliconFlow account/model rate-limit rules](https://docs.siliconflow.cn/docs/userguide/faqs/rate-limit-and-upgradation)
- [SiliconFlow API error codes](https://docs.siliconflow.cn/en/faqs/error-code)
- [Tencent Hunyuan-MT model card and supported languages](https://github.com/Tencent-Hunyuan/Hunyuan-MT/blob/main/README.md)

## Slow local-library builder

The scanner de-duplicates entries by normalized source text and target locale.
The queue worker claims one entry, calls one provider account, validates the
plain-text result, then schedules the next entry after the configured delay
(30 seconds by default). At most one unique worker per tenant is scheduled.
Ambiguous network outcomes are held for review rather than replayed, avoiding
accidental duplicate paid calls.

The public `POST /_longos/instant-translate/library` endpoint is lookup-only:
it accepts at most 120 current-page text nodes, returns ready exact matches,
does not enqueue input, and is protected by same-origin CSRF. Returned entries
are loaded into xnx3's local cache before `translate.execute()`.

## Legacy migration

The plugin migration imports, without deleting the source data:

- clean `translated` and `approved` rows from `lgt_entries` and
  `lgt_translations` as ready local entries;
- `needs_review` or quality-flagged translations as preserved review entries;
- encrypted provider accounts and their dispatch settings;
- glossary terms used to protect brands, placeholders, URLs and numbers.

The old `lgt_*` tables remain an untouched rollback backup. The obsolete
`LongosGlobalTranslate` package and registration are retired after import; the
new runtime has no dependency on its classes or tables.

## Storefront safety

The xnx3 wrapper is mounted only on public home, product, search, category,
brand, page, cart and checkout routes. Checkout translates static headings,
labels, shipping/payment method names and action copy, while input controls,
saved addresses, address suggestions, error details, totals, prices, currencies,
SKU and inventory remain excluded. Email-, phone-, card-, token- and secret-like
text is rejected again immediately before a browser fallback request.

The wrapper follows xnx3's documented `client.edge` integration and disables
the unused `init`, `language`, `ip` and `connectTest` control requests before
execution. Only the locked Microsoft Edge translation endpoint can carry an
approved translation batch; xnx3 control traffic and unexpected endpoints are
discarded locally.

Automatic language selection uses only the server-verified visitor country
from the trusted edge request context. A visitor's manual selection overrides
that decision for the current browser tab only. Closing the tab or starting a
new visit evaluates the country again, so a selection made while using a VPN
cannot permanently disable later IP-based detection. Client health diagnostics
record the selection source and country without storing the visitor IP.

This remains presentation translation. It does not create localized URLs,
metadata or sitemaps and therefore is not multilingual SEO.

## xnx3 dependency

The unmodified vendored `translate.min.js` is xnx3/translate package `4.1.0`,
runtime `4.1.0.20260526`, commit
`311c593ccc1f0016b6d9c47060e984a966b6fe45`, SHA-256
`f027e461c331e21c60634725db8f13c01d72788faf87dc815882a7c0572e96e4`.
See `THIRD_PARTY_NOTICES.md` and the vendored MIT license.

## Deployment and rollback

Deploy only through the normal Git + Coolify flow. The LONGOS upgrade runner
registers the bundled plugin, runs its tenant migrations, imports legacy data,
and publishes browser assets. Do not run Composer, npm or migrations manually
inside a production container.

Disabling the plugin stops storefront injection and background dispatch; it
does not delete the new local library or the legacy backup tables.

## Verification

```bash
php -l plugins/LongosInstantTranslate/Bootstrap.php
node --check plugins/LongosInstantTranslate/Static/public/translate-widget.js
node plugins/LongosInstantTranslate/Tests/verify_source_contracts.mjs
vendor/bin/phpunit tests/Unit/InstantTranslateProviderTest.php
vendor/bin/phpunit tests/Feature/InstantTranslateLibraryTest.php
```
