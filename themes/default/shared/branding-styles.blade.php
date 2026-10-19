@php
  $brandStyle = site_brand_style();
  $preset = [
    'minimal' => ['surface' => '#FFFFFF', 'subtle' => '#F6F7F9'],
    'soft' => ['surface' => '#FBFAF7', 'subtle' => '#F3F7F3'],
    'editorial' => ['surface' => '#FCFBF8', 'subtle' => '#F4F0E9'],
  ][$brandStyle['preset']];
@endphp
<style id="longos-native-store-brand">
  :root {
    --longos-primary: {{ $brandStyle['primary_color'] }};
    --longos-accent: {{ $brandStyle['accent_color'] }};
    --longos-radius: {{ $brandStyle['border_radius'] }}px;
    --longos-surface: {{ $preset['surface'] }};
    --longos-subtle: {{ $preset['subtle'] }};
  }
  .btn-primary, .bg-primary { background-color: var(--longos-primary) !important; border-color: var(--longos-primary) !important; }
  .btn-outline-primary { color: var(--longos-primary); border-color: var(--longos-primary); }
  .btn-outline-primary:hover { color: #fff; background-color: var(--longos-primary); border-color: var(--longos-primary); }
  .btn, .form-control, .form-select, .card, .dropdown-menu { border-radius: var(--longos-radius); }
  .form-control:focus, .form-select:focus { border-color: var(--longos-primary); box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--longos-primary) 20%, transparent); }
</style>
