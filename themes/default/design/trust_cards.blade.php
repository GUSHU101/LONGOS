<section class="module-item section-trust-cards section-trust-cards--{{ $content['style_variant'] ?? 'soft' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))<h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>@endif
    @if (!empty($content['sub_title']))<p class="section-subheading">{{ $content['sub_title'] }}</p>@endif
    <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns'] ?? 3 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 1 }} trust-card-grid">
      @forelse($content['items'] ?? [] as $item)
        <div>
          @if (!empty($item['link']))
            <a href="{{ $item['link'] }}" class="trust-card" @if(!empty($item['new_window'])) target="_blank" rel="noopener noreferrer" @endif>
          @else
            <div class="trust-card">
          @endif
            @if (!empty($item['icon']))<div class="trust-card-icon" aria-hidden="true">{{ $item['icon'] }}</div>@endif
            @if (!empty($item['title']))<h3>{{ $item['title'] }}</h3>@endif
            @if (!empty($item['description']))<p>{{ $item['description'] }}</p>@endif
          @if (!empty($item['link']))</a>@else</div>@endif
        </div>
      @empty
        @if ($design)<div class="builder-section-grid__full"><div class="custom-code-empty">{{ __('shop/design.empty.trust_cards') }}</div></div>@endif
      @endforelse
    </div>
  </div>
</section>
