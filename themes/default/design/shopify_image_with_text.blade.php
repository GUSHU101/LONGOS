<section class="module-item section-shopify-image-with-text {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-image-text-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-image-text-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <article class="shopify-image-with-text shopify-image-with-text--{{ $content['height'] }} shopify-image-with-text--media-{{ $content['desktop_image_width'] }} shopify-image-with-text--{{ $content['layout'] }} shopify-image-with-text--{{ $content['content_layout'] }} shopify-image-with-text--position-{{ $content['desktop_content_position'] }} shopify-image-with-text--align-{{ $content['desktop_content_alignment'] }} shopify-image-with-text--mobile-align-{{ $content['mobile_content_alignment'] }} shopify-image-with-text--behavior-{{ $content['image_behavior'] }}">
      <div class="shopify-image-with-text__media" aria-hidden="true">
        @if(!empty($content['image']))<img src="{{ $content['image'] }}" alt="" loading="lazy" decoding="async">@elseif($design)<div class="shopify-image-with-text__placeholder">{{ __('shop/design.empty.shopify_image') }}</div>@endif
      </div>
      <div class="shopify-image-with-text__copy">
        <div class="shopify-image-with-text__blocks">
          @foreach($content['blocks'] as $block)
            @if($block['type'] === 'heading' && !empty($block['heading']))<h2 class="shopify-image-with-text__heading shopify-image-with-text__heading--{{ $block['heading_size'] }}">{{ $block['heading'] }}</h2>@endif
            @if($block['type'] === 'caption' && !empty($block['caption']))<p class="shopify-image-with-text__caption shopify-image-with-text__caption--{{ $block['text_style'] }} shopify-image-with-text__caption--{{ $block['text_size'] }}">{{ $block['caption'] }}</p>@endif
            @if($block['type'] === 'text' && !empty($block['html']))<div class="shopify-image-with-text__body shopify-image-with-text__body--{{ $block['text_style'] }}">{!! $block['html'] !!}</div>@endif
            @if($block['type'] === 'button' && !empty($block['button_label']))
              @if(!empty($block['button_link']))<a href="{{ $block['button_link'] }}" class="btn {{ !empty($block['button_style_secondary']) ? 'btn-outline-primary' : 'btn-primary' }}">{{ $block['button_label'] }}</a>
              @elseif($design)<span class="btn {{ !empty($block['button_style_secondary']) ? 'btn-outline-primary' : 'btn-primary' }} disabled" aria-disabled="true">{{ $block['button_label'] }}</span>
              @endif
            @endif
          @endforeach
          @if($design && empty($content['blocks']))<div class="shopify-image-with-text__empty">{{ __('shop/design.empty.shopify_image_blocks') }}</div>@endif
        </div>
      </div>
    </article>
  </div>
</section>
