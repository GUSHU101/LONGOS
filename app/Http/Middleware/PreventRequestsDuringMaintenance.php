<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array
     */
    protected $except = [
        // The already-authenticated control-plane update page keeps polling
        // this read-only endpoint while an update owns maintenance mode.
        // Controller authorization still requires the root administrator.
        '*/system-updates/status',
    ];
}
