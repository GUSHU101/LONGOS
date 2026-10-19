@if($design || !empty($content['brands']))
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info module-brand">
    @if($content['title'])<h2 class="module-title" id="module-heading-{{ $module_id }}">{{ $content['title'] }} <span class="wave-line" aria-hidden="true"></span></h2>@endif
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 3 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 2 }}">
        @foreach ($content['brands'] as $brand)
        <div>
          <a href="{{ $brand['url'] }}" class="module-brand__link text-decoration-none">
            <div class="brand-item">
              <img src="{{ $brand['logo'] ?? asset('image/default/banner-1.png') }}" loading="lazy" decoding="async" alt="{{ $brand['name'] }}" class="img-fluid seo-img">
            </div>
            <p class="text-center text-dark mb-0">{{ $brand['name'] }}</p>
          </a>
        </div>
        @endforeach
        @if(empty($content['brands']) && $design)
          <div class="builder-section-grid__full"><div class="custom-code-empty">请在右侧选择需要展示的品牌。</div></div>
        @endif
      </div>
    </div>
    @if ($content['brands'] && !empty($content['show_view_all']))
    <div class="d-flex justify-content-center mt-4">
      <a class="btn btn-outline-secondary btn-lg" href="{{ shop_route('brands.index') }}">{{ __('common.show_all') }}</a>
    </div>
    @endif
  </div>
</section>
@endif
