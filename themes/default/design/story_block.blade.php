@php
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-story-block {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    <article class="story-block story-block--{{ $content['theme'] ?? 'light' }} {{ ($content['image_position'] ?? 'right') === 'left' ? 'story-block--image-left' : '' }}">
      <div class="story-block__copy">
        @if(!empty($content['eyebrow']))<p class="story-block__eyebrow" data-lgt-field="module.{{ $module_id }}.eyebrow">{{ $content['eyebrow'] }}</p>@endif
        @if(!empty($content['title']))<h2 data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</h2>@endif
        @if(!empty($content['description']))<p class="story-block__description" data-lgt-field="module.{{ $module_id }}.description">{{ $content['description'] }}</p>@endif
        @if(!empty($content['link']))<a href="{{ $content['link'] }}" class="story-block__action" @if(!empty($content['new_window'])) target="_blank" rel="noopener noreferrer" @endif><span data-lgt-field="module.{{ $module_id }}.button_text">{{ $content['button_text'] ?: __('common.view_more') }}</span> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>@endif
      </div>
      <div class="story-block__media">@if(!empty($content['image']))<img src="{{ $content['image'] }}" alt="{{ $content['image_alt'] ?? '' }}" loading="lazy" decoding="async">@elseif($design)<div class="custom-code-empty">{{ __('shop/design.empty.story_image') }}</div>@endif</div>
    </article>
  </div>
</section>
