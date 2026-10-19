<?php

namespace Beike\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:120'],
            'mode'              => ['required', Rule::in(['automatic', 'code'])],
            'code'              => ['nullable', 'string', 'max:64'],
            'type'              => ['required', Rule::in(['product_amount', 'buy_x_get_y', 'order_amount', 'free_shipping'])],
            'value_type'        => ['nullable', Rule::in(['percentage', 'fixed'])],
            'value'             => ['nullable', 'numeric', 'min:0'],
            'minimum_amount'    => ['nullable', 'numeric', 'min:0'],
            'buy_quantity'      => ['nullable', 'integer', 'min:1'],
            'get_quantity'      => ['nullable', 'integer', 'min:1'],
            'product_ids'       => ['nullable'],
            'gift_product_ids'  => ['nullable'],
            'status'            => ['nullable', 'boolean'],
            'starts_at'         => ['nullable', 'date'],
            'ends_at'           => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
