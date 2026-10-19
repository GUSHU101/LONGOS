<?php

namespace Beike\Admin\Http\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Admin\Http\Requests\AbandonedOrderRecoverySendRequest;
use Beike\Admin\Http\Requests\AbandonedOrderRecoverySettingsRequest;
use Beike\Models\AbandonedOrderRecovery;
use Beike\Models\Order;
use Beike\Services\AbandonedOrderRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AbandonedOrderRecoveryController extends Controller
{
    public function index(Request $request, AbandonedOrderRecoveryService $service)
    {
        $orders  = $service->candidates($request->only('keyword'));
        $history = AbandonedOrderRecovery::query()
            ->with(['order:id,number', 'adminUser:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        $previewOrderId = $orders->first()?->id
            ?? Order::query()->whereHas('orderProducts')->latest('id')->value('id');

        return view('admin::pages.abandoned_order_recoveries.index', [
            'orders'         => $orders,
            'settings'       => $service->settings(),
            'discounts'      => $service->activeDiscountCodes(),
            'history'        => $history,
            // Template preview is useful before an order reaches the delivery
            // delay. Fall back to the latest real order instead of disabling
            // the preview button whenever the candidate list is empty.
            'previewOrderId' => $previewOrderId,
            'stats'          => [
                'sent'      => AbandonedOrderRecovery::query()->where('status', AbandonedOrderRecovery::STATUS_SENT)->count(),
                'restored'  => AbandonedOrderRecovery::query()->whereNotNull('restored_at')->count(),
                'converted' => AbandonedOrderRecovery::query()->whereNotNull('converted_at')->count(),
            ],
        ]);
    }

    public function updateSettings(
        AbandonedOrderRecoverySettingsRequest $request,
        AbandonedOrderRecoveryService $service,
    ): JsonResponse {
        $settings = $service->saveSettings($request->validated('settings'));

        return json_success(trans('common.updated_success'), $settings);
    }

    public function preview(Request $request, AbandonedOrderRecoveryService $service): JsonResponse
    {
        $validated = $request->validate([
            'order_id'                   => ['required', 'integer', 'min:1'],
            'discount_id'                => ['nullable', 'integer', 'min:1'],
            'settings'                   => ['required', 'array'],
            'settings.delay_hours'       => ['required', 'integer', 'min:1', 'max:720'],
            'settings.cooldown_hours'    => ['required', 'integer', 'min:1', 'max:720'],
            'settings.max_attempts'      => ['required', 'integer', 'min:1', 'max:10'],
            'settings.link_days'         => ['required', 'integer', 'min:1', 'max:90'],
            'settings.subject'           => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'settings.heading'           => ['required', 'string', 'max:180'],
            'settings.message'           => ['required', 'string', 'max:2000'],
            'settings.button_label'      => ['required', 'string', 'max:64'],
            'settings.discount_message'  => ['required', 'string', 'max:300'],
        ]);
        $order = Order::query()->with(['orderProducts', 'orderTotals'])->findOrFail($validated['order_id']);
        $html  = $service->preview($order, $validated['discount_id'] ?? null, $validated['settings']);

        return json_success(trans('common.success'), ['html' => $html]);
    }

    public function send(
        AbandonedOrderRecoverySendRequest $request,
        AbandonedOrderRecoveryService $service,
    ): JsonResponse {
        $validated  = $request->validated();
        $discountId = isset($validated['discount_id']) ? (int) $validated['discount_id'] : null;
        $sent       = [];
        $failed     = [];

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::query()->find($orderId);
            if (! $order) {
                $failed[] = ['order_id' => $orderId, 'message' => trans('admin/abandoned_order_recovery.order_missing')];

                continue;
            }

            try {
                $recovery = $service->send($order, $discountId, current_user());
                $sent[]   = ['order_id' => $order->id, 'recovery_id' => $recovery->id];
            } catch (ValidationException $exception) {
                $failed[] = [
                    'order_id' => $order->id,
                    'message'  => collect($exception->errors())->flatten()->first() ?: trans('admin/abandoned_order_recovery.send_blocked_generic'),
                ];
            } catch (Throwable $exception) {
                Log::warning('Admin abandoned-order recovery send failed.', [
                    'order_id' => $order->id,
                    'message'  => SensitiveValueRedactor::redact($exception->getMessage()),
                ]);
                $failed[] = [
                    'order_id' => $order->id,
                    'message'  => trans('admin/abandoned_order_recovery.send_failed'),
                ];
            }
        }

        $message = trans('admin/abandoned_order_recovery.send_result', [
            'sent'   => count($sent),
            'failed' => count($failed),
        ]);

        return json_success($message, compact('sent', 'failed'));
    }
}
