# Shopify theme migration

## Source and command

The repository contains the actual source directory `shopify模板组件` (the alternate `shopify模板组件文件内容` name from the project brief is supported when present).

Run:

```powershell
php artisan builder:inspect-shopify --output=storage/app/builder/shopify-theme-manifest.json
php artisan builder:analyze-shopify
php artisan builder:generate-puck-registry
php artisan builder:validate-shopify
```

The same idempotent workflow is available through `npm run shopify:migrate`; use `npm run shopify:migrate -- --dry-run` to inspect the exact scan/IR/registry counts without writing any artifact. Use `npm run shopify:validate` in CI or before reviewing a source-theme update. Validation proves that the recursive inventory, IR source references and generated Puck component types stay in sync. It does not execute Liquid, theme JavaScript or external application code.

The command recursively discovers every file, records its SHA-256 hash, classifies it by path and content, and emits a JSON manifest. It does not modify source theme files or database data.

The analysis command creates `storage/app/builder/shopify-template-migration-manifest.json` plus `docs/SHOPIFY-TEMPLATE-MIGRATION-REPORT.md`. The registry command consumes this IR artifact and writes `storage/app/builder/shopify-puck-registry.json`. Each definition carries a combined source hash, and unchanged IR does not rewrite the registry artifact. Puck loads the generated definitions under the **Shopify migrated** category. Every withheld definition also carries concrete `manual_review_reasons` (for example, cart-session interaction, predictive search, a protected form endpoint, or restricted Liquid), and validation fails if a review-only definition lacks one. Rerun all three commands after a source-theme update; source hashes make the resulting review auditable.

## Pipeline

```text
Shopify files → scanner/classifier → Liquid tokenizer/parser
→ dependency graph → section component units → component IR → Puck registry → JSON artifacts/report
```

`ShopifyLiquidParser` tokenizes text, output and tag nodes before extracting tags, objects, filters, section schemas and dependencies. It recognizes the principal control tags (`if`, `unless`, `case`, `for`, `assign`, `capture`, `render`, `include`, `form`, `paginate`, `style`, `javascript`, and `schema`) without treating a whole Liquid file as a regex substitution.

## Output model

Each source file has path, relative path, filename, extension, classification, size, hash, dependencies, reverse references, and warnings. Every Shopify section becomes a component unit containing its transitive local dependencies plus extracted schema settings, blocks, and presets.

The scanner distinguishes sections, snippets, templates, layouts, assets, styles, scripts, config, locale files, SVGs and generic JSON. Asset and snippet dependencies resolve to their real source path before a component unit is produced.

Generated field mappings preserve the original Shopify type alongside the Puck mapping, so text, rich text, ranges, checkboxes, selects and each repeated block's settings are editable in Puck. `image_picker` opens the existing permission-aware LONGOS media manager as a Puck Custom Field. `video` opens that same manager in a server-filtered MP4-only mode; it accepts the selected catalog path, never an arbitrary remote URL. `video_url` has a dedicated external-video control that validates YouTube and Vimeo URLs before save. Shopify `url` uses a dedicated safe-link field that accepts only relative, http(s), mailto, or tel destinations. Product/product-list, collection/collection-list (mapped to LONGOS categories), and page fields use the real permission-protected autocomplete APIs. Color fields have dedicated controls.

There is no independent LONGOS menu resource corresponding to Shopify `link_list`: the existing menu settings are one global header tree, while `/admin/menus` searches admin routes. The migration therefore leaves `link_list` as an explicit recovery-required field instead of corrupting a section by pretending that an admin navigation result is a storefront menu.

## Current baseline

The latest validated manifest records 348 source files, 348 IR records, 45 section component units and 45 generated registry entries. This is discovery coverage, not a claim that all 45 units have already been converted to React/Puck components. The manifest makes subsequent conversion incremental and auditable: source hash changes can be compared before regenerating an affected unit.

## Migration policy

Simple presentational sections should map to a registry component plus Blade renderer first, preserving Shopify schema settings as Builder fields. Dynamic Shopify runtime constructs, app blocks, and remote app APIs must stay explicitly represented in the manifest and receive a dedicated LONGOS integration instead of being silently converted into static text.

`ShopifyRendererRegistry` owns the independent mapping from a migrated component code to its purpose-built Blade renderer. Its `shopify-video` entry renders a responsive local MP4 or a fixed YouTube/Vimeo embed after server-side normalization; `shopify-slideshow` replaces Shopify Theme Editor-only code with a scoped native carousel, including accessible controls, pause, reduced-motion behavior and bounded local slide media; `shopify-image-banner` renders its dual-image hero, content blocks, controlled layout enums, and safe links; `shopify-rich-text` uses a restricted HTMLPurifier allowlist for its text blocks; `shopify-image-with-text` keeps its original media width, mobile alignment, overlap, and content-block semantics; `shopify-multirow` preserves repeated alternating media/text rows; `shopify-multicolumn` preserves its card grids, image treatment and safe actions; `shopify-collapsible-content` renders native details/summary controls with selected LONGOS page content resolved in the current locale; and `shopify-collection-list` resolves active local categories in the editor-configured order. Catalog media is preferred, while valid legacy HTTP(S) images remain compatible; these renderers never consume raw Liquid or executable URLs. The analyzer marks a non-runtime-dependent unit `MIGRATED` only when this registry provides such a renderer. `shopify-pickup-availability` remains review-only: this LONGOS installation has no authoritative pickup-location and per-SKU inventory adapter, so it is deliberately withheld rather than showing invented availability.

For a generated `shopify-*` component that does not yet have a dedicated storefront renderer, LONGOS uses the restricted `design.shopify_migrated` Blade recovery renderer. It exposes only escaped text content; it never executes Liquid, third-party scripts or arbitrary HTML. This prevents a component from disappearing while retaining a safe upgrade path to a purpose-built renderer.

`shopify-featured-blog` maps its Shopify `blog` field to the real LONGOS page-category picker, then resolves only active local pages from that category into normalized article cards. Its page title, summary, media, author, date, and URLs are all server-bound before Blade renders them. Shopify app-slot, cart-notification, password-template, and 404-template fragments are intentionally excluded: they do not have a reliable ordinary-page editing contract in LONGOS.

`shopify-main-page` makes Shopify's implicit template `page` object explicit in Puck with LONGOS's permission-protected page picker. It preserves source padding behavior, resolves only active local content in the current locale, purifies rich text before Blade receives it, and keeps a page-level heading for correct document structure.

`shopify-main-collection-banner` similarly turns Shopify's implicit `collection` runtime object into an active LONGOS-category picker. It resolves localized name, purified description, catalog image, and route only after server-side validation; its source display toggles remain editable in Puck.

Schema fields of Shopify type `liquid` are explicitly marked **stored for review, never executed** in Puck and force the component into `MANUAL_REVIEW`. This is a security status, not an error: the source is retained for a future restricted-template implementation without granting it runtime execution.
