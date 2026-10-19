<section class="module-item section-shopify-page {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-page-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-page-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <article class="shopify-page">
      @if(!empty($content['page_content']['title']))<h2 class="shopify-page__heading shopify-page__heading--{{ $content['heading_size'] }}">{{ $content['page_content']['title'] }}</h2>@endif
      @if(!empty($content['page_content']['html']))<div class="shopify-page__body">{!! $content['page_content']['html'] !!}</div>
      @elseif($design)<div class="shopify-page__empty">请选择已启用的 LONGOS 内容页以展示其内容。</div>@endif
    </article>
  </div>
</section>
