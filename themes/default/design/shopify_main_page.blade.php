<section class="module-item section-shopify-main-page {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-main-page-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-main-page-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <article class="shopify-main-page">
      @if(!empty($content['page_content']['title']))<h1 class="shopify-main-page__heading">{{ $content['page_content']['title'] }}</h1>@endif
      @if(!empty($content['page_content']['html']))<div class="shopify-main-page__body">{!! $content['page_content']['html'] !!}</div>
      @elseif($design)<div class="shopify-main-page__empty">请选择已启用的 LONGOS 内容页以展示其内容。</div>@endif
    </article>
  </div>
</section>
