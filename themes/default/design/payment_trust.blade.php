@php
  $paymentLabels = ['visa' => 'VISA', 'mastercard' => 'mastercard', 'amex' => 'AMEX', 'paypal' => 'PayPal', 'apple_pay' => ' Pay', 'google_pay' => 'G Pay', 'shop_pay' => 'Shop Pay', 'klarna' => 'Klarna.', 'afterpay' => 'Afterpay', 'unionpay' => 'UnionPay', 'jcb' => 'JCB', 'discover' => 'DISCOVER', 'diners' => 'Diners', 'ideal' => 'iDEAL', 'bancontact' => 'Bancontact', 'sofort' => 'SOFORT', 'giropay' => 'giropay', 'cartes_bancaires' => 'CB'];
  $configuredPaymentMarks = is_array($content['payments'] ?? null) ? $content['payments'] : [];
  $storefrontPaymentMarks = is_array($footer_content['bottom']['enabled_payments'] ?? null)
    ? $footer_content['bottom']['enabled_payments']
    : [];
  $visiblePaymentMarks = array_values(array_intersect(
    array_keys($paymentLabels),
    $configuredPaymentMarks,
    $storefrontPaymentMarks,
  ));
  $lgtEntity = (int) hexdec(substr(hash('sha256', 'lgt-builder:' . $module_id), 0, 15));
@endphp
<section class="module-item section-payment-trust {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" data-lgt-root data-lgt-kind="builder" data-lgt-entity="{{ $lgtEntity }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    <div class="payment-trust">
      <div class="payment-trust__head"><span class="payment-trust__shield" aria-hidden="true"><i class="bi bi-shield-check"></i></span><div><strong data-lgt-field="module.{{ $module_id }}.title">{{ $content['title'] }}</strong>@if(!empty($content['sub_title']))<small data-lgt-field="module.{{ $module_id }}.sub_title">{{ $content['sub_title'] }}</small>@endif</div>@if(!empty($content['status_text']))<span class="payment-trust__status"><i aria-hidden="true"></i><span data-lgt-field="module.{{ $module_id }}.status_text">{{ $content['status_text'] }}</span></span>@endif</div>
      <div class="payment-trust__benefits" role="list">
        @foreach($content['benefits'] ?? [] as $benefit)<div class="payment-trust__benefit" role="listitem"><span class="{{ !empty($benefit['gold']) ? 'is-gold' : '' }}">{{ $benefit['icon'] }}</span><div><strong data-lgt-field="module.{{ $module_id }}.benefits.{{ $loop->index }}.title">{{ $benefit['title'] }}</strong><small data-lgt-field="module.{{ $module_id }}.benefits.{{ $loop->index }}.text">{{ $benefit['text'] }}</small></div></div>@endforeach
      </div>
      @if($visiblePaymentMarks !== [])
        <div class="payment-trust__payments"><div><strong data-lgt-field="module.{{ $module_id }}.payments_title">{{ $content['payments_title'] }}</strong>@if(!empty($content['payments_sub']))<small data-lgt-field="module.{{ $module_id }}.payments_sub">{{ $content['payments_sub'] }}</small>@endif</div><div class="payment-trust__grid" role="list">@foreach($visiblePaymentMarks as $payment)<span class="payment-trust__payment payment-trust__payment--{{ $payment }}" role="listitem" aria-label="{{ $paymentLabels[$payment] }}">{{ $paymentLabels[$payment] }}</span>@endforeach</div></div>
      @endif
    </div>
  </div>
</section>
