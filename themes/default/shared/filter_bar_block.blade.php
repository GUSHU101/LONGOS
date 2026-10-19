<div class="product-tool">
  <p class="product-results-count" aria-live="polite">
    {{ trans_choice('shop/categories.product_count', $products->total(), ['count' => $products->total()]) }}
  </p>

  <div class="product-tool-controls">
    <fieldset class="style-wrap" aria-label="{{ __('shop/categories.view_as') }}">
      <legend class="visually-hidden">{{ __('shop/categories.view_as') }}</legend>
      <label class="{{ !request('style_list') || request('style_list') == 'grid' ? 'active' : ''}} grid"
        data-bs-toggle="tooltip"
        data-bs-placement="top"
        title="{{ __('common.text_grid') }}"
        >
        <svg viewBox="0 0 19 19" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><rect width="5" height="5"></rect><rect x="7" width="5" height="5"></rect><rect x="14" width="5" height="5"></rect><rect y="7" width="5" height="5"></rect><rect x="7" y="7" width="5" height="5"></rect><rect x="14" y="7" width="5" height="5"></rect><rect y="14" width="5" height="5"></rect><rect x="7" y="14" width="5" height="5"></rect><rect x="14" y="14" width="5" height="5"></rect></svg>
        <input class="visually-hidden" aria-label="{{ __('common.text_grid') }}" value="grid" type="radio" name="style_list" {{ !request('style_list') || request('style_list') == 'grid' ? 'checked' : '' }}>
      </label>
      <label class="ms-1 {{ request('style_list') == 'list' ? 'active' : '' }} list"
        data-bs-toggle="tooltip"
        data-bs-placement="top"
        title="{{ __('common.text_list') }}"
        >
        <svg viewBox="0 0 19 19" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><rect width="5" height="5"></rect><rect x="7" height="5" width="12"></rect><rect y="7" width="5" height="5"></rect><rect x="7" y="7" height="5" width="12"></rect><rect y="14" width="5" height="5"></rect><rect x="7" y="14" height="5" width="12"></rect></svg>
        <input class="visually-hidden" aria-label="{{ __('common.text_list') }}" value="list" type="radio" name="style_list" {{ request('style_list') == 'list' ? 'checked' : '' }}>
      </label>
    </fieldset>

    <div class="right-per-page">
      <label class="product-tool-select product-tool-select--per-page">
        <span>{{ __('shop/categories.show') }}</span>
        <select class="form-select perpage-select" aria-label="{{ __('shop/categories.products_per_page') }}">
        @foreach ($per_pages as $val)
        <option value="{{ $val }}" {{ request('per_page') == $val ? 'selected' : '' }}>{{ $val }}</option>
        @endforeach
        </select>
      </label>

      <label class="product-tool-select product-tool-select--sort">
        <span>{{ __('shop/categories.sort_by') }}</span>
        <select class="form-select order-select" aria-label="{{ __('shop/categories.sort_by') }}">
          <option value="">{{ __('common.default') }}</option>
          <option value="products.sales|asc" {{ request('sort') == 'products.sales' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('common.sales') }} ({{ __('common.low') . '-' . __('common.high')}})</option>
          <option value="products.sales|desc" {{ request('sort') == 'products.sales' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('common.sales') }} ({{ __('common.high') . '-' . __('common.low')}})</option>
          <option value="pd.name|asc" {{ request('sort') == 'pd.name' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('common.name') }} (A - Z)</option>
          <option value="pd.name|desc" {{ request('sort') == 'pd.name' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('common.name') }} (Z - A)</option>
          <option value="product_skus.price|asc" {{ request('sort') == 'product_skus.price' && request('order') == 'asc' ? 'selected' : '' }}>{{ __('product.price') }} ({{ __('common.low') . '-' . __('common.high')}})</option>
          <option value="product_skus.price|desc" {{ request('sort') == 'product_skus.price' && request('order') == 'desc' ? 'selected' : '' }}>{{ __('product.price') }} ({{ __('common.high') . '-' . __('common.low')}})</option>
        </select>
      </label>
    </div>
  </div>
</div>
