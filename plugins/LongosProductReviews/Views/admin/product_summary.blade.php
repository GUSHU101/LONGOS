<div class="border-top mt-3 pt-3 pb-3">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
    <span class="small text-muted">{{ trans('LongosProductReviews::reviews.menu') }}</span>
    <span class="badge bg-light text-dark">{{ $count }}</span>
  </div>
  <div class="d-grid gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="{{ admin_route('longos_product_reviews.index', ['product_id' => $productId]) }}">{{ trans('LongosProductReviews::reviews.manage_reviews') }}</a>
    <a class="btn btn-sm btn-outline-primary" href="{{ admin_route('longos_product_reviews.create', ['product_id' => $productId]) }}">{{ trans('LongosProductReviews::reviews.add_review') }}</a>
  </div>
</div>
