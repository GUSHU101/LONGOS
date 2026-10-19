<a
  href="{{ admin_route('longos_product_reviews.create', ['product_id' => $productId]) }}"
  class="btn btn-default btn-sm"
  data-bs-toggle="tooltip"
  title="{{ trans('LongosProductReviews::reviews.add_review') }}"
  aria-label="{{ trans('LongosProductReviews::reviews.add_review') }}"
>
  <i class="bi bi-star" aria-hidden="true"></i>
</a>
