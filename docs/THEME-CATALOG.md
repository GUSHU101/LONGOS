# Default retail theme

The storefront has one source-owned theme: `default`. It is a restrained DTC
retail system for product-led independent stores, not a collection of
brand-imitation plugins.

## Source of truth

- `resources/beike/shop/default/css/retail-dtc.scss` — canonical retail
  tokens, typography, responsive layout, navigation, product and transactional
  surface rules.
- `resources/beike/shop/default/css/longos-commerce.scss` — shared component
  foundation used by the canonical source.
- `resources/beike/shop/default/css/footer-rebuild.scss` — footer-only layout
  and accessibility treatment.

## Design decisions

- Warm neutral canvas, near-black primary action and one restrained highlight.
- Jost local font stack, fluid display scale and 16px base text.
- One 1440px maximum content grid with fluid gutters and mobile-first spacing.
- Square-to-subtle 2px controls rather than incompatible card, pill and glass
  treatments.
- Product discovery, checkout and account flows use the same tokens so a
  customer never moves between unrelated visual systems.

## Activation

No theme plugin needs to be enabled. The `default` theme is compiled with the
main storefront assets and is the only source-owned storefront template.

## Release checks

1. Run `npm run development` after editing the default SCSS sources.
2. Check the default theme at 320px, 768px, 1024px and 1440px; verify header,
   product card, search/filter, cart, checkout, account and footer states.
3. Confirm keyboard focus, validation states and contrast before release.
