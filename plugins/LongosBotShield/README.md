# LONGOS Bot Shield

LONGOS Bot Shield has one deterministic action: visitors outside the shielding
scope continue to the storefront normally; every request inside the scope is
served the active LONGOS control-site public document at the original host and
URL. The first document is the control homepage; links to public products,
categories, brands, pages, page categories, policies, and latest products can
be followed for secondary and tertiary browsing without redirecting the
address bar to the control domain. There are no scores, thresholds, challenge
mode, monitor mode, or block mode.

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
catalog. One optional switch adds mainland China (`CN`), Hong Kong (`HK`), and
Macao (`MO`) to the shielding scope as a single policy. It is disabled by
default. Explicit IP/CIDR and verified provider allowlists take precedence.
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
When Cloudflare Pseudo IPv4 overwrites the normal visitor header, LONGOS
prefers the preserved `CF-Connecting-IPv6` value so network ownership is
resolved for the real address rather than a synthetic Class E IPv4 address.

Cloudflare Rules fields such as `ip.src.asnum`, `ip.src.country`, and
`ip.src.continent` exist at the edge, while `request.cf.asn` and
`request.cf.asOrganization` exist inside Workers; Cloudflare does not
automatically forward those ASN properties to Laravel. LONGOS therefore does
not trust an invented client-supplied ASN header. It uses Cloudflare for the
verified visitor IP, accepts standard country/location origin headers only
from a verified Cloudflare edge, and resolves network ownership locally with
IPinfo Lite. Enabling Cloudflare's **Add visitor location headers** Managed
Transform additionally exposes validated country, continent, city, region,
and timezone metadata in the health diagnostic. Location does not trigger
shielding by default. When the explicit CN/HK/MO switch is enabled, only a
country code from that verified context may trigger the policy; unverified
origin headers, city, region, and timezone never do. If Cloudflare does not
send a usable country code, the normal local IPinfo Lite lookup supplies it
without an additional API request. Cloudflare's `XX` (unknown) and `T1` (Tor)
special markers are not treated as countries and therefore do not suppress
the local fallback.

The built-in exact platform ASN catalog is audited against the supplied Lite
snapshot. Daily MMDB identity also covers newly observed platform ASNs by AS
name without waiting for a plugin release. AS domain is retained for
diagnostics and explicit allowlists but is not hard-blocking evidence: Lite can
associate an unrelated access ASN with a platform-looking domain, and using a
domain substring alone would create false positives.

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

For a shielding-scope match, the plugin fetches the verified primary `/` or a
strictly allowlisted public document path from the active LONGOS control site
without forwarding visitor cookies, authorization, query strings, request
bodies, or sessions. The substituted response keeps the visitor's host and
address bar, is sandboxed, marked `no-store` and `noindex`, and cannot submit
forms or execute the control site's scripts in the tenant origin. Internal
control-site links are rewritten onto the current visitor origin; external
links remain external.

Lazy-loaded image, picture, video, iframe, and stylesheet attributes are
promoted to native HTML attributes before caching, so hero, collection, and
product-card media render even though scripts stay disabled. Passive CSS,
JavaScript, image, font, icon, and video requests bypass classification and
are never replaced by an HTML document. Assets still resolve against the
control-site base, which preserves control-site uploads and theme resources.
Meta refresh redirects are removed.

Only read-only public document routes participate in secondary/tertiary
browsing. Account, cart, checkout, order, authentication, API, callback, and
payment paths always receive the safe root document; they are never proxied or
executed against another tenant. Missing deep documents also fall back to the
known-good root homepage.

Pagination forwards only a validated `page` value (2-500); tracking and other
untrusted query parameters are never sent to the control site or included in
its cache key. Deep-document fetches are serialized per control site, and a
failed/missing path receives a short negative cache before falling back to the
root. Random path scans therefore cannot create concurrent upstream fetches or
repeated 404 work. HTML rewriting is fail-safe: if a malformed document ever
causes a regular-expression transformation to fail, the last intact document
is returned instead of an empty response.

Control-document fetches occur only for an already shielded visitor or an
administrator health check; they never delay an ordinary shopper. They use a
one-second connect timeout and five-second total timeout with no retries. The
root homepage stays fresh for one hour and may use a trusted stale copy for 24
hours. Bounded deep documents stay fresh for 15 minutes with a two-hour stale
copy, limiting cache storage while keeping navigation responsive. The health
action preloads the root production cache. If no validated copy is available,
the plugin returns a temporary `503` rather than exposing the child storefront
to an in-scope visitor.

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
