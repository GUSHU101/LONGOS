<section class="module-item section-image-with-text {{ $design ? 'module-item-design' : ''}}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info  ">
    <div class="img-text-banner-wrap {{ $content['module_size'] ?? 'container-fluid' }}">
      <div class="row {{ $content['image_position'] == 'left' ? 'flex-row-reverse' : '' }} mx-0">
        <div class="col-12 col-lg-6 px-0">
          <div class="text-wrap {{ $content['text_position'] == 'left' ? 'align-items-start text-start' : ($content['text_position'] == 'center' ? 'align-items-center text-center' : 'align-items-end text-end') }}" style="background-color: {{ $content['bg_color'] }}; color: {{ $content['text_color'] ?? '#222' }}">
            <div style="max-width: {{ $content['text_max_width'] ?? '1900' }}px">
              @if ($content['title'])
                <h2 class="title">{{ $content['title'] }}</h2>
              @endif
              @if ($content['sub_title'])
              <div class="fs-4 sub-title mb-4">{{ $content['sub_title'] }}</div>
              @endif
              <p class="description">{{ $content['description'] }}</p>
              @if ($content['link'])
                <a href="{{ $content['link'] }}" class="btn btn-lg" style="background-color: {{ $content['btn_bg'] ?? '#fd560f' }}; color: {{ $content['btn_color'] ?? '#fff' }}">{{ $content['button_text'] }}</a>
              @endif
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6 px-0">
          <div class="img-wrap">
            @php
              // v2 uses explicit responsive sources; v1 `image` remains a
              // compatible fallback for previously published sections.
              $desktopImage = $content['desktop_image'] ?? $content['image'] ?? '';
              $mobileImage = $content['mobile_image'] ?? '';
            @endphp
            <picture>
              @if ($mobileImage)<source media="(max-width: 767px)" srcset="{{ $mobileImage }}">@endif
              <img src="{{ $desktopImage }}" class="img-fluid seo-img" alt="{{ $content['image_alt'] ?? '' }}" loading="lazy" decoding="async">
            </picture>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
