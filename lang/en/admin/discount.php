<?php

return [
    'title'            => 'Discounts', 'description' => 'Create automatic promotions or customer-entered discount codes. Leave product IDs blank to apply a rule storewide.', 'add' => 'Add discount',
    'name'             => 'Name', 'mode' => 'Method', 'type' => 'Discount type', 'rule' => 'Rule', 'status' => 'Status', 'period' => 'Active period', 'code' => 'Code', 'empty' => 'No discount campaigns have been created.',
    'campaign_name'    => 'Campaign name', 'name_placeholder' => 'Example: Summer storewide 10% off', 'automatic' => 'Automatic discount', 'discount_code' => 'Discount code',
    'product_amount'   => 'Product discount', 'buy_x_get_y' => 'Buy X, get Y', 'order_amount' => 'Order discount', 'free_shipping' => 'Free shipping',
    'value'            => 'Discount value', 'percentage' => 'Percentage', 'fixed' => 'Fixed amount', 'buy_get' => 'Buy / get', 'buy' => 'Buy', 'get' => 'Get',
    'gift_product_ids' => 'Gift product IDs', 'gift_product_ids_help' => 'Separate multiple IDs with commas. Leave blank to use the lowest-priced item in the qualifying range.',
    'minimum_amount'   => 'Minimum order amount', 'minimum_amount_help' => 'Calculated in the USD base currency. Enter 0 for no amount threshold.',
    'minimum_quantity' => 'Minimum item quantity', 'minimum_quantity_help' => 'Enter 0 for no quantity threshold. Product discounts count qualifying products; order and shipping discounts count the whole order.', 'items' => 'items',
    'product_ids'      => 'Eligible product IDs', 'product_ids_help' => 'Separate multiple IDs with commas; leave blank for all products.', 'starts_at' => 'Starts at', 'ends_at' => 'Ends at', 'unlimited' => 'No limit', 'name_required' => 'Enter a campaign name.', 'code_required' => 'Enter a discount code.', 'code_taken' => 'This discount code is already in use.',
    'js'               => [
        'enabled'                => 'Enabled', 'disabled' => 'Disabled', 'edit' => 'Edit discount', 'add' => 'Add discount', 'automatic' => 'Automatic discount', 'discount_code' => 'Discount code',
        'types'                  => ['product_amount' => 'Product discount', 'buy_x_get_y' => 'Buy X, get Y', 'order_amount' => 'Order discount', 'free_shipping' => 'Free shipping'],
        'type_descriptions'      => ['product_amount' => 'Apply a percentage or fixed discount to qualifying products.', 'buy_x_get_y' => 'Unlock gift items after the required qualifying quantity is purchased.', 'order_amount' => 'Apply a percentage or fixed discount to the order subtotal.', 'free_shipping' => 'Remove the selected shipping charge after all thresholds are met.'],
        'minimum_amount_summary' => 'spend :amount', 'minimum_quantity_summary' => 'buy :quantity items', 'and' => ' and ', 'threshold_separator' => ' · when ', 'buy_get_summary' => 'Buy :buy, get :get', 'free_shipping' => 'Free shipping', 'value_summary' => 'Save :value',
        'now'                    => 'Now', 'to' => 'to', 'long_term' => 'No end date', 'delete_confirm' => 'This discount will no longer be available after deletion. Continue?',
    ],
];
