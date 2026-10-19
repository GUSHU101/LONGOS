<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Models\AbandonedOrderRecovery;
use Beike\Services\AbandonedOrderRecoveryService;
use Beike\Services\StateMachineService;
use Illuminate\Validation\ValidationException;

class AbandonedOrderRecoveryController extends Controller
{
    public function show(string $token, AbandonedOrderRecoveryService $service)
    {
        $recovery = $this->resolve($token, $service);
        $service->markOpened($recovery);

        return view('order_recovery', [
            'recovery'   => $recovery,
            'order'      => $recovery->order,
            'token'      => $token,
            'canRestore' => ! $recovery->suppressed_at
                && $recovery->order?->status === StateMachineService::UNPAID,
        ]);
    }

    public function restore(string $token, AbandonedOrderRecoveryService $service)
    {
        $recovery = $this->resolve($token, $service);

        try {
            $result  = $service->restoreToCart($recovery);
            $message = $result['unavailable'] > 0
                ? trans('shop/order_recovery.restored_partial', $result)
                : trans('shop/order_recovery.restored');

            if ($recovery->discount_code && ! $result['discount_applied']) {
                $message .= ' ' . trans('shop/order_recovery.discount_expired');
            }

            return redirect(shop_route('checkout.index'))->with('success', $message);
        } catch (ValidationException $exception) {
            return back()->withErrors(collect($exception->errors())->flatten()->all());
        }
    }

    public function suppress(string $token, AbandonedOrderRecoveryService $service)
    {
        $recovery = $this->resolve($token, $service);
        $service->suppress($recovery);

        return redirect($service->signedRecoveryUrl($token, $recovery->expires_at))
            ->with('success', trans('shop/order_recovery.suppressed'));
    }

    private function resolve(string $token, AbandonedOrderRecoveryService $service): AbandonedOrderRecovery
    {
        $recovery = $service->findPublic($token);
        abort_unless($recovery, 404);

        $customer = current_customer();
        if ($customer && $recovery->order?->customer_id
                      && (int) $customer->id !== (int) $recovery->order->customer_id) {
            abort(404);
        }

        return $recovery;
    }
}
