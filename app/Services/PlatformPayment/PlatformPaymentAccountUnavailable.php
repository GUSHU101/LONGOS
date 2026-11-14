<?php

namespace App\Services\PlatformPayment;

use RuntimeException;

/**
 * Raised only before a remote PayPal order can exist (OAuth acquisition or
 * managed callback validation). The caller may safely fail over the untouched
 * session to a different receiver account. It must never be used for an
 * ambiguous Create Order timeout or a post-approval capture failure.
 */
final class PlatformPaymentAccountUnavailable extends RuntimeException {}
