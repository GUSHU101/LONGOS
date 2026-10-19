<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\View;

class RegisterAdminErrorViewPaths
{
    /**
     * Register the error view paths.
     *
     * @return void
     */
    public function __invoke()
    {
        if (is_admin()) {
            $viewPaths[] = base_path('resources/beike/admin/views');

            // The admin 500 template extends admin::layouts.master. Errors
            // can occur before AdminServiceProvider has registered that hint,
            // so register it here as part of the exception-safe view setup.
            View::replaceNamespace('admin', $viewPaths);
            View::replaceNamespace('errors', collect($viewPaths)->map(function ($path) {
                return "{$path}/errors";
            })->all());
        }
    }
}
