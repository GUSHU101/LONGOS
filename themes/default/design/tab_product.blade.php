@php
  $hasProducts = collect($content['tabs'] ?? [])->contains(fn ($tab) => !empty($tab['products']));
@endphp
@if($design || $hasProducts)
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  @php
    $editableTabsValue = $content['editableTabsValue'] ?? 0;
  @endphp
  <div class="module-info module-tab-product ">
    <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
      @if ($content['title'] ?? false)
        <h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>
      @endif
      @if ($content['tabs'] ?? false)
        <div class="nav justify-content-center" role="tablist" aria-label="{{ $content['title'] ?? __('common.all') }}">
          @foreach ($content['tabs'] as $key => $tabs)
          @php
            $tabActive = $design ? $editableTabsValue == $key : $loop->first;
          @endphp
          <button class="nav-link {{ $tabActive ? 'active' : '' }}" id="tab-product-trigger-{{ $module_id }}-{{ $loop->index }}" data-bs-target="#tab-product-{{ $module_id }}-{{ $loop->index }}" data-bs-toggle="tab" type="button" role="tab" aria-controls="tab-product-{{ $module_id }}-{{ $loop->index }}" aria-selected="{{ $tabActive ? 'true' : 'false' }}">{{ $tabs['title'] ?: __('shop/design.unnamed_group') }}</button>
          @endforeach
        </div>
        <div class="tab-content">
          @foreach ($content['tabs'] as $key => $products)
          @php
            $panelActive = $design ? $editableTabsValue == $key : $loop->first;
          @endphp
          <div class="tab-pane fade {{ $panelActive ? 'show active' : '' }}" id="tab-product-{{ $module_id }}-{{ $loop->index }}" role="tabpanel" aria-labelledby="tab-product-trigger-{{ $module_id }}-{{ $loop->index }}" tabindex="0">
            <div class="builder-section-grid builder-section-grid--desktop-{{ $content['columns_desktop'] ?? 4 }} builder-section-grid--tablet-{{ $content['columns_tablet'] ?? 3 }} builder-section-grid--mobile-{{ $content['columns_mobile'] ?? 2 }}">
              @if ($products['products'])
                @foreach ($products['products'] as $product)
                <div class="product-grid">
                  @include('shared.product')
                </div>
                @endforeach
              @elseif (!$products['products'] and $design)
                @for ($s = 0; $s < 8; $s++)
                <div class="product-grid">
                  <div class="product-wrap">
                    <div class="image"><div class="product-placeholder-image" aria-hidden="true"><img src="{{ asset('image/placeholder.png') }}" class="img-fluid" alt=""></div></div>
                    <div class="product-name">{{ __('shop/design.empty.product') }}</div>
                    <div class="product-price">
                      <span class="price-new">66.66</span>
                      <span class="price-lod">99.99</span>
                    </div>
                  </div>
                </div>
                @endfor
              @endif
            </div>
          </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</section>
@endif
