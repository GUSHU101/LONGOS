@extends('admin::layouts.master')

@section('title', trans('LongosProductReviews::reviews.ai_generate'))
@section('page-title-back', admin_route('longos_product_reviews.index'))

@push('header')
  <style>
    .review-generator{max-width:1100px}.review-product-picker{position:relative}.review-product-results{background:#fff;border:1px solid #d8dce5;border-radius:.5rem;box-shadow:0 1rem 2rem rgba(28,35,45,.12);left:0;max-height:18rem;overflow:auto;position:absolute;right:0;top:calc(100% + .35rem);z-index:30}.review-product-results[hidden]{display:none}.review-product-option{align-items:center;background:#fff;border:0;border-bottom:1px solid #eef0f3;color:#222;display:flex;gap:.75rem;min-height:3.25rem;padding:.55rem .75rem;text-align:left;width:100%}.review-product-option:last-child{border-bottom:0}.review-product-option:is(:hover,.is-active,:focus-visible){background:#f4f6f8;outline:0}.review-product-option img{border-radius:.35rem;height:2.25rem;object-fit:cover;width:2.25rem}.review-generator .btn{min-height:44px}.review-preview-body{max-width:36rem;white-space:pre-line}.review-config-status{align-items:center;display:flex;gap:.5rem}.review-config-status i{font-size:1.15rem}.review-batch-progress{height:.75rem}.review-batch-progress::-webkit-progress-bar{background:#e9edf3;border-radius:999px}.review-batch-progress::-webkit-progress-value{background:#2563eb;border-radius:999px}.review-batch-progress::-moz-progress-bar{background:#2563eb;border-radius:999px}@media(max-width:575.98px){.review-generator-actions{align-items:stretch!important;flex-direction:column}.review-generator-actions .btn{width:100%}}
  </style>
@endpush

@section('content')
  @php
    $formValue = static fn (string $key, mixed $default = ''): mixed => old($key, request()->input($key, data_get($batchInput ?? [], $key, $default)));
    $batchActive = $batch && in_array($batch->status, [\Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_PENDING, \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_RUNNING], true);
  @endphp
  <div class="review-generator mx-auto">
    <div class="mb-4">
      <h2 class="h4 mb-1">{{ trans('LongosProductReviews::reviews.ai_generate') }}</h2>
      <p class="text-muted mb-0">{{ trans('LongosProductReviews::reviews.ai_generate_subtitle') }}</p>
    </div>

    @if($errors->any())
      <div class="alert alert-danger" role="alert" tabindex="-1">
        <div class="fw-semibold mb-1">{{ trans('common.error_form') }}</div>
        <ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif

    <div class="alert alert-warning" role="note">
      <div class="fw-semibold"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_safety_title') }}</div>
      <div class="small mt-1">{{ trans('LongosProductReviews::reviews.ai_safety_help') }}</div>
    </div>

    @include('LongosProductReviews::admin.partials.generation_tasks', [
      'generationBatches' => $recentBatches,
      'currentBatchId' => $batch?->id,
    ])

    @if($batchActive)
      <div class="card border-primary mb-4" data-generation-batch data-status-url="{{ admin_route('longos_product_reviews.generate.status', ['batch' => $batch->id]) }}">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <strong><i class="bi bi-hourglass-split" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_batch_running') }}</strong>
            <span class="text-muted small" data-batch-count role="status" aria-live="polite">{{ trans('LongosProductReviews::reviews.ai_batch_progress', ['processed' => $batch->processed_count, 'total' => $batch->requested_count]) }}</span>
          </div>
          <progress class="review-batch-progress w-100" data-batch-progress max="{{ max(1, $batch->requested_count) }}" value="{{ $batch->processed_count }}">{{ $batch->processed_count }}/{{ $batch->requested_count }}</progress>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
            <p class="text-muted small mb-0">{{ trans('LongosProductReviews::reviews.ai_batch_running_help') }}</p>
            <form method="post" action="{{ admin_route('longos_product_reviews.generate.cancel', ['batch' => $batch->id]) }}">
              @csrf
              <button class="btn btn-sm btn-outline-danger" type="submit" title="{{ trans('LongosProductReviews::reviews.ai_batch_cancel_help') }}"><i class="bi bi-x-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_batch_cancel') }}</button>
            </form>
          </div>
        </div>
      </div>
    @elseif($batch && $batch->status === \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_FAILED)
      <div class="alert alert-danger" role="alert">
        <div class="fw-semibold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_batch_failed_title') }}</div>
        <div class="mt-1">{{ $batch->error ?: trans('LongosProductReviews::reviews.ai_batch_failed') }}</div>
        <div class="small mt-2">{{ trans('LongosProductReviews::reviews.ai_batch_retry_help') }}</div>
      </div>
    @elseif($batch && $batch->status === \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMPLETED)
      <div class="alert alert-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_task_completed_notice') }} <a class="alert-link" href="#review-generation-preview">{{ trans('LongosProductReviews::reviews.ai_task_view_results') }}</a></div>
    @elseif($batch && $batch->status === \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_COMMITTED)
      <div class="alert alert-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_batch_already_committed') }}</div>
    @elseif($batch && $batch->status === \Plugin\LongosProductReviews\Models\ReviewGenerationBatch::STATUS_CANCELLED)
      <div class="alert alert-secondary" role="status">
        <div class="fw-semibold"><i class="bi bi-x-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_batch_cancelled_title') }}</div>
        <div class="small mt-1">{{ trans('LongosProductReviews::reviews.ai_batch_cancelled') }}</div>
      </div>
    @endif

    <div class="card mb-4">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <strong>{{ trans('LongosProductReviews::reviews.ai_settings') }}</strong>
        <span class="review-config-status {{ $settings['configured'] ? 'text-success' : 'text-danger' }}">
          <i class="bi bi-{{ $settings['configured'] ? 'check-circle-fill' : 'exclamation-circle-fill' }}" aria-hidden="true"></i>
          {{ trans('LongosProductReviews::reviews.' . ($settings['configured'] ? 'ai_configured' : 'ai_not_configured')) }}
        </span>
      </div>
      <div class="card-body">
        <form method="post" action="{{ admin_route('longos_product_reviews.generate.settings') }}" class="row g-3">
          @csrf
          <div class="col-lg-5">
            <label class="form-label" for="siliconflow-api-key">{{ trans('LongosProductReviews::reviews.ai_api_key') }}</label>
            <input id="siliconflow-api-key" class="form-control @error('api_key') is-invalid @enderror" type="password" name="api_key" maxlength="512" autocomplete="new-password" placeholder="{{ $settings['configured'] ? trans('LongosProductReviews::reviews.ai_api_key_saved_placeholder') : 'sk-...' }}">
            <div class="form-text">{{ trans('LongosProductReviews::reviews.ai_api_key_help') }}</div>
          </div>
          <div class="col-lg-5">
            <label class="form-label" for="siliconflow-model">{{ trans('LongosProductReviews::reviews.ai_model') }}</label>
            <input id="siliconflow-model" class="form-control @error('model') is-invalid @enderror" name="model" maxlength="160" value="{{ old('model', $settings['model']) }}" required>
            <div class="form-text">{{ trans('LongosProductReviews::reviews.ai_model_help') }}</div>
            @foreach($settings['rate_limit_profiles'] as $profile)
              <div class="form-text {{ $profile['source'] === 'fallback' ? 'text-warning' : 'text-success' }}"><i class="bi bi-speedometer2" aria-hidden="true"></i> <code>{{ $profile['model'] }}</code> — {{ trans('LongosProductReviews::reviews.' . ($profile['source'] === 'fallback' ? 'ai_rate_limits_fallback' : 'ai_rate_limits_detected'), ['rpm' => number_format($profile['rpm']), 'tpm' => number_format($profile['tpm'])]) }}</div>
            @endforeach
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="siliconflow-timeout">{{ trans('LongosProductReviews::reviews.ai_timeout') }}</label>
            <input id="siliconflow-timeout" class="form-control @error('timeout') is-invalid @enderror" type="number" name="timeout" min="10" max="120" value="{{ old('timeout', $settings['timeout']) }}" required>
          </div>
          <div class="col-12">
            <label class="form-label" for="siliconflow-parallel-models">{{ trans('LongosProductReviews::reviews.ai_parallel_models') }}</label>
            <textarea id="siliconflow-parallel-models" class="form-control @error('parallel_models') is-invalid @enderror" name="parallel_models" rows="3" maxlength="1000" spellcheck="false" placeholder="tencent/Hunyuan-MT-7B">{{ old('parallel_models', $settings['parallel_models']) }}</textarea>
            <div class="form-text">{{ trans('LongosProductReviews::reviews.ai_parallel_models_help') }}</div>
          </div>
          <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-lock" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_save_settings') }}</button>
            <span class="text-muted small align-self-center">{{ trans('LongosProductReviews::reviews.ai_save_before_test') }}</span>
          </div>
        </form>
        <form method="post" action="{{ admin_route('longos_product_reviews.generate.test') }}" class="mt-2">
          @csrf
          <button class="btn btn-outline-secondary" type="submit" @disabled(!$settings['configured'])><i class="bi bi-plug" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_test_connection') }}</button>
        </form>
      </div>
    </div>

    <form id="review-generation-form" method="post" action="{{ admin_route('longos_product_reviews.generate.preview') }}">
      @csrf
      <div class="card mb-4">
        <div class="card-header"><strong>{{ trans('LongosProductReviews::reviews.ai_generation_rules') }}</strong></div>
        <div class="card-body p-4">
          <div class="review-product-picker mb-3">
            <label class="form-label" for="review-product-search">{{ trans('LongosProductReviews::reviews.product_search') }} <span class="text-danger" aria-hidden="true">*</span></label>
            <input id="review-product-search" class="form-control @error('product_id') is-invalid @enderror" type="search" name="product_name" value="{{ old('product_name', $selectedProduct?->description?->name ?? '') }}" placeholder="{{ trans('LongosProductReviews::reviews.product_search_placeholder') }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-required="true" aria-controls="review-product-results" aria-describedby="review-product-help review-product-status">
            <input id="review-product-id" type="hidden" name="product_id" value="{{ $formValue('product_id', $selectedProduct?->id ?? '') }}">
            <div id="review-product-results" class="review-product-results" role="listbox" hidden></div>
            <div id="review-product-status" class="visually-hidden" role="status" aria-live="polite"></div>
            <div id="review-product-help" class="form-text">{{ trans('LongosProductReviews::reviews.product_search_help') }}</div>
            <noscript><select class="form-select mt-2" name="product_id" required><option value="">{{ trans('common.please_choose') }}</option>@foreach($fallbackProducts as $product)<option value="{{ $product->id }}" @selected((int) $formValue('product_id') === (int) $product->id)>{{ $product->description?->name ?: '#' . $product->id }}</option>@endforeach</select></noscript>
          </div>

          <div class="row g-3">
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-count">{{ trans('LongosProductReviews::reviews.ai_count') }}</label><input id="review-count" class="form-control @error('count') is-invalid @enderror" type="number" name="count" min="1" max="{{ \Plugin\LongosProductReviews\Services\ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH }}" value="{{ $formValue('count', 10) }}" required><div class="form-text">{{ trans('LongosProductReviews::reviews.ai_count_help', ['count' => \Plugin\LongosProductReviews\Services\ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH]) }}</div></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-language">{{ trans('LongosProductReviews::reviews.ai_output_language') }}</label><select id="review-language" class="form-select" name="output_language">@foreach(['auto','zh-CN','en-US','ja-JP','de-DE','fr-FR','es-ES'] as $language)<option value="{{ $language }}" @selected($formValue('output_language', 'auto') === $language)>{{ trans('LongosProductReviews::reviews.ai_language_' . str_replace('-', '_', strtolower($language))) }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-date-from">{{ trans('LongosProductReviews::reviews.ai_date_from') }}</label><input id="review-date-from" class="form-control @error('date_from') is-invalid @enderror" type="date" name="date_from" max="{{ now()->toDateString() }}" value="{{ $formValue('date_from', now()->subDays(90)->toDateString()) }}" required></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-date-to">{{ trans('LongosProductReviews::reviews.ai_date_to') }}</label><input id="review-date-to" class="form-control @error('date_to') is-invalid @enderror" type="date" name="date_to" max="{{ now()->toDateString() }}" value="{{ $formValue('date_to', now()->toDateString()) }}" required></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-rating-min">{{ trans('LongosProductReviews::reviews.ai_rating_min') }}</label><select id="review-rating-min" class="form-select" name="rating_min">@foreach(range(1,5) as $rating)<option value="{{ $rating }}" @selected((int) $formValue('rating_min', 4) === $rating)>{{ $rating }} ★</option>@endforeach</select></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-rating-max">{{ trans('LongosProductReviews::reviews.ai_rating_max') }}</label><select id="review-rating-max" class="form-select" name="rating_max">@foreach(range(1,5) as $rating)<option value="{{ $rating }}" @selected((int) $formValue('rating_max', 5) === $rating)>{{ $rating }} ★</option>@endforeach</select></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-rating-distribution">{{ trans('LongosProductReviews::reviews.ai_rating_distribution') }}</label><select id="review-rating-distribution" class="form-select" name="rating_distribution">@foreach(['natural','balanced','random'] as $distribution)<option value="{{ $distribution }}" @selected($formValue('rating_distribution', 'natural') === $distribution)>{{ trans('LongosProductReviews::reviews.ai_distribution_' . $distribution) }}</option>@endforeach</select><div class="form-text">{{ trans('LongosProductReviews::reviews.ai_rating_distribution_help') }}</div></div>
            <div class="col-sm-6 col-lg-3"><label class="form-label" for="review-length">{{ trans('LongosProductReviews::reviews.ai_review_length') }}</label><select id="review-length" class="form-select" name="review_length">@foreach(['mixed','short','medium','long'] as $length)<option value="{{ $length }}" @selected($formValue('review_length', 'mixed') === $length)>{{ trans('LongosProductReviews::reviews.ai_length_' . $length) }}</option>@endforeach</select></div>
            <div class="col-lg-6"><label class="form-label" for="review-countries">{{ trans('LongosProductReviews::reviews.ai_countries') }}</label><textarea id="review-countries" class="form-control @error('countries') is-invalid @enderror" name="countries" rows="3" maxlength="3000" required>{{ $formValue('countries', trans('LongosProductReviews::reviews.ai_countries_default')) }}</textarea><div class="form-text">{{ trans('LongosProductReviews::reviews.ai_countries_help') }}</div></div>
            <div class="col-12"><label class="form-label" for="review-examples">{{ trans('LongosProductReviews::reviews.ai_examples') }}</label><textarea id="review-examples" class="form-control @error('examples') is-invalid @enderror" name="examples" rows="7" minlength="10" maxlength="12000" required placeholder="{{ trans('LongosProductReviews::reviews.ai_examples_placeholder') }}">{{ $formValue('examples') }}</textarea><div class="form-text">{{ trans('LongosProductReviews::reviews.ai_examples_help') }}</div></div>
          </div>
        </div>
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
          <span class="text-muted small"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_data_notice') }}</span>
          <button class="btn btn-primary px-4" type="submit" data-generate-submit @disabled(!$settings['configured'] || $batchActive)><i class="bi bi-stars" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.ai_generate_preview') }}</button>
        </div>
      </div>
    </form>

    @if($preview)
      @php
        $previewItems = collect($preview['items']);
        $previewAverage = number_format((float) $previewItems->avg('rating'), 1);
        $previewCountries = $previewItems->pluck('country')->filter()->unique()->count();
      @endphp
      <div class="card mb-4" id="review-generation-preview">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><strong>{{ trans('LongosProductReviews::reviews.ai_preview_title') }}</strong><span class="text-muted small">{{ $preview['product_name'] }}</span></div>
        <div class="px-3 py-2 bg-light border-bottom text-muted small">{{ trans('LongosProductReviews::reviews.ai_preview_summary', ['count' => $previewItems->count(), 'average' => $previewAverage, 'countries' => $previewCountries]) }}</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th><input id="generation-select-all" class="form-check-input" type="checkbox" checked aria-label="{{ trans('LongosProductReviews::reviews.ai_select_all_generated') }}"></th><th>#</th><th>{{ trans('LongosProductReviews::reviews.author_name') }}</th><th>{{ trans('LongosProductReviews::reviews.author_country') }}</th><th>{{ trans('LongosProductReviews::reviews.rating') }}</th><th>{{ trans('LongosProductReviews::reviews.review_date') }}</th><th>{{ trans('LongosProductReviews::reviews.review_body') }}</th></tr></thead>
              <tbody>@foreach($preview['items'] as $item)<tr><td><input class="form-check-input generation-row-select" type="checkbox" name="selected_ids[]" value="{{ $item['spec_id'] }}" form="generation-commit-form" checked aria-label="{{ trans('LongosProductReviews::reviews.ai_select_generated', ['title' => $item['title']]) }}"></td><td>{{ $loop->iteration }}</td><td>{{ $item['author_name'] }}</td><td>{{ $item['country'] }}</td><td class="text-warning text-nowrap" aria-label="{{ trans('LongosProductReviews::reviews.out_of_five', ['rating' => $item['rating']]) }}">{{ str_repeat('★', $item['rating']) }}</td><td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($item['reviewed_at'])->format('Y-m-d') }}</td><td class="review-preview-body"><strong class="d-block text-dark">{{ $item['title'] }}</strong>{{ $item['body'] }}</td></tr>@endforeach</tbody>
            </table>
          </div>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-3 review-generator-actions">
          <p class="text-muted small mb-0">{{ trans('LongosProductReviews::reviews.ai_preview_commit_help') }}</p>
          <form id="generation-commit-form" method="post" action="{{ admin_route('longos_product_reviews.generate.commit') }}">
            @csrf
            <input type="hidden" name="batch_id" value="{{ $batch?->id }}">
            <button class="btn btn-success px-4" type="submit" data-commit-selected><i class="bi bi-check2-circle" aria-hidden="true"></i> <span>{{ trans('LongosProductReviews::reviews.ai_save_drafts', ['count' => count($preview['items'])]) }}</span></button>
          </form>
        </div>
      </div>
    @endif
  </div>
@endsection

@push('footer')
<script>
(() => {
  const form = document.getElementById('review-generation-form')
  const input = document.getElementById('review-product-search')
  const productId = document.getElementById('review-product-id')
  const results = document.getElementById('review-product-results')
  const help = document.getElementById('review-product-help')
  const status = document.getElementById('review-product-status')
  const searchUrl = @json(admin_route('products.autocomplete'));
  const messages = {required:@json(trans('LongosProductReviews::reviews.product_required')),searching:@json(trans('LongosProductReviews::reviews.product_searching')),empty:@json(trans('LongosProductReviews::reviews.product_no_results')),error:@json(trans('LongosProductReviews::reviews.product_search_error')),generating:@json(trans('LongosProductReviews::reviews.ai_generating'))}
  const defaultHelp = help.textContent
  let selectedLabel = input.value, timer = null, request = null, active = -1
  const close = () => { results.hidden = true; input.setAttribute('aria-expanded','false'); input.removeAttribute('aria-activedescendant'); active = -1 }
  const choose = (item) => { productId.value = String(item.id); input.value = item.name || `#${item.id}`; selectedLabel = input.value; help.textContent = defaultHelp; help.classList.remove('text-danger'); input.removeAttribute('aria-invalid'); close(); input.focus() }
  const render = (items) => { results.replaceChildren(); active = -1; items.forEach((item) => { const option=document.createElement('button'); option.type='button'; option.id=`generator-product-${item.id}`; option.className='review-product-option'; option.setAttribute('role','option'); option.tabIndex=-1; if(item.image_format){const image=document.createElement('img');image.src=item.image_format;image.alt='';option.append(image)} const label=document.createElement('span');label.textContent=item.name||`#${item.id}`;option.append(label);option.addEventListener('click',()=>choose(item));results.append(option) }); results.hidden=!items.length; input.setAttribute('aria-expanded',String(Boolean(items.length))); status.textContent=items.length?'':messages.empty }
  const search = async () => { const query=input.value.trim(); if(query.length<2){close();return} request?.abort();request=new AbortController();status.textContent=messages.searching;try{const response=await fetch(`${searchUrl}?name=${encodeURIComponent(query)}`,{headers:{Accept:'application/json'},credentials:'same-origin',signal:request.signal});if(!response.ok)throw new Error();const payload=await response.json();render(Array.isArray(payload.data)?payload.data:[])}catch(error){if(error.name!=='AbortError'){close();status.textContent=messages.error}} }
  input.addEventListener('input',()=>{if(input.value!==selectedLabel)productId.value='';clearTimeout(timer);timer=setTimeout(search,250)})
  input.addEventListener('keydown',(event)=>{const options=[...results.querySelectorAll('[role="option"]')];if((event.key==='ArrowDown'||event.key==='ArrowUp')&&options.length){event.preventDefault();active=(active+(event.key==='ArrowDown'?1:-1)+options.length)%options.length;options.forEach((option,index)=>option.classList.toggle('is-active',index===active));input.setAttribute('aria-activedescendant',options[active].id);options[active].scrollIntoView({block:'nearest'})}else if(event.key==='Enter'&&active>=0){event.preventDefault();options[active].click()}else if(event.key==='Escape')close()})
  document.addEventListener('click',(event)=>{if(!event.target.closest('.review-product-picker'))close()})
  form.addEventListener('submit',(event)=>{if(!productId.value){event.preventDefault();help.textContent=messages.required;help.classList.add('text-danger');input.setAttribute('aria-invalid','true');input.focus();return}const button=form.querySelector('[data-generate-submit]');button.disabled=true;button.setAttribute('aria-busy','true');button.textContent=messages.generating})
  document.getElementById('review-generation-preview')?.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'})

  const generatedSelections = [...document.querySelectorAll('.generation-row-select')]
  const generatedSelectAll = document.getElementById('generation-select-all')
  const commitSelected = document.querySelector('[data-commit-selected]')
  const commitTemplate = @json(trans('LongosProductReviews::reviews.ai_save_drafts', ['count' => '__COUNT__']));
  const syncGeneratedSelection = () => {
    const selected = generatedSelections.filter((checkbox) => checkbox.checked).length
    if (commitSelected) {
      commitSelected.disabled = selected === 0
      commitSelected.querySelector('span').textContent = commitTemplate.replace('__COUNT__', String(selected))
    }
    if (generatedSelectAll) {
      generatedSelectAll.checked = generatedSelections.length > 0 && selected === generatedSelections.length
      generatedSelectAll.indeterminate = selected > 0 && selected < generatedSelections.length
    }
  }
  generatedSelectAll?.addEventListener('change', () => { generatedSelections.forEach((checkbox) => { checkbox.checked = generatedSelectAll.checked }); syncGeneratedSelection() })
  generatedSelections.forEach((checkbox) => checkbox.addEventListener('change', syncGeneratedSelection))
  syncGeneratedSelection()

  const batch = document.querySelector('[data-generation-batch]')
  if (batch) {
    const progress = batch.querySelector('[data-batch-progress]')
    const count = batch.querySelector('[data-batch-count]')
    const progressTemplate = @json(trans('LongosProductReviews::reviews.ai_batch_progress', ['processed' => '__PROCESSED__', 'total' => '__TOTAL__']));
    let polling = false
    const poll = async () => {
      if (polling || document.hidden) return
      polling = true
      try {
        const response = await fetch(batch.dataset.statusUrl, {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'})
        if (!response.ok) throw new Error()
        const payload = await response.json()
        progress.max = Math.max(1, Number(payload.total) || 1)
        progress.value = Math.max(0, Number(payload.processed) || 0)
        count.textContent = progressTemplate.replace('__PROCESSED__', String(payload.processed || 0)).replace('__TOTAL__', String(payload.total || 0))
        if (payload.finished) window.location.reload()
      } catch (error) {
        count.textContent = @json(trans('LongosProductReviews::reviews.ai_batch_status_error'));
      } finally {
        polling = false
      }
    }
    const timer = window.setInterval(poll, 2000)
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll() })
    window.addEventListener('pagehide', () => window.clearInterval(timer), {once:true})
    poll()
  }
})()
</script>
@endpush
