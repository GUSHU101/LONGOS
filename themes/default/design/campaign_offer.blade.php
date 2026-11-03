@php
  $timerMode = $content['timer_mode'] ?? 'none';
  $timerVisible = in_array($timerMode, ['daily', 'fixed'], true);
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-campaign-offer {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @if(!empty($content['title'])) aria-labelledby="campaign-title-{{ $module_id }}" @endif @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    <div class="campaign-offer" id="campaign-offer-{{ $module_id }}" data-campaign-offer data-timer-mode="{{ $timerMode }}" data-ends-at="{{ $content['ends_at'] ?? '' }}" data-copy-label="{{ __('shop/design.copy') }}" data-copy-success="{{ __('shop/design.copied') }}" data-copy-failed="{{ __('shop/design.copy_failed') }}">
      <header class="campaign-offer__header">
        <div class="campaign-offer__gift" aria-hidden="true"><i class="bi bi-gift"></i></div>
        <div class="campaign-offer__copy"><h2 id="campaign-title-{{ $module_id }}" data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</h2>@if(!empty($content['sub_title']))<p data-lgt-field="module.{{ $module_id }}.sub_title">{{ $content['sub_title'] }}</p>@endif</div>
        <span
          class="campaign-offer__status"
          data-campaign-status
          data-active-label="{{ __('shop/design.campaign_active') }}"
          data-expired-label="{{ __('shop/design.campaign_expired') }}"
          aria-live="polite"
        ><i aria-hidden="true"></i><span data-campaign-status-label>{{ __('shop/design.campaign_active') }}</span></span>
      </header>
      @if ($timerVisible)
        <div class="campaign-offer__countdown" data-campaign-countdown>
          <span><strong>{{ $timerMode === 'daily' ? __('shop/design.campaign_daily') : __('shop/design.campaign_ending') }}</strong><small>{{ $timerMode === 'daily' ? __('shop/design.until_daily_reset') : __('shop/design.time_remaining') }}</small></span>
          <time data-campaign-time aria-label="{{ __('shop/design.time_remaining') }}">--:--:--</time>
        </div>
      @endif
      <div class="campaign-offer__tiers" role="list">
        @forelse($content['offers'] ?? [] as $offer)
          <article class="campaign-offer__tier {{ !empty($offer['best']) ? 'is-best' : '' }}" role="listitem">
            @if(!empty($offer['badge']))<span class="campaign-offer__badge" data-lgt-field="module.{{ $module_id }}.offers.{{ $loop->index }}.badge">{{ $offer['badge'] }}</span>@endif
            <span class="campaign-offer__quantity"><strong>{{ $offer['quantity'] }}+</strong><small class="campaign-offer__quantity-unit">{{ __('shop/design.item_unit') }}</small></span>
            <div class="campaign-offer__tier-copy"><span class="campaign-offer__condition" data-lgt-field="module.{{ $module_id }}.offers.{{ $loop->index }}.condition">{{ $offer['condition'] }}</span><strong class="campaign-offer__benefit" data-lgt-field="module.{{ $module_id }}.offers.{{ $loop->index }}.benefit">{{ $offer['benefit'] }}</strong>@if(!empty($offer['description']))<small class="campaign-offer__description" data-lgt-field="module.{{ $module_id }}.offers.{{ $loop->index }}.description">{{ $offer['description'] }}</small>@endif @if(!empty($offer['free_shipping']))<em class="campaign-offer__shipping"><i class="bi bi-truck" aria-hidden="true"></i> {{ __('shop/design.free_shipping') }}</em>@endif</div>
          </article>
        @empty
          @if($design)<div class="custom-code-empty">{{ __('shop/design.empty.campaign_tiers') }}</div>@endif
        @endforelse
      </div>
      @if(!empty($content['code']))
        <div class="campaign-offer__code"><div class="campaign-offer__code-copy"><span class="campaign-offer__code-label" data-lgt-field="module.{{ $module_id }}.code_label">{{ $content['code_label'] ?: __('shop/design.discount_code') }}</span><strong>{{ $content['code'] }}</strong></div><button type="button" data-copy-code="{{ $content['code'] }}" aria-label="{{ __('shop/design.copy_discount_code', ['code' => $content['code']]) }}"><i class="bi bi-files" aria-hidden="true"></i><span class="campaign-offer__code-action" data-copy-label aria-live="polite">{{ __('shop/design.copy') }}</span></button></div>
      @endif
      @if(!empty($content['terms']))<p class="campaign-offer__terms"><i class="bi bi-check2-circle" aria-hidden="true"></i><span data-lgt-field="module.{{ $module_id }}.terms">{{ $content['terms'] }}</span></p>@endif
    </div>
  </div>
</section>
