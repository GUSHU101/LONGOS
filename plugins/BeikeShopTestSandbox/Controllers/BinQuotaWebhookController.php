<?php

namespace Plugin\BeikeShopTestSandbox\Controllers;

use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BinQuotaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = trim((string) config('beikeshop_test.bin_lookup.quota_webhook_secret'));

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Quota webhook secret is not configured.',
            ], 503);
        }

        $raw      = $request->getContent();
        $provided = trim((string) $request->header('X-BSL-Signature', ''));
        $provided = preg_replace('/^sha256=/i', '', $provided);

        $expected = hash_hmac('sha256', $raw, $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $payload = $request->json()->all();

        Cache::put(
            'beikeshop_test:bsl:quota_webhook:last',
            [
                'receivedAt' => now()->toIso8601String(),
                'payload'    => $payload,
            ],
            now()->addDays(30)
        );

        try {
            DB::table('beikeshop_test_bin_events')->insert([
                'event_id'        => (string) Str::uuid(),
                'order_id'        => null,
                'event'           => 'QUOTA_WEBHOOK',
                'provider'        => 'binsearchlookup.com',
                'endpoint'        => '/webhooks/quota',
                'bin_prefix'      => null,
                'source'          => 'WEBHOOK',
                'status'          => 'OK',
                'http_status'     => 200,
                'response_ms'     => null,
                'request_id'      => null,
                'provider_cached' => null,
                'response_hash'   => hash('sha256', $raw),
                'error_code'      => null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable) {
            // Webhook acknowledgement must not depend on local telemetry storage.
        }

        return response()->json(['success' => true]);
    }
}
