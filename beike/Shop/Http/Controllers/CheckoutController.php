<?php

/**
 * CheckoutController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-28 16:47:57
 * @modified   2022-06-28 16:47:57
 */

namespace Beike\Shop\Http\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use App\Services\PlatformPayment\PlatformPaymentSessionService;
use Beike\Exceptions\CartException;
use Beike\Models\Order;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CheckoutController extends Controller
{
    public function index()
    {
        $this->useCheckoutLocale();

        try {
            $data                           = (new CheckoutService)->checkoutData();
            $data                           = hook_filter('checkout.index.data', $data);
            $data['checkout_confirm_token'] = (string) Str::uuid();

            // Render the view while still inside this guarded block. Returning
            // a lazy View object would defer Blade and plugin-hook failures
            // until after the controller exits, bypassing the recovery path
            // below and exposing the generic 500 page to the customer.
            return response()->view('checkout', $data);
        } catch (CartException $e) {
            if ($e->getMessage() === trans('shop/carts.empty_selected_products')
                && ($order = $this->recentUnpaidConfirmation())) {
                return redirect(order_checkout_result_url($order, 'error'));
            }

            return redirect(shop_route('carts.index'))->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Checkout page could not be rendered.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return redirect(shop_route('carts.index'))->withErrors(['error' => trans('shop/checkout.unable_open_checkout')]);
        }
    }

    /**
     * 更改结算信息
     *
     * @param Request $request
     * @return mixed
     */
    public function update(Request $request): mixed
    {
        $this->useCheckoutLocale();

        $validated = $request->validate([
            'shipping_address_id'                  => 'sometimes|integer|min:1',
            'payment_address_id'                   => 'sometimes|integer|min:1',
            'shipping_method_code'                 => 'sometimes|nullable|string|max:191',
            'payment_method_code'                  => 'sometimes|nullable|string|max:191',
            'shipping_quote_data'                  => 'sometimes|array',
            'shipping_quote_data.code'             => 'required_with:shipping_quote_data|string|max:191',
            'shipping_quote_data.fields'           => 'sometimes|array|max:50',
            'shipping_quote_data.fields.*'         => 'nullable|string|max:1000',
            'guest_shipping_address'               => 'sometimes|array',
            'guest_shipping_address.email'         => 'nullable|email|max:191',
            'guest_shipping_address.name'          => 'nullable|string|max:64',
            'guest_shipping_address.calling_code'  => 'nullable|string|max:12',
            'guest_shipping_address.phone'         => 'nullable|string|max:32',
            'guest_shipping_address.country_id'    => 'nullable|integer|min:1',
            'guest_shipping_address.zone_id'       => 'nullable|integer|min:1',
            'guest_shipping_address.city'          => 'nullable|string|max:120',
            'guest_shipping_address.zipcode'       => 'nullable|string|max:32',
            'guest_shipping_address.address_1'     => 'nullable|string|max:255',
            'guest_shipping_address.address_2'     => 'nullable|string|max:255',
            'guest_payment_address'                => 'sometimes|array',
            'guest_payment_address.email'          => 'nullable|email|max:191',
            'guest_payment_address.name'           => 'nullable|string|max:64',
            'guest_payment_address.calling_code'   => 'nullable|string|max:12',
            'guest_payment_address.phone'          => 'nullable|string|max:32',
            'guest_payment_address.country_id'     => 'nullable|integer|min:1',
            'guest_payment_address.zone_id'        => 'nullable|integer|min:1',
            'guest_payment_address.city'           => 'nullable|string|max:120',
            'guest_payment_address.zipcode'        => 'nullable|string|max:32',
            'guest_payment_address.address_1'      => 'nullable|string|max:255',
            'guest_payment_address.address_2'      => 'nullable|string|max:255',
            'discount_code'                        => 'sometimes|nullable|string|max:64',
        ]);

        try {
            // Only validated checkout fields may reach extension hooks. This
            // prevents unrelated request data from becoming an undocumented
            // plugin input or being persisted in a cart extension payload.
            $data = (new CheckoutService)->update($validated);

            return hook_filter('checkout.update.data', $data);
        } catch (CartException $e) {
            return json_fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Checkout update failed.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return json_fail(trans('shop/checkout.unable_update_checkout'));
        }
    }

    /**
     * 确认提交订单
     *
     * @return mixed
     * @throws \Throwable
     */
    public function confirm(Request $request)
    {
        $this->useCheckoutLocale();

        $validated = $request->validate([
            'comment'                             => 'nullable|string|max:255',
            'checkout_token'                      => 'required|uuid',
            'shipping_quote_data'                 => 'sometimes|array',
            'shipping_quote_data.code'            => 'required_with:shipping_quote_data|string|max:191',
            'shipping_quote_data.fields'          => 'sometimes|array|max:50',
            'shipping_quote_data.fields.*'        => 'nullable|string|max:1000',
        ]);

        try {
            $checkoutToken = trim((string) ($validated['checkout_token'] ?? ''));
            $customer      = current_customer();
            $customerId    = (int) ($customer->id ?? 0);
            $confirmation  = session('checkout_confirmations.' . $checkoutToken);
            $orderNumber   = CheckoutService::confirmedOrderNumber($checkoutToken, $customer);

            if ($orderNumber === null
                && is_array($confirmation)
                && (int) ($confirmation['customer_id'] ?? -1) === $customerId) {
                $orderNumber = (string) ($confirmation['number'] ?? '');
            }

            if (is_string($orderNumber) && $orderNumber !== '') {
                $existingOrder = OrderRepo::getOrderByNumber($orderNumber, $customer);
                if ($existingOrder) {
                    if (! $customer) {
                        $this->rememberGuestOrderNumber((string) $existingOrder->number);
                    }

                    return $this->confirmationResponse($existingOrder);
                }
            }

            $data = (new CheckoutService)->confirm($validated, $checkoutToken);

            return $this->confirmationResponse($data);
        } catch (CartException $e) {
            return json_fail($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Checkout confirmation failed.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return json_fail(trans('shop/checkout.unable_place_order'));
        }
    }

    public function success(Request $request)
    {
        $this->useCheckoutLocale();

        $order_number = request('order_number');

        $customer        = current_customer();
        $order           = OrderRepo::getOrderByNumber($order_number, $customer);
        $guestAuthorized = in_array((string) $order_number, array_map('strval', session('guest_order_numbers', [])), true)
            || $request->hasValidRelativeSignature()
            || $request->hasValidSignature();
        if (empty($order) || (empty($customer) && ! $guestAuthorized)) {
            abort(404);
        }
        if (empty($customer)) {
            $this->rememberGuestOrderNumber((string) $order->number);
        }

        $paymentStatus = (string) request('payment_status');
        $notice        = match ($paymentStatus) {
            'cancelled'   => trans('shop/checkout.platform_pay_cancelled'),
            'error'       => trans('shop/checkout.platform_pay_error'),
            'unavailable' => trans('shop/checkout.platform_pay_unavailable'),
            default       => '',
        };
        $autoRestoreCart = $notice !== ''
            && (string) $order->status              === StateMachineService::UNPAID
            && (string) $order->payment_method_code === PlatformPaymentSessionService::PAYMENT_CODE
            && current_site() !== null;
        $data = hook_filter('account.order.show.data', [
            'order'                  => $order,
            'html_items'             => [],
            'platformPaymentNotice'  => $notice,
            'autoRestoreCart'        => $autoRestoreCart,
            'cartRestoreUrl'         => $autoRestoreCart
                ? shop_route('orders.restore-cart', ['number' => $order->number])
                : '',
            'cartUrl'                => shop_route('carts.index'),
        ]);

        return view('checkout/success', $data);
    }

    private function useCheckoutLocale(): void
    {
        $locale = trim((string) config('longos.checkout_locale', 'en'));
        app()->setLocale($locale !== '' ? $locale : 'en');
    }

    private function rememberGuestOrderNumber(string $number): void
    {
        $numbers   = array_values(array_unique(array_map('strval', (array) session('guest_order_numbers', []))));
        $numbers[] = $number;
        session()->put('guest_order_numbers', array_slice(array_values(array_unique($numbers)), -20));
    }

    /**
     * Keep the hook-compatible Order response while exposing only the order
     * number and a signed, same-store recovery destination. Once confirmation
     * succeeds the cart no longer owns these items, so every later payment
     * failure must recover through the unpaid order rather than resubmitting
     * the stale checkout.
     */
    private function confirmationResponse(Order $order): mixed
    {
        $order->setAttribute('payment_recovery_url', order_checkout_result_url($order, 'error'));
        $order->setVisible(['number', 'payment_recovery_url']);

        return hook_filter('checkout.confirm.data', $order);
    }

    /**
     * A provider or browser-back navigation can reload the old checkout after
     * its selected rows were converted into an order. Recover only the newest
     * confirmation belonging to this customer/session, and only while it is
     * still unpaid; unrelated empty-cart visits continue to the cart page.
     */
    private function recentUnpaidConfirmation(): ?Order
    {
        $confirmations = array_reverse((array) session('checkout_confirmations', []), true);
        $customer      = current_customer();
        $customerId    = (int) ($customer->id ?? 0);

        foreach ($confirmations as $confirmation) {
            if (! is_array($confirmation)
                || (int) ($confirmation['customer_id'] ?? -1) !== $customerId
                || (int) ($confirmation['created_at'] ?? 0) < now()->subDay()->timestamp) {
                continue;
            }

            $number = trim((string) ($confirmation['number'] ?? ''));
            $order  = $number !== '' ? OrderRepo::getOrderByNumber($number, $customer) : null;
            if (! $order || (! $customer && (int) $order->customer_id !== 0)) {
                return null;
            }

            return $order->status === StateMachineService::UNPAID ? $order : null;
        }

        return null;
    }
}
