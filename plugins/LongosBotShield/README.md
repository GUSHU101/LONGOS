# LONGOS Bot Shield

LONGOS Bot Shield has one deterministic action: visitors outside the shielding
scope continue to the storefront normally; every request inside the scope is
served the active LONGOS control-site homepage at the original host and URL.
There are no scores, thresholds, challenge mode, monitor mode, or block mode.

## Shielding scope

The release-owned local catalog is always enabled and cannot be disabled from
the tenant panel. It contains:

- high-confidence Meta/Facebook, TikTok/ByteDance, Amazon/AWS, and Google cloud
  or content ASNs, while deliberately excluding consumer access networks such
  as Google Fiber/WiFi, Amazon Leo, generic transit carriers, consumer ISPs,
  and end-user IPv6 tunnel networks;
- major hosting, cloud, CDN, platform, colocation, and datacenter ASNs and
  organization names, excluding generic transit-carrier identity;
- known commercial VPN and proxy-network owner names, matched through their
  ASN identity without using broad `vpn` or `proxy` substrings;
- Google, Meta, TikTok, search, AI, SEO, social-preview, archive, and monitoring
  crawler user agents;
- explicit Puppeteer, Playwright, Selenium, WebDriver, HeadlessChrome,
  PhantomJS, browserless, and automation-header signatures;
- common script HTTP clients such as curl, wget, Python requests/httpx, Go,
  Java, OkHttp, Node fetch/axios/undici, Guzzle, PowerShell, and REST clients.

Tenant CIDR/ASN and organization additions are merged into the built-in
catalog. Explicit IP/CIDR and verified provider allowlists take precedence.
Missing ordinary browser headers, device performance, screen dimensions, and
request volume are not shielding reasons, preventing low-end devices and
shared NAT traffic from being misclassified.

## IPInfo Lite

IPInfo Lite is the only external provider. The token is encrypted with Laravel
Crypt at rest and is never rendered back into the password field. Live API
fallback uses the HTTPS `Authorization: Bearer` header; the official Lite MMDB
download endpoint receives the token as its required HTTPS query parameter.
The administration panel displays whether a token is already saved; leaving a
saved field blank keeps it.

The plugin downloads IPInfo's official Lite MMDB to
`storage/app/private/longos-bot-shield/ipinfo_lite.mmdb`, validates it, swaps it
atomically, and refreshes it every 24 hours. Storefront ASN lookups normally
read this local database, so a first-seen address does not wait for an external
request. The scheduler refreshes it daily, the panel health action can
synchronize it immediately, and a missing/stale file also schedules a
best-effort refresh after the current response.

MMDB is intentionally used instead of CSV/JSON/Parquet. The current CSV
expands to hundreds of megabytes, while the indexed MMDB download is roughly
26 MB and supports direct IP lookups without parsing or loading the entire data
set into PHP memory. Downloads stream to a temporary file, are size- and
content-validated, and only then replace the active copy; an interrupted
refresh keeps the last valid database. The atomic replacement unlinks the old
MMDB immediately, and abandoned temporary downloads older than one hour are
pruned on the next synchronization. Normal storage therefore contains one
active database; two copies exist only briefly while a new one is validated.

When a storefront is proxied by Cloudflare, LONGOS accepts
`CF-Connecting-IP` only after the direct edge address has been verified against
Cloudflare's official ranges (including the authenticated Coolify Caddy hop).
Direct clients cannot spoof that header. The resulting Laravel visitor IP is
looked up in the local MMDB to obtain ASN, AS name, and AS domain before the
release-owned platform/hosting catalogs are evaluated.

Until the local database is ready, live API fallback remains bounded to one
request with a 0.3-second connect timeout and 0.8-second total timeout, without
retries. The same fallback is used for the rare MMDB record that lacks both AS
name and AS domain, so organization-based coverage is not silently skipped.
Successful fallback results are isolated by tenant and a keyed IP hash. Fresh
results use the configured cache lifetime, with one additional day of trusted
stale fallback. Concurrent misses collapse behind a 15-second atomic marker.
Repeated failures open a tenant-scoped circuit breaker, and
`unavailable` is never shielding evidence. IPInfo Lite supplies basic ASN
identity rather than paid privacy/proxy flags. Proxy and hosting coverage
therefore comes from exact ASN and organization catalogs. When neither local
nor cached identity is available, crawler, automation, script-client and CIDR
detection continues immediately and ordinary visitors fail open.

## Homepage substitution

For a shielding-scope match, the plugin fetches the verified primary `/` of
the active LONGOS control site without forwarding visitor cookies,
authorization, request bodies, or sessions. The substituted response keeps the
visitor's host and address bar, is sandboxed, marked `no-store` and `noindex`,
and cannot submit forms or execute the control site's scripts in the tenant
origin.

Control-homepage fetches occur only for an already shielded visitor or an
administrator health check; they never delay an ordinary shopper. They use a
one-second connect timeout and five-second total timeout with no retries.
Successful HTML stays fresh for one hour and may be served from a trusted stale
copy for 24 hours. The health action preloads the same production cache. If no
validated copy is available, the plugin returns a temporary `503` rather than
exposing the child storefront to an in-scope visitor.

Stripe webhooks and PayPal provider notifications/return continuations bypass
Bot Shield and remain protected by their payment plugin's signed or
order-bound validation. Customer-initiated payment create/capture endpoints do
not bypass the plugin. Unmatched cart, checkout, account, and payment requests
always continue normally.

## Setup

1. Open LONGOS Bot Shield in administration.
2. Enter an IPInfo Lite API token and save. After reload, the field remains
   blank by design and shows **stored securely**.
3. Select **Sync database and warm homepage**. The live administration probe
   has a separate three-second diagnostic window. It resolves the
   administrator's current ingress IP, reports the returned ASN/organization
   and exact shield/pass decision, synchronizes the local MMDB, and verifies
   that the actual control-site homepage has entered cache without saving or
   displaying the token.
4. Review optional allowlists and tenant-specific deny additions.
5. Enable the plugin.

For a provider-independent result, add the exact test address or its verified
network to **IP/CIDR shielding scope**. ASN and organization rules require a
successful current or cached IPInfo Lite response; IP/CIDR and user-agent
automation rules are entirely local.

Diagnostics contain only tenant identity, an irreversible keyed IP hash,
matched reason categories, and a rotating support reference. Raw visitor IPs
and API tokens are never logged.

## References

- [IPInfo Lite API](https://ipinfo.io/developers/lite-api)
- [IPInfo Lite database](https://ipinfo.io/developers/ipinfo-lite-database)
- [IPInfo API authentication](https://ipinfo.io/developers/authentication)

IP address data powered by [IPinfo](https://ipinfo.io). The Lite database is
used under its published CC BY-SA 4.0 terms.
