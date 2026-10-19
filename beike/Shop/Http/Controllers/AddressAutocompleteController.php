<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Shop\Services\AddressAutocompleteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddressAutocompleteController extends Controller
{
    public function index(Request $request, AddressAutocompleteService $service)
    {
        $validated = $request->validate([
            'q'            => ['required', 'string', 'min:3', 'max:160'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'lang'         => ['nullable', 'string', 'regex:/^[a-z]{2}(?:-[a-z]{2})?$/i'],
        ]);

        try {
            $suggestions = $service->search(
                (string) $validated['q'],
                (string) ($validated['lang'] ?? app()->getLocale()),
                (string) ($validated['country_code'] ?? ''),
            );

            return json_success(trans('common.get_success'), $suggestions);
        } catch (Throwable $e) {
            // Do not log the customer's street-address query. The exception
            // class is sufficient for operations while preserving privacy.
            Log::warning('Checkout address suggestions are temporarily unavailable.', [
                'exception' => $e::class,
            ]);

            return json_fail(trans('shop/checkout.address_lookup_unavailable'), [], 503);
        }
    }
}
