<?php

/**
 * OrderController.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-06-06 10:47:27
 * @modified   2023-06-06 10:47:27
 */

namespace Beike\Shop\Http\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Exceptions\CartException;
use Beike\Models\Order;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Beike\Shop\Services\OrderCartRestoreService;
use Beike\Shop\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderController extends Controller
{
    public function show(Request $request, int $number)
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($number, $customer);
        if (empty($order) || (empty($customer) && ! $this->isGuestOrderAuthorized($order, $number, $request, true))) {
            abort(404);
        }
        $data  = hook_filter('order.show.data', ['order' => $order, 'html_items' => []]);

        return view('order_info', $data);
    }

    /**
     * Display the payment screen for an unpaid order.
     *
     * This route is shared by signed-in customers and guest checkout. Guest
     * access is restricted to order numbers created in the current session.
     */
    public function pay(Request $request, $number)
    {
        $order      = null;
        $authorized = false;

        try {
            $customer = current_customer();
            $order    = OrderRepo::getOrderByNumber($number, $customer);

            if (empty($order) || (empty($customer) && ! $this->isGuestOrderAuthorized($order, $number, $request, true))) {
                throw new CartException(trans('shop/order.invalid_order'));
            }
            $authorized = true;
            if (! $customer) {
                $this->rememberGuestOrderNumber((string) $order->number);
            }

            hook_action('account.order.pay.before', ['order' => $order]);

            return (new PaymentService($order))->pay();
        } catch (CartException $e) {
            return $this->paymentFailureRedirect($order, $e->getMessage(), $authorized);
        } catch (Throwable $e) {
            Log::error('Payment page could not be rendered.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return $this->paymentFailureRedirect($order, trans('shop/checkout.payment_method_unavailable'), $authorized);
        }
    }

    /**
     * Cancel an order for either the current customer or a session-authorized guest.
     */
    public function cancel(Request $request, $number)
    {
        $order   = $this->authorizedOrder($request, $number);
        $comment = trans('shop/order.cancel_order');

        if (! in_array($order->status, [StateMachineService::UNPAID, StateMachineService::PAID], true)) {
            throw new CartException(trans('shop/account/order_info.action_unavailable'));
        }

        if ($order->status == StateMachineService::PAID) {
            StateMachineService::getInstance($order)->changeStatus(StateMachineService::REFUNDING, $comment);
        } else {
            StateMachineService::getInstance($order)->changeStatus(StateMachineService::CANCELLED, $comment);
        }

        return json_success(trans('shop/account/order.cancelled'));
    }

    /**
     * Cancel a charge-free platform order and merge its products back into
     * the current customer's (or guest session's) cart.
     */
    public function restoreCart(Request $request, $number, OrderCartRestoreService $restoreService)
    {
        $order = null;

        try {
            $order = $this->authorizedOrder($request, $number);
            $site  = current_site();
            if (! $site) {
                throw new CartException(trans('shop/checkout.cart_restore_failed'));
            }

            $result  = $restoreService->restore($site, $order, current_customer());
            $message = $result['unavailable_quantity'] > 0
                ? trans('shop/checkout.cart_restored_partial', [
                    'restored'    => $result['restored_quantity'],
                    'unavailable' => $result['unavailable_quantity'],
                ])
                : trans('shop/checkout.cart_restored', ['count' => $result['restored_quantity']]);

            session()->flash('success', $message);

            if (! $request->expectsJson()) {
                return redirect(shop_route('carts.index'));
            }

            return json_success($message, [
                ...$result,
                'redirect_url' => shop_route('carts.index'),
            ]);
        } catch (CartException $exception) {
            if (! $request->expectsJson()) {
                return back()->withErrors(['error' => $exception->getMessage()]);
            }

            return json_fail($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Order products could not be restored to the cart.', [
                'order_id'  => $order?->id,
                'exception' => $exception::class,
                'message'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);

            $message = trans('shop/checkout.cart_restore_failed');

            return $request->expectsJson()
                ? json_fail($message)
                : back()->withErrors(['error' => $message]);
        }
    }

    /**
     * Mark an authorized order as complete.
     */
    public function complete(Request $request, $number)
    {
        $order = $this->authorizedOrder($request, $number);
        if ($order->status !== StateMachineService::SHIPPED) {
            throw new CartException(trans('shop/account/order_info.action_unavailable'));
        }

        StateMachineService::getInstance($order)->changeStatus(
            StateMachineService::COMPLETED,
            trans('shop/order.confirm_order')
        );

        return json_success(trans('shop/account/order.completed'));
    }

    private function authorizedOrder(Request $request, $number): Order
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($number, $customer);

        if (empty($order) || (empty($customer) && ! $this->isGuestOrderAuthorized($order, $number, $request))) {
            throw new CartException(trans('shop/order.invalid_order'));
        }

        return $order;
    }

    private function isGuestOrderAuthorized(
        Order $order,
        $number,
        Request $request,
        bool $allowSignedRead = false,
    ): bool {
        if ($order->customer_id) {
            return false;
        }

        if (in_array((string) $number, array_map('strval', session('guest_order_numbers', [])), true)) {
            return true;
        }

        // New mail links use a relative signature so an active secondary
        // domain can redirect to a new primary domain without invalidating
        // the URL. Keep accepting historical absolute signatures until they
        // naturally expire.
        return $allowSignedRead
            && ($request->hasValidRelativeSignature() || $request->hasValidSignature());
    }

    private function paymentFailureRedirect(?Order $order, string $message, bool $authorized)
    {
        $url = $order && $authorized
            ? order_checkout_result_url($order)
            : shop_route('carts.index');

        return redirect($url)->withErrors(['error' => $message]);
    }

    private function rememberGuestOrderNumber(string $number): void
    {
        $numbers   = array_values(array_unique(array_map('strval', (array) session('guest_order_numbers', []))));
        $numbers[] = $number;
        session()->put('guest_order_numbers', array_slice(array_values(array_unique($numbers)), -20));
    }
}
