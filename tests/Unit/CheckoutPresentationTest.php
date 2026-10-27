<?php

namespace Tests\Unit;

use Beike\Plugin\Plugin;
use PHPUnit\Framework\TestCase;

class CheckoutPresentationTest extends TestCase
{
    public function test_payment_plugins_use_the_checkout_locale_instead_of_the_admin_locale(): void
    {
        $plugin = (new Plugin('', []))
            ->setName(['zh_cn' => '中文支付名称', 'en' => 'English payment name'])
            ->setDescription(['zh_cn' => '中文支付说明', 'en' => 'English payment description']);

        self::assertSame('English payment name', $plugin->getLocaleName('en'));
        self::assertSame('English payment name', $plugin->getLocaleName('en_US'));
        self::assertSame('English payment description', $plugin->getLocaleDescription('en'));
        self::assertSame('中文支付名称', $plugin->getLocaleName('zh_cn'));

        $resource = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Shop/Http/Resources/Checkout/PaymentMethodItem.php');
        self::assertStringContainsString('getLocaleName(locale())', $resource);
        self::assertStringContainsString('getLocaleDescription(locale())', $resource);
        self::assertStringNotContainsString('Admin\\Http\\Resources\\PluginResource', $resource);
    }

    public function test_reference_checkout_layout_keeps_real_checkout_integrations(): void
    {
        $root     = dirname(__DIR__, 2);
        $checkout = (string) file_get_contents($root . '/themes/default/checkout.blade.php');
        $address  = (string) file_get_contents($root . '/themes/default/checkout/_address_inline.blade.php');

        self::assertStringContainsString('class="checkout-header"', $checkout);
        self::assertStringContainsString('class="checkout-wrapper"', $checkout);
        self::assertStringContainsString('class="checkout-left"', $checkout);
        self::assertStringContainsString('class="delivery-options"', $checkout);
        self::assertStringContainsString('class="payment-box"', $checkout);
        self::assertStringContainsString('class="order-card"', $checkout);
        self::assertStringContainsString('class="mobile-pay-bar"', $checkout);
        self::assertStringContainsString('class="product-quantity"', $checkout);
        self::assertStringContainsString('<h1 class="visually-hidden">', $checkout);
        self::assertStringContainsString(':focus-visible', $checkout);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $checkout);
        self::assertStringNotContainsString('checkout-progress__inner', $checkout);
        self::assertStringNotContainsString('checkout-panel', $checkout);
        self::assertStringNotContainsString('total-wrap', $checkout);

        self::assertStringContainsString("@include('checkout._address_inline')", $checkout);
        self::assertStringContainsString("@hookwrapper('checkout.shipping_method')", $checkout);
        self::assertStringContainsString("@hookwrapper('checkout.confirm')", $checkout);
        self::assertStringContainsString('window.longosStorefrontRoutes.checkoutConfirm', $checkout);
        self::assertStringContainsString('updateShippingMethods(res.shipping_methods', $checkout);
        self::assertStringContainsString('updatePaymentMethods(res.payment_methods', $checkout);
        self::assertStringContainsString("$('.checkout-submit-total').html(finalTotal)", $checkout);
        self::assertStringContainsString("$('.checkout-totals').html", $checkout);
        self::assertStringContainsString('escapeCheckoutText(item.title)', $checkout);
        self::assertStringContainsString('safeCheckoutImageUrl', $checkout);
        self::assertStringContainsString('stripCheckoutMarkup', $checkout);
        self::assertStringNotContainsString('{!! $shipping[\'html\'] !!}', $checkout);
        self::assertStringContainsString('{!! $shipping[\'safe_html\'] !!}', $checkout);
        self::assertStringContainsString('collectShippingQuoteData', $checkout);
        self::assertStringContainsString('data-shipping-quote-fields', $checkout);
        self::assertStringContainsString('let checkoutUpdateQueue = Promise.resolve()', $checkout);
        self::assertStringContainsString('const enqueueCheckoutUpdate = (payload)', $checkout);
        self::assertStringContainsString('checkoutUpdatePending > 0', $checkout);
        self::assertStringContainsString('id="checkout-error-summary"', $checkout);
        self::assertStringContainsString('role="alert"', $checkout);
        self::assertStringContainsString("__('shop/checkout.no_payment_methods')", $checkout);
        self::assertStringNotContainsString("__('shop/carts.invalid_payment_method')", $checkout);
        self::assertStringContainsString('window.showCheckoutError = showCheckoutError', $checkout);
        self::assertStringContainsString("res.status === 'fail'", $address);
        self::assertStringContainsString('{hmsg: true, hload: true}', $checkout);
        self::assertStringContainsString('aria-busy', $checkout);
        self::assertStringContainsString('class="pay-button__label"', $checkout);
        self::assertStringContainsString('class="pay-button__icon"', $checkout);
        self::assertStringContainsString('.pay-button:focus-visible', $checkout);
        self::assertStringContainsString('.pay-button:disabled', $checkout);
        self::assertStringContainsString('.field select { appearance: none;', $checkout);
        self::assertStringNotContainsString('{!! $checkoutTotalFormat !!}', $checkout);
        self::assertStringNotContainsString("{!! \$cart['price_format']", $checkout);
        self::assertStringNotContainsString('@json([', $checkout . $address);
        self::assertStringContainsString('Illuminate\Support\Js::from([', $checkout);
        self::assertStringContainsString('Illuminate\Support\Js::from([', $address);

        self::assertStringContainsString('v-model.trim="addressForm.email"', $address);
        self::assertStringContainsString('v-model.trim="addressForm.first_name"', $address);
        self::assertStringContainsString('v-model.trim="addressForm.last_name"', $address);
        self::assertStringContainsString("'shipping given-name' : 'billing given-name'", $address);
        self::assertStringContainsString("'shipping family-name' : 'billing family-name'", $address);
        self::assertStringContainsString('syncFullName()', $address);
        self::assertStringContainsString('[this.addressForm.first_name, this.addressForm.last_name]', $address);
        self::assertStringContainsString('v-model.number="addressForm.country_id"', $address);
        self::assertStringContainsString('class="row-2"', $address);
        self::assertStringContainsString('class="address-location-grid"', $address);
        self::assertStringContainsString('class="country-phone-grid"', $address);
        self::assertLessThan(
            strpos($address, 'checkout-address-phone'),
            strpos($address, 'checkout-address-country')
        );
        self::assertStringContainsString('checkout-address-suggestions', $address);
        self::assertStringNotContainsString('navigator.geolocation', $address);
        self::assertStringNotContainsString('addressLocationUse', $address);
        self::assertStringContainsString('params.country_code = selectedCountryCode', $address);
        self::assertStringContainsString('$http.post(checkoutAddressSuggestionsUrl, params', $address);
        self::assertStringContainsString('@click="selectAddressSuggestion(suggestion)"', $address);
        self::assertStringContainsString('scheduleAddressLookup', $address);
        self::assertStringContainsString('const requestId = ++this.addressLookupRequestId', $address);
        self::assertStringContainsString('this.lookupAddressSuggestions(requestId)', $address);
        self::assertStringContainsString("query !== String(this.addressForm.address_1 || '').trim()", $address);
        self::assertStringContainsString(':aria-busy="addressLookupLoading', $address);
        self::assertStringContainsString('clearAddressSuggestions(); countryChange(addressForm.country_id, true)', $address);
        self::assertStringContainsString('detectVisitorCountry', $address);
        self::assertStringContainsString('shipping address-line1', $address);
        self::assertStringContainsString('{ guest_shipping_address: address, guest_payment_address: address }', $address);
        self::assertStringContainsString('selectedAddressId()', $address);
        self::assertStringContainsString('this.shippingRequired ? this.form.shipping_address_id : this.form.payment_address_id', $address);
        self::assertStringContainsString('!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/', $address);
        self::assertStringNotContainsString('@click.stop="editSavedAddress(address)"', $address);
        self::assertStringContainsString('countryChange(countryId, resetZone = false)', $address);
        self::assertStringContainsString('const requestId = ++this.zoneRequestId', $address);
        self::assertStringContainsString(':required="phoneRequired"', $address);
        self::assertStringContainsString('enqueueCheckoutUpdate(payload).then((res)', $address);
        self::assertStringContainsString('if (this.zonesLoading || this.zonesLoadFailed)', $address);
        self::assertStringContainsString('readyForSubmit()', $address);
        self::assertStringContainsString('markAddressDirty()', $address);
        self::assertStringContainsString('retryZones()', $address);
        self::assertStringContainsString('this.addressCommitted && !regionIsValid', $address);
        self::assertStringContainsString('regionValidationBlocked', $address);
        self::assertStringContainsString('this.messages.countryUnavailable', $address);
        self::assertStringContainsString('zoneCode.split(\'-\').pop()', $address);
        self::assertStringContainsString('checkoutAddressApp.focusAddressEditor()', $checkout);
        self::assertStringContainsString('!addressReady', $checkout);
        self::assertStringContainsString('<button type="button" class="payment-header radio-line-item', $checkout);
        self::assertStringNotContainsString('<div class="payment-option radio-line-item', $checkout);
        self::assertStringNotContainsString('id="checkout-comment"', $checkout);
        self::assertStringNotContainsString("comment: $('textarea[name=comment]').val()", $checkout);
        self::assertStringContainsString('escapeCheckoutText(stripCheckoutMarkup(item.description))', $checkout);
        self::assertStringContainsString("\$shipping['description']", $checkout);
        self::assertStringContainsString('quote.description', $checkout);
        self::assertStringNotContainsString('quote.html', $checkout);
        self::assertStringContainsString("\$cart['subtotal_format']", $checkout);
        self::assertStringContainsString("\$cart['url']", $checkout);

        $routes = (string) file_get_contents($root . '/beike/Shop/Routes/shop.php');
        self::assertStringContainsString("Route::post('checkout/address-suggestions'", $routes);
        self::assertStringNotContainsString("Route::get('checkout/address-suggestions'", $routes);

        // Card details belong to the selected payment provider on the payment
        // step; the visual reference must not introduce fake card collection.
        self::assertStringNotContainsString('autocomplete="cc-number"', $checkout);
        self::assertStringNotContainsString('autocomplete="cc-csc"', $checkout);
    }

    public function test_checkout_confirm_is_serialized_and_transaction_safe(): void
    {
        $root    = dirname(__DIR__, 2);
        $service = (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php');

        self::assertStringContainsString("return 'checkout:confirm:' . \$this->cart->getKey()", $service);
        self::assertStringContainsString('Cache::lock($this->checkoutLockKey(), 60)', $service);
        self::assertStringContainsString('->lockForUpdate()', $service);
        self::assertStringContainsString('checkoutCartFingerprint', $service);
        self::assertStringContainsString('rememberConfirmation($checkoutToken, $order)', $service);
        self::assertStringContainsString('confirmedOrderNumber', $service);
        self::assertStringContainsString('CartRepo::selectedCartProducts', $service);
        self::assertStringContainsString('DB::transaction(function ()', $service);
        self::assertStringNotContainsString('DB::beginTransaction()', $service);
        self::assertStringNotContainsString('DB::rollBack()', $service);
    }

    public function test_checkout_does_not_expose_internal_errors_or_order_personal_data(): void
    {
        $root       = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/CheckoutController.php');

        self::assertStringContainsString("\$order->setVisible(['number', 'payment_recovery_url'])", $controller);
        self::assertStringContainsString("order_checkout_result_url(\$order, 'error')", $controller);
        self::assertStringContainsString("trans('shop/checkout.unable_update_checkout')", $controller);
        self::assertStringContainsString("trans('shop/checkout.unable_place_order')", $controller);
        self::assertStringContainsString('(new CheckoutService)->update($validated)', $controller);
        self::assertStringNotContainsString('$request->all()', $controller);
        self::assertStringContainsString('catch (CartException $e)', $controller);
        self::assertStringContainsString("'comment'", $controller);
        self::assertStringContainsString("'nullable|string|max:255'", $controller);
        self::assertStringContainsString("'checkout_token'", $controller);
        self::assertStringContainsString("'required|uuid'", $controller);
        self::assertStringContainsString("session('checkout_confirmations.' . \$checkoutToken)", $controller);
        self::assertStringContainsString('CheckoutService::confirmedOrderNumber($checkoutToken, $customer)', $controller);
        self::assertStringContainsString("response()->view('checkout', \$data)", $controller);
        self::assertStringContainsString('checkout_token: {!! \Illuminate\Support\Js::from($checkout_confirm_token', (string) file_get_contents($root . '/themes/default/checkout.blade.php'));

        $orders     = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/OrderController.php');
        $redirects  = (string) file_get_contents($root . '/app/Http/Middleware/RedirectSecondaryDomain.php');
        $newMail    = (string) file_get_contents($root . '/themes/default/mails/order_new.blade.php');
        $updateMail = (string) file_get_contents($root . '/themes/default/mails/order_update.blade.php');
        self::assertStringContainsString('$request->hasValidRelativeSignature() || $request->hasValidSignature()', $orders);
        self::assertStringContainsString("\$request->has('signature') && \$request->hasValidSignature()", $redirects);
        self::assertStringNotContainsString("\$request->get('email')", $orders);
        self::assertStringContainsString('order_access_url($order)', $newMail);
        self::assertStringContainsString('order_access_url($order)', $updateMail);
        self::assertStringNotContainsString("'email' => \$order->email", $newMail . $updateMail);
    }

    public function test_checkout_filters_unavailable_inventory_and_broken_optional_plugins(): void
    {
        $root     = dirname(__DIR__, 2);
        $cart     = (string) file_get_contents($root . '/beike/Shop/Services/CartService.php');
        $plugins  = (string) file_get_contents($root . '/beike/Repositories/PluginRepo.php');
        $checkout = (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php');
        $state    = (string) file_get_contents($root . '/beike/Services/StateMachineService.php');
        $worker   = (string) file_get_contents($root . '/beike/Console/Commands/ProcessOrder.php');
        $paypal   = (string) file_get_contents($root . '/plugins/Paypal/Controllers/PaypalController.php');
        $stripe   = (string) file_get_contents($root . '/plugins/Stripe/Controllers/StripeController.php');
        $payments = (string) file_get_contents($root . '/beike/Repositories/OrderPaymentRepo.php');

        self::assertStringContainsString('$skuQuantity < 1', $cart);
        self::assertStringContainsString('! $sku?->active', $cart);
        self::assertStringContainsString('Payment plugin skipped during discovery.', $plugins);
        self::assertStringContainsString('Shipping plugin skipped during discovery.', $plugins);
        self::assertStringContainsString('Payment plugin skipped while building checkout methods.', $checkout);
        self::assertStringContainsString('Payment plugin skipped because its runtime routes are unavailable.', $checkout);
        self::assertStringContainsString("'paypal' => ['shop.paypal.create', 'shop.paypal.capture']", $checkout);
        self::assertStringContainsString("'stripe' => ['shop.stripe.intent', 'shop.stripe_capture']", $checkout);
        self::assertStringContainsString('CartService::resetRuntimeCache()', $checkout);
        self::assertStringContainsString('Payment plugin skipped because its checkout template is missing.', $checkout);
        self::assertStringContainsString("self::UNPAID => ['reserveStock'", $state);
        self::assertStringContainsString("self::CANCELLED => ['revertStock'", $state);
        self::assertStringContainsString('lockForUpdate()->firstOrFail()', $state);
        self::assertStringContainsString("['stock_reserved' => true]", $state);
        self::assertStringContainsString("['stock_reserved' => false]", $state);
        self::assertStringContainsString('DB::transaction(function () use ($status, $comment, $notify)', $state);
        self::assertStringContainsString('DB::afterCommit(function () use ($order)', $state);
        self::assertStringContainsString('public function markPaid(', $state);
        self::assertStringContainsString('->markPaid()', $paypal);
        self::assertStringContainsString('->markPaid()', $stripe);
        self::assertStringNotContainsString('DB::beginTransaction()', $paypal);
        self::assertStringNotContainsString('DB::rollBack()', $paypal);
        self::assertStringContainsString("firstOrNew(['order_id' => \$orderId])", $payments);
        self::assertStringContainsString('lockForUpdate()->firstOrFail()', $payments);
        self::assertStringContainsString('->lazyById(100)', $worker);
        self::assertStringContainsString('Unable to auto-cancel order; continuing with the next order.', $worker);

        $routes = (string) file_get_contents($root . '/beike/Shop/Routes/shop.php');
        self::assertStringContainsString("[\\Beike\\Shop\\Http\\Controllers\\OrderController::class, 'pay']", $routes);
    }

    public function test_checkout_translations_and_address_validation_stay_in_sync(): void
    {
        $root    = dirname(__DIR__, 2);
        $english = require $root . '/lang/en/shop/checkout.php';
        $chinese = require $root . '/lang/zh_cn/shop/checkout.php';
        $request = (string) file_get_contents($root . '/beike/Shop/Http/Requests/AddressRequest.php');

        $englishKeys = array_keys($english);
        $chineseKeys = array_keys($chinese);
        sort($englishKeys);
        sort($chineseKeys);
        self::assertSame($englishKeys, $chineseKeys);
        self::assertStringContainsString('Rule::requiredIf($countryHasZones)', $request);
        self::assertStringContainsString("'city'       => 'required|string|max:120'", $request);
        self::assertStringContainsString("'address_1'  => 'required|string|max:255'", $request);

        $addressService  = (string) file_get_contents($root . '/beike/Shop/Services/AddressService.php');
        $addressResource = (string) file_get_contents($root . '/beike/Shop/Http/Resources/Account/AddressResource.php');
        $addressRepo     = (string) file_get_contents($root . '/beike/Repositories/AddressRepo.php');
        self::assertStringContainsString("\$zone?->name ?? ''", $addressService);
        self::assertStringContainsString('private static function normalizeLocation', $addressService);
        self::assertStringContainsString("where('country_id', \$countryId)", $addressService);
        self::assertStringContainsString("getRelation('zone')", $addressResource);
        self::assertStringContainsString('$customer?->address_id', $addressResource);
        self::assertStringContainsString("with(['country', 'zone'])", $addressRepo);

        $cartRepo = (string) file_get_contents($root . '/beike/Repositories/CartRepo.php');
        self::assertStringContainsString('(int) $shippingAddress->customer_id !== (int) $customerId', $cartRepo);
        self::assertStringContainsString('(int) $paymentAddress->customer_id !== (int) $customerId', $cartRepo);

        self::assertSame('First name', $english['first_name']);
        self::assertSame('Last name', $english['last_name']);
        self::assertSame('Live shipment tracking', $english['shipment_tracking']);
        self::assertSame('实时物流追踪', $chinese['shipment_tracking']);
        self::assertStringContainsString('cart is saved', $english['no_payment_methods']);
        self::assertStringContainsString('购物车已为您保留', $chinese['no_payment_methods']);
    }

    public function test_shipping_quotes_totals_and_live_tracking_use_one_hardened_contract(): void
    {
        $root            = dirname(__DIR__, 2);
        $shippingMethods = (string) file_get_contents($root . '/beike/Services/ShippingMethodService.php');
        $shippingTotal   = (string) file_get_contents($root . '/beike/Shop/Services/TotalServices/ShippingService.php');
        $checkoutService = (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php');
        $flatShipping    = (string) file_get_contents($root . '/plugins/FlatShipping/Bootstrap.php');
        $shipmentService = (string) file_get_contents($root . '/beike/Services/ShipmentService.php');
        $orderRepo       = (string) file_get_contents($root . '/beike/Repositories/OrderRepo.php');
        $orderModel      = (string) file_get_contents($root . '/beike/Models/Order.php');
        $quoteSanitizer  = (string) file_get_contents($root . '/beike/Services/ShippingQuoteHtmlSanitizer.php');
        $expressSetting  = (string) file_get_contents($root . '/resources/beike/admin/views/pages/setting/express.blade.php');
        $orderInfo       = (string) file_get_contents($root . '/themes/default/shared/order_info.blade.php');
        $orderMail       = (string) file_get_contents($root . '/themes/default/mails/order_update.blade.php');

        self::assertStringContainsString("\$quote['code'] = \$pluginCode . '.' . \$suffix", $shippingMethods);
        self::assertStringContainsString("\$quote['description'] = \$description", $shippingMethods);
        self::assertStringContainsString('Duplicate shipping quote skipped.', $shippingMethods);
        self::assertStringContainsString('ShippingQuoteHtmlSanitizer', $shippingMethods);
        self::assertStringContainsString('public array $shippingMethods = []', $checkoutService);
        self::assertStringContainsString('$this->shippingMethods = $shipments', $checkoutService);
        self::assertStringContainsString("\$extra['shipping_quote_data']", $checkoutService);
        self::assertStringContainsString('filterQuoteData($currentQuote, $quoteData)', $checkoutService);
        self::assertStringContainsString('applyFieldValues', $quoteSanitizer);
        self::assertStringContainsString("'shipping_quote_data'    => \$shippingQuoteData ?: null", $orderRepo);
        self::assertStringContainsString("'shipping_quote_data' => 'array'", $orderModel);
        self::assertStringContainsString('$shippingMethods = $this->shippingMethods ?:', $checkoutService);
        self::assertStringContainsString("\$selectedQuote['cost']", $shippingTotal);
        self::assertStringNotContainsString('->getShippingFee(', $shippingTotal);
        self::assertStringContainsString("'description' =>", $flatShipping);

        self::assertStringContainsString('{tracking_number}', $expressSetting);
        self::assertStringContainsString('inputmode="url"', $expressSetting);
        self::assertStringContainsString("str_contains(\$template, '{tracking_number}')", $shipmentService);
        self::assertStringContainsString("hook_filter('service.shipment.tracking_url'", $shipmentService);
        self::assertStringContainsString("!== 'https'", $shipmentService);
        self::assertStringContainsString('ShipmentService::trackingUrl($ship)', $orderInfo);
        self::assertStringContainsString("__('order.track_shipment')", $orderInfo);
        self::assertStringContainsString('ShipmentService::trackingUrl($ship)', $orderMail);
    }
}
