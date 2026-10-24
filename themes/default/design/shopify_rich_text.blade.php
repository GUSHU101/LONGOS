<section class="module-item section-shopify-rich-text {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-rich-text-padding-top: {{ (int) ($content['padding_top'] ?? 40) }}px; --shopify-rich-text-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 52) }}px;" @include('design.partials.section_style')>
  <div class="{{ !empty($content['full_width']) ? 'container-fluid' : 'container' }}">
    <div class="shopify-rich-text shopify-rich-text--position-{{ $content['desktop_content_position'] }} shopify-rich-text--align-{{ $content['content_alignment'] }} {{ !empty($content['full_width']) ? 'shopify-rich-text--full' : '' }}">
      <div class="shopify-rich-text__blocks">
        @foreach($content['blocks'] as $block)
          @if($block['type'] === 'heading' && !empty($block['heading']))<h2 class="shopify-rich-text__heading shopify-rich-text__heading--{{ $block['heading_size'] }}">{{ $block['heading'] }}</h2>@endif
          @if($block['type'] === 'caption' && !empty($block['caption']))<p class="shopify-rich-text__caption shopify-rich-text__caption--{{ $block['text_style'] }} shopify-rich-text__caption--{{ $block['text_size'] }}">{{ $block['caption'] }}</p>@endif
          @if($block['type'] === 'text' && !empty($block['html']))<div class="shopify-rich-text__body">{!! $block['html'] !!}</div>@endif
          @if($block['type'] === 'button' && (!empty($block['button_label']) || !empty($block['button_label_2'])))
            <div class="shopify-rich-text__buttons">
              @foreach(['' => ['label' => $block['button_label'] ?? '', 'link' => $block['button_link'] ?? '', 'secondary' => $block['button_style_secondary'] ?? false], '2' => ['label' => $block['button_label_2'] ?? '', 'link' => $block['button_link_2'] ?? '', 'secondary' => $block['button_style_secondary_2'] ?? false]] as $button)
                @if($button['label'])
                  @if($button['link'])<a href="{{ $button['link'] }}" class="btn {{ $button['secondary'] ? 'btn-outline-primary' : 'btn-primary' }}">{{ $button['label'] }}</a>
                  @elseif($design)<span class="btn {{ $button['secondary'] ? 'btn-outline-primary' : 'btn-primary' }} disabled" aria-disabled="true">{{ $button['label'] }}</span>
                  @endif
                @endif
              @endforeach
            </div>
          @endif
        @endforeach
        @if($design && empty($content['blocks']))<div class="shopify-rich-text__empty">{{ __('shop/design.empty.shopify_rich_text') }}</div>@endif
      </div>
    </div>
  </div>
</section>
