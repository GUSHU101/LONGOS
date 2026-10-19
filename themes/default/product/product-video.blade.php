@if ($product['video'])
  <div class="video-wrap">
    @php
      $videoHost = strtolower((string) parse_url($product['video'], PHP_URL_HOST));
      $videoHost = preg_replace('/^www\./', '', $videoHost);
      $isYouTube = in_array($videoHost, ['youtube.com', 'm.youtube.com', 'youtu.be'], true);
    @endphp

    @if ($isYouTube)
      <div id="product-video"></div>
    @else
      <video
        id="product-video"
        class="video-js vjs-big-play-centered vjs-fluid vjs-16-9"
        controls loop muted
      >
        <source src="{{ image_origin($product['video']) }}" type="video/mp4"/>
      </video>
    @endif

    <div class="close-video d-none"><i class="bi bi-x-circle"></i></div>
    <div class="open-video"><i class="bi bi-play-circle"></i></div>
  </div>
@endif


@push('add-scripts')
  <script>
    const videoUrl = {{ Js::from((string) $product['video']) }};
    const videoId = (function(url) {
      try {
        const parsed = new URL(url);
        const hostname = parsed.hostname.toLowerCase().replace(/^www\./, '');
        let candidate = null;

        if (hostname === 'youtu.be') candidate = parsed.pathname.split('/').filter(Boolean)[0] || null;
        if (['youtube.com', 'm.youtube.com'].includes(hostname)) candidate = parsed.searchParams.get('v');

        return candidate && /^[A-Za-z0-9_-]{6,20}$/.test(candidate) ? candidate : null;
      } catch (e) { return null; }
    })(videoUrl);

    const isYouTube = !!videoId;
    let pVideo = null;

    $(function () {
      // 点击播放
      if ($('#product-video').length && !isYouTube) {
        pVideo = videojs("product-video");
      }

      $(document).on('click', '.open-video', function () {
        if (isYouTube) {
          const iframe = document.createElement('iframe');
          iframe.width = '100%';
          iframe.height = '100%';
          iframe.src = `https://www.youtube.com/embed/${encodeURIComponent(videoId)}?autoplay=1&mute=1`;
          iframe.title = 'Product video';
          iframe.referrerPolicy = 'strict-origin-when-cross-origin';
          iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
          iframe.allowFullscreen = true;
          document.getElementById('product-video').replaceChildren(iframe);
        } else if ($('#product-video').length) {
          pVideo.play();
          pVideo.currentTime(0);
        }

        $(this).addClass('d-none');
        $('#product-video').fadeIn();
        $('.close-video').removeClass('d-none');
      });

      // 点击关闭
      $(document).on('click', '.close-video', function () {
        if (isYouTube) {
          $('#product-video').fadeOut();
        } else if (pVideo) {
          pVideo.pause();
          $('#product-video').fadeOut();
        }

        $('.close-video').addClass('d-none');
        $('.open-video').removeClass('d-none');
      });
    });

    function closeVideo() {
      if (pVideo) {
        pVideo.pause();
      }

      $('.close-video').addClass('d-none');
      $('.open-video').removeClass('d-none');
      $('#product-video').fadeOut();
    }
  </script>
@endpush
