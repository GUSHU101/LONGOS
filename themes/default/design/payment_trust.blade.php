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
@endphp
<section class="module-item section-payment-trust {{ $design ? 'module-item-design' : '' }}" id="module-{{ $module_id }}" @include('design.partials.section_style')>
  <div class="module-info {{ $content['module_size'] ?? 'container-fluid' }}">
    <div class="payment-trust">
      <div class="payment-trust__head"><span class="payment-trust__shield" aria-hidden="true"><i class="bi bi-shield-check"></i></span><div><strong>{{ $content['title'] }}</strong>@if(!empty($content['sub_title']))<small>{{ $content['sub_title'] }}</small>@endif</div>@if(!empty($content['status_text']))<span class="payment-trust__status"><i aria-hidden="true"></i>{{ $content['status_text'] }}</span>@endif</div>
      <div class="payment-trust__benefits" role="list">
        @foreach($content['benefits'] ?? [] as $benefit)<div class="payment-trust__benefit" role="listitem"><span class="{{ !empty($benefit['gold']) ? 'is-gold' : '' }}">{{ $benefit['icon'] }}</span><div><strong>{{ $benefit['title'] }}</strong><small>{{ $benefit['text'] }}</small></div></div>@endforeach
      </div>
      @if($visiblePaymentMarks !== [])
        <div class="payment-trust__payments"><div><strong>{{ $content['payments_title'] }}</strong>@if(!empty($content['payments_sub']))<small>{{ $content['payments_sub'] }}</small>@endif</div><div class="payment-trust__grid" role="list">@foreach($visiblePaymentMarks as $payment)<span class="payment-trust__payment payment-trust__payment--{{ $payment }}" role="listitem" aria-label="{{ $paymentLabels[$payment] }}">{{ $paymentLabels[$payment] }}</span>@endforeach</div></div>
      @endif
    </div>
  </div>
</section>
