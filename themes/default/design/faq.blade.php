<section class="module-item section-faq section-faq--{{ $content['style_variant'] ?? 'divided' }} {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @if(!empty($content['title'])) aria-labelledby="module-heading-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    @if (!empty($content['title']))<h2 class="section-heading" id="module-heading-{{ $module_id }}">{{ $content['title'] }}</h2>@endif
    @if (!empty($content['sub_title']))<p class="section-subheading">{{ $content['sub_title'] }}</p>@endif
    <div class="faq-list">
      @forelse($content['items'] ?? [] as $index => $item)
        @php
          $collapseId = 'faq-' . $module_id . '-' . $index;
          $isOpen = !empty($content['open_first']) && $index === 0;
        @endphp
        <article class="faq-item">
          <h3 class="faq-question mb-0" id="{{ $collapseId }}-label">
            <button class="faq-toggle {{ $isOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
              <span>{{ $item['question'] }}</span><i class="bi bi-plus-lg" aria-hidden="true"></i>
            </button>
          </h3>
          <div id="{{ $collapseId }}" class="collapse {{ $isOpen ? 'show' : '' }}" aria-labelledby="{{ $collapseId }}-label">
            <div class="faq-answer">{!! nl2br(e($item['answer'])) !!}</div>
          </div>
        </article>
      @empty
        @if ($design)<div class="custom-code-empty">{{ __('shop/design.empty.faq') }}</div>@endif
      @endforelse
    </div>
  </div>
</section>
