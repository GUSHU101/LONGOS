<?php

namespace Plugin\LongosGlobalTranslate\Services;

/**
 * Explicit, finite public storefront-interface vocabulary.
 *
 * The catalog is built only from LONGOS' checked-in English storefront
 * language files plus a small compatibility set retained for older themes.
 * It is deliberately not a DOM scraper: merchant content is handled by its
 * own public-content contracts, while customer input, addresses, SKU names, quantities, amounts, order IDs,
 * payment-method payloads and provider iframe content can never enter a translation-provider request through this class.
 */
final class CommerceUiCatalog
{
    public const KIND = 'commerce_ui';

    public const ENTITY_ID = 1;

    /**
     * Only language packs used by public storefront templates are admitted.
     * Mail, tenant-control-plane and arbitrary application language files
     * must never be added here. A small number of legacy admin-namespaced
     * labels are intentionally listed below only because the default public
     * theme renders those exact static labels.
     *
     * @var list<string>
     */
    private const STOREFRONT_LANGUAGE_FILES = [
        'lang/en/shop/account.php',
        'lang/en/shop/account/addresses.php',
        'lang/en/shop/account/edit.php',
        'lang/en/shop/account/order.php',
        'lang/en/shop/account/order_info.php',
        'lang/en/shop/account/order_success.php',
        'lang/en/shop/account/password.php',
        'lang/en/shop/account/rma.php',
        'lang/en/shop/account/rma_form.php',
        'lang/en/shop/account/rma_info.php',
        'lang/en/shop/account/wishlist.php',
        'lang/en/shop/brands.php',
        'lang/en/shop/carts.php',
        'lang/en/shop/categories.php',
        'lang/en/shop/checkout.php',
        'lang/en/shop/common.php',
        'lang/en/shop/currency.php',
        'lang/en/shop/design.php',
        'lang/en/shop/file.php',
        'lang/en/shop/footer.php',
        'lang/en/shop/forgotten.php',
        'lang/en/shop/header.php',
        'lang/en/shop/login.php',
        'lang/en/shop/order.php',
        'lang/en/shop/order_recovery.php',
        'lang/en/shop/products.php',
        'lang/en/shop/steps.php',
        'lang/en/shop/tracking.php',
        'lang/en/shop/wishlist.php',
        // These framework-level packs are referenced directly by the default
        // public theme (pagination, address, RMA and hosted payment views).
        'lang/en/address.php',
        'lang/en/admin/attribute.php',
        'lang/en/admin/builder.php',
        'lang/en/admin/product.php',
        'lang/en/auth.php',
        'lang/en/cart.php',
        'lang/en/common.php',
        'lang/en/maintenance.php',
        'lang/en/order.php',
        'lang/en/page_category.php',
        'lang/en/pagination.php',
        'lang/en/platform_pay.php',
        'lang/en/policy.php',
        'lang/en/product.php',
        'lang/en/rma.php',
    ];

    /**
     * Public extension templates have their own namespaces rather than using
     * the default theme packs.  Keep this allow-list at the exact field
     * level: it covers only labels rendered in storefront, checkout or shop
     * widgets, and cannot accidentally schedule an extension's admin/help
     * screen for translation.
     *
     * Entries that contain interpolation placeholders are harmlessly skipped
     * by isSafeStaticSource().  They remain here so a future static rewrite
     * of the corresponding public template is picked up automatically.
     *
     * @var array<string, list<string>>
     */
    private const STOREFRONT_PLUGIN_LANGUAGE_FIELDS = [
        'plugins/LongosPlatformPay/Lang/en/common.php' => [
            'secure_title', 'secure_help', 'initializing', 'choose_method',
            'methods_label', 'amount', 'paypal_help', 'card', 'card_help',
            'card_details', 'pay_now', 'pay_amount', 'retry', 'fallback',
            'connecting', 'connect_paypal', 'verify_paypal', 'create_stripe',
            'verifying', 'checking', 'processing', 'cancelled', 'locked',
            'missing_redirect', 'stripe_incomplete', 'paypal_load',
            'paypal_unavailable', 'stripe_load', 'sdk_timeout', 'sdk_failed',
            'unavailable', 'failed', 'timeout', 'inline_title', 'inline_intro',
            'inline_loading', 'inline_processing', 'inline_choose',
            'card_incomplete', 'configuration_changed', 'session_created',
            'status_verified', 'session_failed',
        ],
        'plugins/LongosLiveChat/Lang/en/chat.php' => [
            'title', 'send', 'placeholder', 'close_window', 'start',
            'start_hint', 'name', 'email', 'load_older', 'loading', 'sending',
            'retry_send', 'connected', 'operator_online', 'operator_offline',
            'unread_replies', 'reconnecting', 'init_failed', 'send_failed',
        ],
        'plugins/LongosProductReviews/Lang/en/reviews.php' => [
            'title', 'out_of_five', 'global_rating', 'star_filter',
            'all_ratings', 'with_photos', 'sort_reviews', 'sort_featured',
            'sort_newest', 'sort_highest', 'sort_lowest', 'clear_filters',
            'rating_distribution', 'verified_customer_feedback',
            'verified_purchase', 'load_more', 'loading', 'load_error',
            'all_loaded', 'no_matching_reviews', 'images', 'review_photo_alt',
            'open_review_photo', 'close_photo', 'previous_photo', 'next_photo',
        ],
        'plugins/Paypal/Lang/en/checkout.php' => [
            'currency_conversion', 'not_configured', 'connecting', 'redirecting', 'creating',
            'approve', 'confirming', 'completed', 'cancelled', 'failed',
            'not_completed', 'start_failed', 'load_failed', 'display_failed',
            'unavailable', 'missing_order_id',
        ],
        'plugins/Stripe/Lang/en/common.php' => [
            'title_info', 'cardnum', 'expiration_date', 'cvv', 'btn_submit',
            'inline_title', 'inline_intro', 'cardholder_name',
            'cardholder_required', 'card_details_incomplete', 'inline_loading',
            'inline_processing', 'capture_fail',
        ],
    ];

    /** @var array<string, string>|null */
    private static ?array $cachedEntries = null;

    /**
     * Stable field => locked English source.
     *
     * Compatibility keys are kept stable so an already published dictionary
     * remains usable after the catalog receives additional default-theme
     * coverage. New language-pack keys are deterministic and source values
     * are de-duplicated before scheduling provider work.
     *
     * @return array<string, string>
     */
    public static function entries(): array
    {
        if (self::$cachedEntries !== null) {
            return self::$cachedEntries;
        }

        $entries = self::legacyEntries();
        $seen    = array_fill_keys(array_values($entries), true);

        foreach (self::STOREFRONT_LANGUAGE_FILES as $relativePath) {
            self::appendLanguagePack($entries, $seen, $relativePath);
        }

        foreach (self::STOREFRONT_PLUGIN_LANGUAGE_FIELDS as $relativePath => $fields) {
            self::appendLanguagePack($entries, $seen, $relativePath, $fields);
        }

        return self::$cachedEntries = $entries;
    }

    public static function sourceFor(string $field): ?string
    {
        return self::entries()[$field] ?? null;
    }

    /**
     * Public contract used by the regression audit: only static text that
     * satisfies this predicate may be sent to a translation provider or be
     * exposed through the storefront interface dictionary.
     */
    public static function acceptsStaticSource(string $source): bool
    {
        return self::isSafeStaticSource(self::canonicalSource($source));
    }

    /**
     * Use the same canonical source representation in the browser audit as
     * the dictionary builder. Non-breaking spaces occur in a few upstream
     * public labels and must not produce a false coverage miss.
     */
    public static function canonicalSource(string $source): string
    {
        return self::normalize($source);
    }

    /** @return array<string, string> */
    private static function legacyEntries(): array
    {
        return [
            'checkout.contact_information'  => 'Contact information',
            'checkout.email'                => 'Email',
            'checkout.email_address'        => 'Email address',
            'checkout.shipping_address'     => 'Shipping address',
            'checkout.billing_address'      => 'Billing address',
            'checkout.first_name'           => 'First name',
            'checkout.last_name'            => 'Last name',
            'checkout.country_region'       => 'Country / Region',
            'checkout.phone_number'         => 'Phone number',
            'checkout.address'              => 'Address',
            'checkout.apartment'            => 'Apartment, suite, unit',
            'checkout.city'                 => 'City',
            'checkout.state_province'       => 'State / Province',
            'checkout.postal_code'          => 'ZIP / Postal code',
            'checkout.delivery_method'      => 'Delivery Method',
            'checkout.payment_method'       => 'Payment method',
            'checkout.order_summary'        => 'Order summary',
            'checkout.product_total'        => 'Product total',
            'checkout.shipping_fee'         => 'Shipping Fee',
            'checkout.order_total'          => 'Order Total',
            'checkout.coupon'               => 'Coupon',
            'checkout.secure_checkout'      => 'Secure checkout',
            'checkout.use_this_address'     => 'Use this address',
            'checkout.use_selected_address' => 'Use the selected address',
            'checkout.same_as_shipping'     => 'Same as shipping address',
            'checkout.add_new_address'      => 'Add new address',
            'checkout.select_country'       => 'Select a country / region',
            'checkout.select_state'         => 'Select a state / province',
            'checkout.place_order'          => 'Place order',
            'checkout.pay_now'              => 'Pay now',
            'checkout.back_to_cart'         => 'Back to cart',
            'checkout.continue_to_payment'  => 'Continue to payment',
            'checkout.order_confirmed'      => 'Order confirmed',
            'checkout.thank_you'            => 'Thank you for your order!',
            'checkout.loading'              => 'Loading...',
            'checkout.retry'                => 'Retry',
            'checkout.save_changes'         => 'Save changes',
            'checkout.cancel'               => 'Cancel',
            'checkout.edit'                 => 'Edit',
            'checkout.free'                 => 'Free',
            'account.my_account'            => 'My account',
            'account.account_details'       => 'Account details',
            'account.order_history'         => 'Order history',
            'account.orders'                => 'Orders',
            'account.addresses'             => 'Addresses',
            'account.wishlist'              => 'Wishlist',
            'account.personal_information'  => 'Personal information',
            'account.change_password'       => 'Change password',
            'account.current_password'      => 'Current password',
            'account.new_password'          => 'New password',
            'account.confirm_password'      => 'Confirm password',
            'account.password'              => 'Password',
            'account.login'                 => 'Login',
            'account.register'              => 'Register',
            'account.logout'                => 'Logout',
            'account.forgot_password'       => 'Forgot password?',
            'account.save'                  => 'Save',
            'account.order_number'          => 'Order number',
            'account.date'                  => 'Date',
            'account.status'                => 'Status',
            'account.total'                 => 'Total',
            'account.view'                  => 'View',
            'account.no_orders'             => 'No orders found.',
            'payment.payment'               => 'Payment',
            'payment.processing'            => 'Processing payment...',
            'payment.return_to_store'       => 'Return to store',
            'common.continue'               => 'Continue',
            'common.close'                  => 'Close',
            // Laravel's stock public error and pagination templates use raw
            // keys rather than a language-file namespace. These are fixed
            // UI labels; the dynamic “Go to page :page” variant is excluded
            // by design because it contains a rendered value.
            'errors.forbidden'             => 'Forbidden',
            'errors.page_expired'          => 'Page Expired',
            'errors.pagination_navigation' => 'Pagination Navigation',
            'errors.server_error'          => 'Server Error',
            'errors.service_unavailable'   => 'Service Unavailable',
            'errors.too_many_requests'     => 'Too Many Requests',
            'errors.unauthorized'          => 'Unauthorized',
            'pagination.showing'           => 'Showing',
            'pagination.of'                => 'of',
            'pagination.results'           => 'results',
            'pagination.to'                => 'to',
        ];
    }

    /**
     * @param array<string, string> $entries
     * @param array<string, true>   $seen
     * @param list<string>|null     $allowedFields
     */
    private static function appendLanguagePack(array &$entries, array &$seen, string $relativePath, ?array $allowedFields = null): void
    {
        $absolutePath = base_path($relativePath);
        if (! is_file($absolutePath)) {
            return;
        }

        try {
            $values = require $absolutePath;
        } catch (\Throwable) {
            // A malformed optional language file must not turn the
            // storefront widget into a 500 page. The audited compatible
            // vocabulary above remains available until deployment fixes the
            // source file.
            return;
        }
        if (! is_array($values)) {
            return;
        }

        $allowed = $allowedFields === null ? null : array_fill_keys($allowedFields, true);
        foreach (self::flatten($values) as $path => $source) {
            if ($allowed !== null && ! isset($allowed[$path])) {
                continue;
            }

            $source = self::normalize($source);
            if (! self::isSafeStaticSource($source) || isset($seen[$source])) {
                continue;
            }

            $field           = 'theme.' . str_replace(['/', '.php'], ['.', ''], $relativePath) . '.' . $path;
            $entries[$field] = $source;
            $seen[$source]   = true;
        }
    }

    /** @param array<string|int, mixed> $values @return array<string, string> */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flattened = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flattened += self::flatten($value, $path);
            } elseif (is_string($value)) {
                $flattened[$path] = $value;
            }
        }

        return $flattened;
    }

    private static function normalize(string $source): string
    {
        $source = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = str_replace("\xc2\xa0", ' ', $source);

        return trim((string) preg_replace('/\s+/u', ' ', $source));
    }

    private static function isSafeStaticSource(string $source): bool
    {
        if (mb_strlen($source) < 2 || mb_strlen($source) > 240 || ! mb_check_encoding($source, 'UTF-8')) {
            return false;
        }

        // Interpolated values can contain an order number, amount, customer
        // name, address or a payment-provider response after Laravel renders
        // the template. Those are intentionally left to the original UI.
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $source) === 1 || str_contains($source, '|') || str_contains($source, '{')) {
            return false;
        }

        return ! str_contains($source, '<')
            // PAN-related wording stays outside the public dictionary even
            // where a stock security sentence mentions it.
            && preg_match('/\bcard\s+num(?:ber)?\b/i', $source) !== 1
            && ! preg_match('~(?:https?://|mailto:|\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b)~iu', $source);
    }
}
