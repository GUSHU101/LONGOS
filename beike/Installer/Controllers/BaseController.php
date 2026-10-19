<?php

namespace Beike\Installer\Controllers;

use Illuminate\Routing\Controller;

class BaseController extends Controller
{
    protected function checkInstalled(): void
    {
        if (installed()) {
            abort(404);
        }
    }
}
