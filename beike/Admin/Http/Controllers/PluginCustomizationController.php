<?php

namespace Beike\Admin\Http\Controllers;

class PluginCustomizationController extends Controller
{
    public function index()
    {
        return view('admin::pages.plugins.customization');
    }
}
