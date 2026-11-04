<?php

namespace Plugin\LongosGlobalTranslate\Services;

/**
 * Explicit, finite commerce-interface vocabulary.
 *
 * This is intentionally not a DOM scraper.  The entries below are static
 * labels rendered by LONGOS checkout, account and payment screens.  Customer
 * input, addresses, SKU names, quantities, amounts, order IDs, payment
 * method payloads and provider iframe content never appear here and can never
 * enter a translation-provider request through this catalog.
 */
final class CommerceUiCatalog
{
    public const KIND = 'commerce_ui';

    public const ENTITY_ID = 1;

    /** @return array<string, string> Stable field => locked English source. */
    public static function entries(): array
    {
        return [
            'checkout.contact_information' => 'Contact information',
            'checkout.email' => 'Email',
            'checkout.email_address' => 'Email address',
            'checkout.shipping_address' => 'Shipping address',
            'checkout.billing_address' => 'Billing address',
            'checkout.first_name' => 'First name',
            'checkout.last_name' => 'Last name',
            'checkout.country_region' => 'Country / Region',
            'checkout.phone_number' => 'Phone number',
            'checkout.address' => 'Address',
            'checkout.apartment' => 'Apartment, suite, unit',
            'checkout.city' => 'City',
            'checkout.state_province' => 'State / Province',
            'checkout.postal_code' => 'ZIP / Postal code',
            'checkout.delivery_method' => 'Delivery Method',
            'checkout.payment_method' => 'Payment method',
            'checkout.order_summary' => 'Order summary',
            'checkout.product_total' => 'Product total',
            'checkout.shipping_fee' => 'Shipping Fee',
            'checkout.order_total' => 'Order Total',
            'checkout.coupon' => 'Coupon',
            'checkout.secure_checkout' => 'Secure checkout',
            'checkout.use_this_address' => 'Use this address',
            'checkout.use_selected_address' => 'Use the selected address',
            'checkout.same_as_shipping' => 'Same as shipping address',
            'checkout.add_new_address' => 'Add new address',
            'checkout.select_country' => 'Select a country / region',
            'checkout.select_state' => 'Select a state / province',
            'checkout.place_order' => 'Place order',
            'checkout.pay_now' => 'Pay now',
            'checkout.back_to_cart' => 'Back to cart',
            'checkout.continue_to_payment' => 'Continue to payment',
            'checkout.order_confirmed' => 'Order confirmed',
            'checkout.thank_you' => 'Thank you for your order!',
            'checkout.loading' => 'Loading...',
            'checkout.retry' => 'Retry',
            'checkout.save_changes' => 'Save changes',
            'checkout.cancel' => 'Cancel',
            'checkout.edit' => 'Edit',
            'checkout.free' => 'Free',
            'account.my_account' => 'My account',
            'account.account_details' => 'Account details',
            'account.order_history' => 'Order history',
            'account.orders' => 'Orders',
            'account.addresses' => 'Addresses',
            'account.wishlist' => 'Wishlist',
            'account.personal_information' => 'Personal information',
            'account.change_password' => 'Change password',
            'account.current_password' => 'Current password',
            'account.new_password' => 'New password',
            'account.confirm_password' => 'Confirm password',
            'account.password' => 'Password',
            'account.login' => 'Login',
            'account.register' => 'Register',
            'account.logout' => 'Logout',
            'account.forgot_password' => 'Forgot password?',
            'account.save' => 'Save',
            'account.order_number' => 'Order number',
            'account.date' => 'Date',
            'account.status' => 'Status',
            'account.total' => 'Total',
            'account.view' => 'View',
            'account.no_orders' => 'No orders found.',
            'payment.payment' => 'Payment',
            'payment.processing' => 'Processing payment...',
            'payment.return_to_store' => 'Return to store',
            'common.continue' => 'Continue',
            'common.close' => 'Close',
        ];
    }

    public static function sourceFor(string $field): ?string
    {
        return self::entries()[$field] ?? null;
    }
}
