@extends('admin::layouts.master')

@section('title', trans('LongosProductReviews::reviews.' . ($review->exists ? 'edit_review' : 'add_review')))
@section('page-title-back', admin_route('longos_product_reviews.index'))

@push('header')
  <style>
    .review-form-shell{max-width:980px}.review-product-picker{position:relative}.review-product-results{background:#fff;border:1px solid #d8dce5;border-radius:.5rem;box-shadow:0 1rem 2rem rgba(28,35,45,.12);left:0;max-height:18rem;overflow:auto;position:absolute;right:0;top:calc(100% + .35rem);z-index:30}.review-product-results[hidden]{display:none}.review-product-option{align-items:center;background:#fff;border:0;border-bottom:1px solid #eef0f3;color:#222;display:flex;gap:.75rem;min-height:3.25rem;padding:.55rem .75rem;text-align:left;width:100%}.review-product-option:last-child{border-bottom:0}.review-product-option:is(:hover,.is-active,:focus-visible){background:#f4f6f8;outline:0}.review-product-option img{border-radius:.35rem;height:2.25rem;object-fit:cover;width:2.25rem}.review-rating{display:flex;flex-wrap:wrap;gap:.5rem}.review-rating input{height:1px;opacity:0;position:absolute;width:1px}.review-rating label{background:#fff8e6;border:1px solid #e7d7a7;border-radius:.5rem;color:#7a5700;cursor:pointer;font-weight:600;min-height:2.75rem;padding:.65rem .85rem}.review-rating input:checked+label{background:#fff0bf;border-color:#9b6d00;box-shadow:inset 0 0 0 1px #9b6d00}.review-rating input:focus-visible+label{outline:3px solid #2563eb;outline-offset:2px}.review-image-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(7.5rem,1fr))}.review-image{background:#f7f8fa;border:1px solid #e1e4e8;border-radius:.6rem;overflow:hidden;padding:.5rem}.review-image img{aspect-ratio:1;display:block;object-fit:cover;width:100%}.review-image .form-check{margin-top:.5rem}.review-form-actions{align-items:center;display:flex;gap:.75rem;justify-content:flex-end}.review-form-actions .btn{min-height:44px}@media(max-width:575.98px){.review-form-actions{align-items:stretch;flex-direction:column-reverse}.review-form-actions .btn{width:100%}}
  </style>
@endpush

@section('content')
  <div class="review-form-shell mx-auto">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
      <div><h2 class="h4 mb-1">{{ trans('LongosProductReviews::reviews.' . ($review->exists ? 'edit_review' : 'add_review')) }}</h2><p class="text-muted mb-0">{{ trans('LongosProductReviews::reviews.subtitle') }}</p></div>
    </div>

    @if($errors->any())
      <div class="alert alert-danger" role="alert" tabindex="-1" id="review-form-errors">
        <div class="fw-semibold mb-1">{{ trans('common.error_form') }}</div>
        <ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif

    <form id="product-review-form" method="post" enctype="multipart/form-data" action="{{ $review->exists ? admin_route('longos_product_reviews.update', $review) : admin_route('longos_product_reviews.store') }}">
      @csrf @if($review->exists) @method('PUT') @endif
      <div class="card mb-3"><div class="card-body p-4">
        <h3 class="h5 mb-3">{{ trans('LongosProductReviews::reviews.product') }}</h3>
        <div class="review-product-picker">
          <label class="form-label" for="review-product-search">{{ trans('LongosProductReviews::reviews.product_search') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></label>
          <input id="review-product-search" class="form-control @error('product_id') is-invalid @enderror" type="search" name="product_name" value="{{ old('product_name', $selectedProduct?->description?->name ?? '') }}" placeholder="{{ trans('LongosProductReviews::reviews.product_search_placeholder') }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-required="true" aria-controls="review-product-results" aria-describedby="review-product-help review-product-status @error('product_id') review-product-error @enderror">
          <input id="review-product-id" type="hidden" name="product_id" value="{{ old('product_id', $review->product_id) }}">
          <div id="review-product-results" class="review-product-results" role="listbox" hidden></div>
          <div id="review-product-status" class="visually-hidden" role="status" aria-live="polite"></div>
          <div id="review-product-help" class="form-text">{{ trans('LongosProductReviews::reviews.product_search_help') }}</div>
          @error('product_id')<div id="review-product-error" class="invalid-feedback d-block">{{ $message }}</div>@enderror
          <noscript>
            <label class="form-label mt-3" for="review-product-fallback">{{ trans('LongosProductReviews::reviews.product') }}</label>
            <select id="review-product-fallback" class="form-select" name="product_id" required>
              <option value="">{{ trans('common.please_choose') }}</option>
              @foreach($fallbackProducts as $product)<option value="{{ $product->id }}" @selected((int) old('product_id', $review->product_id) === (int) $product->id)>{{ $product->description?->name ?: '#' . $product->id }}</option>@endforeach
            </select>
          </noscript>
        </div>
      </div></div>

      <div class="card mb-3"><div class="card-body p-4">
        <h3 class="h5 mb-3">{{ trans('LongosProductReviews::reviews.title') }}</h3>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="review-author">{{ trans('LongosProductReviews::reviews.author_name') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></label><input id="review-author" class="form-control @error('author_name') is-invalid @enderror" name="author_name" maxlength="120" value="{{ old('author_name', $review->author_name) }}" required aria-required="true" @error('author_name') aria-describedby="review-author-error" @enderror>@error('author_name')<div id="review-author-error" class="invalid-feedback">{{ $message }}</div>@enderror</div>
          <div class="col-md-6"><label class="form-label" for="review-country">{{ trans('LongosProductReviews::reviews.author_country') }}</label><input id="review-country" class="form-control @error('author_country') is-invalid @enderror" name="author_country" maxlength="80" value="{{ old('author_country', $review->author_country) }}">@error('author_country')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
          <div class="col-12">
            <fieldset><legend class="form-label">{{ trans('LongosProductReviews::reviews.rating') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></legend><div class="review-rating">
              @foreach(range(5, 1) as $rating)<span><input id="review-rating-{{ $rating }}" type="radio" name="rating" value="{{ $rating }}" @checked((int) old('rating', $review->rating ?: 5) === $rating) required><label for="review-rating-{{ $rating }}"><span aria-hidden="true">{{ str_repeat('★', $rating) }}</span><span class="visually-hidden">{{ trans('LongosProductReviews::reviews.out_of_five', ['rating' => $rating]) }}</span></label></span>@endforeach
            </div></fieldset>@error('rating')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
          </div>
          <div class="col-12"><label class="form-label" for="review-title">{{ trans('LongosProductReviews::reviews.review_title') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></label><input id="review-title" class="form-control @error('title') is-invalid @enderror" name="title" maxlength="160" value="{{ old('title', $review->title) }}" required aria-required="true" @error('title') aria-describedby="review-title-error" @enderror>@error('title')<div id="review-title-error" class="invalid-feedback">{{ $message }}</div>@enderror</div>
          <div class="col-12"><label class="form-label" for="review-body">{{ trans('LongosProductReviews::reviews.review_body') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></label><textarea id="review-body" class="form-control @error('body') is-invalid @enderror" name="body" rows="7" minlength="10" maxlength="5000" required aria-required="true" aria-describedby="review-body-counter @error('body') review-body-error @enderror">{{ old('body', $review->body) }}</textarea><div id="review-body-counter" class="form-text text-end"><span id="review-body-count">0</span>/5000</div>@error('body')<div id="review-body-error" class="invalid-feedback">{{ $message }}</div>@enderror</div>
          <div class="col-md-6"><label class="form-label" for="review-date">{{ trans('LongosProductReviews::reviews.review_date') }} <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden"> ({{ trans('LongosProductReviews::reviews.required_field') }})</span></label><input id="review-date" class="form-control @error('reviewed_at') is-invalid @enderror" type="datetime-local" name="reviewed_at" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('reviewed_at', $review->reviewed_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" required aria-required="true" @error('reviewed_at') aria-describedby="review-date-error" @enderror>@error('reviewed_at')<div id="review-date-error" class="invalid-feedback">{{ $message }}</div>@enderror</div>
          <div class="col-md-6"><label class="form-label" for="review-sort">{{ trans('LongosProductReviews::reviews.sort_order') }}</label><input id="review-sort" class="form-control @error('sort_order') is-invalid @enderror" type="number" name="sort_order" min="-10000" max="10000" value="{{ old('sort_order', $review->sort_order ?? 0) }}"><div class="form-text">{{ trans('LongosProductReviews::reviews.sort_order_help') }}</div>@error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        </div>
      </div></div>

      <div class="card mb-3"><div class="card-body p-4">
        <h3 class="h5 mb-3">{{ trans('LongosProductReviews::reviews.images') }}</h3>
        @if(!empty($review->images))<div class="review-image-grid mb-3">@foreach($review->images as $image)<div class="review-image"><a href="{{ image_origin($image) }}" target="_blank" rel="noopener noreferrer"><img src="{{ image_resize($image, 360, 360) }}" alt="{{ trans('LongosProductReviews::reviews.review_photo_alt', ['author' => $review->author_name]) }}"></a><div class="form-check"><input class="form-check-input" type="checkbox" name="remove_images[]" value="{{ $image }}" id="remove-review-image-{{ $loop->index }}"><label class="form-check-label small" for="remove-review-image-{{ $loop->index }}">{{ trans('LongosProductReviews::reviews.remove_image') }}</label></div></div>@endforeach</div>@endif
        <label class="form-label" for="review-images">{{ trans('LongosProductReviews::reviews.images') }}</label>
        <input id="review-images" class="form-control @error('images') is-invalid @enderror @error('images.*') is-invalid @enderror" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="review-images-help">
        <div id="review-images-help" class="form-text">{{ trans('LongosProductReviews::reviews.images_help') }}</div>
        <div id="review-images-status" class="form-text" role="status" aria-live="polite"></div>
        @error('images')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror @error('images.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        <div id="review-new-image-preview" class="review-image-grid mt-3" aria-live="polite"></div>
      </div></div>

      <div class="card mb-3"><div class="card-body p-4">
        <div class="form-check form-switch mb-3"><input type="hidden" name="published" value="0"><input id="review-published" class="form-check-input" type="checkbox" name="published" value="1" @checked((bool) old('published', $review->published ?? true))><label class="form-check-label" for="review-published">{{ trans('LongosProductReviews::reviews.published') }}</label></div>
        <div class="form-check form-switch"><input type="hidden" name="verified_purchase" value="0"><input id="review-verified" class="form-check-input" type="checkbox" name="verified_purchase" value="1" @checked((bool) old('verified_purchase', $review->verified_purchase ?? true))><label class="form-check-label" for="review-verified">{{ trans('LongosProductReviews::reviews.verified') }}</label></div>
      </div></div>

      <div class="review-form-actions"><a class="btn btn-outline-secondary" href="{{ admin_route('longos_product_reviews.index') }}">{{ trans('common.cancel') }}</a><button class="btn btn-primary px-4" type="submit" data-review-submit>{{ trans('common.save') }}</button></div>
    </form>
  </div>
@endsection

@push('footer')
<script>
(() => {
  const form = document.getElementById('product-review-form');
  const input = document.getElementById('review-product-search');
  const productId = document.getElementById('review-product-id');
  const results = document.getElementById('review-product-results');
  const help = document.getElementById('review-product-help');
  const productStatus = document.getElementById('review-product-status');
  const searchUrl = @json(admin_route('products.autocomplete'));
  const requiredMessage = @json(trans('LongosProductReviews::reviews.product_required'));
  const defaultHelp = help.textContent;
  const searchingMessage = @json(trans('LongosProductReviews::reviews.product_searching'));
  const noResultsMessage = @json(trans('LongosProductReviews::reviews.product_no_results'));
  const searchErrorMessage = @json(trans('LongosProductReviews::reviews.product_search_error'));
  let selectedLabel = input.value;
  let timer = null;
  let request = null;
  let active = -1;

  const closeResults = () => { results.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); results.querySelectorAll('[role="option"]').forEach((option) => option.setAttribute('aria-selected', 'false')); active = -1; };
  const choose = (item) => { productId.value = String(item.id); input.value = item.name || `#${item.id}`; selectedLabel = input.value; help.textContent = defaultHelp; help.classList.remove('text-danger'); input.removeAttribute('aria-invalid'); productStatus.textContent = ''; closeResults(); input.focus(); };
  const activate = (index) => {
    const options = [...results.querySelectorAll('[role="option"]')];
    if (!options.length) return;
    active = (index + options.length) % options.length;
    options.forEach((option, i) => { option.classList.toggle('is-active', i === active); option.setAttribute('aria-selected', String(i === active)); });
    input.setAttribute('aria-activedescendant', options[active].id);
    options[active].scrollIntoView({block: 'nearest'});
  };
  const render = (items) => {
    results.replaceChildren(); active = -1;
    items.forEach((item) => {
      const option = document.createElement('button'); option.type = 'button'; option.id = `review-product-option-${item.id}`; option.className = 'review-product-option'; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false'); option.tabIndex = -1;
      if (item.image_format) { const image = document.createElement('img'); image.src = item.image_format; image.alt = ''; option.append(image); }
      const label = document.createElement('span'); label.textContent = item.name || `#${item.id}`; option.append(label); option.addEventListener('click', () => choose(item)); results.append(option);
    });
    results.hidden = items.length === 0; input.setAttribute('aria-expanded', String(items.length > 0)); productStatus.textContent = items.length ? '' : noResultsMessage;
  };
  const search = async () => {
    const query = input.value.trim();
    if (query.length < 2) { productStatus.textContent = ''; closeResults(); return; }
    request?.abort(); request = new AbortController();
    productStatus.textContent = searchingMessage;
    try { const response = await fetch(`${searchUrl}?name=${encodeURIComponent(query)}`, {headers: {'Accept':'application/json'}, signal: request.signal}); if (!response.ok) throw new Error(); const payload = await response.json(); render(Array.isArray(payload.data) ? payload.data : []); }
    catch (error) { if (error.name !== 'AbortError') { closeResults(); productStatus.textContent = searchErrorMessage; } }
  };
  input.addEventListener('input', () => { if (input.value !== selectedLabel) productId.value = ''; help.textContent = defaultHelp; help.classList.remove('text-danger'); input.removeAttribute('aria-invalid'); clearTimeout(timer); timer = setTimeout(search, 250); });
  input.addEventListener('keydown', (event) => { const count = results.querySelectorAll('[role="option"]').length; if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && count) { event.preventDefault(); activate(active + (event.key === 'ArrowDown' ? 1 : -1)); } else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); results.querySelectorAll('[role="option"]')[active].click(); } else if (event.key === 'Escape') { closeResults(); } });
  document.addEventListener('click', (event) => { if (!event.target.closest('.review-product-picker')) closeResults(); });
  form.addEventListener('submit', (event) => { if (!productId.value) { event.preventDefault(); help.textContent = requiredMessage; help.classList.add('text-danger'); input.setAttribute('aria-invalid', 'true'); input.focus(); } });

  const body = document.getElementById('review-body'); const counter = document.getElementById('review-body-count'); const countBody = () => counter.textContent = String(body.value.length); body.addEventListener('input', countBody); countBody();
  const images = document.getElementById('review-images'); const preview = document.getElementById('review-new-image-preview'); const imageStatus = document.getElementById('review-images-status');
  const imageLimitMessage = @json(trans('LongosProductReviews::reviews.error_image_limit', ['count' => 6]));
  const imagesSelectedMessage = @json(trans('LongosProductReviews::reviews.images_selected', ['count' => '__COUNT__']));
  const savingMessage = @json(trans('LongosProductReviews::reviews.saving'));
  images.addEventListener('change', () => { const files = [...images.files]; preview.replaceChildren(); images.setCustomValidity(files.length > 6 ? imageLimitMessage : ''); imageStatus.textContent = files.length > 6 ? imageLimitMessage : (files.length ? imagesSelectedMessage.replace('__COUNT__', String(files.length)) : ''); imageStatus.classList.toggle('text-danger', files.length > 6); files.slice(0, 6).forEach((file) => { const wrap = document.createElement('div'); wrap.className = 'review-image'; const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.alt = file.name; image.addEventListener('load', () => URL.revokeObjectURL(image.src), {once:true}); wrap.append(image); preview.append(wrap); }); });
  form.addEventListener('submit', (event) => { if (event.defaultPrevented) return; const submit = form.querySelector('[data-review-submit]'); submit.disabled = true; submit.setAttribute('aria-busy', 'true'); submit.textContent = savingMessage; });
})();
</script>
@endpush
