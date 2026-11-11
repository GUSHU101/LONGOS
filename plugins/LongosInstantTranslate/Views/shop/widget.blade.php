@php
  $configJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  $position = ($payload['position'] ?? 'right') === 'left' ? 'left' : 'right';
  $theme = in_array($payload['theme'] ?? 'system', ['system', 'light', 'dark'], true) ? $payload['theme'] : 'system';
@endphp
<link rel="stylesheet" href="{{ asset('plugin/longos_instant_translate/translate-widget.css') }}?v={{ $payload['version'] }}">
<div id="longos-instant-translate"
     class="lit-widget lit-widget--{{ $position }} notranslate"
     data-theme="{{ $theme }}"
     translate="no"
     hidden
     style="--lit-accent: {{ $payload['accent_color'] }}; --lit-bottom: {{ (int) $payload['bottom_offset'] }}px; --lit-mobile-bottom: {{ (int) $payload['mobile_offset'] }}px">
  <button type="button" class="lit-widget__toggle" aria-label="Choose language" aria-expanded="false" aria-controls="longos-instant-translate-panel">
    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
    <span class="lit-widget__current">EN</span>
  </button>
  <section id="longos-instant-translate-panel" class="lit-widget__panel" role="dialog" aria-modal="false" aria-labelledby="longos-instant-translate-title" hidden>
    <header class="lit-widget__header">
      <div><span class="lit-widget__eyebrow">LONGOS</span><strong id="longos-instant-translate-title">Choose language</strong></div>
      <button type="button" class="lit-widget__close" aria-label="Close language menu">×</button>
    </header>
    <label class="lit-widget__search-wrap" for="longos-instant-translate-search" hidden>
      <span class="visually-hidden">Search languages</span>
      <input id="longos-instant-translate-search" class="lit-widget__search" type="search" autocomplete="off" placeholder="Search languages">
    </label>
    <div class="lit-widget__languages" role="group" aria-label="Available languages"></div>
    <p class="lit-widget__status" role="status" aria-live="polite"></p>
  </section>
</div>
<script id="longos-instant-translate-config" type="application/json">{!! $configJson !!}</script>
<script defer src="{{ asset('plugin/longos_instant_translate/vendor/translate.min.js') }}?v={{ $payload['engine_version'] }}"></script>
<script defer src="{{ asset('plugin/longos_instant_translate/translate-widget.js') }}?v={{ $payload['version'] }}"></script>
