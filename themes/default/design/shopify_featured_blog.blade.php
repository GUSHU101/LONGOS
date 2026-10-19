<section class="module-item section-shopify-featured-blog {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" style="--shopify-featured-blog-padding-top: {{ (int) ($content['padding_top'] ?? 36) }}px; --shopify-featured-blog-padding-bottom: {{ (int) ($content['padding_bottom'] ?? 36) }}px;" @include('design.partials.section_style')>
  <div class="container">
    <div class="shopify-featured-blog shopify-featured-blog--desktop-{{ $content['columns_desktop'] }}">
      @if(!empty($content['heading']))<h2 class="shopify-featured-blog__heading shopify-featured-blog__heading--{{ $content['heading_size'] }}">{{ $content['heading'] }}</h2>@endif
      <div class="shopify-featured-blog__viewport" tabindex="0" aria-label="Featured articles">
        <ul class="shopify-featured-blog__grid">
          @forelse($content['articles'] as $article)
            <li class="shopify-featured-blog__item">
              <article class="shopify-featured-blog__card">
                @if(!empty($content['show_image']))<div class="shopify-featured-blog__media">@if(!empty($article['image']))<img src="{{ $article['image'] }}" alt="" loading="lazy" decoding="async">@else<div class="shopify-featured-blog__placeholder" aria-hidden="true"></div>@endif</div>@endif
                <div class="shopify-featured-blog__content">
                  @if(!empty($content['show_date']) || (!empty($content['show_author']) && !empty($article['author'])))<p class="shopify-featured-blog__meta">@if(!empty($content['show_date']) && !empty($article['date']))<time datetime="{{ $article['date'] }}">{{ $article['date'] }}</time>@endif @if(!empty($content['show_author']) && !empty($article['author']))<span>{{ $article['author'] }}</span>@endif</p>@endif
                  @if(!empty($article['title']))<h3>@if(!empty($article['url']))<a href="{{ $article['url'] }}">{{ $article['title'] }}</a>@else{{ $article['title'] }}@endif</h3>@endif
                  @if(!empty($article['summary']))<p class="shopify-featured-blog__summary">{{ $article['summary'] }}</p>@endif
                  @if(!empty($article['url']))<a class="shopify-featured-blog__read" href="{{ $article['url'] }}">阅读文章 <span aria-hidden="true">→</span></a>@endif
                </div>
              </article>
            </li>
          @empty
            @if($design)<li class="shopify-featured-blog__empty">请选择已启用的 LONGOS 内容分类以展示最新文章。</li>@endif
          @endforelse
        </ul>
      </div>
      @if(!empty($content['show_view_all']) && !empty($content['view_all_url']))<div class="shopify-featured-blog__action"><a href="{{ $content['view_all_url'] }}" class="btn btn-primary">查看全部文章</a></div>@endif
    </div>
  </div>
</section>
