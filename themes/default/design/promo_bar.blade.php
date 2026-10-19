@php
  $background = $content['style']['background_color'] ?? '#000000';
  $background = preg_match('/^#[0-9A-Fa-f]{6}$/', $background) ? $background : '#000000';
@endphp
<section class="module-item section-promo-bar {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif style="--promo-background: {{ $background }}; background-color: {{ $background }};">
  <div class="{{ $content['module_size'] }} py-3">
    <div class="promo-bar">
      <div class="promo-bar__copy"><h2 class="promo-bar__title" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>@if($content['sub_title'])<p class="promo-bar__subtitle">{{ $content['sub_title'] }}</p>@endif</div>
      @if($content['link'])<a href="{{ $content['link'] }}" class="btn btn-light promo-bar__action" @if(!empty($content['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ $content['button_text'] }}</a>@endif
    </div>
  </div>
</section>
