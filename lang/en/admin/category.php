<?php

/**
 * order.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-02 14:22:41
 * @modified   2022-08-02 14:22:41
 */

return [
    'index'             => 'Category',
    'edit_category'     => 'Edit Category',
    'parent_category'   => 'Parent Category',
    'category_image'    => 'category image',

    'categories_index'                => 'Index',
    'categories_create'               => 'Create',
    'categories_show'                 => 'Detail',
    'categories_update'               => 'Edit',
    'categories_delete'               => 'Delete',
    'smart_add_products'              => 'Smart Auto-classification',
    'smart_title'                     => 'Smart selection: :category',
    'smart_description'               => 'Matches only product-name keywords or selected brands inside the current tenant. Product descriptions are not inspected and no data is sent to an external AI.',
    'smart_name_keywords'             => 'Product-name keywords',
    'smart_name_keywords_placeholder' => 'Separate keywords with commas or new lines, e.g. running shoes, sneaker',
    'smart_brands'                    => 'Selected brands',
    'smart_brands_placeholder'        => 'Select one or more brands',
    'smart_auto_enabled'              => 'Automatically classify future created or updated products',
    'smart_auto_help'                 => 'A product is added when its name matches any keyword OR its brand is selected.',
    'smart_save_rule'                 => 'Save auto rule only',
    'smart_rule_saved'                => 'The automatic classification rule was saved.',
    'smart_rule_required'             => 'Enter at least one product-name keyword or select one brand before enabling automatic classification.',
    'smart_invalid_brands'            => 'A selected brand is missing or outside the current tenant.',
    'smart_match_reason'              => 'Match reason',
    'smart_match_name'                => 'Name: :value',
    'smart_match_brand'               => 'Brand: :value',
    'smart_confidence'                => 'Match strength',
    'smart_strict'                    => 'Strict (most precise)',
    'smart_balanced'                  => 'Balanced (recommended)',
    'smart_broad'                     => 'Broad (more results)',
    'smart_rescan'                    => 'Analyze again',
    'smart_select_all'                => 'Select all results',
    'smart_clear'                     => 'Clear selection',
    'smart_product'                   => 'Product',
    'smart_score'                     => 'Score',
    'smart_keywords'                  => 'Matched keywords',
    'smart_status'                    => 'Status',
    'smart_summary'                   => 'Scanned :scanned / :eligible eligible products, found :matches matches, and returned :returned.',
    'smart_truncated'                 => 'Many products matched, so only the highest-ranked results are shown. Reduce keywords or brands to narrow the list.',
    'smart_empty'                     => 'No product name matched a keyword and no product belonged to a selected brand.',
    'smart_apply'                     => 'Add selected products',
    'smart_select_required'           => 'Select at least one candidate product.',
    'smart_preview_ready'             => 'Smart product analysis is ready.',
    'smart_added'                     => ':count products were added to this category.',
    'smart_category_disabled'         => 'Enable this category before running smart product selection.',
    'smart_upgrade_pending'           => 'Smart auto-classification is completing its automatic upgrade. Category management remains available; refresh shortly.',
];
