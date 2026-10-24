@php
  $reviewsEndpoint = \Illuminate\Support\Facades\Route::has('shop.longos_product_reviews.index')
    ? shop_route('longos_product_reviews.index', ['product' => $product['id']])
    : url('products/' . $product['id'] . '/customer-reviews');
@endphp
<section
  class="longos-reviews"
  id="customer-reviews"
  aria-labelledby="customer-reviews-heading"
  data-review-section
  data-url="{{ $reviewsEndpoint }}"
  data-rating="0"
  data-with-images="0"
  data-sort="featured">
  <div class="longos-reviews__heading">
    <p class="longos-reviews__eyebrow">{{ trans('LongosProductReviews::reviews.verified_customer_feedback') }}</p>
    <h2 id="customer-reviews-heading">{{ trans('LongosProductReviews::reviews.title') }}</h2>
  </div>

  <div class="longos-reviews__layout">
    <aside class="longos-reviews__summary" aria-label="{{ trans('LongosProductReviews::reviews.rating_distribution') }}">
      <div class="longos-reviews__average">
        <strong>{{ number_format($reviews['average'], 1) }}</strong>
        <div>
          <span class="longos-review-stars longos-review-stars--average" style="--review-rating: {{ min(100, max(0, ($reviews['average'] / 5) * 100)) }}%" aria-hidden="true">★★★★★</span>
          <span class="visually-hidden">{{ trans('LongosProductReviews::reviews.out_of_five', ['rating' => number_format($reviews['average'], 1)]) }}</span>
          <p>{{ trans('LongosProductReviews::reviews.global_rating', ['count' => $reviews['total']]) }}</p>
        </div>
      </div>

      <div class="longos-reviews__distribution">
        @foreach($reviews['distribution'] as $item)
          <button class="longos-reviews__distribution-row" type="button" data-review-rating="{{ $item['rating'] }}" aria-pressed="false">
            <span>{{ trans('LongosProductReviews::reviews.star_filter', ['rating' => $item['rating']]) }}</span>
            <span class="longos-reviews__track" aria-hidden="true"><span style="--review-progress: {{ $item['percentage'] }}%"></span></span>
            <span>{{ $item['count'] }}</span>
          </button>
        @endforeach
      </div>
    </aside>

    <div class="longos-reviews__content">
      <div class="longos-reviews__toolbar" aria-label="{{ trans('LongosProductReviews::reviews.sort_reviews') }}">
        <div class="longos-reviews__filters">
          <button class="longos-reviews__filter is-active" type="button" data-review-rating="0" aria-pressed="true">{{ trans('LongosProductReviews::reviews.all_ratings') }}</button>
          <button class="longos-reviews__filter" type="button" data-review-photos aria-pressed="false"><i class="bi bi-image" aria-hidden="true"></i> {{ trans('LongosProductReviews::reviews.with_photos') }}</button>
        </div>
        <label class="longos-reviews__sort">
          <span>{{ trans('LongosProductReviews::reviews.sort_reviews') }}</span>
          <select data-review-sort>
            <option value="featured">{{ trans('LongosProductReviews::reviews.sort_featured') }}</option>
            <option value="newest">{{ trans('LongosProductReviews::reviews.sort_newest') }}</option>
            <option value="highest">{{ trans('LongosProductReviews::reviews.sort_highest') }}</option>
            <option value="lowest">{{ trans('LongosProductReviews::reviews.sort_lowest') }}</option>
          </select>
        </label>
      </div>

      <div class="longos-reviews__results-meta">
        <p data-review-result-count>{{ trans_choice('LongosProductReviews::reviews.filtered_result_count', $reviews['total'], ['count' => $reviews['total']]) }}</p>
        <button type="button" data-review-clear hidden>{{ trans('LongosProductReviews::reviews.clear_filters') }}</button>
      </div>

      <div class="longos-reviews__list" id="customer-reviews-list" data-review-list>
        @include('LongosProductReviews::shop.cards', ['reviews' => $reviews['reviews']])
      </div>

      <div class="longos-reviews__empty" data-review-empty hidden>
        <i class="bi bi-chat-square-text" aria-hidden="true"></i>
        <strong>{{ trans('LongosProductReviews::reviews.no_matching_reviews') }}</strong>
        <button type="button" data-review-clear>{{ trans('LongosProductReviews::reviews.clear_filters') }}</button>
      </div>

      <button
        class="longos-reviews__more"
        type="button"
        data-review-load-more
        data-offset="{{ $reviews['next_offset'] }}"
        aria-controls="customer-reviews-list"
        @if(!$reviews['has_more']) hidden @endif>
        <span>{{ trans('LongosProductReviews::reviews.load_more') }}</span>
        <i class="bi bi-chevron-down" aria-hidden="true"></i>
      </button>
      <p class="longos-reviews__status" data-review-status role="status" aria-live="polite"></p>
    </div>
  </div>

  <dialog class="longos-review-lightbox" data-review-lightbox aria-label="{{ trans('LongosProductReviews::reviews.images') }}">
    <button class="longos-review-lightbox__close" type="button" data-review-lightbox-close aria-label="{{ trans('LongosProductReviews::reviews.close_photo') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    <button class="longos-review-lightbox__nav is-previous" type="button" data-review-lightbox-previous aria-label="{{ trans('LongosProductReviews::reviews.previous_photo') }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
    <img src="" alt="" data-review-lightbox-image>
    <button class="longos-review-lightbox__nav is-next" type="button" data-review-lightbox-next aria-label="{{ trans('LongosProductReviews::reviews.next_photo') }}"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
  </dialog>
</section>

@once
  @push('add-scripts')
    <script>
      (() => {
        const requests = new WeakMap()
        const labels = {
          loading: @json(trans('LongosProductReviews::reviews.loading')),
          loadError: @json(trans('LongosProductReviews::reviews.load_error')),
          allLoaded: @json(trans('LongosProductReviews::reviews.all_loaded')),
        }

        const setFilterState = (section) => {
          const rating = section.dataset.rating || '0'
          section.querySelectorAll('[data-review-rating]').forEach((button) => {
            const active = button.dataset.reviewRating === rating
            button.classList.toggle('is-active', active)
            button.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          const photos = section.dataset.withImages === '1'
          const photoButton = section.querySelector('[data-review-photos]')
          photoButton?.classList.toggle('is-active', photos)
          photoButton?.setAttribute('aria-pressed', photos ? 'true' : 'false')
          const clear = rating !== '0' || photos || section.dataset.sort !== 'featured'
          section.querySelectorAll('[data-review-clear]').forEach((button) => { button.hidden = !clear })
        }

        const loadReviews = async (section, append = false) => {
          const list = section.querySelector('[data-review-list]')
          const more = section.querySelector('[data-review-load-more]')
          const status = section.querySelector('[data-review-status]')
          const empty = section.querySelector('[data-review-empty]')
          const resultCount = section.querySelector('[data-review-result-count]')
          if (!list || !more || !status || !empty || !resultCount) return

          requests.get(section)?.abort()
          const controller = new AbortController()
          requests.set(section, controller)
          section.classList.add('is-loading')
          section.setAttribute('aria-busy', 'true')
          more.disabled = true
          more.setAttribute('aria-busy', 'true')
          status.textContent = labels.loading

          try {
            const url = new URL(section.dataset.url, window.location.origin)
            url.searchParams.set('offset', append ? (more.dataset.offset || '0') : '0')
            url.searchParams.set('rating', section.dataset.rating || '0')
            url.searchParams.set('with_images', section.dataset.withImages || '0')
            url.searchParams.set('sort', section.dataset.sort || 'featured')
            const response = await fetch(url, {
              credentials: 'same-origin',
              headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
              signal: controller.signal,
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const payload = await response.json()
            if (append) list.insertAdjacentHTML('beforeend', payload.html || '')
            else list.innerHTML = payload.html || ''
            more.dataset.offset = String(payload.next_offset || 0)
            more.hidden = !payload.has_more
            empty.hidden = Number(payload.total || 0) !== 0
            resultCount.textContent = payload.result_label || ''
            status.textContent = !payload.has_more && append ? labels.allLoaded : ''
            setFilterState(section)
          } catch (error) {
            if (error.name !== 'AbortError') status.textContent = labels.loadError
          } finally {
            if (requests.get(section) === controller) {
              requests.delete(section)
              section.classList.remove('is-loading')
              section.removeAttribute('aria-busy')
              more.disabled = false
              more.removeAttribute('aria-busy')
            }
          }
        }

        const showPhoto = (section, anchor) => {
          const dialog = section.querySelector('[data-review-lightbox]')
          const image = dialog?.querySelector('[data-review-lightbox-image]')
          if (!dialog || !image || typeof dialog.showModal !== 'function') return false
          const photos = [...section.querySelectorAll('[data-review-photo]')]
          dialog.dataset.photoIndex = String(Math.max(0, photos.indexOf(anchor)))
          image.src = anchor.dataset.full || anchor.href
          image.alt = anchor.querySelector('img')?.alt || ''
          if (!dialog.open) dialog.showModal()
          return true
        }

        const stepPhoto = (section, direction) => {
          const dialog = section.querySelector('[data-review-lightbox]')
          const photos = [...section.querySelectorAll('[data-review-photo]')]
          if (!dialog || !photos.length) return
          const current = Number(dialog.dataset.photoIndex || 0)
          showPhoto(section, photos[(current + direction + photos.length) % photos.length])
        }

        document.addEventListener('click', (event) => {
          const section = event.target.closest('[data-review-section]')
          if (!section) return

          const rating = event.target.closest('[data-review-rating]')
          if (rating) {
            section.dataset.rating = rating.dataset.reviewRating || '0'
            setFilterState(section)
            loadReviews(section)
            return
          }
          if (event.target.closest('[data-review-photos]')) {
            section.dataset.withImages = section.dataset.withImages === '1' ? '0' : '1'
            setFilterState(section)
            loadReviews(section)
            return
          }
          if (event.target.closest('[data-review-clear]')) {
            section.dataset.rating = '0'
            section.dataset.withImages = '0'
            section.dataset.sort = 'featured'
            const sort = section.querySelector('[data-review-sort]')
            if (sort) sort.value = 'featured'
            setFilterState(section)
            loadReviews(section)
            return
          }
          if (event.target.closest('[data-review-load-more]')) {
            loadReviews(section, true)
            return
          }
          const photo = event.target.closest('[data-review-photo]')
          if (photo && showPhoto(section, photo)) {
            event.preventDefault()
            return
          }
          if (event.target.closest('[data-review-lightbox-close]')) section.querySelector('[data-review-lightbox]')?.close()
          if (event.target.closest('[data-review-lightbox-previous]')) stepPhoto(section, -1)
          if (event.target.closest('[data-review-lightbox-next]')) stepPhoto(section, 1)
        })

        document.addEventListener('change', (event) => {
          if (!event.target.matches('[data-review-sort]')) return
          const section = event.target.closest('[data-review-section]')
          if (!section) return
          section.dataset.sort = event.target.value
          setFilterState(section)
          loadReviews(section)
        })
      })()
    </script>
  @endpush
@endonce
