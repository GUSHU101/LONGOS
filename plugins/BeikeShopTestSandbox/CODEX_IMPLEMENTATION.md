# BeikeShop TEST Payment Security Sandbox — Codex Implementation Guide

> Final release target: **V3.3 Codex Ready**
>
> This document is written so Codex can implement the plugin directly inside a BeikeShop/Laravel/Puck repository instead of only giving architectural advice.

---

## 0. Read this first — exact safety/scope boundary

This project is a **TEST/SANDBOX-only defensive payment-security demo**.

The implementation MUST keep all of the following constraints:

1. Only predefined synthetic / PSP-documented TEST card numbers may enter the flow.
2. The backend MUST reject any card number that is not in the exact server-side TEST allowlist.
3. Never weaken or remove the allowlist.
4. Never accept arbitrary real PAN/CVV data.
5. Never collect a real bank/issuer OTP. The OTP UI is a local TEST 4/6-digit sandbox input only.
6. Never imitate a real issuer login page or credential collection flow.
7. BinSearchLookup is used only after the card has already passed the TEST-card allowlist.
8. Do not implement multiple free accounts / key rotation to evade provider quota.
9. Customer-facing pages must NOT display BinSearchLookup provider internals, issuer metadata, quota, cache state, Luhn diagnostics, or admin fraud details.
10. Complete PAN/CVV/identity/TEST OTP data are admin-only sandbox data and remain encrypted at rest in `sensitive_payload`.

These constraints are intentional requirements, not optional recommendations.

If a future request conflicts with these constraints, preserve the TEST/SANDBOX boundary and implement the closest safe equivalent.

---

# 1. Codex execution mode

When working from this file, do the following:

- **Edit the repository directly.** Do not stop at a prose plan.
- Read the current repository before changing anything.
- Reuse existing BeikeShop/Laravel/Puck conventions when paths differ.
- If the exact path in this guide does not exist, search the repository for the equivalent controller, route registration, Puck component registration, Blade admin view, service provider, or admin permission hook and adapt it.
- Do not ask for confirmation for ordinary path/name differences; make the best compatible implementation.
- Do not delete unrelated application code.
- Preserve existing public routes unless this guide explicitly says to replace a legacy route.
- Avoid adding unnecessary Composer/npm dependencies. The final package already includes a lightweight XLSX writer.
- Run syntax/build checks before finishing.
- If BinSearchLookup credentials are absent, complete all code and local checks anyway; report that only authenticated upstream success remains unverified.

At completion, return:

1. changed files;
2. migrations/commands to run;
3. checks executed and results;
4. any environment values the operator must provide.

---

# 2. Goal

Implement a BeikeShop plugin/demo containing:

- customer TEST checkout;
- synthetic card visualization;
- device fingerprint HMAC;
- original client IP and IP HMAC;
- risk radar;
- 4/6 digit TEST OTP + manual review state machine;
- real BinSearchLookup integration;
- BIN cache/stale cache/quota/circuit breaker/audit;
- admin Anti-Fraud Radar Pro;
- persistent records with manual deletion only;
- same-card-head/BIN-prefix historical search;
- selected / page-selected / filtered-all / database-all XLSX export;
- one-sheet focused export containing only the requested card/identity/device/IP/BIN fields.

---

# 3. Source package structure

The reference implementation is organized as:

```text
app/
  Console/Commands/
    WarmBeikeShopBinCache.php
  Http/Controllers/
    TestCheckoutPluginController.php
    AdminRadarController.php
    BinIntelligenceController.php
    BinQuotaWebhookController.php
  Services/
    AdminAuditService.php
    AdminRadarService.php
    BinSearchLookupService.php
    FullExportService.php
    OrderRetentionService.php
    SandboxReviewService.php
    SimpleXlsxWriter.php

config/
  beikeshop_test.php

database/migrations/
  2026_09_03_000000_create_beikeshop_test_checkout_logs.php
  2026_09_03_010000_add_radar_device_fingerprint_fields.php
  2026_09_03_020000_add_binsearchlookup_fields.php
  2026_09_03_030000_harden_bin_intelligence.php
  2026_09_03_040000_add_admin_radar_pro_fields.php

resources/
  export_templates/
    beikeshop_test_full_export_template.xlsx
  views/
    admin_live_screen.blade.php

routes/
  api.php

src/puck/components/
  TestCheckoutBlock.tsx

tools/
  binsearchlookup_diagnostics.php
  binsearchlookup_smoke_test.php
```

If the BeikeShop project uses plugin-specific namespaces/directories, place these classes in the corresponding plugin namespace and update imports/routes accordingly.

---

# 4. Environment

Merge these values into the environment configuration:

```dotenv
BEIKESHOP_TEST_CARD_MODE=whitelist

BINSEARCHLOOKUP_ENABLED=true
BINSEARCHLOOKUP_BASE_URL=https://api.binsearchlookup.com
BINSEARCHLOOKUP_API_KEY=bsl_replace_with_your_key
BINSEARCHLOOKUP_USER_ID=replace-with-your-user-uuid

BINSEARCHLOOKUP_CACHE_TTL=86400
BINSEARCHLOOKUP_STALE_TTL=604800

BINSEARCHLOOKUP_TIMEOUT=5
BINSEARCHLOOKUP_CONNECT_TIMEOUT=3
BINSEARCHLOOKUP_RETRY_ATTEMPTS=2

BINSEARCHLOOKUP_QUOTA_CACHE=60
BINSEARCHLOOKUP_QUOTA_GUARD=true
BINSEARCHLOOKUP_MONTH_GUARD=0.95
BINSEARCHLOOKUP_MINUTE_GUARD=0.90

BINSEARCHLOOKUP_CIRCUIT_FAILURES=3
BINSEARCHLOOKUP_CIRCUIT_WINDOW=60
BINSEARCHLOOKUP_CIRCUIT_OPEN=60

BINSEARCHLOOKUP_HEALTH_CACHE=30
BINSEARCHLOOKUP_WEBHOOK_SECRET=
```

Never commit real API credentials.

---

# 5. Card-input modes

`BEIKESHOP_TEST_CARD_MODE` controls the server-side behavior:

- `whitelist` (default): accepts only the exact synthetic/demo PANs below.
- `open`: accepts any 12-19 digit PAN and runs the configured BIN detection,
  risk, local TEST OTP, and admin-review flow.

In either mode, the Sandbox Gift profile stays local-only. The current
synthetic/demo whitelist profiles include:

```text
4242424242424242   Visa TEST
4000056655665556   Visa TEST debit profile
5555555555554444   Mastercard TEST
5200828282828210   Mastercard TEST debit profile
5105105105105100   Mastercard TEST prepaid profile
378282246310005    AMEX TEST
3566002020360505   JCB TEST
3530111333300000   legacy JCB TEST profile
6200000000000005   UnionPay TEST profile
6200000000000047   UnionPay TEST debit profile
9900000000000000   local Sandbox Gift profile
```

The backend must perform exact lookup such as:

```php
$card = self::TEST_CARD_PROFILES[$pan] ?? null;

if (!$card) {
    return response()->json([
        'success' => false,
        'message' => 'TEST-ONLY：当前卡号不属于内置演示卡档案，禁止提交真实银行卡号。',
    ], 422);
}
```

Do not replace this with prefix-only acceptance.

---

# 6. Customer checkout behavior

## Shop-wide checkout takeover

When the TEST Payment Security Sandbox plugin is enabled, it becomes the
default checkout surface for every storefront theme:

- every visit to `/checkout` redirects to `/beikeshop-test/checkout`;
- normal checkout update/confirm requests and order payment pages are blocked;
- payment plugins are removed from the runtime payment-method collection, but
  their stored enablement is not changed;
- disabling this plugin automatically restores the original checkout and the
  configured payment plugins.

Set `BEIKESHOP_TEST_CHECKOUT_TAKEOVER=false` only if the standalone demo page
is wanted without taking over the store checkout.

Implement `TestCheckoutBlock.tsx` as the customer-facing sandbox surface.

Required behavior:

- explicit TEST/SANDBOX banner;
- predefined TEST card selector;
- synthetic customer identity profiles;
- field-level validation;
- PAN formatting;
- MM/YY formatting;
- 3-digit or AMEX-style 4-digit TEST CVV handling;
- local SVG card visual;
- browser online/offline awareness;
- AbortController for duplicate checkout requests;
- 4 or 6 digit TEST OTP;
- 60 second OTP timer;
- WAITING_REVIEW polling;
- exponential retry on status polling failure;
- admin `RETRY_OTP` returns customer to OTP entry;
- admin `APPROVE` completes the TEST payment with the `APPROVED` state.

Customer UI MUST NOT expose:

```text
BinSearchLookup
BIN API provider status
LIVE/CACHE/STALE/FALLBACK
Issuer
BIN Country
Luhn
Length validation
provider response time
quota
circuit breaker
admin risk internals
```

The public `start()` and `/status/{orderId}` responses must also avoid these provider diagnostics.

---

# 7. Device/IP collection

Collect browser signals such as:

```text
userAgent
platform
language
timezone
screenWidth
screenHeight
colorDepth
hardwareConcurrency
deviceMemory
maxTouchPoints
cookieEnabled
```

Normalize the signal object deterministically and store:

```text
device_fingerprint_hash = HMAC-SHA256(normalized JSON)
client_ip                = server-side request IP
client_ip_hash           = HMAC-SHA256(client IP)
```

Use the server request object for the original IP. Do not trust a client-submitted IP field.

---

# 8. Sensitive TEST payload

Store the complete TEST-only payload encrypted using Laravel `Crypt::encryptString()`.

The encrypted payload may contain only synthetic sandbox fields such as:

```text
fullName
address
phone
cardNumber
expiryDate
cvv
latestSandboxOtp
deviceSignals
```

Do not expose this payload through public APIs.

Admin list APIs should return summaries only. Decrypt only when an authenticated administrator explicitly opens sensitive details or requests a full export.

---

# 9. OTP/manual review state machine

Required states:

```text
IDLE
SUBMITTING
OTP_REQUIRED
VERIFYING
WAITING_REVIEW
CARD_UNSUPPORTED
```

TEST OTP rules:

```text
4 digits OR 6 digits
```

Do not validate against a real bank OTP.

Flow:

```text
checkout start
→ OTP_REQUIRED

TEST OTP submit
→ WAITING_REVIEW

admin RETRY_OTP
→ OTP_REQUIRED

admin APPROVE
→ APPROVED
```

Use `SandboxReviewService` and a DB transaction with `lockForUpdate()` for review decisions.

---

# 10. BinSearchLookup integration

Use `BinSearchLookupService`.

For allowlisted TEST PANs, the backend may call the provider validation endpoint so the sandbox can receive:

```text
lengthValid
luhnValid
Brand
Type
Category
Prepaid
Issuer
IssuerPhone
IssuerUrl
CountryName
isoCode2
isoCode3
brandLogo
similarBins
cached
```

Maintain normalized fields used by the application:

```text
status
source
provider
endpoint
httpStatus
requestId
bin
brand
type
category
prepaid
issuer
issuerPhone
issuerUrl
country
iso2
iso3
brandLogo
similarBins
providerCached
resolvedFunding
resolvedClassCode     // may remain internal; DO NOT export C/D/P/G code
responseTimeMs
lookedUpAt
responseHash
```

For the local Sandbox Gift profile, keep BIN behavior local-only.

---

# 11. BIN reliability requirements

Keep all of these:

## Fresh cache

Default 24h.

## Stale cache

Default 7 days for provider/network/rate-limit recovery.

## Single-flight

Use Laravel Cache Lock to avoid cache stampede for the same TEST PAN.

## Retry

Retry only transient connection/5xx errors with short jitter/backoff.

Do not blindly retry:

```text
400
401
403
429
```

## Circuit breaker

Default behavior:

```text
3 failures in window
→ open circuit for 60 seconds
```

## Runtime quota guard

Read actual provider quota rather than hard-coding tier limits.

## Batch warm

Support configured BIN cache warm through the batch lookup function and Artisan command.

## Health

Expose provider health to authenticated administrators.

## Quota webhook

Verify provider webhook HMAC before accepting quota notifications.

---

# 12. BIN/risk audit

`beikeshop_test_bin_events` should record fields including:

```text
event_id
order_id
event
provider
endpoint
bin_prefix
source
status
http_status
response_ms
request_id
provider_cached
response_hash
error_code
created_at
```

BIN failures must not crash the checkout demo. Use local profile/stale fallback as designed.

---

# 13. Admin Anti-Fraud Radar Pro

Admin endpoints must remain behind:

```text
auth:sanctum
can:manage-test-checkout
```

Admin UI requirements:

- KPI overview;
- live/pause mode;
- server-driven pagination;
- 10/25/50/100 per-page selection;
- text search;
- state filter;
- internal C/D/P/G filter may remain for admin analysis;
- risk filter;
- BIN status filter;
- today-only toggle;
- sorting;
- order detail drawer;
- BIN detail tab;
- device/IP/risk detail tab;
- review/admin/BIN timeline;
- sensitive TEST reveal with audit;
- test card front/back visual with audit;
- forced BIN refresh;
- BIN batch warm;
- manual review with reason;
- manual permanent delete;
- same-card-head search;
- XLSX selection/export center.

---

# 14. Lightweight live refresh

Do NOT return full decrypted order payload every second.

Use:

```text
GET /api/beikeshop-test/admin/radar/pulse
```

The pulse should return only lightweight change information such as:

```text
stream version
waiting review count
latest updated_at
server time
```

Only refetch the paginated order summaries when the stream version changes.

---

# 15. Sensitive reveal audit

Full TEST PAN/CVV/name/address/phone/device signals must not be part of the normal admin table response.

Only return them through the authenticated sensitive endpoint after an explicit admin action.

Record admin events such as:

```text
VIEW_SENSITIVE_TEST_DATA
VIEW_CARD_VISUAL
REVIEW_DECISION
BIN_FORCE_REFRESH
BIN_BATCH_WARM
EXPORT_FULL_XLSX
DOWNLOAD_EXPORT_TEMPLATE
MANUAL_DELETE_ORDER
```

---

# 16. Record retention

Database records are **manual-delete-only**.

Do not add:

```text
TTL for order rows
automatic cleanup job
prune command
scheduled deletion
age-based deletion
```

The config should retain:

```php
'records' => [
    'manual_delete_only' => true,
],
```

Caches may expire; database order/audit records must not disappear automatically.

---

# 17. Manual deletion

Authenticated admin route:

```text
DELETE /api/beikeshop-test/admin/radar/orders/{orderId}
```

Request body:

```json
{
  "confirmation": "DEMO_XXXXXXXXXXXX"
}
```

Requirements:

- exact order ID confirmation;
- use DB transaction;
- delete associated order-bound review/BIN/admin events;
- delete checkout order;
- record a separate `MANUAL_DELETE_ORDER` admin audit event not bound to the deleted order ID so deletion itself remains auditable;
- update stream version.

---

# 18. Same-card-head / BIN-prefix historical search

Support a dedicated 6–8 digit admin BIN-prefix filter:

```text
424242
42424242
```

Query by indexed prefix:

```sql
WHERE bin_prefix LIKE '424242%'
```

Do not decrypt the full TEST PAN for prefix search.

Order detail should display same-prefix counts and allow one-click search for:

```text
same first 6 digits
same first 8 digits
```

When the admin starts same-BIN search, automatically search historical records rather than limiting to today.

---

# 19. Final XLSX export — exact contract

The final UI should use the XLSX export only. The old summary CSV route/button is not part of the final UI surface.

Export route:

```text
POST /api/beikeshop-test/admin/radar/export/full
```

Template route:

```text
GET /api/beikeshop-test/admin/radar/export/template
```

Supported selection modes:

```text
single order
multiple individually checked orders
select current page
select all filtered results
select all database records
```

For very large filtered/all selection, do not send every order ID to the browser. Send `scope=filtered` + filters or `scope=all` and let the backend stream database rows.

## Request contract

```json
{
  "scope": "selected | filtered | all",
  "orderIds": ["DEMO_..."],
  "filters": {
    "search": "",
    "status": "",
    "classCode": "",
    "risk": "",
    "binStatus": "",
    "binPrefix": "424242",
    "todayOnly": false
  },
  "includeSensitive": true
}
```

`includeSensitive` must be explicitly accepted because the workbook contains synthetic sensitive-looking TEST fields.

---

# 20. XLSX structure — one worksheet only

The workbook MUST contain exactly one worksheet:

```text
All Records
```

One selected order = one row.

Do not create Review/BIN/Admin child worksheets.

The fixed export columns, in this exact order, are:

```text
1  Card Brand
2  Full TEST PAN
3  Expiry
4  CVV
5  Full Name
6  Phone
7  Address
8  Device Fingerprint HMAC
9  Original IP
10 BIN Prefix
11 BIN Status
12 BIN Source
13 BIN Brand
14 BIN Type
15 BIN Category
16 BIN Prepaid
17 BIN Funding
18 BIN Issuer
19 BIN Issuer Phone
20 BIN Issuer URL
21 BIN Country
22 BIN ISO2
23 BIN ISO3
24 BIN Luhn Valid
25 BIN Length Valid
26 BIN Masked PAN
27 BIN Provider Cached
28 BIN Endpoint
29 BIN HTTP Status
30 BIN Request ID
31 BIN Brand Logo
32 BIN Similar Bins JSON
33 BIN Stale Reason
34 BIN Error Code
35 BIN Response Ms
36 BIN Lookup At
37 BIN LookedUp At
38 BIN Response Hash
39 BIN Raw JSON
```

Explicitly DO NOT export these project-derived classifications:

```text
C/D/P/G
Card Type local C/D/P/G label
Effective Class
Effective Funding
Classification Authority
BIN Class / resolvedClassCode
```

`BIN Raw JSON` must also remove internal class-code keys such as:

```text
resolvedClassCode
classCode
class_code
```

It may still contain provider/normalized BIN funding text such as CREDIT/DEBIT/PREPAID because that is BIN information rather than the project C/D/P/G code.

The template file is:

```text
resources/export_templates/beikeshop_test_full_export_template.xlsx
```

`FullExportService::HEADERS` and the XLSX template header row MUST always match exactly.

Use the included `SimpleXlsxWriter`; do not require PhpSpreadsheet unless the host project already standardizes on it and migration is explicitly requested.

---

# 21. Routes

Customer routes:

```text
POST /api/beikeshop-test/checkout
POST /api/beikeshop-test/checkout/verify
GET  /api/beikeshop-test/checkout/status/{orderId}
```

Provider webhook:

```text
POST /api/beikeshop-test/bin/quota-webhook
```

Admin radar routes:

```text
GET    /api/beikeshop-test/admin/radar/pulse
GET    /api/beikeshop-test/admin/radar/overview
GET    /api/beikeshop-test/admin/radar/orders
POST   /api/beikeshop-test/admin/radar/export/full
GET    /api/beikeshop-test/admin/radar/export/template
GET    /api/beikeshop-test/admin/radar/orders/{orderId}
GET    /api/beikeshop-test/admin/radar/orders/{orderId}/timeline
POST   /api/beikeshop-test/admin/radar/orders/{orderId}/decision
DELETE /api/beikeshop-test/admin/radar/orders/{orderId}
GET    /api/beikeshop-test/admin/radar/orders/{orderId}/sensitive
```

BIN admin routes:

```text
GET  /api/beikeshop-test/admin/bin/status
GET  /api/beikeshop-test/admin/bin/quota
GET  /api/beikeshop-test/admin/bin/events
POST /api/beikeshop-test/admin/bin/warm
POST /api/beikeshop-test/admin/bin/refresh/{orderId}
```

Legacy compatibility routes may remain only if the host application still depends on them, but do not reintroduce a summary CSV button into the final admin UI.

---

# 22. Database migrations

Run all provided migrations in order.

Core tables/fields include:

```text
beikeshop_test_checkout_logs
beikeshop_test_bin_events
beikeshop_test_review_events
beikeshop_test_admin_events
```

The checkout log contains:

```text
order_id
scenario
card_brand
card_type
pan_last4
client_ip
client_ip_hash
device_fingerprint_hash
risk_score
risk_flags
sensitive_payload
status
otp_attempt_count
otp_submitted_at
reviewed_at
reviewed_by
bin_prefix
bin_lookup_status
bin_lookup_source
bin_lookup_data
bin_lookup_at
bin_luhn_valid
bin_length_valid
bin_card_number_masked
bin_response_hash
bin_response_ms
effective_class_code
effective_funding_type
classification_authority
```

Internal C/D/P/G fields may remain in the database/admin radar. They are simply excluded from the final XLSX export.

---

# 23. BeikeShop integration checklist

Codex must locate the host application's equivalents and wire the plugin into them:

1. Laravel API route registration.
2. Admin authentication/permission middleware.
3. Admin menu/view route that renders `admin_live_screen.blade.php`.
4. Puck component registry for `TestCheckoutBlock`.
5. Public asset path for card SVGs.
6. Migration loading.
7. Console command discovery/registration if the host does not auto-discover commands.
8. Config loading for `config/beikeshop_test.php`.
9. CSRF/Sanctum conventions used by the actual BeikeShop install.

Do not invent a new framework when the host already provides an integration pattern.

---

# 24. Install/upgrade commands

Typical Laravel deployment:

```bash
php artisan migrate
php artisan config:clear
php artisan cache:clear
```

Then inspect routes:

```bash
php artisan route:list | grep beikeshop-test
```

If Redis is available, prefer it for cache/locks/circuit state.

---

# 25. Validation commands Codex must run

## PHP syntax

```bash
find app config database routes tools -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

If installed inside a plugin directory, run the equivalent command scoped to that plugin.

## Admin JS

Extract the `<script>` contents from the Blade view and run:

```bash
node --check /tmp/beikeshop_admin_radar.js
```

## Frontend

Use the host project's actual scripts, for example:

```bash
npm run typecheck
npm run build
```

If one script does not exist, inspect `package.json` and run the project's equivalent.

## Laravel

When the host app is available:

```bash
php artisan route:list | grep beikeshop-test
php artisan migrate:status
```

## BinSearchLookup diagnostics

Without credentials:

```bash
php Tools/binsearchlookup_diagnostics.php health
```

With valid sandbox credentials:

```bash
BINSEARCHLOOKUP_API_KEY='...' \
BINSEARCHLOOKUP_USER_ID='...' \
php Tools/binsearchlookup_diagnostics.php quota
```

And only with an allowlisted TEST PAN:

```bash
BINSEARCHLOOKUP_API_KEY='...' \
BINSEARCHLOOKUP_USER_ID='...' \
php Tools/binsearchlookup_diagnostics.php validate 4242424242424242
```

Never test the diagnostic tool with a real customer PAN.

---

# 26. Final acceptance checklist

Codex should not declare the implementation complete until these pass:

## Customer

- [ ] Unknown/non-allowlisted PAN returns HTTP 422 before external BIN lookup.
- [ ] Customer UI has no BIN provider dashboard or issuer/Luhn diagnostics.
- [ ] 4-digit TEST OTP accepted.
- [ ] 6-digit TEST OTP accepted.
- [ ] WAITING_REVIEW updates correctly.
- [ ] RETRY_OTP returns client to OTP entry.
- [ ] APPROVE produces APPROVED state and completes the customer TEST payment.

## BIN

- [ ] Allowlisted non-gift TEST card can call BinSearchLookup when credentials exist.
- [ ] Local gift card stays local-only.
- [ ] Fresh cache works.
- [ ] Stale cache path exists.
- [ ] 429 does not rotate provider accounts.
- [ ] Circuit state is visible only to admin.
- [ ] Quota endpoint is admin-only.
- [ ] Same-BIN prefix search uses indexed `bin_prefix`, not decrypted full PAN.

## Admin

- [ ] Admin radar list is summary-only.
- [ ] Sensitive detail is loaded only on explicit admin action.
- [ ] Sensitive reveal is audited.
- [ ] Manual review is transactional/audited.
- [ ] Records do not disappear automatically.
- [ ] Manual delete requires exact full order ID confirmation.
- [ ] Manual delete produces surviving delete audit.

## Export

- [ ] Per-row checkbox works.
- [ ] Single-order export works.
- [ ] Multiple checked rows export works.
- [ ] Current-page select-all works.
- [ ] Filtered-all export works without loading all IDs into the browser.
- [ ] Database-all export works without loading all IDs into the browser.
- [ ] XLSX has exactly one worksheet named `All Records`.
- [ ] XLSX has exactly 39 fixed columns.
- [ ] Full TEST PAN/Expiry/CVV/Name/Phone/Address/Device Fingerprint/Original IP appear when exporting stored synthetic test records.
- [ ] Complete normalized BIN fields appear.
- [ ] No C/D/P/G project class column appears.
- [ ] `BIN Raw JSON` does not contain `resolvedClassCode`, `classCode`, or `class_code`.
- [ ] Template header row exactly matches `FullExportService::HEADERS`.

---

# 27. Codex final response format

After implementation, respond with this structure:

```text
Implemented
- <major change>
- <major change>

Changed files
- path/file
- path/file

Commands to run
- php artisan migrate
- php artisan config:clear
- ...

Checks
- PHP lint: PASS/FAIL
- Admin JS: PASS/FAIL
- TS/Puck build: PASS/FAIL
- Routes: PASS/FAIL
- XLSX one-sheet/39-column check: PASS/FAIL

External verification still needed
- BinSearchLookup authenticated request requires operator API key/user ID (only if credentials were not available)
```

Do not claim a live authenticated BinSearchLookup success unless it was actually tested with valid credentials.

---

# 28. Ready-to-paste Codex instruction

You can paste the following into Codex together with this repository:

> Read `CODEX_IMPLEMENTATION.md` completely and implement it directly in this BeikeShop/Laravel/Puck repository. This is a defensive TEST/SANDBOX-only payment-security demo using exact synthetic test-card allowlists; do not broaden it to real payment credential collection. Inspect the existing repository first, adapt paths/namespaces to the host application's conventions, and make the edits instead of only describing them. Preserve customer/admin data boundaries, BinSearchLookup reliability behavior, manual-delete-only retention, same-BIN historical search, and the final one-sheet 39-column XLSX export contract. If host paths differ, locate the equivalent integration points and continue without stopping for routine clarification. Run the validation commands and acceptance checks in this document before finishing, then report changed files, deployment commands, check results, and any credential-dependent upstream test that could not be performed.

---

## Important note about model behavior

No instruction file can guarantee that any coding model will **100%** never refuse or never encounter an environment/tool limitation. This guide is deliberately scoped as an explicit synthetic TEST/SANDBOX defensive implementation, with prohibited real-credential behavior removed, so the implementation task is unambiguous and executable while staying within a safe development boundary.
