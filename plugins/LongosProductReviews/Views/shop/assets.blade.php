@php
  // This is a bundled, trusted stylesheet. Keeping it in the plugin package
  // preserves modular ownership while inlining it here avoids a second
  // tenant-routed request and cannot be broken by a stale CDN 404 response.
  $longosReviewCssPath = base_path('plugins/LongosProductReviews/Static/css/reviews.css');
  $longosReviewCss = is_file($longosReviewCssPath) ? file_get_contents($longosReviewCssPath) : false;
@endphp
@if (is_string($longosReviewCss) && $longosReviewCss !== '')
  <style id="longos-product-review-styles">{!! $longosReviewCss !!}</style>
@endif
