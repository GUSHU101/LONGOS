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
        $order = null;

        try {
            $customer = current_customer();
            $order    = OrderRepo::getOrderByNumber($number, $customer);

            if (empty($order) || (empty($customer) && ! $this->isGuestOrderAuthorized($order, $number, $request))) {
                throw new CartException(trans('shop/order.invalid_order'));
            }

            hook_action('account.order.pay.before', ['order' => $order]);

            return (new PaymentService($order))->pay();
        } catch (CartException $e) {
            return $this->paymentFailureRedirect($order, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Payment page could not be rendered.', [
                'exception' => $e::class,
                'message'   => SensitiveValueRedactor::redact($e->getMessage()),
            ]);

            return $this->paymentFailureRedirect($order, trans('shop/checkout.payment_method_unavailable'));
        }
    }

    /**
     * Cancel an order for either the current customer or a session-authorized guest.
     */
    public function cancel(Request $request, $number)
    {
        $order   = $this->authorizedOrder($request, $number);
        $comment = trans('shop/order.cancel_order');

        if ($order->status == StateMachineService::PAID) {
            StateMachineService::getInstance($order)->changeStatus(StateMachineService::REFUNDING, $comment);
        } else {
            StateMachineService::getInstance($order)->changeStatus(StateMachineService::CANCELLED, $comment);
        }

        return json_success(trans('shop/account/order.cancelled'));
    }

    /**
     * Mark an authorized order as complete.
     */
    public function complete(Request $request, $number)
    {
        $order = $this->authorizedOrder($request, $number);
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

    private function paymentFailureRedirect(?Order $order, string $message)
    {
        $url = $order
            ? shop_route('checkout.success', ['order_number' => $order->number])
            : shop_route('carts.index');

        return redirect($url)->withErrors(['error' => $message]);
    }
}
