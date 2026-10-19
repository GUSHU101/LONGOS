# Shopify template migration report

## Inventory

- Source: `C:\Users\LONG\Downloads\beikeshop_v2.0.0.31\shopify模板组件`
- Files discovered: 348
- Component units: 45
- Scanner warnings: 0

### Files by type

- asset: 1
- config: 2
- json: 2
- layout: 2
- locale: 51
- script: 36
- section: 46
- snippet: 39
- style: 65
- svg: 88
- template: 16

## Migration status

- DISCOVERED: 0
- ANALYZED: 6
- AUTO_MIGRATABLE: 0
- MIGRATED: 14
- VERIFIED: 0
- MANUAL_REVIEW: 25
- BLOCKED: 0

### Withheld runtime boundaries

- 1 × contains a Shopify liquid setting; source Liquid is stored for review and is never executed
- 14 × requires LONGOS cart/session data and transactional cart-interaction adapters
- 14 × requires a CSRF-protected LONGOS form action and server-side validation mapping
- 2 × requires a LONGOS localized article-listing adapter
- 4 × requires a LONGOS localized article/detail adapter
- 1 × requires a LONGOS pickup-location and per-SKU inventory adapter
- 1 × requires a LONGOS predictive-search service adapter
- 7 × requires a LONGOS query, pagination, and URL-state adapter
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/cart-notification.js)
- 2 × requires a LONGOS replacement for Shopify browser runtime (assets/cart.js, assets/quantity-popover.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/disclosures.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/facets.js, assets/main-search.js, assets/show-more.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/product-form.js, assets/quick-add.js, assets/quick-add-bulk.js, assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/product-info.js, assets/product-form.js, assets/show-more.js, assets/price-per-item.js, assets/magnify.js, assets/theme-editor.js, assets/quick-add.js, assets/product-modal.js, assets/media-gallery.js, assets/product-model.js, assets/disclosures.js, assets/pickup-availability.js, assets/recipient-form.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/product-info.js, assets/show-more.js, assets/price-per-item.js, assets/product-form.js, assets/magnify.js, assets/theme-editor.js, assets/product-model.js, assets/product-modal.js, assets/media-gallery.js, assets/disclosures.js, assets/pickup-availability.js, assets/recipient-form.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/quick-add.js, assets/product-form.js, assets/quick-add-bulk.js, assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js, assets/facets.js, assets/show-more.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/quick-order-list.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/share.js)
- 1 × requires a LONGOS replacement for Shopify browser runtime (assets/theme-editor.js)
- 2 × requires a LONGOS search and facet-query adapter
- 1 × requires a verified LONGOS customer or contact-form endpoint
- 3 × requires an explicit mapping for Shopify global shop policies and navigation
- 9 × requires an explicit mapping from Shopify route helpers to LONGOS storefront routes
- 6 × requires page-bound LONGOS product, variant, price, and availability context
- 2 × requires route-bound LONGOS category context with pagination/filter mapping

## Coverage

- Schema settings discovered: 382
- Blocks discovered: 70
- Liquid tags: `liquid`, `if`, `endif`, `elsif`, `render`, `for`, `endfor`, `form`, `endform`, `schema`, `endschema`, `comment`, `endcomment`, `unless`, `endunless`, `#`, `assign`, `paginate`, `else`, `endpaginate`, `break`, `doc`, `capture`, `endcapture`, `style`, `endstyle`, `case`, `when`, `endcase`, `continue`, `javascript`, `endjavascript`, `cycle`
- Liquid filters: `asset_url`, `stylesheet_tag`, `t`, `escape`, `inline_asset_content`, `capitalize`, `line_items_for`, `sum`, `money`, `map`, `json`, `image_url`, `image_tag`, `unit_price_with_measurement`, `item_count_for_variant`, `standard_event_data`, `plus`, `divided_by`, `ceil`, `split`, `last`, `money_with_currency`, `times`, `minus`, `placeholder_svg_tag`, `strip_html`, `truncatewords`, `metafield_tag`, `round`, `first`, `floor`, `default`, `truncate`, `time_tag`, `payment_terms`, `where`, `structured_data`, `money_amount`, `payment_button`, `login_button`, `payment_type_svg_tag`, `date`, `link_to`, `prepend`, `append`, `at_least`, `money_without_currency`, `downcase`, `format_address`, `video_tag`
- Shopify objects: `section`, `forloop`, `block`, `class`, `settings`, `localPosition`, `localization`, `country`, `language`, `product`, `routes`, `items_in_cart`, `cart`, `variant`, `image`, `item`, `sku`, `price_break`, `price`, `price_break_price`, `paginate`, `anchor`, `part`, `variant_id`, `min`, `option`, `property`, `discount`, `wrapper_class`, `card_style`, `card_color_scheme`, `card_collection`, `placeholder_image`, `symbol`, `title`, `modal_id`, `cart_disclosure_rows`, `content`, `grid_space_desktop`, `grid_space_mobile`, `card_product`, `image_shape`, `section_id`, `rating_decimal`, `product_form_id`, `price_class`, `compare_at_price`, `money_price`, `desktop_tablet_padding`, `mobile_padding`, `file`, `form`, `customer`, `disclosures_content`, `img`, `summary_html`, `summary_item`, `details_html`, `details_item`, `heading_size`, `heading`, `article`, `media_height`, `products_to_display`, `cart_qty`, `variant_price_compare`, `featured_media`, `media`, `media_count`, `first_3d_model`, `media_width`, `media_index`, `thumbnail_id`, `gift_card_recipient_feature_active`, `mobile_columns`, `media_fit`, `value`, `escaped_value`, `label_unavailable`, `input_id`, `input_name`, `input_dataset`, `swatch_value`, `swatch_focal_point`, `gift_card_recipient_control_flag`, `field`, `max_chars_message`, `message_label_rendered`, `max_chars_message_rendered`, `type`, `id`, `name`, `additional_props`, `help_text`, `footer_grid_class`, `link`, `shop`, `powered_by_link`, `policy`, `header_tag`, `request`, `potential_action_target`, `search`, `color_scheme`, `blog`, `comment`, `post_message`, `share_link`, `content_for_additional_checkout_buttons`, `collection`, `results`, `template`, `results_url`, `filter`, `max`, `visual_layout_class`, `label_class`, `text_value`, `id_prefix`, `page`, `number_of_slides`, `empty_column`, `image_width`, `number_of_columns`, `grid_space_tablet`, `number_of_columns_mobile`, `borders_class`, `corners_class`, `padding_class`, `odd_class`, `closest_location`, `product_variant`, `product_option`, `availability`, `address`, `query`, `predictive_search`, `video_id`, `alt`, `poster`, `loop`
- Commerce bindings: `product`, `routes`, `cart`, `customer`, `article`, `shop`, `search`, `blog`, `collection`, `page`, `predictive_search`

### Field mapping

- puck_builtin_field: 372
- native_recovery_required: 36
- inspector_presentation: 98
- puck_safe_url_field: 10
- puck_media_field: 18
- puck_commerce_field: 10
- puck_external_video_field: 2
- restricted_never_executed: 3
- puck_video_media_field: 1

### Shopify setting types

- checkbox: 94
- range: 76
- color_scheme: 34
- header: 84
- paragraph: 14
- text: 28
- url: 10
- inline_richtext: 29
- select: 136
- image_picker: 18
- product: 2
- collection: 3
- video_url: 2
- richtext: 9
- page: 4
- liquid: 3
- blog: 1
- link_list: 2
- video: 1

## Component review queue

| Component | Complexity | Status | Runtime boundary / review reason | Settings | Blocks |
| --- | --- | --- | --- | ---: | ---: |
| announcement-bar | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/theme-editor.js)<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 9 | 1 |
| apps | HIGH | ANALYZED | — | 1 | 1 |
| bulk-quick-order-list | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/quick-order-list.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires page-bound LONGOS product, variant, price, and availability context<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a LONGOS query, pagination, and URL-state adapter | 0 | 0 |
| cart-drawer | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/cart.js, assets/quantity-popover.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes | 0 | 0 |
| cart-icon-bubble | CRITICAL | ANALYZED | — | 0 | 0 |
| cart-live-region-text | CRITICAL | MANUAL_REVIEW | requires LONGOS cart/session data and transactional cart-interaction adapters | 0 | 0 |
| cart-notification-button | LOW | ANALYZED | — | 0 | 0 |
| cart-notification-product | HIGH | ANALYZED | — | 0 | 0 |
| collage | CRITICAL | MANUAL_REVIEW | requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 10 | 4 |
| collapsible-content | HIGH | MIGRATED | — | 16 | 1 |
| collection-list | HIGH | MIGRATED | — | 13 | 1 |
| contact-form | CRITICAL | MANUAL_REVIEW | requires a verified LONGOS customer or contact-form endpoint<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 6 | 0 |
| custom-liquid | LOW | MANUAL_REVIEW | contains a Shopify liquid setting; source Liquid is stored for review and is never executed | 5 | 0 |
| disclosures | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/disclosures.js) | 8 | 0 |
| email-signup-banner | CRITICAL | MANUAL_REVIEW | requires a CSRF-protected LONGOS form action and server-side validation mapping | 13 | 3 |
| featured-blog | HIGH | MIGRATED | — | 15 | 0 |
| featured-collection | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/product-form.js, assets/quick-add.js, assets/quick-add-bulk.js, assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires a CSRF-protected LONGOS form action and server-side validation mapping<br>requires a LONGOS query, pagination, and URL-state adapter | 28 | 0 |
| featured-product | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/product-info.js, assets/show-more.js, assets/price-per-item.js, assets/product-form.js, assets/magnify.js, assets/theme-editor.js, assets/product-model.js, assets/product-modal.js, assets/media-gallery.js, assets/disclosures.js, assets/pickup-availability.js, assets/recipient-form.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires page-bound LONGOS product, variant, price, and availability context<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 14 | 13 |
| footer | CRITICAL | MANUAL_REVIEW | requires an explicit mapping for Shopify global shop policies and navigation<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 16 | 5 |
| header | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/cart-notification.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires a LONGOS search and facet-query adapter<br>requires an explicit mapping for Shopify global shop policies and navigation<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 18 | 1 |
| image-banner | HIGH | MIGRATED | — | 14 | 3 |
| image-with-text | HIGH | MIGRATED | — | 16 | 4 |
| main-404 | HIGH | ANALYZED | — | 0 | 0 |
| main-article | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/share.js)<br>requires a LONGOS localized article-listing adapter<br>requires a LONGOS localized article/detail adapter<br>requires a CSRF-protected LONGOS form action and server-side validation mapping<br>requires a LONGOS query, pagination, and URL-state adapter | 0 | 5 |
| main-blog | CRITICAL | MANUAL_REVIEW | requires a LONGOS localized article-listing adapter<br>requires a LONGOS localized article/detail adapter<br>requires a LONGOS query, pagination, and URL-state adapter | 8 | 0 |
| main-cart-footer | CRITICAL | MANUAL_REVIEW | requires LONGOS cart/session data and transactional cart-interaction adapters | 4 | 3 |
| main-cart-items | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/cart.js, assets/quantity-popover.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes | 4 | 0 |
| main-collection-banner | HIGH | MIGRATED | — | 4 | 0 |
| main-collection-product-grid | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/quick-add.js, assets/product-form.js, assets/quick-add-bulk.js, assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js, assets/facets.js, assets/show-more.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires route-bound LONGOS category context with pagination/filter mapping<br>requires a CSRF-protected LONGOS form action and server-side validation mapping<br>requires a LONGOS query, pagination, and URL-state adapter | 18 | 0 |
| main-list-collections | CRITICAL | MIGRATED | — | 5 | 0 |
| main-page | HIGH | MIGRATED | — | 3 | 0 |
| main-password-footer | HIGH | ANALYZED | — | 1 | 0 |
| main-password-header | CRITICAL | MANUAL_REVIEW | requires an explicit mapping for Shopify global shop policies and navigation<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 2 | 0 |
| main-product | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/product-info.js, assets/product-form.js, assets/show-more.js, assets/price-per-item.js, assets/magnify.js, assets/theme-editor.js, assets/quick-add.js, assets/product-modal.js, assets/media-gallery.js, assets/product-model.js, assets/disclosures.js, assets/pickup-availability.js, assets/recipient-form.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires page-bound LONGOS product, variant, price, and availability context<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 15 | 18 |
| main-search | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/facets.js, assets/main-search.js, assets/show-more.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires a LONGOS search and facet-query adapter<br>requires a LONGOS localized article/detail adapter<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a CSRF-protected LONGOS form action and server-side validation mapping<br>requires a LONGOS query, pagination, and URL-state adapter | 18 | 0 |
| multicolumn | HIGH | MIGRATED | — | 19 | 1 |
| multirow | HIGH | MIGRATED | — | 17 | 1 |
| page | MEDIUM | MIGRATED | — | 6 | 0 |
| pickup-availability | HIGH | MANUAL_REVIEW | requires a LONGOS pickup-location and per-SKU inventory adapter | 0 | 0 |
| predictive-search | CRITICAL | MANUAL_REVIEW | requires page-bound LONGOS product, variant, price, and availability context<br>requires route-bound LONGOS category context with pagination/filter mapping<br>requires a LONGOS predictive-search service adapter<br>requires a LONGOS localized article/detail adapter | 0 | 0 |
| quick-order-list | CRITICAL | MANUAL_REVIEW | requires a LONGOS replacement for Shopify browser runtime (assets/quantity-popover.js, assets/price-per-item.js, assets/quick-order-list.js)<br>requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires page-bound LONGOS product, variant, price, and availability context<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a LONGOS query, pagination, and URL-state adapter | 7 | 0 |
| related-products | CRITICAL | MANUAL_REVIEW | requires LONGOS cart/session data and transactional cart-interaction adapters<br>requires page-bound LONGOS product, variant, price, and availability context<br>requires an explicit mapping from Shopify route helpers to LONGOS storefront routes<br>requires a CSRF-protected LONGOS form action and server-side validation mapping | 16 | 0 |
| rich-text | HIGH | MIGRATED | — | 7 | 4 |
| slideshow | CRITICAL | MIGRATED | — | 10 | 1 |
| video | HIGH | MIGRATED | — | 16 | 0 |

Status is evidence-based: `ANALYZED` means the scanner/parser/IR completed; it does not claim storefront visual parity. `MANUAL_REVIEW` identifies a listed runtime-dependent or security boundary that must not be auto-executed.
