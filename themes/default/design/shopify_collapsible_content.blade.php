<section class="module-item section-shopify-collapsible-content {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-collapsible-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-collapsible-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="shopify-collapsible-content shopify-collapsible-content--layout-{{ $content['layout'] }}">
    <div class="container">
      <div class="shopify-collapsible-content__inner shopify-collapsible-content__inner--{{ $content['desktop_layout'] }}">
        <header class="shopify-collapsible-content__header shopify-collapsible-content__header--{{ $content['heading_alignment'] }}">
          @if(!empty($content['caption']))<p class="shopify-collapsible-content__caption">{{ $content['caption'] }}</p>@endif
          @if(!empty($content['heading']))<h2 class="shopify-collapsible-content__heading shopify-collapsible-content__heading--{{ $content['heading_size'] }}">{{ $content['heading'] }}</h2>@endif
        </header>
        <div class="shopify-collapsible-content__grid">
          @if(!empty($content['image']))<div class="shopify-collapsible-content__media shopify-collapsible-content__media--{{ $content['image_ratio'] }}"><img src="{{ $content['image'] }}" alt="" loading="lazy" decoding="async"></div>@endif
          <div class="shopify-collapsible-content__rows">
            @forelse($content['blocks'] as $block)
              <details class="shopify-collapsible-content__row" @if(!empty($content['open_first_collapsible_row']) && $loop->first) open @endif>
                <summary id="shopify-collapsible-summary-{{ $module_id }}-{{ $loop->iteration }}">
                  @if(($block['icon'] ?? 'none') !== 'none')<span class="shopify-collapsible-content__icon" data-icon="{{ $block['icon'] }}" aria-hidden="true"></span>@endif
                  <h3>{{ $block['heading'] ?: 'More information' }}</h3><span class="shopify-collapsible-content__caret" aria-hidden="true"></span>
                </summary>
                <div class="shopify-collapsible-content__body" role="region" aria-labelledby="shopify-collapsible-summary-{{ $module_id }}-{{ $loop->iteration }}">
                  @if(!empty($block['html'])){!! $block['html'] !!}@endif
                  @if(!empty($block['page_html'])){!! $block['page_html'] !!}@endif
                </div>
              </details>
            @empty
              @if($design)<div class="shopify-collapsible-content__empty">{{ __('shop/design.empty.shopify_collapsible') }}</div>@endif
            @endforelse
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
