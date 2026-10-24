<section class="module-item section-testimonials section-testimonials--{{ $content['style_variant'] ?? 'cards' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))<h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>@endif
    @if (!empty($content['sub_title']))<p class="section-subheading">{{ $content['sub_title'] }}</p>@endif
    <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns'] ?? 3 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 1 }}">
      @forelse($content['items'] ?? [] as $item)
        <div>
          <figure class="testimonial-card mb-0">
            <div class="testimonial-rating" aria-label="{{ $item['rating'] }} / 5">@for($star = 1; $star <= 5; $star++)<i class="bi {{ $star <= $item['rating'] ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>@endfor</div>
            <blockquote>“{{ $item['quote'] }}”</blockquote>
            @if (!empty($item['name']))<figcaption>{{ $item['name'] }}@if (!empty($item['detail']))<small>{{ $item['detail'] }}</small>@endif</figcaption>@endif
          </figure>
        </div>
      @empty
        @if ($design)<div class="builder-section-grid__full"><div class="custom-code-empty">{{ __('shop/design.empty.testimonials') }}</div></div>@endif
      @endforelse
    </div>
  </div>
</section>
