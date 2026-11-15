@php
  $longosReviewCardCssPath = base_path('plugins/LongosProductReviews/Static/css/cards.css');
  $longosReviewCardCss = is_file($longosReviewCardCssPath) ? file_get_contents($longosReviewCardCssPath) : false;
@endphp
@if (is_string($longosReviewCardCss) && $longosReviewCardCss !== '')
  <style id="longos-product-review-card-styles">{!! $longosReviewCardCss !!}</style>
@endif
