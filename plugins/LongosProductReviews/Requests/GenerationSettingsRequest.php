<?php

namespace Plugin\LongosProductReviews\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugin\LongosProductReviews\Services\SiliconFlowSettings;

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
            'api_key'         => ['nullable', 'string', 'max:512'],
            'model'           => ['bail', 'required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+){1,2}$/'],
            'parallel_models' => ['nullable', 'string', 'max:1000'],
            'timeout'         => ['bail', 'required', 'integer', 'between:10,120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $raw = trim((string) $this->input('parallel_models'));
            if ($raw === '') {
                return;
            }
            $values    = array_values(array_filter(preg_split('/[\s,;，；]+/u', $raw) ?: []));
            $submitted = [(string) $this->input('model'), ...$values];
            if (count($values) > SiliconFlowSettings::MAX_MODELS - 1
                || count($submitted)                                                             !== count(array_unique($submitted))
                || count(SiliconFlowSettings::parseModels((string) $this->input('model'), $raw)) !== count($submitted)) {
                $validator->errors()->add('parallel_models', trans('LongosProductReviews::reviews.ai_parallel_models_invalid', [
                    'count' => SiliconFlowSettings::MAX_MODELS - 1,
                ]));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'api_key'         => trim((string) $this->input('api_key')),
            'model'           => trim((string) $this->input('model')),
            'parallel_models' => trim((string) $this->input('parallel_models')),
            'timeout'         => (int) $this->input('timeout', 60),
        ]);
    }
}
