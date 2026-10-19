# LONGOS TEST Payment Security Sandbox V3.3

This is the LONGOS defensive payment-security sandbox. Historical directory, route, table, and configuration identifiers are retained for upgrade compatibility.

## Start here

- `CODEX_IMPLEMENTATION.md` — complete Codex implementation/landing guide.
- `FINAL_RELEASE.md` — final release contract.
- `.env.beikeshop-test.example` — BinSearchLookup/runtime configuration.
- `resources/export_templates/beikeshop_test_full_export_template.xlsx` — final one-sheet/39-column export template.

## Final scope

- exact server-side TEST-card allowlist;
- customer TEST checkout and 4/6-digit TEST OTP;
- customer-side BIN internals hidden;
- encrypted synthetic sensitive payload;
- original IP + IP HMAC;
- device fingerprint HMAC;
- risk radar;
- real BinSearchLookup integration with cache/stale cache/quota/circuit breaker/audit;
- admin Anti-Fraud Radar Pro;
- manual-delete-only database retention;
- 6–8 digit same-BIN/card-head historical search;
- single/multiple/current-page/filtered-all/database-all XLSX selection export;
- final XLSX contains one worksheet (`All Records`) with exactly 39 columns;
- C/D/P/G project classifications are not exported.

## Deploy

```bash
php artisan migrate
php artisan config:clear
php artisan cache:clear
```

Then verify:

```bash
php artisan route:list | grep beikeshop-test
```

Use Redis for Laravel cache/locks if available.

## Important

This package is TEST/SANDBOX-only. Do not remove the exact TEST-card allowlist or adapt it to collect real payment credentials or real bank OTPs.
