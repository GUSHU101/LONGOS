<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAuditService
{
    public function record(
        string $event,
        ?string $orderId = null,
        ?int $actorId = null,
        array $meta = []
    ): void {
        try {
            DB::table('beikeshop_test_admin_events')->insert([
                'event_id' => (string) Str::uuid(),
                'order_id' => $orderId,
                'actor_id' => $actorId,
                'event'    => $event,
                'meta'     => $meta
                    ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Admin telemetry must never break the sandbox workflow.
        }
    }
}
