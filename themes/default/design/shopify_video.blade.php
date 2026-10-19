@php
  $title = $content['heading'] ?? '';
  $description = $content['description'] ?? '';
  $video = $content['video'] ?? '';
  $embed = $content['external_embed'] ?? '';
  $poster = $content['cover_image'] ?? '';
  $hasVideo = is_string($video) && $video !== '' || is_string($embed) && $embed !== '';
  $headingClass = $content['heading_size'] ?? 'h1';
@endphp
<section class="module-item section-shopify-video {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-video-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-video-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="module-info {{ !empty($content['full_width']) ? 'container-fluid' : 'container' }}">
    <div class="shopify-video {{ !empty($content['full_width']) ? 'shopify-video--full' : '' }}">
      @if ($title)<h2 class="shopify-video__heading shopify-video__heading--{{ $headingClass }}">{{ $title }}</h2>@endif
      @if ($hasVideo)
        <div class="shopify-video__media">
          @if ($video)
            <video controls preload="metadata" @if($poster) poster="{{ $poster }}" @endif @if(!empty($content['enable_video_looping'])) loop @endif>
              <source src="{{ $video }}" type="video/mp4">
              你的浏览器不支持 HTML 视频播放。
            </video>
          @else
            <iframe src="{{ $embed }}" title="{{ $description ?: $title ?: 'Video' }}" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
          @endif
        </div>
      @elseif ($design)
        <div class="shopify-video__empty">请选择媒体库视频，或填写 YouTube/Vimeo 视频地址。</div>
      @endif
      @if ($description)<p class="shopify-video__description">{{ $description }}</p>@endif
    </div>
  </div>
</section>
