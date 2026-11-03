@php
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-feature-grid section-feature-grid--{{ $content['card_style'] ?? 'editorial' }} section-feature-grid--ratio-{{ $content['image_ratio'] ?? 'landscape' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))
      <h2 class="section-heading" id="module-heading-{{ $module_id }}" data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</h2>
    @endif
    @if (!empty($content['sub_title']))
      <p class="section-subheading" data-lgt-field="module.{{ $module_id }}.sub_title">{{ $content['sub_title'] }}</p>
    @endif
    <div class="feature-grid feature-grid--desktop-{{ $content['columns'] ?? 3 }} feature-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} feature-grid--mobile-{{ $content['columns_mobile'] ?? 1 }}">
      @forelse($content['items'] ?? [] as $item)
        <article class="feature-card">
          @if (!empty($item['image']))
            <div class="feature-card__media"><img src="{{ $item['image'] }}" alt="{{ $item['image_alt'] ?? '' }}" loading="lazy" decoding="async"></div>
          @endif
          <div class="feature-card__body">
            @if (!empty($item['eyebrow']))
              <p data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.eyebrow">{{ $item['eyebrow'] }}</p>
            @endif
            @if (!empty($item['title']))
              <h3 data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.title">{{ $item['title'] }}</h3>
            @endif
            @if (!empty($item['text']))
              <div data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.text">{{ $item['text'] }}</div>
            @endif
            @if (!empty($item['link']))
              <a href="{{ $item['link'] }}" @if (!empty($item['new_window'])) target="_blank" rel="noopener noreferrer" @endif><span data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.link_label">{{ $item['link_label'] ?: __('common.view_more') }}</span> <i class="bi bi-arrow-right"></i></a>
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
