@addStyle(asset('vendor/swiper/swiper-bundle.min.css'))
@addScript(asset('vendor/swiper/swiper-bundle.min.js'))

@php
  $hasSlides = collect($content['images'] ?? [])->contains(fn ($image) => !empty($image['image']));
@endphp
@if($design || $hasSlides)
<section class="module-item {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info  ">
    <div class="{{ $content['module_size'] ?? 'w-100' }}">
      @if ($content['scroll_text']['text'])
        <div class="module-swiper-img-scroll-text-2" style="
          background-color: {{ $content['scroll_text']['bg'] }};
          color: {{ $content['scroll_text']['color'] }};
          font-size: {{ $content['scroll_text']['font_size'] }}px;
          padding: {{ $content['scroll_text']['padding'] }}px 0;
          ">
          <div class="scroll-info">
            <span class="scroll-text">{{ $content['scroll_text']['text'] }}</span>
          </div>
        </div>
      @endif

      <div class="swiper module-swiper-img-text-{{ $module_id }} module-img-text-slideshow-2 {{ $content['module_size'] ?? 'w-100' }}" aria-roledescription="carousel" aria-label="{{ $content['title'] ?? site_brand_name() }}">
        <div class="swiper-wrapper">
          @foreach($content['images'] as $image)
            <div class="swiper-slide">
              <div class="image-wrap">
                @if(!empty($image['image']) && ($image['type'] ?? 'image') === 'video')
                  <video class="slide-media" controls preload="metadata" playsinline @if(!empty($image['image_alt'])) aria-label="{{ $image['image_alt'] }}" @endif><source src="{{ $image['image'] }}"></video>
                @elseif(!empty($image['image']))
                  <img src="{{ $image['image'] }}" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async" class="slide-media img-fluid" alt="{{ $image['image_alt'] ?? '' }}">
                @elseif($design)
                  <div class="builder-slideshow-placeholder" aria-hidden="true">请选择轮播图片或视频</div>
                @endif
              </div>
              <div class="image-text-wrap">
                <div class="container-fluid content-wrap {{ $image['text_position'] }}">
                  <div class="text-wrap" data-swiper-parallax-y="-100" data-swiper-parallax-duration="1000" data-swiper-parallax-opacity="0.5" >
                    @if ($image['sub_title'])
                      <div class="sub-title">{{ $image['sub_title'] }}</div>
                    @endif
                    @if ($image['title'])
                      <h2 class="title">{{ $image['title'] }}</h2>
                    @endif
                    @if ($image['description'])
                      <p class="description">{{ $image['description'] }}</p>
                    @endif
                    @if ($image['link']['link'])
                      <a href="{{ $image['link']['link'] }}" class="btn" @if(!empty($image['link']['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ __('shop/account.check_details') }}</a>
                    @endif
                  </div>
                </div>
              </div>
            </div>
          @endforeach
        </div>
        <div class="swiper-pagination slideshow-pagination-{{ $module_id }}"></div>
        @if (count($content['images']) > 1 && !empty($content['autoplay']))
          <button class="builder-slideshow-toggle" type="button" aria-pressed="false" aria-label="{{ __('common.slideshow_pause') }}">
            <i class="bi bi-pause-fill" aria-hidden="true"></i><span>{{ __('common.slideshow_pause') }}</span>
          </button>
        @endif
      </div>
    </div>
  </div>

  <script>
    $(function () {
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var autoplayOptions = {!! !empty($content['autoplay']) ? json_encode(['delay' => $content['autoplay_delay'], 'disableOnInteraction' => false, 'pauseOnMouseEnter' => true]) : 'false' !!};
    if (reduceMotion) autoplayOptions = false;
    var slideshow = null;
    if (typeof Swiper === "function") slideshow = new Swiper ('.module-swiper-img-text-{{ $module_id }}', {
      loop: {{ count($content['images']) > 1 ? 'true' : 'false' }},
      parallax : true,
      pauseOnMouseEnter: true,
      clickable :true,
      effect: 'fade',

      pagination: {
        el: '.slideshow-pagination-{{ $module_id }}',
        clickable: true
      },
      keyboard: { enabled: true, onlyInViewport: true },
      a11y: { enabled: true },
      autoplay: autoplayOptions,
    })

    var $toggle = $('.module-swiper-img-text-{{ $module_id }} .builder-slideshow-toggle');
    function updateToggle(paused) {
      var label = paused ? @json(__('common.slideshow_play')) : @json(__('common.slideshow_pause'));
      $toggle.attr({'aria-pressed': paused ? 'true' : 'false', 'aria-label': label});
      $toggle.find('span').text(label);
      $toggle.find('i').toggleClass('bi-pause-fill', !paused).toggleClass('bi-play-fill', paused);
    }
    if ($toggle.length) {
      updateToggle(reduceMotion);
      $toggle.on('click', function () {
        if (!slideshow || !slideshow.autoplay) return;
        var paused = slideshow.autoplay.running;
        paused ? slideshow.autoplay.stop() : slideshow.autoplay.start();
        updateToggle(paused);
      });
    }

    scrollTextFun()

      function scrollTextFun() {
        var $module = $('.module-swiper-img-text-{{ $module_id }}').prev('.module-swiper-img-scroll-text-2');
        if (!$module.length) {
          return;
        }

        var $scrollText = $module.find('.scroll-text');
        var scrollTextWidth = Math.max($scrollText.width(), 1);
        var scrollInfoWidth = $module.width();

        // 滚动速度（像素/秒）
        var speed = 50; // 可调整滚动速度
        var duration = scrollTextWidth / speed; // 动画持续时间，单位：秒

        // 计算需要重复的次数，确保内容填满容器
        var scrollCount = Math.ceil(scrollInfoWidth / scrollTextWidth) + 1;
        var $scrollInfo = $module.find('.scroll-info');
        var $copies = $();

        // Clone the already escaped text node rather than rebuilding it as an
        // HTML string; merchant announcement text must never become markup.
        for (var i = 0; i < scrollCount; i++) {
          var $copy = $scrollText.clone().css('animation-duration', duration + 's');
          if (i > 0) $copy.attr('aria-hidden', 'true');
          if (reduceMotion) $copy.css('animation', 'none');
          $copies = $copies.add($copy);
        }

        $scrollInfo.empty().append($copies);
      }
    })
  </script>
</section>
@endif
