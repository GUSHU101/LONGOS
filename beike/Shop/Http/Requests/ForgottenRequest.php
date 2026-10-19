<?php

namespace Beike\Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgottenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email'    => 'required|email:rfc|max:255|exists:customers,email',
            'code'     => 'required|digits:6',
            'password' => 'required|string|min:8|max:255|confirmed',
        ];
    }

    public function attributes()
    {
        return [
            'email'    => trans('shop/forgotten.email'),
            'code'     => trans('shop/forgotten.verification_code'),
            'password' => trans('shop/forgotten.password'),
        ];
    }
}
