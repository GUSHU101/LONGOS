@php
  $moduleContent = is_array($content ?? null) ? $content : [];
  $title = is_scalar($moduleContent['title'] ?? null) ? trim((string) $moduleContent['title']) : '';
  $subtitle = is_scalar($moduleContent['sub_title'] ?? null) ? trim((string) $moduleContent['sub_title']) : '';
  $products = is_array($moduleContent['products'] ?? null) ? $moduleContent['products'] : [];
  $isDesign = (bool) ($design ?? false);
  $layout = ($moduleContent['layout'] ?? 'grid') === 'scroll' ? 'scroll' : 'grid';
  $desktopColumns = min(5, max(2, (int) ($moduleContent['columns_desktop'] ?? 4)));
  $tabletColumns = min(3, $desktopColumns, max(1, (int) ($moduleContent['columns_tablet'] ?? 3)));
  $mobileColumns = min(2, max(1, (int) ($moduleContent['columns_mobile'] ?? 2)));
  $moduleSize = in_array($moduleContent['module_size'] ?? '', ['container-fluid', 'container', 'w-100'], true)
    ? $moduleContent['module_size'] : 'container-fluid';
  $emptyLabel = $feedKind === 'bestseller' ? '等待店铺产生销售数据' : '等待店铺上架商品';
@endphp

@if($isDesign || $products)
<section class="module-item auto-product-feed auto-product-feed--{{ $layout }} {{ $isDesign ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  @include('design._partial._module_tool')
  <div class="{{ $moduleSize }} auto-product-feed__inner" style="--feed-columns-desktop: {{ $desktopColumns }}; --feed-columns-tablet: {{ $tabletColumns }}; --feed-columns-mobile: {{ $mobileColumns }};">
    @if ($title !== '' || $subtitle !== '')
      <header class="auto-product-feed__header">
        @if ($title !== '')<h2 class="module-title mb-0">{{ $title }}</h2>@endif
        @if ($subtitle !== '')<p>{{ $subtitle }}</p>@endif
      </header>
    @endif

    <div class="auto-product-feed__items" role="list" aria-label="{{ $title !== '' ? $title : $emptyLabel }}">
      @foreach ($products as $product)
        <article class="auto-product-feed__item" role="listitem">@include('shared.product')</article>
      @endforeach
      @if (! $products && $isDesign)
        @for ($index = 0; $index < min(4, (int) ($moduleContent['limit'] ?? 8)); $index++)
          <article class="auto-product-feed__item" role="listitem">
            <div class="product-wrap">
              <div class="image" aria-hidden="true"><img src="{{ asset('image/placeholder.png') }}" class="img-fluid" alt=""></div>
              <div class="product-name">{{ $emptyLabel }}</div>
              <div class="product-price"><span class="price-new">$66.66</span></div>
            </div>
          </article>
        @endfor
      @endif
    </div>
  </div>
</section>

@once
  <style>
    .auto-product-feed__inner { padding-block: clamp(2rem, 5vw, 4.5rem); }
    .auto-product-feed__header { display: grid; gap: .65rem; margin: 0 auto clamp(1.5rem, 3vw, 2.5rem); text-align: center; max-width: 48rem; }
    .auto-product-feed__header p { margin: 0; color: color-mix(in srgb, currentColor 62%, transparent); }
    .auto-product-feed__items { display: grid; grid-template-columns: repeat(var(--feed-columns-mobile), minmax(0, 1fr)); gap: clamp(.85rem, 2vw, 1.5rem); }
    .auto-product-feed__item { min-width: 0; }
    .auto-product-feed--scroll .auto-product-feed__items { grid-auto-flow: column; grid-auto-columns: calc((100% - (var(--feed-columns-mobile) - 1) * .85rem) / var(--feed-columns-mobile)); grid-template-columns: none; overflow-x: auto; overscroll-behavior-inline: contain; scroll-snap-type: inline mandatory; scrollbar-width: thin; padding-bottom: .75rem; }
    .auto-product-feed--scroll .auto-product-feed__item { scroll-snap-align: start; }
    @media (min-width: 768px) and (max-width: 1023.98px) {
      .auto-product-feed__items { grid-template-columns: repeat(var(--feed-columns-tablet), minmax(0, 1fr)); }
      .auto-product-feed--scroll .auto-product-feed__items { grid-template-columns: none; grid-auto-columns: calc((100% - (var(--feed-columns-tablet) - 1) * 1.5rem) / var(--feed-columns-tablet)); }
    }
    @media (min-width: 1024px) {
      .auto-product-feed__items { grid-template-columns: repeat(var(--feed-columns-desktop), minmax(0, 1fr)); }
      .auto-product-feed--scroll .auto-product-feed__items { grid-template-columns: none; grid-auto-columns: calc((100% - (var(--feed-columns-desktop) - 1) * 1.5rem) / var(--feed-columns-desktop)); }
    }
  </style>
@endonce
@endif
