@php
  $ads = $content['announcements'] ?? [];
@endphp

@if (! empty($ads['active']) && ! empty($ads['items']))
  <section class="module-item section-shopify-announcement-bar"
           style="background:{{ $ads['bg_color'] ?? '' }};color:{{ $ads['color'] ?? '' }}">
    <div class="container">
      @foreach ($ads['items'] as $item)
        @php
          $text = $item['title'][locale()] ?? $item['title']['en'] ?? '';
          $link = '';

          try {
              $link = handle_link($item['link'] ?? [])['link'] ?? '';
          } catch (\Throwable) {
              $link = '';
          }
        @endphp

        @if ($text)
          @if ($link)
            <a href="{{ $link }}" @if (! empty($item['link']['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ $text }}</a>
          @else
            <span>{{ $text }}</span>
          @endif
        @endif
      @endforeach
    </div>
  </section>
@endif
