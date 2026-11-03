# LONGOS Global Translation — implementation audit and rollback notes

## Gate 0 record

- Baseline: `8f8e9849e3d2bd9480efbd4c36e653634d2a5ae2` on a feature branch; no deployment branch was modified.
- The operator-supplied V2.0 manual, chapters 16–34, was reviewed before implementation. Its hard constraints are reflected in code rather than treated as UI copy.
- `beike/Shop/Providers/PluginServiceProvider.php` registers plugin routes with runtime `plugin.enabled` guards. The plugin therefore does not rely on a first-request tenant decision.
- `beike/Plugin/RuntimeGuard.php` applies the enabled-state check to long-running runtime execution.
- `app/Saas/TenantManager.php` and the tenant connection/prefix lifecycle supply the isolation boundary. Translation tables contain no request-controlled tenant id.
- `SetLocaleFromSession`, `SetLocaleShopApi`, and `SetLocaleAdmin` keep the platform source behavior intact. `PresentationLocaleResolver` never mutates Laravel's locale.
- `VisitorCurrencyService::countryCode()` is used only for country selection; its currency resolution path is not called.
- `BuilderStateService::published()` is the only Builder state examined by the scanner. Draft and preview state is excluded; every indexed Builder field has a matching stable `data-lgt-*` storefront marker.
- `PluginRepo::publishPluginFiles()` is now called by the bundled synchronizer so a widget asset is released with the normal immutable image upgrade.

## Implemented controls

| Invariant | Implementation |
|---|---|
| English source lock | `TranslationSettings` rejects another source locale; UI makes it read-only. |
| Tenant isolation | All plugin tables are created on the active tenant connection. No public route accepts a tenant identifier. |
| Content allowlist | Scanner accepts only active product names, category names, page titles, and explicit plain-text fields from nine published Builder components with matching storefront markers. Orders, checkout, payment, customers, reviews, chat, metadata and HTML bodies are excluded. |
| No GET billing | Storefront endpoints only read settings and an immutable publication dictionary. Provider calls exist only in queued free-only jobs or an explicit administrator verification action. |
| Secret isolation | Credential ciphertext is encrypted, redacted from all responses, never added to logs or storefront JSON. |
| Budget safety | `UsageLedger` journals `reserved`, `sending`, `committed`, `definite_failed`, and `uncertain`; uncertain sends retain budget and have no automatic retry. A lost worker releases only a provably pre-send reservation and quarantines any `sending` request. Paid overage is forced to zero. |
| Queue recovery | Each worker run owns an opaque claim token. A failed worker can finish only its own claim; missing provider authorization pauses and preserves work until an administrator explicitly enables a verified free-only provider. |
| Human precedence | Manual edit uses a version CAS check; a locked row cannot be overwritten by a machine result. |
| Publication | Publication items are immutable snapshots tied to source hashes. Rollback creates a new revision; it never mutates history. |
| Storefront safety | Widget has a host-only secure HttpOnly locale cookie, bounded root selectors, no whole-DOM scan, and is omitted from cart, checkout, sign-in and account flows. |
| Source freshness/removal | Dictionary reads re-check public parent state and the current allowlisted source hash (or Builder published revision), so a changed or deactivated source cannot expose a prior translation. |

## Verification run

Local, credential-free tests that must remain green:

```powershell
vendor\bin\phpunit tests\Feature\GlobalTranslationContentTest.php tests\Feature\GlobalTranslationWorkflowTest.php tests\Unit\GlobalTranslationTextTest.php tests\Unit\GlobalTranslationProviderTest.php
vendor\bin\pint --config=pint.json plugins\LongosGlobalTranslate tests\Feature\GlobalTranslationContentTest.php tests\Feature\GlobalTranslationWorkflowTest.php tests\Unit\GlobalTranslationTextTest.php tests\Unit\GlobalTranslationProviderTest.php
node --check plugins\LongosGlobalTranslate\Static\public\widget.js
```

The implementation tests source-change invalidation, manual locks, immutable publication and rollback, source deactivation, quota hard stops, credential redaction, text-token integrity, and mocked Aliyun/Volcengine/Baidu response paths. They never make a real API request.

## Deliberate operator boundary

External provider verification can incur a provider-side free-quota charge. It is **not** treated as automated CI evidence. The admin must explicitly confirm a small verification request, inspect the local ledger, then separately enable dispatch. A real provider account, billing identity, free quota, DNS/network policy, and account authorization are external facts that automated repository tests cannot assert.

## Coolify deployment and rollback

1. Back up the matching tenant database through Coolify.
2. Push the reviewed commit to the configured Git branch and use the normal Git-based Compose deployment. The image startup upgrade runs the plugin migrations; do not run Composer, migrations, or asset publishing manually in a production container.
3. In the tenant admin, enable the plugin, save language policy, then enable the page-builder app embed only after an approved publication exists.
4. To disable presentation instantly, turn off the app embed or plugin. Published snapshots and ledger records remain intact.
5. To roll back code, select the prior successful Coolify deployment. The migrations are additive/non-destructive; restore the matching database backup only if an operator needs to revert data state too.

## Known V1 scope

Rich HTML, transaction-related text, arbitrary metadata and any Builder component without a stable field marker are intentionally not published. Translating arbitrary markup safely requires a separate structural node contract, content-sanitization review, and browser regression suite. This V1 refuses to represent that as completed support.
