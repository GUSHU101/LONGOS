@extends('admin::layouts.master')

@section('title', trans('LongosProductReviews::reviews.menu'))

@push('header')
  <style>
    .review-row-action{align-items:center;display:inline-flex;height:44px;justify-content:center;width:44px}
    .review-stat-card{border:1px solid #e3e7ed;border-radius:14px;box-shadow:0 4px 18px rgba(20,31,50,.04)}
    .review-empty-icon{align-items:center;background:#f1f4f8;border-radius:50%;color:#657084;display:inline-flex;font-size:1.45rem;height:54px;justify-content:center;width:54px}
  </style>
@endpush

@section('content')
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
      <h2 class="h4 mb-1">{{ trans('LongosProductReviews::reviews.title') }}</h2>
      <p class="text-muted mb-0">{{ trans('LongosProductReviews::reviews.subtitle') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ admin_route('longos_product_reviews.create') }}">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.add_review') }}
    </a>
  </div>

  <div class="row g-3 mb-4" aria-label="{{ trans('LongosProductReviews::reviews.menu') }}">
    <div class="col-6 col-xl-3"><div class="card review-stat-card h-100"><div class="card-body"><div class="text-muted small">{{ trans_choice('LongosProductReviews::reviews.review_count', $stats['total'], ['count' => $stats['total']]) }}</div><div class="fs-3 fw-semibold">{{ $stats['total'] }}</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card review-stat-card h-100"><div class="card-body"><div class="text-muted small">{{ trans('LongosProductReviews::reviews.status_published') }}</div><div class="fs-3 fw-semibold">{{ $stats['published'] }}</div><small class="text-muted">{{ trans('LongosProductReviews::reviews.draft_count') }}: {{ $stats['drafts'] }}</small></div></div></div>
    <div class="col-6 col-xl-3"><div class="card review-stat-card h-100"><div class="card-body"><div class="text-muted small">{{ trans('LongosProductReviews::reviews.verified_count') }}</div><div class="fs-3 fw-semibold">{{ $stats['verified'] }}</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card review-stat-card h-100"><div class="card-body"><div class="text-muted small">{{ trans('LongosProductReviews::reviews.average_rating') }}</div><div class="fs-3 fw-semibold">{{ number_format($stats['average'], 1) }} <span class="text-warning fs-5" aria-hidden="true">★</span></div></div></div></div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form method="get" action="{{ admin_route('longos_product_reviews.index') }}" class="row g-3 align-items-end">
        <div class="col-lg-4">
          <label class="form-label" for="reviews-search">{{ trans('LongosProductReviews::reviews.search') }}</label>
          <input id="reviews-search" class="form-control" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ trans('LongosProductReviews::reviews.search_placeholder') }}">
        </div>
        <div class="col-sm-6 col-lg-2">
          <label class="form-label" for="reviews-rating">{{ trans('LongosProductReviews::reviews.rating') }}</label>
          <select id="reviews-rating" class="form-select" name="rating">
            <option value="0">{{ trans('LongosProductReviews::reviews.filter_all_ratings') }}</option>
            @foreach(range(5, 1) as $rating)<option value="{{ $rating }}" @selected($filters['rating'] === $rating)>{{ trans('LongosProductReviews::reviews.star_filter', ['rating' => $rating]) }}</option>@endforeach
          </select>
        </div>
        <div class="col-sm-6 col-lg-2">
          <label class="form-label" for="reviews-status">{{ trans('common.status') }}</label>
          <select id="reviews-status" class="form-select" name="published">
            <option value="">{{ trans('LongosProductReviews::reviews.filter_all_statuses') }}</option>
            <option value="1" @selected((string) $filters['published'] === '1')>{{ trans('LongosProductReviews::reviews.status_published') }}</option>
            <option value="0" @selected((string) $filters['published'] === '0')>{{ trans('LongosProductReviews::reviews.status_draft') }}</option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-2">
          <label class="form-label" for="reviews-verified">{{ trans('LongosProductReviews::reviews.verified') }}</label>
          <select id="reviews-verified" class="form-select" name="verified">
            <option value="">{{ trans('LongosProductReviews::reviews.filter_all_verification') }}</option>
            <option value="1" @selected((string) $filters['verified'] === '1')>{{ trans('LongosProductReviews::reviews.filter_verified') }}</option>
            <option value="0" @selected((string) $filters['verified'] === '0')>{{ trans('LongosProductReviews::reviews.filter_unverified') }}</option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-2">
          <div class="form-check mb-2">
            <input id="reviews-photos" class="form-check-input" type="checkbox" name="photos" value="1" @checked((string) $filters['photos'] === '1')>
            <label class="form-check-label" for="reviews-photos">{{ trans('LongosProductReviews::reviews.filter_photo_reviews') }}</label>
          </div>
          <div class="d-flex gap-2">
          <button class="btn btn-dark flex-fill" type="submit">{{ trans('LongosProductReviews::reviews.search') }}</button>
          <a class="btn btn-outline-secondary" href="{{ admin_route('longos_product_reviews.index') }}" aria-label="{{ trans('common.reset') }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>{{ trans('LongosProductReviews::reviews.product') }}</th><th>{{ trans('LongosProductReviews::reviews.author_name') }}</th><th>{{ trans('LongosProductReviews::reviews.rating') }}</th><th>{{ trans('LongosProductReviews::reviews.review_title') }}</th><th>{{ trans('common.status') }}</th><th>{{ trans('LongosProductReviews::reviews.review_date') }}</th><th class="text-end">{{ trans('common.action') }}</th></tr></thead>
        <tbody>
          @forelse($reviews as $review)
            <tr>
              <td style="min-width: 220px">
                <div class="d-flex align-items-center gap-2">
                  @if($review->product?->image)<img src="{{ image_resize($review->product->image, 72, 72) }}" width="48" height="48" class="rounded border object-fit-cover" alt="">@endif
                  <div><div class="fw-semibold">{{ $review->product?->description?->name ?: '#' . $review->product_id }}</div><small class="text-muted">#{{ $review->product_id }}</small></div>
                </div>
              </td>
              <td>{{ $review->author_name }}@if($review->author_country)<div class="small text-muted">{{ $review->author_country }}</div>@endif</td>
              <td><span class="text-warning" aria-hidden="true">{{ str_repeat('★', $review->rating) }}</span><span class="visually-hidden">{{ trans('LongosProductReviews::reviews.out_of_five', ['rating' => $review->rating]) }}</span></td>
              <td style="max-width: 300px"><div class="fw-semibold text-truncate">{{ $review->title }}</div><small class="text-muted">{{ \Illuminate\Support\Str::limit($review->body, 80) }}</small></td>
              <td>
                <span class="badge {{ $review->published ? 'bg-success' : 'bg-secondary' }}">{{ trans('LongosProductReviews::reviews.' . ($review->published ? 'status_published' : 'status_draft')) }}</span>
                @if($review->verified_purchase)<span class="badge bg-light text-dark border d-block mt-1" style="width:max-content"><i class="bi bi-patch-check-fill text-success" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.verified_purchase') }}</span>@endif
              </td>
              <td class="text-nowrap">{{ $review->reviewed_at?->format('Y-m-d') }}</td>
              <td class="text-end text-nowrap">
                <form class="d-inline" method="post" action="{{ admin_route('longos_product_reviews.publication', $review) }}">
                  @csrf @method('PATCH')
                  <input type="hidden" name="published" value="{{ $review->published ? 0 : 1 }}">
                  <button class="btn btn-outline-{{ $review->published ? 'warning' : 'success' }} review-row-action" type="submit" title="{{ trans('LongosProductReviews::reviews.' . ($review->published ? 'unpublish_review' : 'publish_review')) }}" aria-label="{{ trans('LongosProductReviews::reviews.' . ($review->published ? 'unpublish_review' : 'publish_review')) }}"><i class="bi bi-{{ $review->published ? 'eye-slash' : 'eye' }}" aria-hidden="true"></i></button>
                </form>
                <a class="btn btn-outline-secondary review-row-action" href="{{ admin_route('longos_product_reviews.edit', $review) }}" aria-label="{{ trans('common.edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <form class="d-inline review-delete-form" method="post" action="{{ admin_route('longos_product_reviews.destroy', $review) }}" data-confirm="{{ trans('LongosProductReviews::reviews.confirm_delete') }}">
                  @csrf @method('DELETE')
                  <button class="btn btn-outline-danger review-row-action" type="submit" aria-label="{{ trans('common.delete') }}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center py-5"><span class="review-empty-icon"><i class="bi bi-chat-square-text" aria-hidden="true"></i></span><h3 class="h5 mt-3 mb-1">{{ trans('LongosProductReviews::reviews.empty_title') }}</h3><p class="text-muted mb-3">{{ trans('LongosProductReviews::reviews.empty_description') }}</p><div class="d-flex flex-wrap justify-content-center gap-2"><a class="btn btn-primary" href="{{ admin_route('longos_product_reviews.create') }}">{{ trans('LongosProductReviews::reviews.add_review') }}</a><a class="btn btn-outline-secondary" href="{{ admin_route('longos_product_reviews.index') }}">{{ trans('LongosProductReviews::reviews.clear_filters') }}</a></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($reviews->hasPages())<div class="card-footer">{{ $reviews->links('admin::vendor/pagination/bootstrap-4') }}</div>@endif
  </div>
@endsection

@push('footer')
  <script>
    document.querySelectorAll('.review-delete-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm || '')) event.preventDefault()
      })
    })
  </script>
@endpush
