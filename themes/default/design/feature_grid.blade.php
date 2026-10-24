<section class="module-item section-feature-grid section-feature-grid--{{ $content['card_style'] ?? 'editorial' }} section-feature-grid--ratio-{{ $content['image_ratio'] ?? 'landscape' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))
      <h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>
    @endif
    @if (!empty($content['sub_title']))
      <p class="section-subheading">{{ $content['sub_title'] }}</p>
    @endif
    <div class="feature-grid feature-grid--desktop-{{ $content['columns'] ?? 3 }} feature-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} feature-grid--mobile-{{ $content['columns_mobile'] ?? 1 }}">
      @forelse($content['items'] ?? [] as $item)
        <article class="feature-card">
          @if (!empty($item['image']))
            <div class="feature-card__media"><img src="{{ $item['image'] }}" alt="{{ $item['image_alt'] ?? '' }}" loading="lazy" decoding="async"></div>
          @endif
          <div class="feature-card__body">
            @if (!empty($item['eyebrow']))
              <p>{{ $item['eyebrow'] }}</p>
            @endif
            @if (!empty($item['title']))
              <h3>{{ $item['title'] }}</h3>
            @endif
            @if (!empty($item['text']))
              <div>{{ $item['text'] }}</div>
            @endif
            @if (!empty($item['link']))
              <a href="{{ $item['link'] }}" @if (!empty($item['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ $item['link_label'] ?: __('common.view_more') }} <i class="bi bi-arrow-right"></i></a>
            @endif
          </div>
        </article>
      @empty
        @if ($design)
          <div class="custom-code-empty">{{ __('shop/design.empty.feature_grid') }}</div>
        @endif
      @endforelse
    </div>
  </div>
</section>
