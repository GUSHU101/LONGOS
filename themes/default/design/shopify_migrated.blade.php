@php
  $asText = static function (mixed $value): string {
      if (is_array($value)) {
          $value = $value[locale()] ?? reset($value) ?? '';
      }
      return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
  };
  $title = $asText($content['title'] ?? $content['heading'] ?? $content['_builder']['name'] ?? 'Shopify section');
  $description = $asText($content['sub_title'] ?? $content['description'] ?? $content['text'] ?? '');
  $button = $asText($content['button_text'] ?? $content['button_label'] ?? '');
@endphp
<section class="module-item section-shopify-migrated {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="{{ $content['module_size'] ?? 'container-fluid' }}">
    <div class="section-shopify-migrated__content">
      <p class="section-shopify-migrated__eyebrow">已迁移 Shopify 模块</p>
      <h2>{{ $title }}</h2>
      @if ($description)<p>{{ $description }}</p>@endif
      @if ($button)<span class="btn btn-primary" aria-hidden="true">{{ $button }}</span>@endif
    </div>
  </div>
</section>
