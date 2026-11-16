<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [
        // Bot Shield runs before the session stack. Its clearance value is
        // already authenticated and encrypted by Laravel Crypt.
        'longos_bot_clearance',
    ];
}
