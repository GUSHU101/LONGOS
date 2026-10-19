<?php

namespace Plugin\LongosProductReviews\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRequest extends FormRequest
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
            'author_name'         => ['bail', 'required', 'string', 'max:120', 'regex:/\S/u'],
            'author_country'      => ['nullable', 'string', 'max:80'],
            'rating'              => ['bail', 'required', 'integer', 'between:1,5'],
            'title'               => ['bail', 'required', 'string', 'max:160', 'regex:/\S/u'],
            'body'                => ['bail', 'required', 'string', 'min:10', 'max:5000', 'regex:/\S/u'],
            'reviewed_at'         => ['bail', 'required', 'date', 'before_or_equal:now'],
            'verified_purchase'   => ['nullable', 'boolean'],
            'published'           => ['nullable', 'boolean'],
            'sort_order'          => ['nullable', 'integer', 'between:-10000,10000'],
            'images'              => ['nullable', 'array', 'max:6'],
            'images.*'            => ['bail', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'remove_images'       => ['nullable', 'array', 'max:6'],
            'remove_images.*'     => ['string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'author_name'        => trim((string) $this->input('author_name')),
            'author_country'     => trim((string) $this->input('author_country')) ?: null,
            'title'              => trim((string) $this->input('title')),
            'body'               => trim((string) $this->input('body')),
            'verified_purchase'  => $this->boolean('verified_purchase'),
            'published'          => $this->boolean('published'),
            'sort_order'         => (int) $this->input('sort_order', 0),
        ]);
    }
}
