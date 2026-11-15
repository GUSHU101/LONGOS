<?php

namespace Plugin\LongosProductReviews\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return current_user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'max:512'],
            'model'   => ['bail', 'required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+){1,2}$/'],
            'timeout' => ['bail', 'required', 'integer', 'between:10,120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'api_key' => trim((string) $this->input('api_key')),
            'model'   => trim((string) $this->input('model')),
            'timeout' => (int) $this->input('timeout', 60),
        ]);
    }
}
