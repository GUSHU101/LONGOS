# LONGOS Global Translation — implementation audit and rollback notes

Last reviewed: 2026-11-10. Current plugin release: **v1.17.0**.

## Architecture and trust boundaries

- Plugin routes are registered behind the normal LONGOS plugin runtime guards. Long-running work re-enters the tenant runtime before accessing translation data.
- Every plugin table uses the active tenant connection or tenant table prefix. Public and admin requests never accept a tenant identifier.
- `PresentationLocaleResolver` chooses only the presentation locale and never mutates Laravel's source locale. English remains the immutable source contract.
- Country headers are accepted only when the immediate peer is in Cloudflare's verified IP ranges. GeoLite2 is an optional offline fallback.
- Storefront routes read settings and immutable publication dictionaries only. Provider calls occur exclusively in administrator-initiated verification or queued translation work.
- Plugin files and assets are released by `BundledPluginSynchronizer`; Git/Coolify owns application updates.

## Implemented controls

| Invariant | Implementation |
|---|---|
| Tenant isolation | Translation tables, settings, credentials, queues, publications and audit records stay on the active tenant connection. |
| Public-content allowlist | The scanner covers public catalog/page records, explicitly supported published Builder display fields, storefront chrome and controlled commerce UI strings. Transactional and private areas are excluded. |
| Source freshness | Dictionaries re-check the current public parent, source hash and Builder publication revision before returning a translation. |
| Credential safety | Encrypted credentials are redacted from controllers, logs and storefront JSON. Verification is an explicit administrator action. |
| Billing safety | `UsageLedger` journals `reserved`, `sending`, `committed`, `definite_failed` and `uncertain`. Metered providers enforce character budgets; rate-only free models retain the audit ledger without an invented character cap. Unknown post-send outcomes are never blindly retried. |
| Multi-worker safety | Opaque run tokens own claims. Job/item locks serialize pause, cancel, recovery and completion. A concurrent pause releases an unsent claim; a true cancellation consumes it. |
| Provider pacing | Per-account QPS controls apply to metered providers. SiliconFlow bypasses that artificial gate and uses only durable provider-user-account/model RPM, TPM and remote-cooldown windows with delayed resume. |
| Human precedence | Manual edits use version compare-and-swap; reviewed locked rows cannot be overwritten by machine output. |
| Publication | Publication items are immutable snapshots. Rollback creates a new revision, and locale maintenance uses a shared mutex with planning/publication. |
| Storefront safety | The widget uses a secure host-only HttpOnly locale cookie, bounded selectors and versioned dictionary responses; it is omitted from sensitive flows. |
| Recovery | Only definite failures are retryable. Stale claims distinguish pre-send reservations from possible post-send requests before releasing quota. |

## Provider scope

The registry supports Aliyun, Volcengine, Baidu, DeepL, Google Cloud Translation, Azure Translator and SiliconFlow. SiliconFlow is restricted to the plugin allowlist of models verified as free for this release. It does not use an artificial character allowance: each API account/model has an isolated durable RPM/TPM window and 429 cooldown, while processed characters and actual response tokens remain auditable.

Provider availability, free pricing, account authorization and billing identity are external facts. Automated tests use mocked providers and never send a real translation request. An operator must deliberately verify an account and separately enable dispatch.

## Verification

Credential-free plugin gates:

```powershell
php vendor/bin/phpunit tests/Unit/GlobalTranslationConfigurationTest.php tests/Unit/GlobalTranslationProviderTest.php tests/Unit/GlobalTranslationTextTest.php tests/Unit/GlobalTranslationWidgetSafetyTest.php tests/Feature/GlobalTranslationWorkflowTest.php
php plugins/LongosGlobalTranslate/Tests/verify_builder_display_contract.php
php plugins/LongosGlobalTranslate/Tests/verify_cloudflare_country_contract.php
node plugins/LongosGlobalTranslate/Tests/verify_source_contracts.mjs
node --check plugins/LongosGlobalTranslate/Static/public/widget.js
```

Repository release gates additionally run Composer validation/audit, Pint, the full PHPUnit suite, frontend quality/build checks, npm audit, deployment contracts and a release-owned plugin source check. `LongosGlobalTranslate` is explicitly included in that source check.

## Coolify deployment and rollback

1. Back up the matching MySQL database and persistent volumes through Coolify.
2. Push a reviewed commit to the configured Git branch or select Deploy. The immutable image contains locked dependencies and built assets.
3. The application entrypoint takes the shared deployment lock and runs the resumable install or `saas:upgrade --if-needed`; do not run Composer, npm or migrations manually in a production container.
4. Enable the plugin per tenant, configure and verify providers, create a scan/translation job, review output, then publish an immutable snapshot.
5. To stop presentation immediately, disable the plugin or its widget. Publications and ledger history remain intact.
6. Roll back to the previous successful Coolify deployment. If a release used irreversible data changes, restore the matching database backup as documented in `docs/COOLIFY.md`.

Historical release notes live in `plugins/LongosGlobalTranslate/docs/releases/`.
