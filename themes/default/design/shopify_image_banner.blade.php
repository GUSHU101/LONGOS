@php
  $hasImages = !empty($content['image']) || !empty($content['image_2']);
@endphp
<section class="module-item section-shopify-image-banner {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="shopify-image-banner shopify-image-banner--{{ $content['image_height'] }} shopify-image-banner--behavior-{{ $content['image_behavior'] }} shopify-image-banner--position-{{ $content['desktop_content_position'] }} shopify-image-banner--align-{{ $content['desktop_content_alignment'] }} shopify-image-banner--mobile-align-{{ $content['mobile_content_alignment'] }} {{ !empty($content['image_2']) ? 'shopify-image-banner--two-images' : '' }} {{ !empty($content['stack_images_on_mobile']) ? 'shopify-image-banner--stack-mobile' : '' }} {{ !empty($content['show_text_below']) ? 'shopify-image-banner--mobile-text-below' : '' }} {{ empty($content['show_text_box']) ? 'shopify-image-banner--transparent-copy' : '' }}" style="--shopify-banner-overlay: {{ number_format(((int) ($content['image_overlay_opacity'] ?? 0)) / 100, 2, '.', '') }};">
    <div class="shopify-image-banner__media" aria-hidden="true">
      @if (!empty($content['image']))<img src="{{ $content['image'] }}" alt="" loading="lazy" decoding="async">@endif
      @if (!empty($content['image_2']))<img src="{{ $content['image_2'] }}" alt="" loading="lazy" decoding="async">@endif
      @if (!$hasImages && $design)<div class="shopify-image-banner__placeholder">{{ __('shop/design.empty.shopify_banner') }}</div>@endif
    </div>
    <div class="shopify-image-banner__overlay" aria-hidden="true"></div>
    <div class="shopify-image-banner__content">
      <div class="shopify-image-banner__box">
        @foreach($content['blocks'] as $block)
          @if($block['type'] === 'heading' && !empty($block['heading']))<h2 class="shopify-image-banner__heading shopify-image-banner__heading--{{ $block['heading_size'] }}">{{ $block['heading'] }}</h2>@endif
          @if($block['type'] === 'text' && !empty($block['text']))<p class="shopify-image-banner__text shopify-image-banner__text--{{ $block['text_style'] }}">{{ $block['text'] }}</p>@endif
          @if($block['type'] === 'buttons' && (!empty($block['button_label_1']) || !empty($block['button_label_2'])))
            <div class="shopify-image-banner__buttons">
              @foreach([1, 2] as $button)
                @php
                  $label = $block['button_label_' . $button] ?? '';
                  $link = $block['button_link_' . $button] ?? '';
                @endphp
                @if($label)
                  @if($link)<a href="{{ $link }}" class="btn {{ !empty($block['button_style_secondary_' . $button]) ? 'btn-outline-light' : 'btn-light' }}">{{ $label }}</a>
                  @elseif($design)<span class="btn {{ !empty($block['button_style_secondary_' . $button]) ? 'btn-outline-light' : 'btn-light' }} disabled" aria-disabled="true">{{ $label }}</span>
                  @endif
                @endif
              @endforeach
            </div>
          @endif
        @endforeach
      </div>
    </div>
  </div>
</section>
