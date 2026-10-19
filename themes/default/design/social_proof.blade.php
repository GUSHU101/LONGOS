<section class="module-item section-social-proof {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}"><div class="social-proof social-proof--{{ $content['theme'] ?? 'ink' }}">
    <div class="social-proof__intro">
      @if (!empty($content['title']))
        <h2>{{ $content['title'] }}</h2>
      @endif
      @if (!empty($content['sub_title']))
        <p>{{ $content['sub_title'] }}</p>
      @endif
    </div>
    <div class="social-proof__items">
      @forelse ($content['items'] ?? [] as $item)
        <article>
          <strong>{{ $item['value'] }}</strong>
          <span>{{ $item['label'] }}</span>
          @if (!empty($item['detail']))
            <small>{{ $item['detail'] }}</small>
          @endif
        </article>
      @empty
        @if ($design)
          <div class="custom-code-empty">请添加信任背书内容以在此处展示。</div>
        @endif
      @endforelse
    </div>
  </div></div>
</section>
