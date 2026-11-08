<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StorefrontPurchaseJourneyTest extends TestCase
{
    public function test_search_dead_end_is_replaced_with_recovery_and_recommendations(): void
    {
        $root       = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/ProductController.php');
        $view       = (string) file_get_contents($root . '/themes/default/search.blade.php');
        $styles     = (string) file_get_contents($root . '/resources/beike/shop/default/css/search-journey.scss');

        self::assertStringContainsString('mb_substr(trim($rawKeyword), 0, 120)', $controller);
        self::assertStringContainsString('$products->isEmpty() ? $feeds->bestsellers(8) : []', $controller);
        self::assertStringContainsString('class="search-page-form"', $view);
        self::assertStringContainsString('role="search"', $view);
        self::assertStringContainsString('class="search-empty-state"', $view);
        self::assertStringContainsString("__('shop/products.try_another_search')", $view);
        self::assertStringContainsString("__('shop/products.recommended_products')", $view);
        self::assertStringNotContainsString('<x-shop-no-data />', $view);
        self::assertStringContainsString('min-height: 44px;', $styles);
        self::assertStringContainsString('@media (max-width: 767.98px)', $styles);
    }

    public function test_cart_serializes_updates_and_blocks_checkout_until_totals_are_current(): void
    {
        $root = dirname(__DIR__, 2);
        $cart = (string) file_get_contents($root . '/themes/default/cart/cart.blade.php');

        self::assertStringContainsString(':disabled="!canCheckout"', $cart);
        self::assertStringContainsString(':aria-busy="cartBusy', $cart);
        self::assertStringContainsString('class="cart-sync-status" role="status" aria-live="polite"', $cart);
        self::assertStringContainsString('queuedQuantityUpdates', $cart);
        self::assertStringContainsString('this.cartMutationChain.then(request)', $cart);
        self::assertStringContainsString('this.cartMutationChain = operation.catch(() => {})', $cart);
        self::assertStringContainsString('confirmedQuantities', $cart);
        self::assertStringContainsString("__('shop/carts.checkout_wait')", $cart);
    }

    public function test_add_to_cart_and_login_modal_expose_accessible_localized_feedback(): void
    {
        $root   = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/themes/default/layout/master.blade.php');
        $common = (string) file_get_contents($root . '/resources/beike/shop/default/js/common.js');

        self::assertStringContainsString("'loginIndex' => shop_route('login.index')", $layout);
        self::assertStringContainsString('id="storefront-feedback"', $layout);
        self::assertStringContainsString('role="status" aria-live="polite"', $layout);
        self::assertStringContainsString(".attr('aria-busy', 'true')", $common);
        self::assertStringContainsString('this.announce(res.message)', $common);
        self::assertStringContainsString("loginUrl.searchParams.set('iframe', 'true')", $common);
        self::assertStringContainsString("window.matchMedia('(max-width: 767.98px)')", $common);
    }

    public function test_add_to_cart_is_coalesced_idempotent_and_serialized_with_checkout(): void
    {
        $root       = dirname(__DIR__, 2);
        $product    = (string) file_get_contents($root . '/themes/default/product/product.blade.php');
        $common     = (string) file_get_contents($root . '/resources/beike/shop/default/js/common.js');
        $request    = (string) file_get_contents($root . '/beike/Shop/Http/Requests/CartRequest.php');
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/CartController.php');

        self::assertStringContainsString('@click="addCart(false, $event.currentTarget)"', $product);
        self::assertStringContainsString('@click="addCart(true, $event.currentTarget)"', $product);
        self::assertStringContainsString('bk.addCart(params, button, () => {', $product);
        self::assertStringContainsString('this.pendingCartAdds.has(requestIdentity)', $common);
        self::assertStringContainsString('const idempotencyKey = this.cartRequestKey(requestIdentity)', $common);
        self::assertStringContainsString('window.localStorage?.setItem(storageKey', $common);
        self::assertStringContainsString('this.clearCartRequestKey(requestIdentity, idempotencyKey)', $common);
        self::assertStringContainsString('timeout: 25000', $common);
        self::assertStringContainsString('idempotency_key: idempotencyKey', $common);
        self::assertStringContainsString("'idempotency_key'", $request);
        self::assertStringContainsString("'cart:add:receipt:'", $controller);
        self::assertStringContainsString("'fingerprint'", $controller);
        self::assertStringContainsString('DB::transaction(', $controller);
        self::assertStringContainsString('return CartService::mutationLockKey($customer);', $controller);
        self::assertStringContainsString('return CartService::mutationLockKey($this->customer);', (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php'));
        self::assertStringContainsString("where('checkout_token_hash', self::checkoutTokenHash", (string) file_get_contents($root . '/beike/Shop/Services/CheckoutService.php'));
        self::assertStringContainsString("'selected' => true", (string) file_get_contents($root . '/beike/Shop/Services/CartService.php'));
        self::assertStringContainsString('Cache::lock(CartService::mutationLockKey(current_customer()), 60)', (string) file_get_contents($root . '/beike/Services/AbandonedOrderRecoveryService.php'));
        self::assertStringContainsString('DashboardEvent::recordCartAdded', $controller);
    }

    public function test_stock_corrections_are_explained_in_cart_and_checkout(): void
    {
        $root     = dirname(__DIR__, 2);
        $service  = (string) file_get_contents($root . '/beike/Shop/Services/CartService.php');
        $cart     = (string) file_get_contents($root . '/themes/default/cart/cart.blade.php');
        $checkout = (string) file_get_contents($root . '/themes/default/checkout.blade.php');

        self::assertStringContainsString("self::recordAdjustment(\$identity, 'removed')", $service);
        self::assertStringContainsString("self::recordAdjustment(\$identity, 'adjusted')", $service);
        self::assertStringContainsString("'notices'", $service);
        self::assertStringContainsString('($notices ?? []) as $notice', $cart);
        self::assertStringContainsString("(\$carts['notices'] ?? []) as \$notice", $checkout);
    }

    public function test_payment_information_signal_is_emitted_only_after_checkout_validation_and_deduplicated(): void
    {
        $root       = dirname(__DIR__, 2);
        $checkout   = (string) file_get_contents($root . '/themes/default/checkout.blade.php');
        $events     = (string) file_get_contents($root . '/beike/Models/DashboardEvent.php');
        $payment    = (string) file_get_contents($root . '/beike/Shop/Services/PaymentService.php');
        $controller = (string) file_get_contents($root . '/beike/Shop/Http/Controllers/CheckoutController.php');

        self::assertStringContainsString('const announcePaymentInformationAdded =', $checkout);
        self::assertSame(1, substr_count($checkout, 'announcePaymentInformationAdded('));
        self::assertStringNotContainsString("key === 'payment_method_code' ? () => announcePaymentInformationAdded", $checkout);
        self::assertStringContainsString('announcePaymentInformationAdded(selectedPaymentCode)', $checkout);
        self::assertGreaterThan(
            strpos($checkout, 'rememberConfirmedOrder(res);'),
            strpos($checkout, 'announcePaymentInformationAdded(selectedPaymentCode)')
        );
        self::assertStringContainsString('Cache::add($deduplicationKey', $events);
        self::assertStringContainsString('DashboardEvent::recordPaymentInformationAdded(current_customer(), $order)', $controller);
        self::assertSame(2, substr_count($payment, 'recordPaymentInformationAdded(current_customer(), $this->order)'));
    }

    public function test_checkout_progress_and_payment_state_are_semantic_and_recoverable(): void
    {
        $root     = dirname(__DIR__, 2);
        $steps    = (string) file_get_contents($root . '/themes/default/shared/steps.blade.php');
        $checkout = (string) file_get_contents($root . '/themes/default/checkout.blade.php');
        $payment  = (string) file_get_contents($root . '/themes/default/checkout/payment.blade.php');

        self::assertStringContainsString('<nav class="checkout-steps"', $steps);
        self::assertStringContainsString('<ol class="steps-wrap">', $steps);
        self::assertStringContainsString('aria-current="step"', $steps);
        self::assertStringContainsString("@include('shared.steps', ['steps' => 2])", $checkout);

        self::assertStringContainsString('id="payment-page-title"', $payment);
        self::assertStringContainsString('class="purchase-order-facts"', $payment);
        self::assertStringContainsString('class="payment-provider-surface"', $payment);
        self::assertStringContainsString("__('shop/checkout.payment_not_charged_notice')", $payment);
        self::assertStringContainsString("shop_route('orders.show'", $payment);
        self::assertStringNotContainsString('<table', $payment);
        self::assertStringNotContainsString('is_mobile()', $payment);
    }

    public function test_order_result_distinguishes_unpaid_from_confirmed_and_explains_next_steps(): void
    {
        $root    = dirname(__DIR__, 2);
        $success = (string) file_get_contents($root . '/themes/default/checkout/success.blade.php');
        $styles  = (string) file_get_contents($root . '/resources/beike/shop/default/css/order-success.scss');

        self::assertStringContainsString('StateMachineService::UNPAID', $success);
        self::assertStringContainsString("__('shop/checkout.order_unpaid_message')", $success);
        self::assertStringContainsString("__('shop/checkout.order_paid_message')", $success);
        self::assertStringContainsString("__('shop/checkout.retry_payment')", $success);
        self::assertStringContainsString('fetch(@json($orderStatusUrl)', $success);
        self::assertStringContainsString('controller.abort(), 5000', $success);
        self::assertStringContainsString('for (const delay of [0, 750, 1500])', $success);
        self::assertStringContainsString("if (paymentState === 'confirmed') return", $success);
        self::assertStringContainsString('class="purchase-next-steps"', $success);
        self::assertStringContainsString('class="purchase-address-grid"', $success);
        self::assertStringNotContainsString('is_mobile()', $success);
        self::assertStringNotContainsString('<table', $success);
        self::assertStringContainsString('min-height: 48px;', $styles);
        self::assertStringContainsString('@media (max-width: 575.98px)', $styles);
    }

    public function test_primary_storefront_journey_translations_stay_aligned(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['products', 'carts', 'checkout'] as $group) {
            $english     = require $root . "/lang/en/shop/{$group}.php";
            $chinese     = require $root . "/lang/zh_cn/shop/{$group}.php";
            $englishKeys = array_keys($english);
            $chineseKeys = array_keys($chinese);
            sort($englishKeys);
            sort($chineseKeys);

            self::assertSame($englishKeys, $chineseKeys, "Translation keys for {$group} must stay aligned.");
        }
    }
}
