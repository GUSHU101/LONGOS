<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Repositories\StorePolicyRepo;

class PolicyController extends Controller
{
    public function show(string $policy)
    {
        $storePolicy = StorePolicyRepo::findBySlug($policy);
        abort_if($storePolicy === null, 404);

        $data = hook_filter('policy.show.data', ['policy' => $storePolicy]);

        return view('policies.show', $data);
    }
}
