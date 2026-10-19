<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderRetentionService
{
    public function __construct(private AdminAuditService $audit) {}

    public function deleteOrder(string $orderId, string $confirmation, ?int $actorId): array
    {
        if (! hash_equals($orderId, trim($confirmation))) {
            throw ValidationException::withMessages([
                'confirmation' => ['必须输入完整订单号确认删除。'],
            ]);
        }

        $result = DB::transaction(function () use ($orderId) {
            $order = DB::table('beikeshop_test_checkout_logs')
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return [
                    'status' => 404,
                    'body'   => [
                        'success' => false,
                        'message' => '订单不存在或已被删除。',
                    ],
                ];
            }

            $reviewCount = DB::table('beikeshop_test_review_events')
                ->where('order_id', $orderId)
                ->count();

            $binCount = DB::table('beikeshop_test_bin_events')
                ->where('order_id', $orderId)
                ->count();

            $adminCount = DB::table('beikeshop_test_admin_events')
                ->where('order_id', $orderId)
                ->count();

            DB::table('beikeshop_test_review_events')
                ->where('order_id', $orderId)
                ->delete();

            DB::table('beikeshop_test_bin_events')
                ->where('order_id', $orderId)
                ->delete();

            DB::table('beikeshop_test_admin_events')
                ->where('order_id', $orderId)
                ->delete();

            DB::table('beikeshop_test_checkout_logs')
                ->where('id', $order->id)
                ->delete();

            return [
                'status' => 200,
                'body'   => [
                    'success' => true,
                    'message' => '订单及关联记录已手动删除。',
                    'deleted' => [
                        'orderId'      => $orderId,
                        'reviewEvents' => $reviewCount,
                        'binEvents'    => $binCount,
                        'adminEvents'  => $adminCount,
                    ],
                ],
            ];
        });

        if (($result['status'] ?? 500) === 200) {
            // 独立删除审计故意不绑定 order_id，避免随订单删除而消失。
            $this->audit->record(
                'MANUAL_DELETE_ORDER',
                null,
                $actorId,
                [
                    'deletedOrderId' => $orderId,
                    'deleted'        => $result['body']['deleted'] ?? [],
                ]
            );

            Cache::forever(
                'beikeshop_test_stream_version',
                (string) Str::uuid()
            );
        }

        return $result;
    }
}
