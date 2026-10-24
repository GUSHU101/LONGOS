@php
  $carouselId = 'shopify-slideshow-' . $module_id;
  $slides = $content['slides'] ?? [];
  $count = count($slides);
@endphp
<section class="module-item section-shopify-slideshow {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div id="{{ $carouselId }}" class="shopify-slideshow shopify-slideshow--{{ $content['layout'] }} shopify-slideshow--{{ $content['slide_height'] }} shopify-slideshow--{{ $content['image_behavior'] }} {{ !empty($content['show_text_below']) ? 'shopify-slideshow--text-below' : '' }}" role="region" aria-roledescription="carousel" aria-label="{{ $content['accessibility_info'] }}" data-autoplay="{{ !empty($content['auto_rotate']) && $count > 1 ? 'true' : 'false' }}" data-speed="{{ (int) $content['change_slides_speed'] * 1000 }}">
    @if($count)
      <div class="shopify-slideshow__track" aria-live="polite">
        @foreach($slides as $index => $slide)
          <article class="shopify-slideshow__slide shopify-slideshow__slide--{{ $slide['box_align'] }} shopify-slideshow__slide--align-{{ $slide['text_alignment'] }} shopify-slideshow__slide--mobile-align-{{ $slide['text_alignment_mobile'] }} shopify-slideshow__slide--{{ $slide['color_scheme'] }} {{ empty($slide['show_text_box']) ? 'shopify-slideshow__slide--transparent-copy' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $index + 1 }} / {{ $count }}" @if($index > 0) hidden @endif>
            @if($slide['image'])<img class="shopify-slideshow__image" src="{{ $slide['image'] }}" alt="" @if($index === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async">@endif
            <div class="shopify-slideshow__overlay" style="--shopify-slideshow-overlay: {{ number_format($slide['image_overlay_opacity'] / 100, 2, '.', '') }}" aria-hidden="true"></div>
            @if($slide['heading'] || $slide['subheading'] || $slide['button_label'])
              <div class="shopify-slideshow__copy"><div class="shopify-slideshow__box">
                @if($slide['heading'])<h2 class="shopify-slideshow__heading shopify-slideshow__heading--{{ $slide['heading_size'] }}">{{ $slide['heading'] }}</h2>@endif
                @if($slide['subheading'])<p class="shopify-slideshow__text">{{ $slide['subheading'] }}</p>@endif
                @if($slide['button_label'])
                  @if($slide['link'])<a class="btn {{ $slide['button_style_secondary'] ? 'btn-outline-light' : 'btn-light' }}" href="{{ $slide['link'] }}">{{ $slide['button_label'] }}</a>
                  @elseif($design)<span class="btn btn-light disabled" aria-disabled="true">{{ $slide['button_label'] }}</span>@endif
                @endif
              </div></div>
            @endif
          </article>
        @endforeach
      </div>
      @if($count > 1)
        <div class="shopify-slideshow__controls" aria-label="{{ __('shop/design.slideshow_controls') }}" data-pause-label="{{ __('shop/design.pause_autoplay') }}" data-play-label="{{ __('shop/design.start_autoplay') }}">
          <button type="button" data-slide-action="previous" aria-label="{{ __('shop/design.previous_slide') }}">‹</button>
          <div class="shopify-slideshow__indicators shopify-slideshow__indicators--{{ $content['slider_visual'] }}" aria-label="{{ __('shop/design.choose_slide') }}">
            @foreach($slides as $index => $slide)<button type="button" data-slide-to="{{ $index }}" aria-label="{{ __('shop/design.show_slide', ['number' => $index + 1]) }}" aria-current="{{ $index === 0 ? 'true' : 'false' }}">@if($content['slider_visual'] === 'numbers'){{ $index + 1 }}@elseif($content['slider_visual'] === 'counter'){{ $index + 1 }} / {{ $count }}@else<span aria-hidden="true"></span>@endif</button>@endforeach
          </div>
          <button type="button" data-slide-action="next" aria-label="{{ __('shop/design.next_slide') }}">›</button>
          @if(!empty($content['auto_rotate']))<button type="button" data-slide-action="toggle" aria-label="{{ __('shop/design.pause_autoplay') }}" aria-pressed="false">Ⅱ</button>@endif
        </div>
      @endif
    @elseif($design)
      <div class="shopify-slideshow__empty">{{ __('shop/design.empty.shopify_slideshow') }}</div>
    @endif
  </div>
</section>
@if($count > 1)
<script>
(() => {
  const root = document.getElementById(@json($carouselId));
  if (!root || root.dataset.ready === 'true') return;
  root.dataset.ready = 'true';
  const slides = Array.from(root.querySelectorAll('.shopify-slideshow__slide'));
  const dots = Array.from(root.querySelectorAll('[data-slide-to]'));
  let current = 0; let playing = root.dataset.autoplay === 'true' && !matchMedia('(prefers-reduced-motion: reduce)').matches; let timer;
  const show = (next) => { current = (next + slides.length) % slides.length; slides.forEach((slide, index) => slide.hidden = index !== current); dots.forEach((dot, index) => dot.setAttribute('aria-current', index === current ? 'true' : 'false')); };
  const stop = () => { if (timer) { clearInterval(timer); timer = undefined; } };
  const start = () => { stop(); if (playing) timer = setInterval(() => show(current + 1), Math.max(3000, Number(root.dataset.speed) || 5000)); };
  const controls = root.querySelector('.shopify-slideshow__controls');
  root.querySelectorAll('[data-slide-action]').forEach((button) => button.addEventListener('click', () => { const action = button.dataset.slideAction; if (action === 'previous') show(current - 1); if (action === 'next') show(current + 1); if (action === 'toggle') { playing = !playing; button.setAttribute('aria-pressed', String(!playing)); button.setAttribute('aria-label', playing ? (controls?.dataset.pauseLabel || 'Pause autoplay') : (controls?.dataset.playLabel || 'Start autoplay')); button.textContent = playing ? 'Ⅱ' : '▶'; start(); } }));
  dots.forEach((dot) => dot.addEventListener('click', () => { show(Number(dot.dataset.slideTo)); start(); }));
  root.addEventListener('mouseenter', stop); root.addEventListener('mouseleave', start); start();
})();
</script>
@endif
