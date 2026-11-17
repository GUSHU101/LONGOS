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
Crypt at rest, is never rendered back into the password field, and is sent only
in the HTTPS `Authorization: Bearer` header. The administration panel displays
whether a token is already saved; leaving a saved field blank keeps it.

Successful results are isolated by tenant and a keyed IP hash. Fresh results
use the configured cache lifetime, with one additional day of trusted stale
fallback. A first-seen IP makes at most one IPInfo request with a 0.2-second
connect timeout and 0.45-second total timeout, without retries. Concurrent
misses collapse behind a 15-second atomic marker. Repeated failures open a
tenant-scoped circuit breaker, and `unavailable` is never shielding evidence.
IPInfo Lite supplies basic ASN identity rather than paid privacy/proxy flags.
Proxy and hosting coverage therefore comes from exact ASN and organization
catalogs. If IPInfo is unavailable or the tenant lookup budget is exhausted,
local crawler, automation, script-client, CIDR, and previously cached ASN
detection continues immediately and ordinary visitors fail open.

## Homepage substitution

For a shielding-scope match, the plugin fetches the verified primary `/` of
the active LONGOS control site without forwarding visitor cookies,
authorization, request bodies, or sessions. The substituted response keeps the
visitor's host and address bar, is sandboxed, marked `no-store` and `noindex`,
and cannot submit forms or execute the control site's scripts in the tenant
origin.

Control-homepage fetches use a 0.3-second connect timeout and 0.8-second total
timeout with no retries. Successful HTML stays fresh for five minutes and may
be served from a trusted stale copy for one hour. If no validated copy is
available, the plugin returns a temporary `503` rather than exposing the child
storefront to an in-scope visitor.

Stripe webhooks and PayPal provider notifications/return continuations bypass
Bot Shield and remain protected by their payment plugin's signed or
order-bound validation. Customer-initiated payment create/capture endpoints do
not bypass the plugin. Unmatched cart, checkout, account, and payment requests
always continue normally.

## Setup

1. Open LONGOS Bot Shield in administration.
2. Enter an IPInfo Lite API token and save. After reload, the field remains
   blank by design and shows **stored securely**.
3. Select **Test IPInfo connection**. The bounded live probe validates the
   entered or saved token without saving or displaying it.
4. Review optional allowlists and tenant-specific deny additions.
5. Enable the plugin.

Diagnostics contain only tenant identity, an irreversible keyed IP hash,
matched reason categories, and a rotating support reference. Raw visitor IPs
and API tokens are never logged.

## References

- [IPInfo Lite API](https://ipinfo.io/developers/lite-api)
- [IPInfo API authentication](https://ipinfo.io/developers/authentication)
