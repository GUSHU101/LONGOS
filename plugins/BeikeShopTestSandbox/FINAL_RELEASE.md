# BeikeShop TEST Sandbox V3.3 — Final Codex Ready Release

This is the consolidated final package from the V2.x/V3.x iteration.

## Final export contract

The final admin XLSX export has exactly one worksheet named `All Records` and 39 columns.

It contains only:

- card brand;
- full allowlisted TEST PAN;
- expiry;
- CVV;
- synthetic full name;
- synthetic phone;
- synthetic address;
- device fingerprint HMAC;
- original client IP;
- complete normalized BIN/provider metadata and sanitized BIN raw JSON.

It does not export project C/D/P/G class codes, Effective Class, Effective Funding, Classification Authority, or BIN Class/resolvedClassCode.

## Selection modes

- single row;
- multiple checked rows;
- current-page select-all;
- all filtered results;
- all database records.

## Data retention

Order/history records are manual-delete-only. No automatic prune/TTL cleanup is included for database records.

## Customer boundary

The customer checkout does not expose BinSearchLookup provider details or administrator fraud data.

## Codex implementation guide

See `CODEX_IMPLEMENTATION.md`.
