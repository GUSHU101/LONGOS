@php
  $average = min(5, max(0, (float) ($summary['average'] ?? 0)));
  $total = max(0, (int) ($summary['total'] ?? 0));
  $reviewUrl = explode('#', $productUrl ?: '#')[0] . '#customer-reviews';
@endphp
@if ($total > 0)
  <a
    class="longos-card-rating"
    href="{{ $reviewUrl }}"
    aria-label="{{ trans('LongosProductReviews::reviews.rating_link_label', ['rating' => number_format($average, 1), 'count' => $total]) }}"
  >
    <span
      class="longos-card-rating__stars"
      style="--longos-card-rating: {{ number_format(($average / 5) * 100, 2, '.', '') }}%"
      aria-hidden="true"
    >★★★★★</span>
    <strong>{{ number_format($average, 1) }}</strong>
    <span aria-hidden="true">({{ $total }})</span>
  </a>
@endif
