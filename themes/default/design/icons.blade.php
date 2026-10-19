@php
  $hasIcons = collect($content['images'] ?? [])->contains(fn ($image) => !empty($image['image']) || !empty($image['text']) || !empty($image['sub_text']));
@endphp
@if($design || $hasIcons)
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info module-icons module-icons--{{ $content['style_variant'] ?? 'minimal' }}">
    @if ($content['title'])
    <h2 class="module-title" id="module-heading-{{ $module_id }}">{{ $content['title'] }} <span class="wave-line" aria-hidden="true"></span></h2>
    @endif
    @if ($content['sub_title'])
    <div class="module-sub-title">{{ $content['sub_title'] }}</div>
    @endif
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 2 }}">
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
          <div class="builder-section-grid__full"><div class="custom-code-empty">请添加图标、权益文案或服务说明。</div></div>
        @endif
      </div>
    </div>
  </div>
</section>
@endif
