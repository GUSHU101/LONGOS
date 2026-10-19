<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Services\UniPushService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppPushController extends Controller
{
    public function index(Request $request): View
    {
        $data = [];

        return view('admin::pages.app_push.index', $data);
    }

    public function push(Request $request)
    {
        $message = $request->validate([
            'push_clientid' => ['required', 'string', 'max:255'],
            'title'         => ['required', 'string', 'max:120'],
            'content'       => ['required', 'string', 'max:2000'],
            'link'          => ['required', 'array'],
            'link.type'     => ['required', 'in:product,category,page,order,custom'],
            'link.value'    => ['nullable', 'string', 'max:500'],
            'customer_id'   => ['nullable', 'integer', 'min:1'],
        ]);

        return UniPushService::getInstance()->pushOrderStatus($message);
    }
}
