<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Services\StorefrontVisitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StorefrontAnalyticsController extends Controller
{
    public function store(Request $request, StorefrontVisitService $visits): Response
    {
        $validated = $request->validate([
            'activity'   => ['required', 'string', Rule::in(['pointer', 'keyboard', 'touch', 'scroll'])],
            'page_path'  => ['nullable', 'string', 'max:1000', 'regex:/^\/(?!\/)/'],
            'page_token' => ['nullable', 'string', 'size:32', 'regex:/^[a-f0-9]+$/'],
        ]);

        try {
            $visits->confirm(
                $request,
                $validated['activity'],
                $validated['page_path']  ?? null,
                $validated['page_token'] ?? null,
            );
        } catch (\Throwable $exception) {
            // Storefront analytics is deliberately fail-open. A telemetry
            // failure must never interrupt browsing, carts or checkout.
            $visits->reportFailure($request, $exception);
        }

        return response('', 204, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
