# Visual Commerce Builder architecture

## Current architecture

The Store Builder is a Laravel-backed React + Puck editor. Its data is saved through the existing `design.update` endpoint and rendered by Blade module views. It is the only available editing surface, so one data model has one editor.

### Verified runtime baseline

The Builder bundle uses the versions actually installed in this repository: React 19.2.8, TypeScript 5.9.3, `@puckeditor/core` 0.23.0 and Webpack 5.74.0. Tailwind 4.3.3 is available to the admin asset toolchain; the Builder deliberately keeps its compact editor-shell CSS co-located with the Blade entrypoint and uses the project's shared SCSS/token layers for storefront styling. This avoids generating an unreviewable utility-class copy of every migrated Shopify style while retaining a Tailwind-compatible build foundation.

## Editor-neutral data contract

`Beike\Services\BuilderPageSchema` owns the persisted envelope, not a browser editor.

- `schema_version: 5` identifies the Builder protocol.
- `engine` remains explicit for backward-compatible saved data (`native` is a legacy data marker; `puck` is written by the current editor), without making an editor library's wire format the stored protocol.
- Each module has `component_version` and a three-device responsive visibility contract.
- The durable module shape remains `code`, `module_id`, `view_path`, `visible`, and `content`, so earlier saved designs remain valid.

This separation is intentional: Puck data is adapted at the client boundary rather than made into a permanent LONGOS database protocol. The adapter maps Builder modules to/from Puck component data and keeps Puck upgrades isolated.

`Beike\Services\PuckDataAdapter` provides that bidirectional boundary. The admin bootstrap exposes its read-only `puckData` output to the React/Puck client without changing saved pages.

`Beike\Services\BuilderDataMigrator` upgrades saved page envelopes before validation. It accepts pre-envelope module arrays, adds the global-style and app-embed roots introduced by earlier releases, supplies the v4 component-version and responsive-visibility contract, and adds v5 page metadata. Migrations are deterministic and do not depend on the active browser editor, so saved designs can be safely opened after an editor upgrade.

`Beike\Services\BuilderComponentMigrator` is a separate component-local layer. Its first migration upgrades `img_text_banner` v1 to v2 by retaining the legacy `image` while adding `desktop_image` and `mobile_image`; the Puck adapter synchronizes the desktop value back to the legacy renderer during the staged storefront transition.

The editor persists one atomic `base.design_draft` bundle containing page design, header, footer, and menu. Ordinary autosaves update only that bundle and deliberately avoid global cache rebuilds. Explicit publish copies the normalized bundle to the four live settings, clears shared caches once, and creates a bounded (25-entry) complete-state snapshot. Restoring a snapshot replaces only the draft until the merchant publishes it again. Every mutation carries an optimistic draft version; conflicts stop autosave and require an explicit reload or overwrite decision. The `settings.value` column is migrated to `MEDIUMTEXT` so complex drafts and bounded history are not truncated by MySQL's former 64 KiB `TEXT` limit.

## Component registry

`Beike\Admin\Services\DesignEditorCatalog` is the serializable registry shared by the editor and plugins. Definitions include:

- stable `code`, `component_version`, `view_path`, category and keywords;
- placement and instance constraints;
- default content (`make`); and
- declared capabilities such as responsive visibility.

Registry entries are data only. Plugins cannot inject executable editor code through this route.

The Builder assembly is deliberately thin: `native-component-fields.ts` owns native section inspector definitions, while the generated Shopify schema bridge and Puck root configuration remain separate concerns. This prevents a growing component library from becoming one hand-maintained `Config` file.

Every native catalog item is also required to resolve to a real Blade view before it is considered usable. The editor keeps common controls visible first and restores the old editor's full payload access through a validated **模块高级配置（JSON）** field. Its object is previewed immediately and expanded only at the server-side Puck adapter before the normal content sanitizer runs; malformed, oversized, or non-object JSON is ignored rather than corrupting a saved page.

Native components also have responsibility-specific inspector controls rather than a shared placeholder form. The image-text sliders edit individual slides, media, links, copy and their announcement strip; product and tab-product sections search real product records; brand and content-page sections search their matching records; and the icon section edits its own image, copy and destination items. The generic controls only cover truly shared concerns such as title and responsive visibility. This ensures a component's displayed name, its editable data, and its Blade renderer remain aligned.

Repeated-item editors use meaningful Chinese summaries and renderer-safe default values, so a newly added slide, tab, card, offer, review, or service row is immediately recognizable and usable. Record pickers give a live Chinese selection count, retain result names while editing, expose explicit remove actions, and use keyboard-operable native controls. The insertion panel shows each component’s Chinese name and purpose description, including migrated theme sections, so administrators choose by intended storefront behavior rather than by an internal code.

## Commerce and media adapters

`resources/beike/admin/js/builder/commerce.ts` defines `CommerceAdapter` and its `LongosCommerceAdapter` implementation. Puck resource fields and the native-section structured-link field use it for real, permission-protected product, category, brand and content-page searches; Shopify collection fields map to LONGOS categories at the boundary. A selected resource shows its available thumbnail, title and status metadata and can be cleared without memorising a database ID. The editor does not import Shopify data shapes or fabricate catalog records.

The image and local-video custom fields open the existing LONGOS file-manager in same-origin dialogs. The video dialog asks the manager to filter to video media and accepts only a returned `video/*` record; its local renderer accepts catalog-relative paths only. A separate external-video field validates YouTube/Vimeo URLs. All media dialogs use a narrow `postMessage` contract that accepts a selection only from their own manager iframe, so media permissions and upload validation remain server-owned. Shopify `link_list` is intentionally a recovery field: LONGOS currently has only a global header menu setting, not a separately addressable menu resource.

The Puck root inspector edits the complete normalized global token set: semantic colors, font family and size, page width, section/grid spacing, radius, product-card treatment, and reduced-motion preference. `DesignService` bounds every value on save and the default theme consumes the resulting CSS variables, so these are storefront settings rather than editor-only decoration.

## Rendering and responsive behavior

`DesignService` normalizes untrusted saves/imports and renders each module in isolation. Published module HTML is wrapped once with device-visibility data attributes. The storefront stylesheet applies desktop (1024px+), tablet (768–1023px), and mobile (0–767px) visibility rules. Thus existing and plugin module Blade files gain responsive visibility without duplicating logic.

The editor offers matching desktop, tablet, and mobile canvas widths. It also detects a module whose registry entry is unavailable: the raw configuration is retained, Puck renders an explicit recovery component, and the administrator can delete, replace, or later restore it by enabling the original theme/plugin.

`ShopifyRendererRegistry` keeps old storefront renderer capability independent from both the scanner and the new Puck inventory. The dedicated migration renderers remain available only to preserve historic saved storefront data. All migrated Shopify modules, including those that were previously verified, are intentionally excluded from Puck's insert list, search results, structure panel, and inspector. This keeps unsupported editing contracts out of the new editor without breaking an older page that still needs to render during transition.

The Builder inventory contains one component per capability. Retired legacy duplicates are retained only for historic storefront rendering and cannot be inserted: `img_text_banner` → `shopify-image-with-text`; `slideshow` → `shopify-slideshow`; `image100` → `shopify-image-banner`; `image200`, `image300`, `image301`, `image400`, `image401`, `image402`, `image403`, and `img_text_banner_multiple` → `shopify-multicolumn`; `rich_text` → `shopify-rich-text`; `faq` → `shopify-collapsible-content`; and `feature_grid` → `shopify-multicolumn`. The two image-text slideshow modules remain because their scrolling announcement-strip behavior is independent.

## Puck adoption boundary

`shopify-featured-blog` extends the registry with a real mapping: Shopify's blog setting is selected as a LONGOS content category through the permission-protected autocomplete API, and the storefront renders that category's newest active local pages. No Shopify blog object is evaluated at runtime.

Runtime-only Shopify surfaces are deliberately retired from the Builder inventory: `shopify-apps`, the cart icon and cart-notification fragments, `shopify-main-404`, and `shopify-main-password-footer`. They depend on an app host, a cart-notification runtime, or a route template that the normal LONGOS page-builder does not own. They have no editable, reliable page-module contract, so they are neither rendered nor offered as addable components.

For `shopify-main-page`, the implicit Shopify `page` runtime object becomes an explicit active LONGOS-page picker in Puck. Its safe resolved content has a single page-level heading; the section cannot render source Liquid or unpurified stored HTML.

The admin ships a separate React 19 + TypeScript + Puck 0.23 bundle at `build/beike/admin/js/puck-builder.js`; storefront assets do not import it. Puck is the sole editing canvas and posts its data to the existing endpoint, where `PuckDataAdapter` converts it back into the Builder domain schema. The previous native-editor route, controller, view, source bundle and compiled assets are removed.

The editor uses Puck's native workbench layout: its header, left component/structure panels, central canvas, and right inspector remain mounted at all times. The central canvas is overridden through Puck's `preview` extension point with a same-origin iframe for the selected home, product, category, or content page, so the active theme's header, footer, and placement modules render as shoppers see them. For product, category, and content-page previews, the header searches the existing permission-protected admin autocomplete endpoints and loads the chosen real storefront URL; it never substitutes a sample record or an editor-only card. Preview URLs deliberately use `design=1` without the historic `iframe=1` embed flag, because that old flag suppresses storefront chrome.

Puck's default block-card drawer is replaced with a searchable textual **Insert existing component** command list. It exposes only native catalog entries with a supported editing contract and verified storefront output, inserts into the durable root section order, selects the new item in the inspector, and relies on the normal autosave to refresh the real theme. Migrated Shopify types are compatibility-only and cannot be inserted. When an inserted component is limited to a product, category, or content page, the editor switches to that corresponding real preview so the result is immediately visible rather than appearing to be lost on the home page. Clicking a storefront module highlights it and selects the matching Puck component through Puck's public selector API using its durable `module_id`; selection, history, and unsaved state remain in one mounted Puck session. There is no overlay editor, hidden Puck canvas, or separate preview portal.

The **Structure** panel follows the selected preview page in real time. Switching the header page selector updates the Puck root `pageType`, reloads the matching storefront URL, and filters the structure rows to modules assigned to that page. Insertion follows the same direct-manipulation rule: the selected preview page is the destination, so the inspector has no separate “display location” selector. Header and footer are part of that same central iframe: an administrator can click either real storefront region (or choose **页头/页尾**), receives a clear green selection outline, and edits its Chinese settings in Puck's normal right inspector. The settings never open as a separate modal, and returning to a page module restores the ordinary module inspector.

## Shopify migration operations

`npm run shopify:migrate` runs the recursive scanner, IR analyzer, Puck registry generator and report writer as one idempotent operation. `npm run shopify:migrate -- --dry-run` performs the same analysis but writes no artifacts; `npm run shopify:validate` checks the scan inventory, IR references, registry types, and the mandatory declared runtime boundary on every `MANUAL_REVIEW` item. This pipeline only analyzes source—it never executes Liquid, Shopify scripts or third-party code.

## Verification

`tests/BuilderCoreAssertions.php` verifies the Puck round trip, a newly inserted module's durable ID/placement round trip, subscription-module retirement and validated advanced configuration path. It also verifies that the public Builder route resolves directly to the Puck entry view, so an old editor route cannot silently replace the sole editing surface. Runtime checks compile every default-theme design template, reject any uncompiled Blade directive, render normalized data through all native catalog modules and preserve migrated Shopify renderer compatibility without loading those types into Puck. PHP syntax checks cover the Builder schema, parser, scanner, command, catalog, service, and home renderer. The normal frontend build remains the final integration check after changing editor or storefront assets.
