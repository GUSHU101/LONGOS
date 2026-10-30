@php
  $iconItems = collect($content['images'] ?? [])->filter(fn ($image) => is_array($image));
  $visibleIconItems = $iconItems->filter(fn ($image) => !empty($image['image']) || !empty($image['text']) || !empty($image['sub_text']));
  $hasIcons = $visibleIconItems->isNotEmpty();
  $imageOnly = $hasIcons && $visibleIconItems->every(fn ($image) => empty($image['text']) && empty($image['sub_text']));
  $mobileColumnsMode = ($content['mobile_columns_mode'] ?? 'auto') === 'fixed' ? 'fixed' : 'auto';
  $mobileGridClass = $mobileColumnsMode === 'auto'
    ? 'builder-section-grid--mobile-auto'
    : 'builder-section-grid--mobile-' . max(1, min(4, (int) ($content['columns_mobile'] ?? 3)));
@endphp
@if($design || $hasIcons)
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info module-icons module-icons--{{ $content['style_variant'] ?? 'minimal' }} {{ $imageOnly ? 'module-icons--image-only' : '' }}">
    @if ($content['title'])
    <h2 class="module-title" id="module-heading-{{ $module_id }}">{{ $content['title'] }} <span class="wave-line" aria-hidden="true"></span></h2>
    @endif
    @if ($content['sub_title'])
    <div class="module-sub-title">{{ $content['sub_title'] }}</div>
    @endif
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} {{ $mobileGridClass }}">
        @foreach ($content['images'] as $image)
        <div class="module-icons__item">
          @if (!empty($image['url']))<a href="{{ $image['url'] }}" class="text-decoration-none" @if(!empty($image['new_window'])) target="_blank" rel="noopener noreferrer" @endif>@else<div>@endif
            <div class="image-item d-flex justify-content-center mb-lg-3">
              @if(!empty($image['image']))<img src="{{ $image['image'] }}" loading="lazy" decoding="async" class="img-fluid seo-img" alt="{{ $image['image_alt'] ?? ''}}">@else<span class="module-icons__fallback" aria-hidden="true">✓</span>@endif
            </div>
            @if ($image['text'])
            <p class="text-center text-dark mt-2 mb-0 title">{{ $image['text'] }}</p>
            @endif
            @if ($image['sub_text'])
            <p class="text-center text-secondary mt-2 mb-0 sub-title">{{ $image['sub_text'] }}</p>
            @endif
          @if (!empty($image['url']))</a>@else</div>@endif
        </div>
        @endforeach
        @if(empty($content['images']) && $design)
          <div class="builder-section-grid__full"><div class="custom-code-empty">{{ __('shop/design.empty.icons') }}</div></div>
        @endif
      </div>
    </div>
  </div>
</section>
@endif
