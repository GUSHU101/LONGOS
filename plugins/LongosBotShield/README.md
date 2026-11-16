# LONGOS Bot Shield

LONGOS Bot Shield protects tenant storefront routes from scripted traffic,
declared and generic crawlers, spoofed crawler user agents, hosting/datacenter
networks, residential proxies, exploit scanners, anonymous relays, and request
bursts.

## Decisions and signals

- IPInfo Lite is the default endpoint. It provides the official free,
  unlimited country and basic ASN dataset; LONGOS consumes the ASN identity
  and discards location fields. Operators with the matching paid entitlement
  can explicitly select Advanced Lookup for hosting, privacy, proxy,
  residential-proxy, VPN, Tor, relay, anycast, and bogon fields.
- Successful lookups are cached by tenant and an HMAC of the visitor IP.
  The cache namespace includes a token fingerprint, so credential rotation
  takes effect immediately. Failures cache for one minute; repeated failures,
  rate limits, and authentication errors open a tenant-scoped circuit breaker.
- Uncached provider lookup starts have a tenant-scoped per-minute budget. Once
  exhausted, requests continue through local detection without waiting for or
  consuming additional provider calls. The production scheduler removes
  expired database-cache and lock rows in bounded batches every ten minutes.
  Per-IP atomic refresh markers also collapse concurrent misses into one
  provider request instead of spending the budget on a cache stampede. Marker
  contention and provider failures reuse the last trusted success for one
  additional day beyond the configured fresh lifetime (48 hours total with
  the default); an `unavailable` result is never itself shielding evidence.
- Storefront request handling reads fresh/stale cache and local catalogs first.
  A first-seen IP performs one IPInfo call capped at 0.45 seconds; failure or a
  missing ASN can perform one Radar fallback capped at 0.30 seconds. Neither
  call retries, so the complete cold external path is budgeted to about 0.75
  seconds. Stale trusted data is returned immediately and refreshed after the
  response instead of making the visitor wait.
- IPInfo is the primary provider. When it is unavailable, circuit-broken, or
  missing an ASN, the optional Cloudflare Radar HTTPS fallback supplies ASN
  and organization identity. Radar has an independent circuit breaker and
  never manufactures hosting, proxy, VPN, or privacy flags that its ASN API
  does not return.
- IPInfo payloads are accepted only when their returned IP matches the
  requested canonical IPv4/IPv6 address. Cloudflare Radar receives the
  canonical IP as a TLS-protected query parameter and its response must match
  the documented successful ASN schema before it is cached or merged.
- The release-owned catalog includes major global cloud, hosting, CDN,
  backbone, and datacenter networks. It includes Cogent (`AS174`),
  Lumen/Level3 (`AS3356`), Hurricane Electric (`AS6939`), Meta, Google,
  Microsoft Azure, Amazon, DigitalOcean, Linode/Akamai, OVH, Hetzner, Choopa,
  M247/M2F-style provider keywords, and tenant-supplied ASN/CIDR additions.
- The crawler catalog covers Google, Meta, Microsoft/Bing, TikTok/ByteDance,
  search engines, AI crawlers, SEO tools, link-preview bots, archives, and
  monitoring agents. Generic bot/spider/crawler/scanner UA patterns are also
  classified. Declared crawlers are blocked by default. The optional verified
  policy uses all five of Google's published crawler, fetcher, and agent CIDR
  feeds and ASN identity for Meta. Successful Google feeds are cached for a day
  with a seven-day stale fallback, avoiding request-path DNS lookups.
  In shielding-scope homepage mode, both declared and source-verified crawlers
  receive the main-site homepage; crawler verification policy continues to
  govern the explicit challenge and block modes.
- HeadlessChrome, Puppeteer, Playwright, Selenium, PhantomJS, WebDriver,
  Cypress, browserless, common script HTTP clients, explicit automation
  headers, and contradictory browser/client-hint/fetch headers are signals.
- Per-IP limits operate in both one-minute and ten-second windows. They are
  supporting signals only: shared-NAT traffic is never challenged or blocked
  by a rate threshold alone. When a request is already inside the configured
  shielding scope, a rate signal changes a rejection to `429`. Unsafe HTTP
  methods and common secret/configuration/exploit scanning paths are rejected,
  including up to four layers of URL encoding.
- The local JavaScript challenge checks standard automation exposure and
  does not reject browsers for missing optional Chrome, language, or window
  properties. It receives only a one-time proof bound to tenant, host, coarse
  IP prefix, UA, and URI. The server atomically consumes the signed proof once
  and sets a one-hour clearance as a Secure HttpOnly cookie. The clearance is
  bound to tenant, host, and UA but not IP, so a verified mobile shopper can
  move between Wi-Fi and carrier networks without losing a cart or checkout
  submission. Clients do not perform repeated hashes or proof-of-work loops.
  Challenge and block documents use
  nonce-based CSP policies. This is a bounded cost gate, not device attestation.

## Vendor allowlist

Vendor allowlist mode is an explicit, disabled-by-default override for trusted
network providers. When enabled, a matching vendor is allowed before CIDR deny,
rate, scan-path, crawler, automation, and network-risk rules are evaluated.

- Prefer exact ASNs. They are normalized and matched exactly.
- Name keywords match only provider-returned ASN/company identity fields.
  Domain entries match the exact returned domain or one of its subdomains;
  `notgoogle.com` does not match `google.com`.
- A declared or spoofed crawler user agent never grants allowlist access. The
  decision requires a successful IPInfo or Cloudflare Radar response for the
  canonical visitor IP.
- If the identity provider is unavailable or does not match, normal protection
  continues. When the mode is disabled or both lists are blank, it adds no
  provider lookup.
- Broad organization words can allow more traffic than intended. Use a
  specific provider name or domain only when an exact ASN is not practical.

## Response modes

- **Shielding-scope homepage (default):** match the visitor against denied
  CIDRs, high-risk ASNs, hosting and
  datacenter networks, residential proxies, VPN/Tor/relay data, organization
  keywords, crawlers, and headless/script automation signals. Configured lists
  and the enabled built-in catalog both contribute to this scope. A match
  fetches the active control site's public `/`
  page server-to-server without forwarding visitor cookies, then render that
  HTML at the requested URL. The host and address bar do not change. On the
  control site or a non-SaaS install, this falls back to the current homepage.
  The response is marked `X-Longos-Bot-Shield: homepage`, `no-store`, and
  `X-Robots-Tag: noindex, nofollow, noarchive` for operational auditability.
  A main-site `<base>` preserves relative visual assets, while a sandbox CSP
  prevents substituted scripts, forms, or navigation from inheriting the
  child storefront's browser origin and session authority. A fifteen-second
  atomic refresh lock prevents cache stampedes. Fetches have a 0.3-second
  connection and 0.8-second total timeout with no retry; successful HTML stays
  fresh for five minutes and a previously validated copy may be served for up
  to one hour when the control homepage briefly returns 5xx.
  Control-site discovery is cached briefly behind its own atomic refresh marker,
  streams candidate rows instead of loading every tenant and domain, and accepts
  only a standard root HTTP(S) origin (HTTPS in production, with no credentials
  or nonstandard port). Fresh HTML cache hits perform no DNS or HTTP work.
  Every matching request receives that homepage, including structured and
  non-cacheable requests. Visitors outside the configured shielding scope are
  passed through normally. Explicit IP and verified-vendor allowlists still
  take precedence.
- **Monitor:** record shielding-scope matches and continue normally.
- **Challenge:** challenge network-identity matches and reject deterministic
  automation, spoofed crawlers, deny-list, or scanner matches. Rate thresholds
  alone never trigger enforcement.
  A valid clearance applies to cacheable and non-cacheable storefront requests,
  so verified customers can continue cart and checkout submissions.
- **Block:** return `403`, or `429` when an enforcement-scope match also exceeds
  a rate threshold.

All four modes consume the same reason-based detection result. No configured
numeric threshold participates in an enforcement decision.

Bot Shield runs before storefront cookies and sessions and is also attached to
the tenant API middleware group. In shielding-scope homepage mode, matching API
requests receive the same sandboxed main-site homepage and never reach the
application handler. In challenge and block modes, rejected API requests use
structured problem responses. `/api/health` and valid CORS preflight requests
remain available for operations and browser interoperability.

The cross-site fetch is restricted to the active LONGOS control site's verified
primary domain from the SaaS registry. It never accepts an administrator-entered
arbitrary URL and never forwards visitor cookies, sessions, authorization, or
request data. Rejected non-cacheable and JSON/API requests never reach
storefront actions in challenge or block mode. A shielding-scope homepage match
deliberately receives the sandboxed homepage for every method and response
format instead. The internal bypass signature is short-lived,
host/path/method bound, consumed before application handlers, and fails closed
when `APP_KEY` is unavailable.

Stripe webhooks and PayPal provider notifications/return continuations bypass
Bot Shield and remain protected by their payment plugin's signed or
order-bound validation. Customer-initiated payment create/capture endpoints do
not bypass Bot Shield.

## Setup

1. Install the plugin from LONGOS administration.
2. Open the plugin settings, enter an IPInfo API token, and select Lite for the
   free unlimited country/ASN endpoint. Select Advanced Lookup only if the
   account is entitled to its hosting/privacy fields.
3. Optionally enter a separate Cloudflare Radar token with `User Details:Read`
   to enable ASN fallback. A platform operator can instead provide
   `CLOUDFLARE_RADAR_API_TOKEN`; the DNS automation token is not reused.
4. Review the crawler policy, action mode, CIDR lists, ASN list, and traffic
   limits.
5. If trusted provider traffic must bypass every detection rule, configure its
   exact ASN and explicitly enable vendor allowlist mode.
6. Enable the plugin. Start with Monitor mode if the storefront sits behind
   unusual corporate NAT or proxy infrastructure.

Tenant provider credentials are encrypted at rest with Laravel Crypt. The
IPInfo and Cloudflare Radar tokens are sent only in `Authorization: Bearer`
headers to their documented HTTPS APIs. LONGOS does not log provider request
URLs or credentials.

Provider lookups disclose the visitor IP address to the configured services.
Operators should list these processors and the purpose of fraud/abuse
prevention in their privacy documentation and select retention/contract terms
appropriate to their jurisdiction.

Diagnostics contain only a keyed IP hash, matched reason categories, action,
and a support reference that rotates every ten minutes. The same reference is
returned to the visitor, so an operator can correlate a report without storing
the raw visitor address.

## Practical limitation

A server cannot reliably identify a freshly installed desktop VM or VDI from
HTTP headers alone. Cloud-hosted VDIs are usually covered by IPInfo hosting/ASN
signals; the browser challenge adds a second signal. The plugin intentionally
does not perform invasive hardware fingerprinting, and advanced automation can
hide individual browser properties. Treat reason-based detection as an abuse
control, not proof of a physical device type.

## References

- [IPInfo API overview](https://ipinfo.io/developers/ipinfo-api)
- [IPInfo Lite API](https://ipinfo.io/developers/lite-api)
- [IPInfo Core API fields](https://ipinfo.io/developers/core-api)
- [IPInfo privacy and residential-proxy fields](https://ipinfo.io/developers/ip-privacy-detection-api-data)
- [Cloudflare Radar ASN lookup by IP](https://developers.cloudflare.com/api/resources/radar/subresources/entities/subresources/asns/methods/ip/)
- [Google crawler verification](https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests)
- [Google user-triggered fetchers and agents](https://developers.google.com/crawling/docs/crawlers-fetchers/google-user-triggered-fetchers)
- [OpenAI crawler user agents](https://developers.openai.com/api/docs/bots)
