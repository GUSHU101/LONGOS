@php
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-social-proof {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}"><div class="social-proof social-proof--{{ $content['theme'] ?? 'ink' }}">
    <div class="social-proof__intro">
      @if (!empty($content['title']))
        <h2 data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</h2>
      @endif
      @if (!empty($content['sub_title']))
        <p data-lgt-field="module.{{ $module_id }}.sub_title">{{ $content['sub_title'] }}</p>
      @endif
    </div>
    <div class="social-proof__items">
      @forelse ($content['items'] ?? [] as $item)
        <article>
          <strong>{{ $item['value'] }}</strong>
          <span data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.label">{{ $item['label'] }}</span>
          @if (!empty($item['detail']))
            <small data-lgt-field="module.{{ $module_id }}.items.{{ $loop->index }}.detail">{{ $item['detail'] }}</small>
          @endif
        </article>
      @empty
        @if ($design)
          <div class="custom-code-empty">{{ __('shop/design.empty.social_proof') }}</div>
        @endif
      @endforelse
    </div>
  </div></div>
</section>
