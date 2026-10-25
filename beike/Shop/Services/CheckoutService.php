<?php

/**
 * CheckoutService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-30 19:37:05
 * @modified   2022-06-30 19:37:05
 */

namespace Beike\Shop\Services;

use App\Saas\Support\SensitiveValueRedactor;
use App\Services\PaymentPluginReadiness;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use Beike\Exceptions\CartException;
use Beike\Libraries\Weight;
use Beike\Models\Address;
use Beike\Models\Country;
use Beike\Models\Order;
use Beike\Models\ProductSku;
use Beike\Models\Zone;
use Beike\Repositories\AddressRepo;
use Beike\Repositories\CartRepo;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\OrderRepo;
use Beike\Repositories\PluginRepo;
use Beike\Services\AbandonedOrderRecoveryService;
use Beike\Services\PaymentMethodService;
use Beike\Services\ShippingMethodService;
use Beike\Services\StateMachineService;
use Beike\Shop\Http\Resources\Account\AddressResource;
use Beike\Shop\Http\Resources\Checkout\PaymentMethodItem;
use Beike\Shop\Services\TotalServices\PromotionDiscountService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CheckoutService
{
    public $customer;

    public $cart;

    public $selectedProducts;

    public $totalService;

    /** @var array<int, array<string, mixed>> */
    public array $shippingMethods = [];

    /**
     * @throws \Exception
     */
    public function __construct($customer = null)
    {
        if (is_int($customer) || empty($customer)) {
            $customer = current_customer();
        }

        $this->customer = $customer;

        $this->cart             = CartRepo::createCart($this->customer);
        $this->selectedProducts = CartRepo::selectedCartProducts($this->customer->id ?? 0);
        if ($this->selectedProducts->count() == 0) {
            throw new CartException(trans('shop/carts.empty_selected_products'));
        }
    }

    /**
     * 更新结账页数据
     *
     * @param $requestData ['shipping_address_id'=>1, 'payment_address_id'=>2, 'shipping_method'=>'code', 'payment_method'=>'code']
     * @return array
     * @throws \Exception
     */
    public function update($requestData): array
    {
        $customer = $this->customer;
        $lock     = Cache::lock($this->checkoutLockKey(), 60);

        if (! $lock->get()) {
            throw new CartException(trans('shop/checkout.order_processing'));
        }

        try {
            // A checkout update and final confirmation mutate the same cart.
            // Keep both operations behind one lock so a slow carrier quote,
            // another browser tab or a retry cannot overwrite newer state.
            $this->cart             = CartRepo::createCart($customer);
            $this->selectedProducts = CartRepo::selectedCartProducts($customer->id ?? 0);
            $this->totalService     = null;
            CartService::resetRuntimeCache();

            if ($this->selectedProducts->isEmpty()) {
                throw new CartException(trans('shop/carts.empty_selected_products'));
            }

            $shippingAddressId  = $requestData['shipping_address_id']  ?? 0;
            $shippingMethodCode = $requestData['shipping_method_code'] ?? '';

            $paymentAddressId  = $requestData['payment_address_id']  ?? 0;
            $paymentMethodCode = $requestData['payment_method_code'] ?? '';

            $guestShippingAddress = $requestData['guest_shipping_address'] ?? [];
            $guestPaymentAddress  = $requestData['guest_payment_address']  ?? [];

            hook_action('service.checkout.update.before', ['request_data' => $requestData, 'cart' => $this->cart]);

            // Apply address changes before selecting and persisting a quote. A
            // combined request may contain both values; doing this in the reverse
            // order would erase the freshly submitted carrier fields as stale.
            if ($shippingAddressId) {
                $this->updateShippingAddressId($shippingAddressId);
            }
            if ($guestShippingAddress) {
                $this->updateGuestShippingAddress($guestShippingAddress);
            }
            if ($shippingMethodCode) {
                $this->updateShippingMethod($shippingMethodCode);
            }
            if (array_key_exists('shipping_quote_data', $requestData)) {
                $this->updateShippingQuoteData((array) $requestData['shipping_quote_data']);
            }

            if ($paymentAddressId) {
                $this->updatePaymentAddressId($paymentAddressId);
            }
            if ($guestPaymentAddress) {
                $this->updateGuestPaymentAddress($guestPaymentAddress);
            }
            if ($paymentMethodCode) {
                $this->updatePaymentMethod($paymentMethodCode);
            }
            if (array_key_exists('discount_code', $requestData)) {
                $this->updateDiscountCode((string) $requestData['discount_code']);
            }

            hook_action('service.checkout.update.after', ['request_data' => $requestData, 'checkout' => $this]);

            return $this->checkoutData();
        } finally {
            $lock->release();
        }
    }

    /**
     * 确认提交订单
     * @throws \Throwable
     */
    public function confirm(array $requestData = [], string $checkoutToken = ''): Order
    {
        $customer = $this->customer;
        $lock     = Cache::lock($this->checkoutLockKey(), 120);

        if (! $lock->get()) {
            throw new CartException(trans('shop/checkout.order_processing'));
        }

        try {
            // Re-read the cart while holding the lock. A second request may
            // have been created by a double click, another tab or a retry at
            // the proxy after the first request already placed the order.
            $this->cart             = CartRepo::createCart($customer);
            $this->selectedProducts = CartRepo::selectedCartProducts($customer->id ?? 0);
            $this->totalService     = null;
            CartService::resetRuntimeCache();

            if ($this->selectedProducts->isEmpty()) {
                throw new CartException(trans('shop/carts.empty_selected_products'));
            }

            // Persist the final browser state while the checkout lock is held.
            // Autocomplete and custom controls can change without firing the
            // earlier AJAX update, so confirmation must not use stale fields.
            if (array_key_exists('shipping_quote_data', $requestData)) {
                $this->updateShippingQuoteData((array) $requestData['shipping_quote_data']);
            }

            $checkoutData             = $this->checkoutData();
            $checkoutData['customer'] = $customer;
            $checkoutData['comment']  = mb_substr(trim((string) ($requestData['comment'] ?? '')), 0, 255);
            $this->validateConfirm($checkoutData);

            // Freeze the exact product/quantity/price snapshot shown in the
            // confirmation data. Cart endpoints do not share the browser's
            // AJAX queue, so an update from another tab must be detected at
            // the database boundary instead of silently creating a mixed order.
            $expectedCartFingerprint = $this->checkoutCartFingerprint((array) data_get($checkoutData, 'carts.carts', []));

            $order = DB::transaction(function () use ($checkoutData, $customer, $expectedCartFingerprint) {
                $lockedProducts = CartRepo::selectedCartProductsBuilder($customer->id ?? 0)
                    ->lockForUpdate()
                    ->get();
                $lockedSkuPrices = ProductSku::query()
                    ->whereIn('sku', $lockedProducts->pluck('product_sku')->filter()->unique())
                    ->lockForUpdate()
                    ->pluck('price', 'sku')
                    ->all();
                if ($lockedProducts->isEmpty()
                    || ! hash_equals($expectedCartFingerprint, $this->cartModelsFingerprint($lockedProducts, $lockedSkuPrices))) {
                    throw new CartException(trans('shop/checkout.cart_changed'));
                }

                $order = OrderRepo::create($checkoutData);
                StateMachineService::getInstance($order)->changeStatus(StateMachineService::UNPAID, '', true);
                app(AbandonedOrderRecoveryService::class)->markConverted($this->cart, $order);
                CartRepo::clearSelectedCartProducts($customer);

                hook_action('service.checkout.confirm.after', ['order' => $order, 'cart' => $this->cart]);

                return $order;
            }, 3);

            // Store the receipt before releasing the cart lock. This closes
            // the post-commit window in which a proxy retry could otherwise
            // see an empty cart before the controller records the order.
            $this->rememberConfirmation($checkoutToken, $order);

            return $order;
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve a previously committed order for an idempotent browser retry.
     */
    public static function confirmedOrderNumber(string $checkoutToken, $customer = null): ?string
    {
        if (! Str::isUuid($checkoutToken)) {
            return null;
        }

        try {
            $number = Cache::get(self::confirmationCacheKey($checkoutToken, $customer));

            return is_string($number) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $number) === 1
                ? $number
                : null;
        } catch (Throwable $e) {
            Log::warning('Checkout confirmation receipt could not be read from cache.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * 计算当前选中商品总重量
     *
     * @return float
     */
    public function getCartWeight(): float
    {
        $weight           = 0;
        $selectedProducts = $this->selectedProducts;
        foreach ($selectedProducts as $product) {
            $productModel = $product->product ?: $product->sku?->product;
            if (! $productModel || ! filter_var($productModel->shipping ?? true, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $skuWeight   = $product->sku?->weight;
            $weightValue = is_numeric($skuWeight) && (float) $skuWeight > 0
                ? (float) $skuWeight
                : (float) ($productModel->weight ?? 0);
            $quantity    = max(0, (int) ($product->quantity ?? 0));
            $weightClass = (string) ($productModel->weight_class ?? '');

            if ($weightValue > 0 && $quantity > 0 && array_key_exists($weightClass, Weight::WEIGHT_CLASS)) {
                $weight += Weight::getInstance()->convert($weightValue * $quantity, $weightClass);
            }
        }

        return $weight;
    }

    /**
     * @throws \Exception
     */
    private function validateConfirm(array &$checkoutData): void
    {
        $current = $checkoutData['current'] ?? [];

        if ($this->customer) {
            if ($this->shippingRequired()) {
                $shippingAddressId = $current['shipping_address_id'] ?? 0;
                $shippingAddress   = Address::query()->find($shippingAddressId);
                if (empty($shippingAddress) || $shippingAddress->customer_id != $this->customer->id) {
                    throw new CartException(trans('shop/carts.invalid_shipping_address'));
                }
                $this->validateCustomerAddress($shippingAddress, trans('shop/carts.invalid_shipping_address'));
            }

            $paymentAddressId = $current['payment_address_id'] ?? 0;
            $paymentAddress   = Address::query()->find($paymentAddressId);
            if (empty($paymentAddress) || $paymentAddress->customer_id != $this->customer->id) {
                throw new CartException(trans('shop/carts.invalid_payment_address'));
            }
            $this->validateCustomerAddress($paymentAddress, trans('shop/carts.invalid_payment_address'));
        } else {
            if ($this->shippingRequired()) {
                $this->validateGuestAddress($current['guest_shipping_address'] ?? [], trans('shop/carts.invalid_shipping_address'));
            }

            $this->validateGuestAddress($current['guest_payment_address'] ?? [], trans('shop/carts.invalid_payment_address'));
        }

        if ($this->showShippingMethod()) {
            $shippingMethodCode = $current['shipping_method_code'] ?? '';
            // checkoutData() has already resolved and normalized every quote
            // for this confirmation. Reuse that snapshot so a remote or
            // stateful shipping plugin cannot return a different result in a
            // second call between total calculation and order creation.
            $shippingMethods = $this->shippingMethods ?: ShippingMethodService::getShippingMethodsForCurrentCart($this);
            $currentQuote    = ShippingMethodService::getCurrentQuote($shippingMethods, $shippingMethodCode);
            if (! $currentQuote) {
                throw new CartException(trans('shop/carts.invalid_shipping_method'));
            }

            $quoteData = (array) ((is_array($this->cart->extra) ? $this->cart->extra : [])['shipping_quote_data'] ?? []);
            $quoteData = ShippingMethodService::filterQuoteData($currentQuote, $quoteData);
            if (! ShippingMethodService::quoteDataIsComplete($currentQuote, $quoteData)) {
                throw new CartException(trans('shop/checkout.complete_shipping_details'));
            }

            $extra                        = is_array($this->cart->extra) ? $this->cart->extra : [];
            $extra['shipping_quote_data'] = $quoteData;
            $this->cart->extra            = $extra;
            $this->cart->saveOrFail();
            $checkoutData['current']['extra'] = $extra;
        }

        $paymentMethodCode = $current['payment_method_code'] ?? '';
        if (! PluginRepo::paymentEnabled($paymentMethodCode)) {
            throw new CartException(trans('shop/carts.invalid_payment_method'));
        }

        hook_action('service.checkout.validate_confirm.after', $checkoutData);
    }

    private function updateShippingAddressId($shippingAddressId)
    {
        if ($this->customer) {
            $shippingAddress = Address::query()->find($shippingAddressId);
            if (empty($shippingAddress) || $shippingAddress->customer_id != $this->customer->id) {
                throw new CartException(trans('shop/carts.invalid_shipping_address'));
            }
        }
        if ((int) $this->cart->shipping_address_id !== (int) $shippingAddressId) {
            $this->clearShippingQuoteData();
        }
        $this->cart->update(['shipping_address_id' => $shippingAddressId]);
        $this->cart->load('shippingAddress');
    }

    private function updatePaymentAddressId($paymentAddressId)
    {
        if ($this->customer) {
            $paymentAddress = Address::query()->find($paymentAddressId);
            if (empty($paymentAddress) || $paymentAddress->customer_id != $this->customer->id) {
                throw new CartException(trans('shop/carts.invalid_payment_address'));
            }
        }
        $this->cart->update(['payment_address_id' => $paymentAddressId]);
        $this->cart->load('paymentAddress');
    }

    private function updateGuestShippingAddress($guestShippingAddress)
    {
        $address = self::formatAddress($guestShippingAddress);
        if ((array) $this->cart->guest_shipping_address !== $address) {
            $this->clearShippingQuoteData();
        }
        $this->cart->update(['guest_shipping_address' => $address]);
    }

    private function updateGuestPaymentAddress($guestPaymentAddress)
    {
        $this->cart->update(['guest_payment_address' => self::formatAddress($guestPaymentAddress)]);
    }

    private function updateShippingMethod($shippingMethodCode)
    {
        if ((string) $this->cart->shipping_method_code !== (string) $shippingMethodCode) {
            $this->clearShippingQuoteData();
        }
        $this->cart->shipping_method_code = $shippingMethodCode;
        $this->cart->save();
    }

    /**
     * Preserve safe, scalar plugin fields with the cart so a shipping plugin
     * can validate them through the existing checkout hooks and reuse them at
     * confirmation time.
     */
    private function updateShippingQuoteData(array $data): void
    {
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '' || ! hash_equals((string) $this->cart->shipping_method_code, $code)) {
            throw new CartException(trans('shop/carts.invalid_shipping_method'));
        }

        $fields = [];
        foreach (array_slice((array) ($data['fields'] ?? []), 0, 50, true) as $key => $value) {
            $key = trim((string) $key);
            if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $key) !== 1 || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            $fields[$key] = mb_substr(trim((string) $value), 0, 1000);
        }

        $extra                        = is_array($this->cart->extra) ? $this->cart->extra : [];
        $extra['shipping_quote_data'] = ['code' => $code, 'fields' => $fields];
        $this->cart->update(['extra' => $extra]);
        $this->cart->extra = $extra;
    }

    private function clearShippingQuoteData(): void
    {
        $extra = is_array($this->cart->extra) ? $this->cart->extra : [];
        unset($extra['shipping_quote_data']);
        $this->cart->extra = $extra;
    }

    private function updatePaymentMethod($paymentMethodCode)
    {
        $this->cart->payment_method_code = $paymentMethodCode;
        $this->cart->save();
    }

    private function updateDiscountCode(string $code): void
    {
        $code = strtoupper(trim($code));
        PromotionDiscountService::validateCode($code);

        $extra = is_array($this->cart->extra) ? $this->cart->extra : [];
        if ($code === '') {
            unset($extra['discount_code']);
        } else {
            $extra['discount_code'] = $code;
        }

        $this->cart->update(['extra' => $extra]);
        $this->cart->extra = $extra;
    }

    public function initTotalService()
    {
        $customer           = $this->customer;
        $totalClass         = hook_filter('service.checkout.total_service', 'Beike\Shop\Services\TotalService');
        $totalService       = (new $totalClass($this->cart, CartService::list($customer, true)));
        $this->totalService = $totalService;
    }

    /**
     * 获取结账页数据
     *
     * @return array
     * @throws \Exception
     */
    public function checkoutData(): array
    {
        $customer    = $this->customer;
        $currentCart = $this->cart;

        $cartList               = CartService::list($customer, true);
        $this->selectedProducts = CartRepo::selectedCartProducts($customer->id ?? 0);
        if (empty($cartList) || $this->selectedProducts->isEmpty()) {
            throw new CartException(trans('shop/carts.empty_selected_products'));
        }
        $carts = CartService::reloadData($cartList);

        if (! $this->totalService) {
            $this->initTotalService();
        }

        $addresses             = AddressRepo::listByCustomer($customer);
        $payments              = $this->paymentMethods();
        $shipments             = ShippingMethodService::getShippingMethodsForCurrentCart($this);
        $this->shippingMethods = $shipments;
        $shippingRequired      = $this->shippingRequired();
        $this->setDefaultCurrentShippingMethod($shipments);
        $this->setDefaultCurrentPaymentMethod($payments);

        $shippingQuote = ShippingMethodService::getCurrentQuote($shipments, $currentCart->shipping_method_code);
        $paymentMethod = PaymentMethodService::getCurrentMethod($payments, $currentCart->payment_method_code);

        $data = [
            'current'          => [
                'shipping_address_id'    => $shippingRequired ? $currentCart->shipping_address_id : 0,
                'guest_shipping_address' => $shippingRequired ? $currentCart->guest_shipping_address : null,
                'shipping_method_code'   => $shippingRequired ? $currentCart->shipping_method_code : '',
                'shipping_method_name'   => $shippingQuote['name'] ?? '',
                'payment_address_id'     => $currentCart->payment_address_id,
                'guest_payment_address'  => $currentCart->guest_payment_address,
                'payment_method_code'    => $currentCart->payment_method_code,
                'payment_method_name'    => $paymentMethod['name'] ?? '',
                'extra'                  => $currentCart->extra,
            ],
            'shipping_require' => $shippingRequired,
            'country_id'       => (int) system_setting('base.country_id'),
            'customer_id'      => $customer->id ?? null,
            'countries'        => CountryRepo::listEnabled(),
            'addresses'        => AddressResource::collection($addresses),
            'shipping_methods' => $shipments,
            'payment_methods'  => $payments,
            'carts'            => $carts,
            'totals'           => $this->totalService->getTotals($this),
        ];

        return hook_filter('service.checkout.data', $data);
    }

    private function setDefaultCurrentShippingMethod($shipments)
    {
        $currentCart = $this->cart;
        if ($this->showShippingMethod()) {
            $shipmentCodes = [];
            foreach ($shipments as $shipment) {
                $quotes = $shipment['quotes'] ?? [];
                if (is_array($quotes)) {
                    $shipmentCodes = array_merge($shipmentCodes, array_column($quotes, 'code'));
                }
            }
            $shipmentCodes = array_values(array_filter($shipmentCodes, fn ($code) => is_string($code) && $code !== ''));
            if (! in_array($currentCart->shipping_method_code, $shipmentCodes, true)) {
                $this->updateShippingMethod($shipmentCodes[0] ?? '');
                $this->totalService->setShippingMethod($shipmentCodes[0] ?? '');
            }
        } else {
            $this->updateShippingMethod('');
            $this->totalService->setShippingMethod('');
        }

    }

    /**
     * A cart can outlive an enabled payment plugin. Keep its persisted choice
     * valid so a disabled or removed method cannot break the confirm step.
     */
    private function setDefaultCurrentPaymentMethod(array $payments): void
    {
        $paymentCodes = collect($payments)
            ->pluck('code')
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->values()
            ->all();

        if (! in_array($this->cart->payment_method_code, $paymentCodes, true)) {
            $this->updatePaymentMethod($paymentCodes[0] ?? '');
        }
    }

    /**
     * A broken optional payment extension must not make every checkout fail.
     * Healthy providers remain available and the faulty provider is logged.
     */
    private function paymentMethods(): array
    {
        $methods = [];
        foreach (PluginRepo::getPaymentMethods() as $paymentMethod) {
            try {
                $method = (new PaymentMethodItem($paymentMethod))->jsonSerialize();
                if (! is_array($method) || ($method['code'] ?? '') === '') {
                    continue;
                }

                $requiredRoutes = match ((string) $method['code']) {
                    'paypal' => ['shop.paypal.create', 'shop.paypal.capture'],
                    'stripe' => ['shop.stripe.intent', 'shop.stripe_capture'],
                    default  => [],
                };
                if ((string) $method['code'] === 'longos_platform_pay') {
                    $requiredRoutes = [
                        'shop.longos_platform_pay.session',
                        'shop.longos_platform_pay.paypal.create',
                        'shop.longos_platform_pay.paypal.capture',
                        'shop.longos_platform_pay.stripe.intent',
                        'shop.longos_platform_pay.stripe.complete',
                    ];
                }
                $missingRoutes = array_values(array_filter(
                    $requiredRoutes,
                    static fn (string $route): bool => ! app('router')->has($route),
                ));
                if ($missingRoutes !== []) {
                    Log::warning('Payment plugin skipped because its runtime routes are unavailable.', [
                        'plugin'         => $method['code'],
                        'missing_routes' => $missingRoutes,
                    ]);

                    continue;
                }

                $viewPath = hook_filter('service.payment.pay.view_path', Str::studly($method['code']) . '::checkout.payment');
                if (! is_string($viewPath) || ! view()->exists($viewPath)) {
                    Log::warning('Payment plugin skipped because its checkout template is missing.', [
                        'plugin' => $method['code'],
                        'view'   => is_string($viewPath) ? $viewPath : get_debug_type($viewPath),
                    ]);

                    continue;
                }

                $configurationIssues = PaymentPluginReadiness::issues((string) $method['code']);
                if ($configurationIssues !== []) {
                    // Never send incomplete provider credentials or their
                    // validation details to the customer-facing checkout.
                    Log::warning('Payment plugin skipped because its credential set is incomplete.', [
                        'plugin'      => $method['code'],
                        'issue_count' => count($configurationIssues),
                    ]);

                    continue;
                }

                if ((string) $method['code'] === PlatformPaymentSessionService::PAYMENT_CODE) {
                    $providers = app(PlatformPaymentSettings::class)->availableProviders();
                    if ($providers === []) {
                        continue;
                    }

                    $hasPaypal = in_array('paypal', $providers, true);
                    $hasStripe = in_array('stripe', $providers, true);
                    $nameKey   = $hasPaypal && $hasStripe
                        ? 'shop/checkout.platform_paypal_card'
                        : ($hasPaypal ? 'shop/checkout.platform_paypal' : 'shop/checkout.platform_card');

                    $method['name']           = trans($nameKey);
                    $method['description']    = trans('shop/checkout.platform_pay_help');
                    $method['icon']           = '';
                    $method['provider_codes'] = $providers;
                    $method['brand_marks']    = PlatformPaymentSettings::paymentMarksForProviders($providers);
                }

                $methods[] = $method;
            } catch (Throwable $e) {
                Log::warning('Payment plugin skipped while building checkout methods.', [
                    'plugin'    => is_object($paymentMethod) ? (string) ($paymentMethod->code ?? '') : get_debug_type($paymentMethod),
                    'exception' => $e::class,
                    'message'   => SensitiveValueRedactor::redact($e->getMessage()),
                ]);
            }
        }

        return $methods;
    }

    private function shippingRequired(): bool
    {
        $customer = $this->customer;

        return CartRepo::shippingRequired($customer->id ?? 0);
    }

    private function showShippingMethod(): bool
    {
        return hook_filter('service.checkout.show_shipping_method', $this->shippingRequired());
    }

    public static function formatAddress($address)
    {
        if (! is_array($address) || ! $address) {
            return [];
        }
        $country = Country::query()->whereKey($address['country_id'] ?? 0)->where('active', true)->first();
        if (! $country) {
            return [];
        }
        $zone = Zone::query()
            ->whereKey($address['zone_id'] ?? 0)
            ->where('country_id', $country->id)
            ->where('active', true)
            ->first();

        return [
            'name'         => trim((string) ($address['name'] ?? '')),
            'email'        => trim((string) ($address['email'] ?? '')),
            'calling_code' => mb_substr(trim((string) ($address['calling_code'] ?? '')), 0, 12),
            'phone'        => mb_substr(trim((string) ($address['phone'] ?? '')), 0, 32),
            'country_id'   => $country->id,
            'country'      => $country->name,
            'zone_id'      => $zone?->id   ?? 0,
            'zone'         => $zone?->name ?? '',
            'city'         => mb_substr(trim((string) ($address['city'] ?? '')), 0, 120),
            'zipcode'      => mb_substr(trim((string) ($address['zipcode'] ?? '')), 0, 32),
            'address_1'    => mb_substr(trim((string) ($address['address_1'] ?? '')), 0, 255),
            'address_2'    => mb_substr(trim((string) ($address['address_2'] ?? '')), 0, 255),
        ];
    }

    private function validateGuestAddress(mixed $address, string $message): void
    {
        if (! is_array($address)
            || trim((string) ($address['name'] ?? ''))      === ''
            || trim((string) ($address['address_1'] ?? '')) === ''
            || trim((string) ($address['city'] ?? ''))      === ''
            || (system_setting('base.address_phoner_equired', '0') == '1' && trim((string) ($address['phone'] ?? '')) === '')
            || ! filter_var($address['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new CartException($message);
        }

        $country = Country::query()->whereKey($address['country_id'] ?? 0)->where('active', true)->first();
        if (! $country) {
            throw new CartException($message);
        }

        $hasZones  = Zone::query()->where('country_id', $country->id)->where('active', true)->exists();
        $validZone = ! $hasZones || Zone::query()
            ->whereKey($address['zone_id'] ?? 0)
            ->where('country_id', $country->id)
            ->where('active', true)
            ->exists();
        if (! $validZone) {
            throw new CartException($message);
        }
    }

    private function validateCustomerAddress(Address $address, string $message): void
    {
        if (trim((string) $address->name)         === ''
            || trim((string) $address->address_1) === ''
            || trim((string) $address->city)      === ''
            || (system_setting('base.address_phoner_equired', '0') == '1' && trim((string) $address->phone) === '')) {
            throw new CartException($message);
        }

        $country = Country::query()->whereKey($address->country_id)->where('active', true)->first();
        if (! $country) {
            throw new CartException($message);
        }

        $hasZones = Zone::query()->where('country_id', $country->id)->where('active', true)->exists();
        if ($hasZones && ! Zone::query()
            ->whereKey($address->zone_id)
            ->where('country_id', $country->id)
            ->where('active', true)
            ->exists()) {
            throw new CartException($message);
        }
    }

    private function checkoutLockKey(): string
    {
        return 'checkout:confirm:' . $this->cart->getKey();
    }

    /**
     * @param array<int, array<string, mixed>> $carts
     */
    private function checkoutCartFingerprint(array $carts): string
    {
        $items = collect($carts)->map(static fn (array $item): array => [
            'id'         => (int) ($item['cart_id'] ?? 0),
            'product_id' => (int) ($item['product_id'] ?? 0),
            'sku'        => (string) ($item['product_sku'] ?? $item['sku'] ?? ''),
            'quantity'   => (int) ($item['quantity'] ?? 0),
            'price'      => self::normalizedMoney($item['price'] ?? 0),
        ])->sortBy('id')->values()->all();

        return hash('sha256', json_encode($items, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function cartModelsFingerprint($products, array $skuPrices = []): string
    {
        $items = collect($products)->map(static function ($item) use ($skuPrices): array {
            $sku = (string) $item->product_sku;

            return [
                'id'         => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'sku'        => $sku,
                'quantity'   => (int) $item->quantity,
                'price'      => self::normalizedMoney($skuPrices[$sku] ?? $item->sku?->price ?? 0),
            ];
        })->sortBy('id')->values()->all();

        return hash('sha256', json_encode($items, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function normalizedMoney(mixed $amount): string
    {
        return number_format((float) $amount, 8, '.', '');
    }

    private function rememberConfirmation(string $checkoutToken, Order $order): void
    {
        if (! Str::isUuid($checkoutToken)) {
            return;
        }

        try {
            Cache::put(
                self::confirmationCacheKey($checkoutToken, $this->customer),
                (string) $order->number,
                now()->addDay()
            );
        } catch (Throwable $e) {
            Log::warning('Checkout confirmation receipt could not be written to cache.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
        }

        try {
            $confirmations = array_filter(
                (array) session('checkout_confirmations', []),
                static fn ($item): bool => is_array($item)
                    && (int) ($item['created_at'] ?? 0) >= now()->subDay()->timestamp
            );
            $confirmations[$checkoutToken] = [
                'number'      => (string) $order->number,
                'customer_id' => (int) ($this->customer->id ?? 0),
                'created_at'  => now()->timestamp,
            ];
            session()->put('checkout_confirmations', array_slice($confirmations, -10, null, true));

            if (! $this->customer) {
                $numbers   = array_values(array_unique(array_map('strval', (array) session('guest_order_numbers', []))));
                $numbers[] = (string) $order->number;
                session()->put('guest_order_numbers', array_slice(array_values(array_unique($numbers)), -20));
            }
        } catch (Throwable $e) {
            Log::warning('Checkout confirmation receipt could not be written to the session.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);
        }
    }

    private static function confirmationCacheKey(string $checkoutToken, $customer = null): string
    {
        $identity = isset($customer->id) && (int) $customer->id > 0
            ? 'customer:' . (int) $customer->id
            : 'session:' . hash('sha256', (string) get_session_id());

        return 'checkout:receipt:' . hash('sha256', $identity . "\0" . $checkoutToken);
    }
}
