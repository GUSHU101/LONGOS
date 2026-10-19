<?php

/**
 * PaymentService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-06 17:33:06
 * @modified   2022-07-06 17:33:06
 */

namespace Beike\Shop\Services;

use Beike\Exceptions\CartException;
use Beike\Models\DashboardEvent;
use Beike\Models\Order;
use Beike\Repositories\OrderRepo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    protected $order;

    protected $orderId;

    protected $paymentMethodCode;

    public function __construct($order)
    {
        $customer = current_customer();
        if (is_numeric($order)) {
            $this->order = OrderRepo::getOrderByIdOrNumber($order, $customer);
        } elseif ($order instanceof Order) {
            $this->order = $order;
        }
        if (empty($this->order)) {
            throw new CartException(trans('shop/order.invalid_order'));
        }
        if ($this->order->status != 'unpaid') {
            throw new CartException(trans('shop/order.order_already_paid'));
        }
        $this->orderId           = (int) $this->order->id;
        $this->paymentMethodCode = $this->order->payment_method_code;
    }

    /**
     * @throws \Exception
     */
    public function pay()
    {
        $orderPaymentCode = $this->paymentMethodCode;
        $paymentCode      = Str::studly($orderPaymentCode);
        $viewPath         = "$paymentCode::checkout.payment";

        $viewPath = hook_filter('service.payment.pay.view_path', $viewPath);

        if (! view()->exists($viewPath)) {
            Log::warning('Payment checkout template is unavailable.', [
                'plugin' => $orderPaymentCode,
                'view'   => $viewPath,
            ]);

            throw new CartException(trans('shop/checkout.payment_method_unavailable'));
        }
        $paymentData = [
            'order'           => $this->order,
            'payment_setting' => plugin_setting($orderPaymentCode),
        ];

        $paymentData = hook_filter('service.payment.pay.data', $paymentData);

        $paymentView = view($viewPath, $paymentData)->render();

        // Reaching a provider's payment form is the platform-neutral point at
        // which payment information can be added.  The event contains no
        // payment data and failures are isolated inside DashboardEvent.
        DashboardEvent::recordPaymentInformationAdded(current_customer());

        return view('checkout.payment', ['order' => $this->order, 'payment' => $paymentView]);
    }
}
