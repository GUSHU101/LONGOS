@php
  $timerMode = $content['timer_mode'] ?? 'none';
  $timerVisible = in_array($timerMode, ['daily', 'fixed'], true);
@endphp
<section class="module-item section-campaign-offer {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    <div class="campaign-offer" id="campaign-offer-{{ $module_id }}" data-campaign-offer data-timer-mode="{{ $timerMode }}" data-ends-at="{{ $content['ends_at'] ?? '' }}">
      <div class="campaign-offer__header">
        <div class="campaign-offer__gift" aria-hidden="true"><i class="bi bi-gift"></i></div>
        <div class="campaign-offer__copy"><h2>{{ $content['title'] }}</h2>@if(!empty($content['sub_title']))<p>{{ $content['sub_title'] }}</p>@endif</div>
        <span
          class="campaign-offer__status"
          data-campaign-status
          data-active-label="{{ __('common.active') }}"
          data-expired-label="活动已结束"
          aria-live="polite"
        ><i aria-hidden="true"></i><span data-campaign-status-label>{{ __('common.active') }}</span></span>
      </div>
      @if ($timerVisible)
        <div class="campaign-offer__countdown" data-campaign-countdown>
          <span><strong>{{ $timerMode === 'daily' ? '每日限时优惠' : '优惠即将结束' }}</strong><small>{{ $timerMode === 'daily' ? '距下一次重置' : '剩余时间' }}</small></span>
          <time data-campaign-time aria-label="剩余时间">--:--:--</time>
        </div>
      @endif
      <div class="campaign-offer__tiers">
        @forelse($content['offers'] ?? [] as $offer)
          <article class="campaign-offer__tier {{ !empty($offer['best']) ? 'is-best' : '' }}">
            @if(!empty($offer['badge']))<span class="campaign-offer__badge">{{ $offer['badge'] }}</span>@endif
            <span class="campaign-offer__quantity"><strong>{{ $offer['quantity'] }}+</strong><small>件</small></span>
            <div><span class="campaign-offer__condition">{{ $offer['condition'] }}</span><strong class="campaign-offer__benefit">{{ $offer['benefit'] }}</strong>@if(!empty($offer['description']))<small>{{ $offer['description'] }}</small>@endif @if(!empty($offer['free_shipping']))<em><i class="bi bi-truck" aria-hidden="true"></i> 免运费</em>@endif</div>
          </article>
        @empty
          @if($design)<div class="custom-code-empty">请添加优惠阶梯以在此处展示。</div>@endif
        @endforelse
      </div>
      @if(!empty($content['code']))
        <div class="campaign-offer__code"><div><span>{{ $content['code_label'] ?: '优惠码' }}</span><strong>{{ $content['code'] }}</strong></div><button type="button" data-copy-code="{{ $content['code'] }}" aria-label="复制优惠码 {{ $content['code'] }}"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-label aria-live="polite">复制</span></button></div>
      @endif
      @if(!empty($content['terms']))<p class="campaign-offer__terms"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ $content['terms'] }}</p>@endif
    </div>
  </div>
</section>
