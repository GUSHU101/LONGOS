<?php

namespace Plugin\PixelTracking\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugin\PixelTracking\Services\PixelEventDispatcher;
use Plugin\PixelTracking\Services\ServerTrackingService;

class TrackingController
{
    public function store(Request $request, ServerTrackingService $tracking): JsonResponse
    {
        if (! $tracking->hasConsent($request)) {
            return response()->json(['ok' => true, 'skipped' => 'consent']);
        }

        $data = $request->validate([
            // Purchase and registration are emitted by authoritative domain
            // hooks only. Accepting either from a browser would allow forged
            // conversions to pollute the advertising data.
            'event_name'                       => ['required', 'string', 'in:PageView,ViewContent,AddToCart,InitiateCheckout,Search'],
            'event_id'                         => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'url'                              => ['required', 'url', 'max:2048'],
            'properties'                       => ['nullable', 'array'],
            'properties.value'                 => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'properties.currency'              => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'properties.content_type'          => ['nullable', 'string', 'max:64'],
            'properties.search_string'         => ['nullable', 'string', 'max:191'],
            'properties.order_id'              => ['nullable', 'string', 'max:191'],
            'properties.content_ids'           => ['nullable', 'array', 'max:50'],
            'properties.content_ids.*'         => ['string', 'max:191'],
            'properties.contents'              => ['nullable', 'array', 'max:50'],
            'properties.contents.*'            => ['array'],
            'properties.contents.*.id'         => ['nullable', 'string', 'max:191'],
            'properties.contents.*.content_id' => ['nullable', 'string', 'max:191'],
            'properties.contents.*.quantity'   => ['nullable', 'integer', 'min:1', 'max:999999'],
            'properties.contents.*.item_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $host        = parse_url($data['url'], PHP_URL_HOST);
        $scheme      = parse_url($data['url'], PHP_URL_SCHEME);
        $port        = parse_url($data['url'], PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
        $requestPort = $request->getPort();
        if (! is_string($host)
            || ! is_string($scheme)
            || ! hash_equals(strtolower($request->getHost()), strtolower($host))
            || ! hash_equals(strtolower($request->getScheme()), strtolower($scheme))
            || (int) $port !== $requestPort) {
            return response()->json(['message' => 'Tracking URL must use this storefront origin.'], 422);
        }

        $prepared = $tracking->prepareBrowserEvent($data, $request);
        if ($prepared !== null) {
            PixelEventDispatcher::dispatch($prepared);
        }

        return response()->json(['ok' => true], 202);
    }
}
