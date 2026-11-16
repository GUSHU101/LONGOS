# LONGOS Bot Shield

LONGOS Bot Shield protects tenant storefront routes from scripted traffic,
declared and generic crawlers, spoofed crawler user agents, hosting/datacenter
networks, residential proxies, exploit scanners, anonymous relays, and request
bursts.

## Decisions and signals

- IPInfo Lookup API ASN, company, privacy, hosting, proxy, residential-proxy,
  VPN, Tor, relay, anycast, and bogon fields are fetched with a Bearer token.
  Location data is discarded.
- Successful lookups are cached by tenant and an HMAC of the visitor IP.
  The cache namespace includes a token fingerprint, so credential rotation
  takes effect immediately. Failures cache for one minute; repeated failures,
  rate limits, and authentication errors open a tenant-scoped circuit breaker.
- Uncached provider lookup starts have a tenant-scoped per-minute budget. Once
  exhausted, requests continue through local detection without waiting for or
  consuming additional provider calls. The production scheduler removes
  expired database-cache and lock rows in bounded batches every ten minutes.
  Per-IP atomic refresh markers also collapse concurrent misses into one
  provider request instead of spending the budget on a cache stampede.
- IPInfo is the primary provider. When it is unavailable or its circuit is
  open, the optional ip-api Pro HTTPS fallback supplies ASN, ISP/organization,
  proxy, and hosting signals. Its `X-Rl` and `X-Ttl` headers proactively open
  an independent circuit before the provider rate limit is exceeded. The
  HTTP-only, non-commercial ip-api free endpoint is never used.
- Provider payloads are accepted only when their returned IP matches the
  requested canonical IPv4/IPv6 address. If IPInfo returns ASN data without
  security fields, ip-api Pro enriches and merges the missing signals rather
  than replacing trustworthy primary data.
- The release-owned catalog includes major global cloud, hosting, CDN,
  backbone, and datacenter networks. It includes Cogent (`AS174`),
  Lumen/Level3 (`AS3356`), Hurricane Electric (`AS6939`), Meta, Google,
  Microsoft Azure, Amazon, DigitalOcean, Linode/Akamai, OVH, Hetzner, Choopa,
  M247/M2F-style provider keywords, and tenant-supplied ASN/CIDR additions.
- The crawler catalog covers Google, Meta, Microsoft/Bing, TikTok/ByteDance,
  search engines, AI crawlers, SEO tools, link-preview bots, archives, and
  monitoring agents. Generic bot/spider/crawler/scanner UA patterns are also
  scored. Declared crawlers are blocked by default. The optional verified
  policy uses all five of Google's published crawler, fetcher, and agent CIDR
  feeds and ASN identity for Meta. Successful Google feeds are cached for a day
  with a seven-day stale fallback, avoiding request-path DNS lookups.
- HeadlessChrome, Puppeteer, Playwright, Selenium, PhantomJS, WebDriver,
  Cypress, browserless, common script HTTP clients, explicit automation
  headers, and contradictory browser/client-hint/fetch headers are signals.
- Per-IP limits operate in both one-minute and ten-second windows. Unsafe HTTP
  methods and common secret/configuration/exploit scanning paths are rejected,
  including up to four layers of URL encoding.
- The local JavaScript challenge checks standard automation exposure and
  several non-invasive browser-coherence signals. It receives only a one-time
  proof bound to tenant, host, IP, UA, and URI, then solves a server-verified
  14-bit SHA-256 work challenge. The server consumes the proof once and sets
  the final clearance as an HttpOnly cookie. Challenge and block documents use
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
  decision requires a successful IPInfo or ip-api response whose returned IP
  is the canonical visitor IP.
- If the identity provider is unavailable or does not match, normal protection
  continues. When the mode is disabled or both lists are blank, it adds no
  provider lookup.
- Broad organization words can allow more traffic than intended. Use a
  specific provider name or domain only when an exact ASN is not practical.

## Response modes

- **LONGOS main-site homepage:** fetch the active control site's public `/`
  page server-to-server without forwarding visitor cookies, then render that
  HTML at the requested URL. The host and address bar do not change. On the
  control site or a non-SaaS install, this falls back to the current homepage.
  The response is marked `X-Longos-Bot-Shield: homepage`, `no-store`, and
  `X-Robots-Tag: noindex, nofollow, noarchive` for operational auditability.
  A main-site `<base>` preserves relative visual assets, while a sandbox CSP
  prevents substituted scripts, forms, or navigation from inheriting the
  child storefront's browser origin and session authority. A fifteen-second atomic
  refresh lock prevents cache stampedes; a previously validated copy may be
  served for up to ten minutes when the control homepage briefly returns 5xx.
  Control-site discovery is cached briefly behind its own atomic refresh marker,
  streams candidate rows instead of loading every tenant and domain, and accepts
  only a standard root HTTP(S) origin (HTTPS in production, with no credentials
  or nonstandard port). Fresh HTML cache hits perform no DNS or HTTP work.
- **Monitor:** record the risk decision and continue normally.
- **Challenge:** challenge medium-risk browsers and reject high-risk traffic.
- **Block:** return `403`, or `429` for rate limits.

Bot Shield runs before storefront cookies and sessions and is also attached to
the tenant API middleware group. API requests always receive structured problem
responses rather than substituted HTML. `/api/health` and valid CORS preflight
requests remain available for operations and browser interoperability.

The cross-site fetch is restricted to the active LONGOS control site's verified
primary domain from the SaaS registry. It never accepts an administrator-entered
arbitrary URL and never forwards visitor cookies, sessions, authorization, or
request data. Non-cacheable requests and JSON/API requests are always rejected
instead of being converted into HTML. The internal bypass signature is
short-lived, host/path/method bound, consumed before application handlers, and
fails closed when `APP_KEY` is unavailable.

## Setup

1. Install the plugin from LONGOS administration.
2. Open the plugin settings and enter an IPInfo API token with ASN and network
   flag access (Core or higher for hosting/anonymous flags).
3. Optionally enter an ip-api Pro key to enable the commercial-use HTTPS
   fallback. A missing key simply leaves the fallback inactive.
4. Review the crawler policy, action mode, CIDR lists, ASN list, and thresholds.
5. If trusted provider traffic must bypass every detection rule, configure its
   exact ASN and explicitly enable vendor allowlist mode.
6. Enable the plugin. Start with Monitor mode if the storefront sits behind
   unusual corporate NAT or proxy infrastructure.

Both provider credentials are encrypted at rest with Laravel Crypt. The IPInfo
token is sent only in the `Authorization: Bearer` header to
`https://api.ipinfo.io`. ip-api requires its Pro key as an HTTPS query
parameter; LONGOS does not log the provider request URL.

Provider lookups disclose the visitor IP address to the configured services.
Operators should list these processors and the purpose of fraud/abuse
prevention in their privacy documentation and select retention/contract terms
appropriate to their jurisdiction.

Diagnostics contain only a keyed IP hash, score, reason categories, action,
and a support reference that rotates every ten minutes. The same reference is
returned to the visitor, so an operator can correlate a report without storing
the raw visitor address.

## Practical limitation

A server cannot reliably identify a freshly installed desktop VM or VDI from
HTTP headers alone. Cloud-hosted VDIs are usually covered by IPInfo hosting/ASN
signals; the browser challenge adds a second signal. The plugin intentionally
does not perform invasive hardware fingerprinting, and advanced automation can
hide individual browser properties. Treat the score as layered risk detection,
not proof of a physical device type.

## References

- [IPInfo API overview](https://ipinfo.io/developers/ipinfo-api)
- [IPInfo Core API fields](https://ipinfo.io/developers/core-api)
- [IPInfo privacy and residential-proxy fields](https://ipinfo.io/developers/ip-privacy-detection-api-data)
- [ip-api JSON fields, limits, and HTTPS notice](https://ip-api.com/docs/api:json)
- [Google crawler verification](https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests)
- [Google user-triggered fetchers and agents](https://developers.google.com/crawling/docs/crawlers-fetchers/google-user-triggered-fetchers)
- [OpenAI crawler user agents](https://developers.openai.com/api/docs/bots)
