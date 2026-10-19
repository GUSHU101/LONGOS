<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SandboxReviewService
{
    public function __construct(private AdminAuditService $audit) {}

    public function decide(
        string $orderId,
        string $action,
        ?int $actorId,
        ?string $reason = null
    ): array {
        if (! in_array($action, ['APPROVE', 'RETRY_OTP'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Unsupported review action.'],
            ]);
        }

        $result = DB::transaction(function () use ($orderId, $action, $actorId, $reason) {
            $order = DB::table('beikeshop_test_checkout_logs')
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return [
                    'status' => 404,
                    'body'   => ['success' => false, 'message' => '沙盒会话不存在'],
                ];
            }

            $nextState = $action === 'APPROVE'
                ? 'APPROVED'
                : 'OTP_REQUIRED';

            // A browser retry or a double click can reach the server after
            // the first transaction has committed. Treat that exact repeated
            // decision as success instead of surfacing a misleading 409.
            if ($order->status === $nextState && $order->reviewed_at !== null) {
                return [
                    'status' => 200,
                    'body'   => [
                        'success'    => true,
                        'state'      => $nextState,
                        'idempotent' => true,
                        'message'    => '该人工裁决已生效。',
                    ],
                ];
            }

            if ($order->status !== 'WAITING_REVIEW') {
                return [
                    'status' => 409,
                    'body'   => [
                        'success' => false,
                        'message' => '只有 WAITING_REVIEW 状态可以人工裁决',
                        'state'   => $order->status,
                    ],
                ];
            }

            $message = $action === 'APPROVE'
                ? '已放行；客户侧将显示 TEST 支付已通过。'
                : '已拒绝本次 OTP；客户侧已重置，可立即输入新的验证码。';

            DB::table('beikeshop_test_checkout_logs')
                ->where('id', $order->id)
                ->update([
                    'status'      => $nextState,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actorId,
                    'updated_at'  => now(),
                ]);

            DB::table('beikeshop_test_review_events')->insert([
                'event_id'    => (string) Str::uuid(),
                'order_id'    => $orderId,
                'actor_id'    => $actorId,
                'action'      => $action,
                'from_status' => $order->status,
                'to_status'   => $nextState,
                'reason'      => $reason,
                'created_at'  => now(),
            ]);

            return [
                'status' => 200,
                'body'   => [
                    'success' => true,
                    'state'   => $nextState,
                    'message' => $message,
                ],
            ];
        });

        if (($result['status'] ?? 500) === 200 && empty($result['body']['idempotent'])) {
            Cache::forever('beikeshop_test_stream_version', (string) Str::uuid());

            $this->audit->record(
                'REVIEW_DECISION',
                $orderId,
                $actorId,
                [
                    'action' => $action,
                    'state'  => $result['body']['state'] ?? null,
                    'reason' => $reason,
                ]
            );
        }

        return $result;
    }
}
