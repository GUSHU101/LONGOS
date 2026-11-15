<div class="card mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h3 class="h6 mb-0">{{ trans('LongosProductReviews::reviews.ai_heading') }}</h3>
      <small class="text-muted">{{ trans('LongosProductReviews::reviews.ai_model') }}: <code>XingChenAGI/Xing4.0-29B</code></small>
    </div>
    <span class="badge bg-light border text-dark">{{ $aiConfigured ? trans('LongosProductReviews::reviews.ai_connected') : trans('LongosProductReviews::reviews.ai_not_connected') }}</span>
  </div>
  <div class="card-body">
    <p class="text-muted small">{{ trans('LongosProductReviews::reviews.ai_notice') }}</p>
    @if($errors->any())
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif
    <form method="post" action="{{ admin_route('longos_product_reviews.ai.key') }}" class="row gy-2 gx-3 align-items-end mb-4">
      @csrf
      <div class="col-lg-7">
        <label for="reviews-ai-key" class="form-label">{{ trans('LongosProductReviews::reviews.ai_key_label') }}</label>
        <input id="reviews-ai-key" name="api_key" type="password" class="form-control" minlength="12" maxlength="512" autocomplete="new-password" placeholder="{{ $aiConfigured ? trans('LongosProductReviews::reviews.ai_key_saved_placeholder') : 'sk-...' }}">
        <div class="form-text">{{ trans('LongosProductReviews::reviews.ai_key_help') }}</div>
      </div>
      <div class="col-auto"><button type="submit" class="btn btn-outline-primary">{{ trans('LongosProductReviews::reviews.ai_save_key') }}</button></div>
      @if($aiConfigured)
        <div class="col-auto"><button type="submit" name="clear_key" value="1" class="btn btn-outline-danger">{{ trans('LongosProductReviews::reviews.ai_clear_key') }}</button></div>
      @endif
    </form>

    <h4 class="h6 mb-3">{{ trans('LongosProductReviews::reviews.ai_generate_title') }}</h4>
    <form method="post" action="{{ admin_route('longos_product_reviews.ai.generate') }}" class="row g-3 align-items-end">
      @csrf
      <div class="col-12">
        <label for="reviews-ai-product-ids" class="form-label">{{ trans('LongosProductReviews::reviews.ai_product_ids_label') }}</label>
        <textarea id="reviews-ai-product-ids" name="product_ids" class="form-control" rows="2" maxlength="4000" placeholder="100020, 100021, 100022">{{ old('product_ids') }}</textarea>
        <div class="form-text">{{ trans('LongosProductReviews::reviews.ai_product_ids_help') }}</div>
      </div>
      <div class="col-6 col-lg-3">
        <label for="reviews-ai-category" class="form-label">{{ trans('LongosProductReviews::reviews.ai_category_id') }}</label>
        <input id="reviews-ai-category" name="category_id" type="number" class="form-control" min="1" value="{{ old('category_id') }}" placeholder="100020">
      </div>
      <div class="col-6 col-lg-3">
        <label for="reviews-ai-limit" class="form-label">{{ trans('LongosProductReviews::reviews.ai_product_limit') }}</label>
        <input id="reviews-ai-limit" name="limit" type="number" class="form-control" value="{{ old('limit', 10) }}" min="1" max="50" required>
      </div>
      <div class="col-6 col-lg-3">
        <label for="reviews-ai-per-product" class="form-label">{{ trans('LongosProductReviews::reviews.ai_per_product') }}</label>
        <input id="reviews-ai-per-product" name="per_product" type="number" class="form-control" value="{{ old('per_product', 3) }}" min="1" max="5" required>
      </div>
      <div class="col-12 col-lg-3">
        <label for="reviews-ai-language" class="form-label">{{ trans('LongosProductReviews::reviews.ai_language') }}</label>
        <select id="reviews-ai-language" name="language" class="form-select" required>
          @foreach($aiLanguages as $code => $label)
            <option value="{{ $code }}" @selected(old('language', 'en') === $code)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-lg-3">
        <div class="form-check">
          <input id="reviews-ai-skip" name="skip_reviewed" type="checkbox" class="form-check-input" value="1" @checked(old('skip_reviewed', false))>
          <label for="reviews-ai-skip" class="form-check-label">{{ trans('LongosProductReviews::reviews.ai_skip_reviewed') }}</label>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input id="reviews-ai-skip-existing" name="skip_ai_existing" type="checkbox" class="form-check-input" value="1" @checked(old('skip_ai_existing', true))>
          <label for="reviews-ai-skip-existing" class="form-check-label">{{ trans('LongosProductReviews::reviews.ai_skip_existing') }}</label>
        </div>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary" @disabled(! $aiConfigured)>{{ trans('LongosProductReviews::reviews.ai_start') }}</button>
        <span class="small text-muted ms-2">{{ trans('LongosProductReviews::reviews.ai_draft_only') }}</span>
      </div>
    </form>
    @if(is_array($aiBatch))
      <div class="border-top mt-4 pt-3" aria-live="polite">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
          <strong>{{ trans('LongosProductReviews::reviews.ai_progress') }}</strong>
          <span>{{ (int) ($aiBatch['completed'] ?? 0) }} / {{ (int) ($aiBatch['total'] ?? 0) }}</span>
          <span class="text-muted">{{ trans('LongosProductReviews::reviews.ai_created') }}: {{ (int) ($aiBatch['created'] ?? 0) }}</span>
          <span class="text-muted">{{ trans('LongosProductReviews::reviews.ai_failed') }}: {{ (int) ($aiBatch['failed'] ?? 0) }}</span>
          <a class="btn btn-sm btn-outline-secondary" href="{{ admin_route('longos_product_reviews.index') }}">{{ trans('LongosProductReviews::reviews.ai_refresh') }}</a>
        </div>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ max(1, (int) ($aiBatch['total'] ?? 0)) }}" aria-valuenow="{{ min((int) ($aiBatch['completed'] ?? 0), (int) ($aiBatch['total'] ?? 0)) }}">
          <div class="progress-bar" style="width:{{ (int) ($aiBatch['total'] ?? 0) > 0 ? round(100 * (int) ($aiBatch['completed'] ?? 0) / (int) $aiBatch['total']) : 0 }}%"></div>
        </div>
        @if(!empty($aiBatch['recent_errors']))
          <p class="small text-danger mt-2 mb-0">{{ trans('LongosProductReviews::reviews.ai_recent_error') }}: @foreach($aiBatch['recent_errors'] as $error) #{{ (int) ($error['product_id'] ?? 0) }} {{ $error['code'] ?? 'FAILED' }}@if(!$loop->last), @endif @endforeach</p>
        @endif
      </div>
    @endif
  </div>
</div>
