@php
  $widget = (array) ($settings['widget'] ?? []);
  $position = ($widget['position'] ?? 'right') === 'left' ? 'left' : 'right';
  $theme = in_array($widget['theme'] ?? 'system', ['system', 'light', 'dark'], true) ? $widget['theme'] : 'system';
  $bottom = max(16, min(240, (int) ($widget['bottom_offset'] ?? 84)));
  $mobileBottom = max(16, min(260, (int) ($widget['mobile_bottom_offset'] ?? 88)));
@endphp
<link rel="stylesheet" href="{{ asset('plugin/longos_global_translate/widget.css') }}?v=1.5.0">
<div id="longos-global-translate"
     class="lgt-widget lgt-widget--{{ $position }}"
     hidden
     data-theme="{{ $theme }}"
     data-config-url="{{ shop_route('longos_global_translate.config') }}"
     data-locale-url="{{ shop_route('longos_global_translate.locale') }}"
     data-commerce-ui="{{ !empty($commerceUi) ? '1' : '0' }}"
     style="--lgt-bottom: {{ $bottom }}px; --lgt-mobile-bottom: {{ $mobileBottom }}px">
  <button type="button" class="lgt-widget__toggle" aria-label="{{ __('LongosGlobalTranslate::translate.choose_language') }}" aria-expanded="false" aria-controls="longos-global-translate-panel">
    <span aria-hidden="true">Aあ</span><span class="lgt-widget__current">EN</span>
  </button>
  <section id="longos-global-translate-panel" class="lgt-widget__panel" aria-label="{{ __('LongosGlobalTranslate::translate.choose_language') }}" aria-hidden="true">
    <div class="lgt-widget__head">
      <strong>{{ __('LongosGlobalTranslate::translate.choose_language') }}</strong>
      <button type="button" class="lgt-widget__close" aria-label="{{ __('LongosGlobalTranslate::translate.close') }}">×</button>
    </div>
    <p class="lgt-widget__hint">{{ __('LongosGlobalTranslate::translate.presentation_only') }}</p>
    <div class="lgt-widget__options" role="list"></div>
    <p class="lgt-widget__status" role="status" aria-live="polite"></p>
  </section>
</div>
<script defer src="{{ asset('plugin/longos_global_translate/widget.js') }}?v=1.5.0"></script>
