<?php

namespace Beike\Shop\Http\Controllers;

use Illuminate\View\View;

class TrackingController extends Controller
{
    public function index(): View
    {
        return view('tracking/index');
    }
}
