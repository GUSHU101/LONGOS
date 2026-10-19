@php
  $brandStyle = site_brand_style();
@endphp
<style id="longos-native-admin-brand">
  :root {
    --longos-primary: {{ $brandStyle['primary_color'] }};
    --longos-accent: {{ $brandStyle['accent_color'] }};
    --longos-radius: {{ $brandStyle['border_radius'] }}px;
  }
  .btn-primary, .bg-primary { background-color: var(--longos-primary) !important; border-color: var(--longos-primary) !important; }
  .btn-outline-primary { color: var(--longos-primary); border-color: var(--longos-primary); }
  .btn-outline-primary:hover { color: #fff; background-color: var(--longos-primary); border-color: var(--longos-primary); }
  .nav-pills .nav-link.active, .sidebar-box .nav-link.active { background-color: var(--longos-primary) !important; }
  .btn, .form-control, .form-select, .card, .dropdown-menu, .modal-content { border-radius: var(--longos-radius); }
  .form-control:focus, .form-select:focus { border-color: var(--longos-primary); box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--longos-primary) 20%, transparent); }
</style>
