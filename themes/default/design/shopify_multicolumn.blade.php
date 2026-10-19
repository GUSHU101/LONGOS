<section class="module-item section-shopify-multicolumn {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-multicolumn-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-multicolumn-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <div class="shopify-multicolumn shopify-multicolumn--desktop-{{ $content['columns_desktop'] }} shopify-multicolumn--mobile-{{ $content['columns_mobile'] }} shopify-multicolumn--align-{{ $content['column_alignment'] }} shopify-multicolumn--image-{{ $content['image_width'] }} shopify-multicolumn--ratio-{{ $content['image_ratio'] }} shopify-multicolumn--surface-{{ $content['background_style'] }} {{ !empty($content['swipe_on_mobile']) ? 'shopify-multicolumn--swipe-mobile' : '' }}">
      @if(!empty($content['title']))<h2 class="shopify-multicolumn__title shopify-multicolumn__title--{{ $content['heading_size'] }}">{{ $content['title'] }}</h2>@endif
      <div class="shopify-multicolumn__viewport" @if(!empty($content['swipe_on_mobile'])) tabindex="0" aria-label="Swipe through columns" @endif>
        <ul class="shopify-multicolumn__list">
          @forelse($content['blocks'] as $block)
            <li class="shopify-multicolumn__item">
              <article class="shopify-multicolumn__card">
                @if(!empty($block['image']))<div class="shopify-multicolumn__media"><img src="{{ $block['image'] }}" alt="" loading="lazy" decoding="async"></div>@endif
                <div class="shopify-multicolumn__info">
                  @if(!empty($block['title']))<h3>{{ $block['title'] }}</h3>@endif
                  @if(!empty($block['html']))<div class="shopify-multicolumn__body">{!! $block['html'] !!}</div>@endif
                  @if(!empty($block['link_label']))
                    @if(!empty($block['link']))<a href="{{ $block['link'] }}" class="shopify-multicolumn__link">{{ $block['link_label'] }} <span aria-hidden="true">→</span></a>
                    @elseif($design)<span class="shopify-multicolumn__link is-disabled" aria-disabled="true">{{ $block['link_label'] }} <span aria-hidden="true">→</span></span>
                    @endif
                  @endif
                </div>
              </article>
            </li>
          @empty
            @if($design)<li class="shopify-multicolumn__empty">请向此模块添加内容列。</li>@endif
          @endforelse
        </ul>
      </div>
      @if(!empty($content['button_label']))
        <div class="shopify-multicolumn__action">
          @if(!empty($content['button_link']))<a href="{{ $content['button_link'] }}" class="btn btn-primary">{{ $content['button_label'] }}</a>
          @elseif($design)<span class="btn btn-primary disabled" aria-disabled="true">{{ $content['button_label'] }}</span>
          @endif
        </div>
      @endif
    </div>
  </div>
</section>
