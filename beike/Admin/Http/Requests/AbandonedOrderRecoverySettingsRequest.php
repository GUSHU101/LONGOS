<?php

namespace Beike\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AbandonedOrderRecoverySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings'                  => ['required', 'array'],
            'settings.delay_hours'      => ['required', 'integer', 'min:1', 'max:720'],
            'settings.cooldown_hours'   => ['required', 'integer', 'min:1', 'max:720'],
            'settings.max_attempts'     => ['required', 'integer', 'min:1', 'max:10'],
            'settings.link_days'        => ['required', 'integer', 'min:1', 'max:90'],
            'settings.subject'          => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'settings.heading'          => ['required', 'string', 'max:180'],
            'settings.message'          => ['required', 'string', 'max:2000'],
            'settings.button_label'     => ['required', 'string', 'max:64'],
            'settings.discount_message' => ['required', 'string', 'max:300'],
        ];
    }
}
