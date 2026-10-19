# LONGOS Christmas Atelier

`longos_christmas` is a separate, installable LONGOS storefront theme. It does not replace or modify `themes/default`.

## Install

1. Open **Admin → Plugins → Themes**.
2. Install **LONGOS Christmas Atelier**.
3. Open **Design → Theme settings** and enable `longos_christmas`.

The installer publishes the theme's Blade files to `themes/longos_christmas` and its prebuilt stylesheet to `public/build/beike/shop/longos_christmas/css/theme.css`. No Node.js build is required in production.

The theme intentionally uses LONGOS' hardened commerce views as fallbacks for transaction-heavy flows while replacing the storefront shell, homepage, menus and product cards. Its stylesheet fully restyles product, category, cart, checkout and account pages without forking their business logic.

## Builder behavior

- A configured slideshow or image-banner module becomes the primary homepage hero.
- The built-in Christmas hero is shown only when the merchant has not configured a hero module.
- Feature grids, image-with-text, story, testimonial, FAQ, rich-text, product and brand modules receive the Christmas Atelier presentation automatically.
- Desktop mega menus support hover, focus, Arrow Down and Escape. Mobile navigation and footer groups use native Bootstrap collapse behavior with synchronized accessibility state.
