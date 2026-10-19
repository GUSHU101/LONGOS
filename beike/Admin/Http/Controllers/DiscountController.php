<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Requests\DiscountRequest;
use Beike\Admin\Services\DiscountService;
use Beike\Repositories\DiscountRepo;

class DiscountController extends Controller
{
    public function index()
    {
        return view('admin::pages.discounts.index', ['discounts' => DiscountRepo::list()]);
    }

    public function store(DiscountRequest $request)
    {
        $discount = DiscountService::create($request->validated());

        return json_success(trans('common.created_success'), $discount);
    }

    public function update(DiscountRequest $request, int $id)
    {
        $discount = DiscountService::update($id, $request->validated());

        return json_success(trans('common.updated_success'), $discount);
    }

    public function destroy(int $id)
    {
        DiscountRepo::find($id)->delete();

        return json_success(trans('common.deleted_success'));
    }
}
