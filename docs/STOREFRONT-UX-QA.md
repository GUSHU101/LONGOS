# Storefront UX and QA guide

## Product intent

The storefront helps a visitor discover a product, evaluate its fit, buy with
confidence, and return after delivery. Each view should have one clear primary
action: explore products, inspect a product, or complete checkout.

## Design system rules

- Use the storefront CSS variables and semantic component classes instead of
  introducing page-specific colors or spacing values.
- Keep body copy at 16px or above, use the established type scale for headings,
  and keep long-form copy near 65 characters per line.
- Primary actions use the restrained near-black retail treatment; warnings and
  errors retain text or icon cues in addition to color.
- Interactive controls require hover, focus, active, disabled, loading, and
  error states. Touch controls must provide at least a 44px target.
- Do not use a link when there is no destination. Avoid automatic carousels and
  videos; customer-controlled motion is the default.

## Canonical retail direction

`resources/beike/shop/default/css/retail-dtc.scss` is the one source-owned
storefront direction. It uses a quiet warm-white canvas, near-black typography
and actions, a restrained stone supporting tone, a 1440px content grid, and
Jost with a safe system-font fallback. It intentionally avoids theme-specific
accent palettes, display-font switches, and decorative treatment differences
between marketing and transactional pages.

Do not add third-party font files without confirming their license.
Merchant-uploaded imagery remains content-managed; the theme supplies its crop,
contrast overlay, and loading behavior rather than substituting AI-generated
product photography.

## Journey priorities and measurement

| Journey phase | Storefront touchpoint | Primary signal |
| --- | --- | --- |
| Discovery | Home, category, search | Product-list click-through rate |
| Evaluation | Product detail, search filters, wish list | Product-to-cart rate |
| Purchase | Cart, checkout, payment | Checkout completion and validation errors |
| Experience | Order detail and RMA | Order-support contact rate |
| Retention | Account, wish list, post-purchase email | Repeat-purchase rate |

Review these signals after launch by device, locale, and traffic source. Do not
change a conversion element without recording a hypothesis and its baseline.

## Required release QA

1. Test home, category/search, product, cart, checkout, login, account, and
   order pages at 320px, 768px, 1024px, and 1440px.
2. Complete a guest and signed-in checkout. Verify unavailable shipping and
   payment states, invalid addresses, discount-code errors, and out-of-stock
   products.
3. Use only keyboard navigation: skip link, desktop menu, filters, product
   actions, mini-cart, address form, checkout radio groups, and pagination.
4. Check a screen reader announcement for cart updates, checkout errors, and
   successful discount application. Verify zoom to 200% retains all actions.
5. Measure mobile LCP, CLS, and INP with production data. Set a 3-second
   loading budget and investigate oversized merchant-uploaded images first.
6. Before publishing URL changes, create and test a 301 mapping; regenerate the
   sitemap and monitor crawl errors for the first seven days.

## Skills applied

The review used accessibility, usability, UX, UI, typography, color,
responsive design, navigation, media, branding, landing-page, customer-journey,
component, interaction-pattern, visual-direction, trend, design-process,
AI-review, and website-audit guidance. Agent-interface, document, spreadsheet,
presentation, plugin, and OpenAI-product skills are not applicable to this
Laravel storefront implementation.
