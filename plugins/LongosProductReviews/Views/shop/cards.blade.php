@foreach($reviews as $review)
  <article class="longos-review-card">
    <header class="longos-review-card__header">
      <span class="longos-review-card__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($review->author_name, 0, 1)) }}</span>
      <div>
        <p class="longos-review-card__author">{{ $review->author_name }}</p>
        <p class="longos-review-card__meta">
          @if($review->author_country)<span>{{ $review->author_country }}</span><span aria-hidden="true">·</span>@endif
          <time datetime="{{ $review->reviewed_at?->toDateString() }}">{{ $review->reviewed_at?->translatedFormat('M j, Y') }}</time>
        </p>
      </div>
    </header>

    <div class="longos-review-card__rating">
      <span class="longos-review-stars" aria-hidden="true">
        @foreach(range(1, 5) as $star)<span class="{{ $star <= $review->rating ? 'is-filled' : '' }}">★</span>@endforeach
      </span>
      <span class="visually-hidden">{{ trans('LongosProductReviews::reviews.out_of_five', ['rating' => $review->rating]) }}</span>
      <h3>{{ $review->title }}</h3>
    </div>

    @if($review->verified_purchase)
      <p class="longos-review-card__verified"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.verified_purchase') }}</p>
    @endif

    <p class="longos-review-card__body">{{ $review->body }}</p>

    @if(count((array) $review->images))
      <div class="longos-review-card__photos" aria-label="{{ trans('LongosProductReviews::reviews.images') }}">
        @foreach($review->images as $image)
          <a
            href="{{ image_origin($image) }}"
            target="_blank"
            rel="noopener"
            data-review-photo
            data-full="{{ image_origin($image) }}"
            aria-label="{{ trans('LongosProductReviews::reviews.open_review_photo', ['number' => $loop->iteration, 'author' => $review->author_name]) }}">
            <img src="{{ image_resize($image, 240, 240) }}" width="112" height="112" loading="lazy" decoding="async" alt="{{ trans('LongosProductReviews::reviews.review_photo_alt', ['author' => $review->author_name]) }}">
          </a>
        @endforeach
      </div>
    @endif
  </article>
@endforeach
