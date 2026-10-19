<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocaleAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        App::setLocale('zh_cn');

        return $next($request);
    }
}
