<?php

namespace Plugin\PixelTracking\Services;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ServerTrackingService
{
    private const DELIVERY_DEDUPLICATION_HOURS = 72;

    private const MAX_EVENT_AGE_SECONDS = 604800;

    private const CONTEXT_ENCRYPTION_PREFIX = 'enc:v1:';

    private const META_EVENT_MAP = [
        'PageView'             => 'PageView',
        'ViewContent'          => 'ViewContent',
        'AddToCart'            => 'AddToCart',
        'InitiateCheckout'     => 'InitiateCheckout',
        'Purchase'             => 'Purchase',
        'Search'               => 'Search',
        'CompleteRegistration' => 'CompleteRegistration',
    ];

    private const TIKTOK_EVENT_MAP = [
        'PageView'             => 'PageView',
        'ViewContent'          => 'ViewContent',
        'AddToCart'            => 'AddToCart',
        'InitiateCheckout'     => 'InitiateCheckout',
        'Purchase'             => 'CompletePayment',
        'Search'               => 'Search',
        'CompleteRegistration' => 'CompleteRegistration',
    ];

    public function hasConsent(Request $request): bool
    {
        if (! plugin_setting('pixel_tracking.require_consent', false)) {
            return true;
        }

        $name  = (string) plugin_setting('pixel_tracking.consent_cookie', 'tracking_consent');
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) !== 1) {
            $name = 'tracking_consent';
        }
        $value = strtolower((string) $request->cookie($name, ''));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    public function trackBrowserEvent(array $event, Request $request): void
    {
        $prepared = $this->prepareBrowserEvent($event, $request);
        if ($prepared === null) {
            return;
        }

        $this->trackPreparedBrowserEvent($prepared);
    }

    /**
     * Snapshot request-bound values before the response is returned. The
     * resulting scalar-only payload is safe to send from a terminating task.
     */
    public function prepareBrowserEvent(array $event, Request $request): ?array
    {
        if (! $this->isTrackingEnabled() || ! $this->hasConsent($request)) {
            return null;
        }

        $eventName = (string) ($event['event_name'] ?? '');
        $eventId   = (string) ($event['event_id'] ?? '');
        if (! isset(self::META_EVENT_MAP[$eventName])
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $eventId) !== 1) {
            return null;
        }

        $attribution = array_merge(
            (array) $this->sessionValue('pixel_tracking.attribution', []),
            $this->attributionFromUrl((string) ($event['url'] ?? $request->fullUrl())),
        );
        $attribution = $this->normalizeAttribution($attribution);
        if ($attribution !== []) {
            $this->putSessionValue('pixel_tracking.attribution', $attribution);
        }
        $fbp         = (string) $request->cookie('_fbp', '');
        $fbc         = (string) $request->cookie('_fbc', '');
        $ttp         = (string) $request->cookie('_ttp', '');
        $identifiers = array_filter(compact('fbp', 'fbc', 'ttp'));
        if ($identifiers !== []) {
            $this->putSessionValue('pixel_tracking.browser_identifiers', array_merge(
                (array) $this->sessionValue('pixel_tracking.browser_identifiers', []),
                $identifiers,
            ));
        }

        $customer = current_customer();
        $context  = [
            'ip'          => getClientIp($request),
            'user_agent'  => (string) $request->userAgent(),
            'url'         => (string) ($event['url'] ?? $request->fullUrl()),
            'referrer'    => (string) $request->header('referer', ''),
            'attribution' => is_array($attribution) ? $attribution : [],
            'fbp'         => $fbp,
            'fbc'         => $fbc ?: $this->fbcFromClickId(
                Arr::get($attribution, 'fbclid'),
                (int) Arr::get($attribution, 'fbclid_time', 0),
            ),
            'ttp'         => $ttp,
            'customer'    => $customer ? [
                'id'        => (string) $customer->id,
                'email'     => (string) $customer->email,
                'telephone' => (string) $customer->telephone,
            ] : [],
        ];

        return [
            'event_name' => $eventName,
            'event_id'   => $eventId,
            'event_time' => time(),
            'properties' => $this->normalizeProperties($event['properties'] ?? []),
            'context'    => $this->normalizeContext($context),
        ];
    }

    public function trackPreparedBrowserEvent(array $prepared, bool $throwOnProviderFailure = false): bool
    {
        if (! $this->isTrackingEnabled()) {
            return true;
        }

        $eventName = (string) ($prepared['event_name'] ?? '');
        $eventId   = (string) ($prepared['event_id'] ?? '');
        if (! isset(self::META_EVENT_MAP[$eventName])
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $eventId) !== 1) {
            return true;
        }

        $successful = $this->dispatch(
            $eventName,
            $eventId,
            $this->normalizeProperties($prepared['properties'] ?? []),
            $this->normalizeContext((array) ($prepared['context'] ?? [])),
            $this->normalizeEventTime($prepared['event_time'] ?? time()),
        );
        if (! $successful && $throwOnProviderFailure) {
            throw new \RuntimeException('One or more pixel tracking providers rejected the event.');
        }

        return $successful;
    }

    public function trackOrderPurchase(Order $order): void
    {
        $prepared = $this->prepareOrderPurchase($order);
        if ($prepared === null) {
            return;
        }

        $this->trackPreparedBrowserEvent($prepared);
    }

    public function prepareOrderPurchase(Order $order): ?array
    {
        if (! $this->isTrackingEnabled()) {
            return null;
        }

        $storedContext = $this->storedOrderContext($order);
        $hasConsent    = (bool) ($storedContext['consent'] ?? $this->sessionValue('pixel_tracking.consent', false));
        if (plugin_setting('pixel_tracking.require_consent', false) && ! $hasConsent) {
            return null;
        }

        $order->loadMissing('orderProducts');
        $identifiers = array_merge(
            (array) ($storedContext['browser_identifiers'] ?? []),
            (array) $this->sessionValue('pixel_tracking.browser_identifiers', []),
        );
        $contents = $order->orderProducts->map(fn ($product) => [
            'id'         => (string) ($product->product_id ?: $product->product_sku),
            'quantity'   => (int) $product->quantity,
            'item_price' => (float) currency_format(
                $product->price,
                (string) $order->currency_code,
                (string) $order->currency_value,
                false,
            ),
        ])->values()->all();

        $properties = [
            'value'        => (float) $order->settlement_total,
            'currency'     => strtoupper((string) $order->currency_code),
            'content_ids'  => array_column($contents, 'id'),
            'contents'     => $contents,
            'content_type' => 'product',
            'order_id'     => (string) $order->number,
        ];

        $attribution = array_merge(
            (array) ($storedContext['attribution'] ?? []),
            (array) $this->sessionValue('pixel_tracking.attribution', []),
        );
        $context     = [
            'ip'          => (string) $order->ip,
            'user_agent'  => (string) $order->user_agent,
            'url'         => shop_route('checkout.success', ['order_number' => $order->number]),
            'referrer'    => '',
            'attribution' => is_array($attribution) ? $attribution : [],
            'fbp'         => $identifiers['fbp'] ?? '',
            'fbc'         => ($identifiers['fbc'] ?? '') ?: $this->fbcFromClickId(
                Arr::get($attribution, 'fbclid'),
                (int) Arr::get($attribution, 'fbclid_time', 0),
            ),
            'ttp'         => $identifiers['ttp'] ?? '',
            'customer'    => [],
            'email'       => (string) $order->email,
            'phone'       => (string) ($order->telephone ?: $order->shipping_telephone),
            'external_id' => (string) ($order->customer_id ?: $order->number),
        ];

        return [
            'event_name' => 'Purchase',
            'event_id'   => 'purchase_' . $order->id,
            'event_time' => time(),
            'properties' => $properties,
            'context'    => $this->normalizeContext($context),
        ];
    }

    /**
     * Persist request-only attribution at order creation. Payment providers
     * commonly confirm an order through a later webhook which has no shopper
     * session; without this snapshot the server conversion silently loses
     * consent and click identifiers.
     */
    public function rememberOrderContext(Order $order, Request $request): void
    {
        try {
            if (! Schema::hasTable('pixel_tracking_order_contexts')) {
                return;
            }

            $consent     = $this->hasConsent($request);
            $attribution = [];
            $identifiers = [];
            if (! plugin_setting('pixel_tracking.require_consent', false) || $consent) {
                $attribution = $this->normalizeAttribution((array) $this->sessionValue('pixel_tracking.attribution', []));
                $identifiers = $this->normalizeBrowserIdentifiers(array_merge(
                    (array) $this->sessionValue('pixel_tracking.browser_identifiers', []),
                    array_filter([
                        'fbp' => (string) $request->cookie('_fbp', ''),
                        'fbc' => (string) $request->cookie('_fbc', ''),
                        'ttp' => (string) $request->cookie('_ttp', ''),
                    ]),
                ));
            }
            $now = now();

            DB::table('pixel_tracking_order_contexts')->updateOrInsert(
                ['order_id' => $order->id],
                [
                    'consent'             => $consent,
                    'attribution'         => $this->encodeStoredArray($attribution),
                    'browser_identifiers' => $this->encodeStoredArray($identifiers),
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ],
            );
        } catch (\Throwable $exception) {
            // Analytics storage is best-effort and must never abort checkout.
            Log::warning('Unable to persist order tracking context.', [
                'order_id'  => $order->id,
                'exception' => $exception::class,
            ]);
        }
    }

    public function prepareCustomerRegistration(mixed $customer, Request $request): ?array
    {
        if (! $this->hasConsent($request)) {
            return null;
        }

        $prepared = $this->prepareBrowserEvent([
            'event_name' => 'CompleteRegistration',
            'event_id'   => 'registration_' . (string) $customer->id,
            'url'        => $request->fullUrl(),
            'properties' => [],
        ], $request);

        if ($prepared === null) {
            return null;
        }

        $prepared['context']['customer']    = [];
        $prepared['context']['email']       = (string) ($customer->email ?? '');
        $prepared['context']['phone']       = (string) ($customer->telephone ?? '');
        $prepared['context']['external_id'] = (string) $customer->id;

        return $prepared;
    }

    private function dispatch(string $eventName, string $eventId, array $properties, array $context, int $eventTime): bool
    {
        $metaSuccessful   = $this->sendMeta($eventName, $eventId, $properties, $context, $eventTime);
        $tiktokSuccessful = $this->sendTikTok($eventName, $eventId, $properties, $context, $eventTime);

        return $metaSuccessful && $tiktokSuccessful;
    }

    /**
     * The Store Builder app-embed toggle is the single public off switch for
     * this integration. Enforce it in the service too, so queued events or a
     * direct endpoint call cannot keep transmitting after an admin disables
     * the embed in the visual editor.
     */
    private function isTrackingEnabled(): bool
    {
        $serverTracking = plugin_setting('pixel_tracking.server_tracking', true);
        $pluginEnabled  = plugin_setting('pixel_tracking.status', true);

        // Browser app-embed and server tracking are independent controls.
        // Merchants may intentionally disable browser scripts while retaining
        // authoritative Purchase events from the server.
        return ! in_array($pluginEnabled, [false, 0, '0'], true)
            && ! in_array($serverTracking, [false, 0, '0'], true);
    }

    private function sendMeta(string $eventName, string $eventId, array $properties, array $context, int $eventTime): bool
    {
        $mappedEvent = self::META_EVENT_MAP[$eventName] ?? null;
        if (! $mappedEvent) {
            return true;
        }

        $allSuccessful = true;

        foreach (PixelProfiles::meta() as $profile) {
            $token = (string) ($profile['access_token'] ?? '');
            if ($token === '') {
                continue;
            }

            $profileId = (string) $profile['pixel_id'];
            $delivery  = $this->beginDelivery('meta', $profileId, $mappedEvent, $eventId);
            if ($delivery === false) {
                continue;
            }

            $payload = [
                'data' => [[
                    'event_name'       => $mappedEvent,
                    'event_time'       => $eventTime,
                    'event_id'         => $eventId,
                    'event_source_url' => $context['url'] ?? '',
                    'action_source'    => 'website',
                    'user_data'        => $this->metaUserData($context),
                    'custom_data'      => $this->metaCustomData($properties, (array) ($context['attribution'] ?? [])),
                ]],
                'access_token' => $token,
            ];
            if (! empty($profile['test_event_code'])) {
                $payload['test_event_code'] = $profile['test_event_code'];
            }

            $version = $profile['api_version'] ?? 'v26.0';

            try {
                $response = Http::connectTimeout(3)
                    ->timeout(8)
                    ->retry(2, 250, fn (\Throwable $exception): bool => $this->shouldRetryProviderRequest($exception), throw: false)
                    ->post("https://graph.facebook.com/{$version}/{$profile['pixel_id']}/events", $payload);
                $error    = $response->json('error.message');
                $received = $response->json('events_received');
                $success  = $response->successful()
                    && $error === null
                    && is_numeric($received)
                    && (int) $received > 0;
                $message = $success ? null : SensitiveValueRedactor::redact((string) ($error ?: 'Meta API HTTP ' . $response->status()));
                $this->recordResult('meta', $profileId, $eventName, $eventId, $success, $response->status(), $message);
                $this->finishDelivery($delivery, $success);
                $allSuccessful = $allSuccessful && $success;
            } catch (\Throwable $exception) {
                $this->recordResult('meta', $profileId, $eventName, $eventId, false, 0, SensitiveValueRedactor::redact($exception->getMessage()));
                $allSuccessful = false;
            } finally {
                $this->releaseDelivery($delivery);
            }
        }

        return $allSuccessful;
    }

    private function sendTikTok(string $eventName, string $eventId, array $properties, array $context, int $eventTime): bool
    {
        $mappedEvent = self::TIKTOK_EVENT_MAP[$eventName] ?? null;
        if (! $mappedEvent) {
            return true;
        }

        $allSuccessful = true;

        foreach (PixelProfiles::tiktok() as $profile) {
            $token = (string) ($profile['access_token'] ?? '');
            if ($token === '') {
                continue;
            }

            $profileId = (string) $profile['pixel_code'];
            $delivery  = $this->beginDelivery('tiktok', $profileId, $mappedEvent, $eventId);
            if ($delivery === false) {
                continue;
            }

            // TikTok Events API 2.0 uses an event-source envelope. The former
            // pixel_code/context payload can return HTTP 200 with a non-zero
            // provider code and must not be treated as a successful delivery.
            $payload = [
                'event_source'    => 'web',
                'event_source_id' => $profile['pixel_code'],
                'data'            => [[
                    'event'      => $mappedEvent,
                    'event_time' => $eventTime,
                    'event_id'   => $eventId,
                    'user'       => $this->tikTokUserData($context),
                    'page'       => array_filter([
                        'url'      => $context['url']      ?? '',
                        'referrer' => $context['referrer'] ?? '',
                    ]),
                    'properties' => $this->tikTokProperties($properties, (array) ($context['attribution'] ?? [])),
                ]],
            ];

            try {
                $response = Http::connectTimeout(3)
                    ->timeout(8)
                    ->retry(2, 250, fn (\Throwable $exception): bool => $this->shouldRetryProviderRequest($exception), throw: false)
                    ->withHeaders(['Access-Token' => $token])
                    ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload);
                $providerCode = $response->json('code');
                $success      = $response->successful() && $providerCode !== null && (int) $providerCode === 0;
                $message      = $success ? null : SensitiveValueRedactor::redact((string) ($response->json('message') ?: 'TikTok API HTTP ' . $response->status()));
                $this->recordResult('tiktok', $profileId, $eventName, $eventId, $success, $response->status(), $message);
                $this->finishDelivery($delivery, $success);
                $allSuccessful = $allSuccessful && $success;
            } catch (\Throwable $exception) {
                $this->recordResult('tiktok', $profileId, $eventName, $eventId, false, 0, SensitiveValueRedactor::redact($exception->getMessage()));
                $allSuccessful = false;
            } finally {
                $this->releaseDelivery($delivery);
            }
        }

        return $allSuccessful;
    }

    private function metaUserData(array $context): array
    {
        $data = array_filter([
            'client_ip_address' => $context['ip']         ?? '',
            'client_user_agent' => $context['user_agent'] ?? '',
            'fbp'               => $context['fbp']        ?? '',
            'fbc'               => $context['fbc']        ?? '',
        ]);
        if (! plugin_setting('pixel_tracking.advanced_matching', false)) {
            return $data;
        }

        return array_filter($data + [
            'em'          => $this->hashIdentifier($context['email'] ?? data_get($context, 'customer.email')),
            'ph'          => $this->hashIdentifier($context['phone'] ?? data_get($context, 'customer.telephone')),
            'external_id' => $this->hashIdentifier($context['external_id'] ?? data_get($context, 'customer.id')),
        ]);
    }

    private function fbcFromClickId(?string $clickId, int $capturedAt = 0): ?string
    {
        if (! $clickId) {
            return null;
        }

        $milliseconds = $capturedAt > 0 ? $capturedAt * 1000 : (int) round(microtime(true) * 1000);

        return 'fb.1.' . $milliseconds . '.' . $clickId;
    }

    private function tikTokUserData(array $context): array
    {
        $data = array_filter([
            'ip'         => $context['ip']         ?? '',
            'user_agent' => $context['user_agent'] ?? '',
            'ttclid'     => Arr::get($context, 'attribution.ttclid'),
            'ttp'        => $context['ttp'] ?? '',
        ]);

        if (! plugin_setting('pixel_tracking.advanced_matching', false)) {
            return $data;
        }

        return array_filter($data + [
            'email'        => $this->hashIdentifier($context['email'] ?? data_get($context, 'customer.email')),
            'phone_number' => $this->hashIdentifier($context['phone'] ?? data_get($context, 'customer.telephone')),
            'external_id'  => $this->hashIdentifier($context['external_id'] ?? data_get($context, 'customer.id')),
        ]);
    }

    private function metaCustomData(array $properties, array $attribution = []): array
    {
        return array_filter([
            'value'         => isset($properties['value']) ? (float) $properties['value'] : null,
            'currency'      => $properties['currency']      ?? null,
            'content_ids'   => $properties['content_ids']   ?? null,
            'contents'      => $properties['contents']      ?? null,
            'content_type'  => $properties['content_type']  ?? null,
            'search_string' => $properties['search_string'] ?? null,
            'order_id'      => $properties['order_id']      ?? null,
            'utm_source'    => $attribution['utm_source']   ?? null,
            'utm_medium'    => $attribution['utm_medium']   ?? null,
            'utm_campaign'  => $attribution['utm_campaign'] ?? null,
            'utm_content'   => $attribution['utm_content']  ?? null,
            'utm_term'      => $attribution['utm_term']     ?? null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function tikTokProperties(array $properties, array $attribution = []): array
    {
        $items    = array_filter($properties['contents'] ?? [], 'is_array');
        $contents = array_map(static fn (array $item) => array_filter([
            'content_id'   => $item['id']                 ?? null,
            'content_type' => $properties['content_type'] ?? null,
            'quantity'     => isset($item['quantity']) ? (int) $item['quantity'] : null,
            'price'        => isset($item['item_price']) ? (float) $item['item_price'] : null,
        ], static fn ($value) => $value !== null && $value !== ''), $items);

        return array_filter([
            'value'         => isset($properties['value']) ? (float) $properties['value'] : null,
            'currency'      => $properties['currency']      ?? null,
            'contents'      => $contents,
            'content_ids'   => $properties['content_ids']   ?? null,
            'content_type'  => $properties['content_type']  ?? null,
            'search_string' => $properties['search_string'] ?? null,
            'order_id'      => $properties['order_id']      ?? null,
            'utm_source'    => $attribution['utm_source']   ?? null,
            'utm_medium'    => $attribution['utm_medium']   ?? null,
            'utm_campaign'  => $attribution['utm_campaign'] ?? null,
            'utm_content'   => $attribution['utm_content']  ?? null,
            'utm_term'      => $attribution['utm_term']     ?? null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function hashIdentifier(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if (str_contains($value, '@') === false) {
            $value = preg_replace('/\D+/', '', $value);
        }

        return $value === '' ? null : hash('sha256', $value);
    }

    private function normalizeProperties(mixed $properties): array
    {
        if (! is_array($properties)) {
            return [];
        }

        $normalized = [];
        if (isset($properties['value']) && is_numeric($properties['value'])) {
            $normalized['value'] = max(0, min((float) $properties['value'], 999999999.99));
        }
        if (isset($properties['currency'])) {
            $currency = strtoupper(trim((string) $properties['currency']));
            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $normalized['currency'] = $currency;
            }
        }

        foreach (['content_type', 'search_string', 'order_id'] as $key) {
            if (isset($properties[$key]) && is_scalar($properties[$key])) {
                $normalized[$key] = mb_substr(trim((string) $properties[$key]), 0, 191);
            }
        }

        if (isset($properties['content_ids']) && is_array($properties['content_ids'])) {
            $normalized['content_ids'] = array_values(array_slice(array_filter(array_map(
                static fn ($id) => is_scalar($id) ? mb_substr(trim((string) $id), 0, 191) : '',
                $properties['content_ids'],
            )), 0, 50));
        }

        if (isset($properties['contents']) && is_array($properties['contents'])) {
            $normalized['contents'] = array_values(array_slice(array_filter(array_map(static function ($item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $id = mb_substr(trim((string) ($item['id'] ?? $item['content_id'] ?? '')), 0, 191);
                if ($id === '') {
                    return null;
                }

                return array_filter([
                    'id'         => $id,
                    'quantity'   => isset($item['quantity']) ? max(1, min((int) $item['quantity'], 999999)) : 1,
                    'item_price' => isset($item['item_price']) && is_numeric($item['item_price'])
                        ? max(0, min((float) $item['item_price'], 999999999.99))
                        : null,
                ], static fn ($value) => $value !== null);
            }, $properties['contents'])), 0, 50));
        }

        return $normalized;
    }

    private function storedOrderContext(Order $order): array
    {
        try {
            if (! Schema::hasTable('pixel_tracking_order_contexts')) {
                return [];
            }

            $context = DB::table('pixel_tracking_order_contexts')
                ->where('order_id', $order->id)
                ->first();
            if (! $context) {
                return [];
            }

            return [
                'consent'             => (bool) $context->consent,
                'attribution'         => $this->decodeStoredArray($context->attribution ?? null),
                'browser_identifiers' => $this->decodeStoredArray($context->browser_identifiers ?? null),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to read order tracking context.', [
                'order_id'  => $order->id,
                'exception' => $exception::class,
            ]);

            return [];
        }
    }

    private function decodeStoredArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = (string) $value;
        if (str_starts_with($value, self::CONTEXT_ENCRYPTION_PREFIX)) {
            try {
                $value = decrypt(substr($value, strlen(self::CONTEXT_ENCRYPTION_PREFIX)));
            } catch (\Throwable) {
                return [];
            }
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeStoredArray(array $value): string
    {
        return self::CONTEXT_ENCRYPTION_PREFIX . encrypt(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function attributionFromUrl(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parameters);
        $attribution = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'ttclid'] as $key) {
            if (isset($parameters[$key]) && is_scalar($parameters[$key])) {
                $attribution[$key] = $parameters[$key];
            }
        }
        if (isset($attribution['fbclid'])) {
            $attribution['fbclid_time'] = time();
        }

        return $attribution;
    }

    private function normalizeAttribution(array $attribution): array
    {
        $normalized = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'ttclid'] as $key) {
            if (isset($attribution[$key]) && is_scalar($attribution[$key])) {
                $normalized[$key] = mb_substr(trim((string) $attribution[$key]), 0, 500);
            }
        }
        if (isset($attribution['fbclid_time']) && is_numeric($attribution['fbclid_time'])) {
            $normalized['fbclid_time'] = max(0, (int) $attribution['fbclid_time']);
        }

        return $normalized;
    }

    private function normalizeBrowserIdentifiers(array $identifiers): array
    {
        $normalized = [];
        foreach (['fbp', 'fbc', 'ttp'] as $key) {
            if (isset($identifiers[$key]) && is_scalar($identifiers[$key])) {
                $value = mb_substr(trim((string) $identifiers[$key]), 0, 500);
                if ($value !== '') {
                    $normalized[$key] = $value;
                }
            }
        }

        return $normalized;
    }

    private function normalizeContext(array $context): array
    {
        $url      = $this->normalizeHttpUrl($context['url'] ?? null);
        $referrer = $this->normalizeHttpUrl($context['referrer'] ?? null);
        $ip       = mb_substr(trim((string) ($context['ip'] ?? '')), 0, 64);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '';
        }

        $customer = is_array($context['customer'] ?? null) ? $context['customer'] : [];
        $customer = array_filter([
            'id'        => mb_substr(trim((string) ($customer['id'] ?? '')), 0, 191),
            'email'     => mb_substr(trim((string) ($customer['email'] ?? '')), 0, 320),
            'telephone' => mb_substr(trim((string) ($customer['telephone'] ?? '')), 0, 64),
        ], static fn ($value) => $value !== '');
        $identifiers = $this->normalizeBrowserIdentifiers([
            'fbp' => $context['fbp'] ?? '',
            'fbc' => $context['fbc'] ?? '',
            'ttp' => $context['ttp'] ?? '',
        ]);

        return array_filter([
            'ip'          => $ip,
            'user_agent'  => mb_substr(trim((string) ($context['user_agent'] ?? '')), 0, 500),
            'url'         => $url,
            'referrer'    => $referrer,
            'attribution' => $this->normalizeAttribution(is_array($context['attribution'] ?? null) ? $context['attribution'] : []),
            'fbp'         => $identifiers['fbp'] ?? '',
            'fbc'         => $identifiers['fbc'] ?? '',
            'ttp'         => $identifiers['ttp'] ?? '',
            'customer'    => $customer,
            'email'       => mb_substr(trim((string) ($context['email'] ?? '')), 0, 320),
            'phone'       => mb_substr(trim((string) ($context['phone'] ?? '')), 0, 64),
            'external_id' => mb_substr(trim((string) ($context['external_id'] ?? '')), 0, 191),
        ], static fn ($value) => $value !== '' && $value !== []);
    }

    private function normalizeHttpUrl(mixed $value): string
    {
        $url    = mb_substr(trim((string) $value), 0, 2048);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)
            ? $url
            : '';
    }

    private function normalizeEventTime(mixed $eventTime): int
    {
        $now       = time();
        $eventTime = is_numeric($eventTime) ? (int) $eventTime : $now;

        return max($now - self::MAX_EVENT_AGE_SECONDS, min($eventTime, $now + 300));
    }

    /**
     * Prevent concurrent request retries from sending the same server event
     * more than once. Provider-side event IDs remain the final deduplication
     * authority; this local guard reduces avoidable duplicate API traffic.
     *
     * @return array{lock_key: string|null, delivered_key: string|null}|false
     */
    private function beginDelivery(string $channel, string $profileId, string $event, string $eventId): array|false
    {
        $fingerprint  = hash('sha256', implode('|', [$channel, $profileId, $event, $eventId]));
        $deliveredKey = 'pixel_tracking:delivered:' . $fingerprint;
        $lockKey      = 'pixel_tracking:delivery_lock:' . $fingerprint;

        try {
            if (Cache::has($deliveredKey) || ! Cache::add($lockKey, true, now()->addSeconds(30))) {
                return false;
            }

            return ['lock_key' => $lockKey, 'delivered_key' => $deliveredKey];
        } catch (\Throwable) {
            // A cache outage must not disable conversion delivery.
            return ['lock_key' => null, 'delivered_key' => null];
        }
    }

    /** @param array{lock_key: string|null, delivered_key: string|null} $delivery */
    private function finishDelivery(array $delivery, bool $success): void
    {
        if (! $success || $delivery['delivered_key'] === null) {
            return;
        }

        try {
            Cache::put($delivery['delivered_key'], true, now()->addHours(self::DELIVERY_DEDUPLICATION_HOURS));
        } catch (\Throwable) {
            // Provider delivery already succeeded; diagnostics cache is optional.
        }
    }

    /** @param array{lock_key: string|null, delivered_key: string|null} $delivery */
    private function releaseDelivery(array $delivery): void
    {
        if ($delivery['lock_key'] === null) {
            return;
        }

        try {
            Cache::forget($delivery['lock_key']);
        } catch (\Throwable) {
            // The short lock expires by itself.
        }
    }

    private function sessionValue(string $key, mixed $default = null): mixed
    {
        try {
            return session($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function putSessionValue(string $key, mixed $value): void
    {
        try {
            session([$key => $value]);
        } catch (\Throwable) {
            // Webhooks and queue workers intentionally have no HTTP session.
        }
    }

    private function recordResult(string $channel, string $profileId, string $event, string $eventId, bool $success, int $status, ?string $error = null): void
    {
        TrackingHealth::record($channel, $profileId, $event, $eventId, $success, $status, $error);

        $context = compact('channel', 'profileId', 'event', 'eventId', 'success', 'status');
        if (! $success) {
            $safeError = preg_replace(
                '/(access[_-]?token|token|secret|authorization)["\']?\s*[=:]\s*["\']?[^"\'\s,&}]+/i',
                '$1=[redacted]',
                strip_tags((string) $error),
            );
            $context['error'] = mb_substr((string) $safeError, 0, 500);
            Log::warning('Pixel tracking dispatch rejected.', $context);

            return;
        }

        if (plugin_setting('pixel_tracking.debug_log', false)) {
            Log::info('Pixel tracking dispatch accepted.', $context);
        }
    }

    private function shouldRetryProviderRequest(\Throwable $exception): bool
    {
        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
