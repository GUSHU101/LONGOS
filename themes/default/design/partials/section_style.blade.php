@php
  $moduleBackground = $content['style']['background_color'] ?? '';
@endphp
@if(is_string($moduleBackground) && preg_match('/^#[0-9A-Fa-f]{6}$/', $moduleBackground)) style="background-color: {{ strtoupper($moduleBackground) }};"@endif
