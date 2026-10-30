<?php

/**
 * PageRequest.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-19 21:58:20
 * @modified   2022-08-19 21:58:20
 */

namespace Beike\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            '_product_form_complete' => ['required', 'accepted'],
            'descriptions'           => ['required', 'array', 'min:1'],
            'descriptions.*.name'    => 'required|string|min:3|max:255',
            'brand_id'               => ['nullable', 'integer', 'min:0'],
            'skus'                   => ['required', 'array', 'min:1'],
            'skus.*.id'              => ['nullable', 'integer', 'min:1'],
            'skus.*.sku'             => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'skus.*.price'           => ['required', 'numeric', 'min:0'],
            'skus.*.origin_price'    => ['nullable', 'numeric', 'min:0'],
            'skus.*.cost_price'      => ['nullable', 'numeric', 'min:0'],
            'skus.*.quantity'        => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'skus.*.weight'          => ['nullable', 'numeric', 'min:0'],
            'skus.*.images'          => ['nullable', 'array'],
        ];
    }

    public function attributes()
    {
        return [
            'descriptions.*.name' => trans('product.name'),
            'brand_id'            => trans('product.brand'),
            'skus.*.sku'          => trans('product.sku'),
            'skus.*.price'        => trans('product.price'),
            'skus.*.cost_price'   => trans('product.cost_price'),
            'skus.*.quantity'     => trans('product.quantity'),
        ];
    }

    public function messages(): array
    {
        return [
            '_product_form_complete.required' => trans('admin/product.form_request_incomplete'),
            '_product_form_complete.accepted' => trans('admin/product.form_request_incomplete'),
        ];
    }
}
