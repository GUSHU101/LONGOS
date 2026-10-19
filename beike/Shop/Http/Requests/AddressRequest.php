<?php

namespace Beike\Shop\Http\Requests;

use Beike\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
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
        $countryId       = (int) $this->input('country_id');
        $countryHasZones = $countryId > 0 && Zone::query()
            ->where('country_id', $countryId)
            ->where('active', true)
            ->exists();

        $rules = [
            'name'         => 'required|string|min:2|max:64',
            'calling_code' => 'nullable|string|max:12',
            'country_id'   => 'required|exists:countries,id,active,1',
            'zone_id'      => [
                Rule::requiredIf($countryHasZones),
                'nullable',
                Rule::exists('zones', 'id')->where(fn ($query) => $query
                    ->where('country_id', $countryId)
                    ->where('active', true)),
            ],
            'city'       => 'required|string|max:120',
            'zipcode'    => 'nullable|string|max:32',
            'address_1'  => 'required|string|max:255',
            'address_2'  => 'nullable|string|max:255',
        ];

        if (system_setting('base.address_phoner_equired', '0') == '1') {
            $rules['phone'] = 'required|string|max:32';
        } else {
            $rules['phone'] = 'nullable|string|max:32';
        }

        return $rules;
    }

    public function attributes()
    {
        return [
            'name'       => trans('address.name'),
            'country_id' => trans('address.country_id'),
            'zone_id'    => trans('address.zone_id'),
            'address_1'  => trans('address.address_1'),
            'phone'      => trans('shop/account/addresses.enter_phone'),
        ];
    }
}
