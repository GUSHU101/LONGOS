@php
  $widget = (array) ($settings['widget'] ?? []);
  $position = ($widget['position'] ?? 'right') === 'left' ? 'left' : 'right';
  $theme = in_array($widget['theme'] ?? 'system', ['system', 'light', 'dark'], true) ? $widget['theme'] : 'system';
  $bottom = max(16, min(240, (int) ($widget['bottom_offset'] ?? 84)));
  $mobileBottom = max(16, min(260, (int) ($widget['mobile_bottom_offset'] ?? 88)));
@endphp
<link rel="stylesheet" href="{{ asset('plugin/longos_global_translate/widget.css') }}?v=1.16.0">
<div id="longos-global-translate"
     class="lgt-widget lgt-widget--{{ $position }}"
     hidden
     data-theme="{{ $theme }}"
     data-config-url="{{ shop_route('longos_global_translate.config') }}"
     data-locale-url="{{ shop_route('longos_global_translate.locale') }}"
     data-commerce-ui="{{ !empty($commerceUi) ? '1' : '0' }}"
     style="--lgt-bottom: {{ $bottom }}px; --lgt-mobile-bottom: {{ $mobileBottom }}px">
  <button type="button" class="lgt-widget__toggle" aria-label="{{ __('LongosGlobalTranslate::translate.choose_language') }}" aria-expanded="false" aria-controls="longos-global-translate-panel">
    <svg aria-hidden="true" class="lgt-widget__globe" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
    <span class="lgt-widget__label">Language</span><span class="lgt-widget__current">EN</span>
  </button>
  <section id="longos-global-translate-panel" class="lgt-widget__panel" aria-label="{{ __('LongosGlobalTranslate::translate.choose_language') }}" aria-hidden="true">
    <div class="lgt-widget__head">
      <strong>{{ __('LongosGlobalTranslate::translate.choose_language') }}</strong>
      <button type="button" class="lgt-widget__close" aria-label="{{ __('LongosGlobalTranslate::translate.close') }}">×</button>
    </div>
    <p class="lgt-widget__hint" hidden></p>
    <label class="lgt-widget__search-wrap" hidden><span class="visually-hidden">Search languages</span><input class="lgt-widget__search" type="search" autocomplete="off" placeholder="Search languages" /></label>
    <div class="lgt-widget__options" role="group" aria-label="Available languages"></div>
    <p class="lgt-widget__status" role="status" aria-live="polite"></p>
  </section>
</div>
<script defer src="{{ asset('plugin/longos_global_translate/widget.js') }}?v=1.16.0"></script>
