@php
  $testimonialItems = is_array($content['items'] ?? null) ? array_values($content['items']) : [];
  $testimonialCount = count($testimonialItems);
  $testimonialLabel = !empty($content['title']) ? $content['title'] : __('shop/design.testimonials_carousel');
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-testimonials section-testimonials--{{ $content['style_variant'] ?? 'cards' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))<h2 class="section-heading" id="module-heading-{{ $module_id }}" data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</h2>@endif
    @if (!empty($content['sub_title']))<p class="section-subheading" data-lgt-field="module.{{ $module_id }}.sub_title">{{ $content['sub_title'] }}</p>@endif
    <div id="testimonial-track-{{ $module_id }}" class="builder-section-grid builder-section-grid--desktop-{{ $content['columns'] ?? 3 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 1 }} testimonials-carousel__track" data-testimonials-carousel role="region" aria-label="{{ $testimonialLabel }}" @if($testimonialCount > 1) tabindex="0" @endif>
      @forelse($testimonialItems as $item)
        <div class="testimonials-carousel__slide" data-testimonial-slide role="group" aria-label="{{ __('shop/design.testimonial_position', ['current' => $loop->iteration, 'total' => $testimonialCount]) }}">
          <figure class="testimonial-card mb-0">
            <div class="testimonial-rating" aria-label="{{ $item['rating'] }} / 5">@for($star = 1; $star <= 5; $star++)<i class="bi {{ $star <= $item['rating'] ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>@endfor</div>
            <blockquote data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.quote">“{{ $item['quote'] }}”</blockquote>
            @if (!empty($item['name']))<figcaption><span data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.name">{{ $item['name'] }}</span>@if (!empty($item['detail']))<small data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.detail">{{ $item['detail'] }}</small>@endif</figcaption>@endif
          </figure>
        </div>
      @empty
        @if ($design)<div class="builder-section-grid__full"><div class="custom-code-empty">{{ __('shop/design.empty.testimonials') }}</div></div>@endif
      @endforelse
    </div>
    @if($testimonialCount > 1)
      <div class="testimonials-carousel__controls" data-testimonials-controls role="group" aria-label="{{ __('shop/design.testimonials_controls') }}">
        <button type="button" data-testimonials-previous aria-controls="testimonial-track-{{ $module_id }}" aria-label="{{ __('shop/design.previous_testimonial') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
        <output data-testimonials-status data-position-label="{{ __('shop/design.testimonial_position', ['current' => '__CURRENT__', 'total' => '__TOTAL__']) }}" aria-label="{{ __('shop/design.testimonial_position', ['current' => 1, 'total' => $testimonialCount]) }}" aria-live="polite" aria-atomic="true">1 / {{ $testimonialCount }}</output>
        <button type="button" data-testimonials-next aria-controls="testimonial-track-{{ $module_id }}" aria-label="{{ __('shop/design.next_testimonial') }}"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
      </div>
    @endif
  </div>
</section>
