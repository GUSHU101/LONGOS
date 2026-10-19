<section class="module-item section-shopify-multirow {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-multirow-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-multirow-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <div class="shopify-multirow shopify-multirow--media-{{ $content['desktop_image_width'] }} shopify-multirow--height-{{ $content['image_height'] }} shopify-multirow--position-{{ $content['desktop_content_position'] }} shopify-multirow--align-{{ $content['desktop_content_alignment'] }} shopify-multirow--mobile-align-{{ $content['mobile_content_alignment'] }}">
      @forelse($content['blocks'] as $block)
        <article class="shopify-multirow__row shopify-multirow__row--image-{{ $block['image_position'] }}">
          <div class="shopify-multirow__media" aria-hidden="true">
            @if(!empty($block['image']))<img src="{{ $block['image'] }}" alt="" loading="lazy" decoding="async">@elseif($design)<div class="shopify-multirow__placeholder">请为这一行选择图片。</div>@endif
          </div>
          <div class="shopify-multirow__copy">
            <div class="shopify-multirow__content">
              @if(!empty($block['caption']))<p class="shopify-multirow__caption">{{ $block['caption'] }}</p>@endif
              @if(!empty($block['heading']))<h2 class="shopify-multirow__heading shopify-multirow__heading--{{ $content['heading_size'] }}">{{ $block['heading'] }}</h2>@endif
              @if(!empty($block['html']))<div class="shopify-multirow__body shopify-multirow__body--{{ $content['text_style'] }}">{!! $block['html'] !!}</div>@endif
              @if(!empty($block['button_label']))
                @if(!empty($block['button_link']))<a href="{{ $block['button_link'] }}" class="btn {{ $content['button_style'] === 'secondary' ? 'btn-outline-primary' : 'btn-primary' }}">{{ $block['button_label'] }}</a>
                @elseif($design)<span class="btn {{ $content['button_style'] === 'secondary' ? 'btn-outline-primary' : 'btn-primary' }} disabled" aria-disabled="true">{{ $block['button_label'] }}</span>
                @endif
              @endif
            </div>
          </div>
        </article>
      @empty
        @if($design)<div class="shopify-multirow__empty">请向此模块添加内容行。</div>@endif
      @endforelse
    </div>
  </div>
</section>
