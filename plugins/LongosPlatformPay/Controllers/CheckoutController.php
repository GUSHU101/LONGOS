<?php

namespace Plugin\LongosPlatformPay\Controllers;

use App\Services\PlatformPayment\PlatformPaymentSessionService;
use Beike\Repositories\OrderRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController
{
    public function store(Request $request, PlatformPaymentSessionService $sessions): JsonResponse
    {
        try {
            $data     = $request->validate(['order_number' => ['required', 'string', 'max:128']]);
            $customer = current_customer();
            $order    = OrderRepo::getOrderByNumber($data['order_number'], $customer);
            if (! $order) {
                throw new \RuntimeException('订单不存在。');
            }
            if (! $customer) {
                $guestOrders = array_map('strval', session('guest_order_numbers', []));
                if (! in_array((string) $order->number, $guestOrders, true)) {
                    throw new \RuntimeException('当前访客会话无权访问该订单。');
                }
            }
            $site = current_site();
            if (! $site) {
                throw new \RuntimeException('当前站点尚未解析。');
            }

            $created = $sessions->create($site, $order);

            return json_success('安全支付会话已创建。', ['checkout_url' => $created['checkout_url']]);
        } catch (Throwable $e) {
            Log::warning('Platform payment session creation failed.', ['exception' => $e::class]);

            return json_fail('暂时无法连接主站支付，请稍后重试。', [], 422);
        }
    }
}
