<?php

namespace Plugin\LongosProductReviews\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugin\LongosProductReviews\Services\ReviewGenerationLimits;

class GenerateReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return current_user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id'          => ['bail', 'required', 'integer', 'exists:products,id'],
            'count'               => ['bail', 'required', 'integer', 'between:1,' . ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH],
            'date_from'           => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'date_to'             => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
            'rating_min'          => ['bail', 'required', 'integer', 'between:1,5'],
            'rating_max'          => ['bail', 'required', 'integer', 'between:1,5'],
            'rating_distribution' => ['bail', 'required', 'in:natural,balanced,random'],
            'review_length'       => ['bail', 'required', 'in:mixed,short,medium,long'],
            'countries'           => ['bail', 'required', 'string', 'max:3000', 'regex:/\S/u'],
            'examples'            => ['bail', 'required', 'string', 'min:10', 'max:12000', 'regex:/\S/u'],
            'output_language'     => ['bail', 'required', 'in:auto,zh-CN,en-US,ja-JP,de-DE,fr-FR,es-ES'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((int) $this->input('rating_min') > (int) $this->input('rating_max')) {
                $validator->errors()->add('rating_max', trans('LongosProductReviews::reviews.ai_rating_range_invalid'));
            }

            $countries = self::countries((string) $this->input('countries'));
            if ($countries === []) {
                $validator->errors()->add('countries', trans('LongosProductReviews::reviews.ai_countries_required'));
            } elseif (count($countries) > 50) {
                $validator->errors()->add('countries', trans('LongosProductReviews::reviews.ai_countries_limit'));
            } elseif (collect($countries)->contains(fn (string $country): bool => mb_strlen($country) > 80)) {
                $validator->errors()->add('countries', trans('LongosProductReviews::reviews.ai_country_too_long'));
            }
        });
    }

    /** @return list<string> */
    public static function countries(string $value): array
    {
        return collect(preg_split('/[\r\n,，;；]+/u', $value) ?: [])
            ->map(fn (string $country): string => trim($country))
            ->filter()
            ->unique(fn (string $country): string => mb_strtolower($country))
            ->values()
            ->all();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_id'          => (int) $this->input('product_id'),
            'count'               => (int) $this->input('count', 10),
            'rating_min'          => (int) $this->input('rating_min', 4),
            'rating_max'          => (int) $this->input('rating_max', 5),
            'rating_distribution' => trim((string) $this->input('rating_distribution', 'natural')),
            'review_length'       => trim((string) $this->input('review_length', 'mixed')),
            'countries'           => trim((string) $this->input('countries')),
            'examples'            => trim((string) $this->input('examples')),
            'output_language'     => trim((string) $this->input('output_language', 'auto')),
        ]);
    }
}
