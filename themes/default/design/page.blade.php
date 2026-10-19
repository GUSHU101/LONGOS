@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))

@if($design || !empty($content['items']))
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info module-pages">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }} position-relative">
      @if($content['title'])<h2 class="module-title" id="module-heading-{{ $module_id }}">{{ $content['title'] }} <span class="wave-line" aria-hidden="true"></span></h2>@endif
      @if ($content['items'])
        <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 1 }}">
          @foreach ($content['items'] as $item)
          <div>
            <div class="pages-wrap">
              <div class="image"><a href="{{ (isset($item['url']) ? $item['url'] : shop_route('pages.show', [$item['id']])) }}">@if(!empty($item['image']))<img src="{{ $item['image'] }}" class="img-fluid seo-img" loading="lazy" decoding="async" alt="{{ $item['title'] ?? '' }}">@else<span class="page-image-placeholder" aria-hidden="true">LONGOS</span>@endif</a>
              </div>
              <div class="page-info">
                <div class="pages-title"><a href="{{ $item['url'] }}">{{ $item['title'] ?? '' }}</a></div>
                <div class="pages-summary">{{ $item['summary'] ?? '' }}</div>
                <div class="pages-view"><a href="{{ $item['url'] }}">{{ __('shop/account.check_details') }}<i class="bi bi-arrow-right-short" aria-hidden="true"></i></a></div>
              </div>
            </div>
          </div>
          @endforeach
        </div>
        @if (!empty($content['show_view_all']) && count($content['items']) > 4)
        <div class="d-flex justify-content-center mt-4">
          <a class="btn btn-outline-secondary btn-lg" href="{{ shop_route('page_categories.home') }}">{{ __('common.show_all') }}</a>
        </div>
        @endif
      @elseif (!$content['items'] and $design)
        <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 2 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 1 }}">
          @for ($s = 0; $s < 4; $s++)
          <div>
            <div class="pages-wrap">
              <div class="image"><div class="page-placeholder-image" aria-hidden="true"><img src="{{ asset('image/placeholder.png') }}" class="img-fluid" alt=""></div></div>
              <div class="pages-name">请在右侧选择内容页</div>
            </div>
          </div>
          @endfor
        </div>
      @endif
    </div>
  </div>
</section>
@endif
