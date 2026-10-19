<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocaleFromSession
{
    public function handle(Request $request, Closure $next): mixed
    {
        App::setLocale('en');
        register('locale', 'en');

        return $next($request);
    }
}
