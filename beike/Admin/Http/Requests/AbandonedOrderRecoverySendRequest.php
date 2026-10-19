<?php

namespace Beike\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AbandonedOrderRecoverySendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids'   => ['required', 'array', 'min:1', 'max:20'],
            'order_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'discount_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
