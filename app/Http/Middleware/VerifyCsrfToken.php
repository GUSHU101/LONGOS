<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Stripe verifies its own signed raw-body webhook. Keep this exact so
        // future plugin routes under /callback do not silently bypass CSRF.
        'callback/stripe',
        // PayPal authenticates NVP IPN bodies by replaying the exact raw body
        // to its verification endpoint, so these provider POSTs have no CSRF token.
        'paypal/nvp/notify',
        'api/*',
    ];
}
