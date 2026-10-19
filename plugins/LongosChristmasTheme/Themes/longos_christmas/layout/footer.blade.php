@php
  $footerIntro = is_array($footer_content['content']['intro'] ?? null) ? $footer_content['content']['intro'] : [];
  $policyProfile = system_setting('base.store_policy_profile', []);
  $policyProfile = is_array($policyProfile) ? $policyProfile : [];
  $footerContact = is_array($footer_content['content']['contact'] ?? null) ? $footer_content['content']['contact'] : [];
  $footerEmail = trim((string) (($policyProfile['contact_email'] ?? '') ?: system_setting('base.email', '')));
  $footerPhone = trim((string) (($footerContact['telephone'] ?? '') ?: ($policyProfile['contact_phone'] ?? '') ?: system_setting('base.telephone', '')));
  $footerAddress = trim((string) (($footerContact['address'] ?? '') ?: ($policyProfile['business_address'] ?? '')));
  $storePolicies = array_values(array_filter(is_array($store_policies ?? null) ? $store_policies : [], static fn ($policy) => is_array($policy) && !empty($policy['url']) && !empty($policy['title'])));
  $customGroups = [];
  foreach (['link1', 'link2', 'link3'] as $groupKey) {
    $group = is_array($footer_content['content'][$groupKey] ?? null) ? $footer_content['content'][$groupKey] : [];
    $links = array_values(array_filter(is_array($group['links'] ?? null) ? $group['links'] : [], static fn ($link) => is_array($link) && !empty($link['link']) && trim((string) ($link['text'] ?? '')) !== ''));
    if (trim((string) ($group['title'] ?? '')) !== '' && $links) $customGroups[] = ['title' => (string) $group['title'], 'links' => $links];
  }
  $paymentLabels = ['visa' => 'VISA', 'mastercard' => 'Mastercard', 'amex' => 'AMEX', 'paypal' => 'PayPal', 'apple_pay' => 'Apple Pay', 'google_pay' => 'G Pay', 'shop_pay' => 'Shop Pay', 'klarna' => 'Klarna', 'afterpay' => 'Afterpay', 'unionpay' => 'UnionPay', 'jcb' => 'JCB', 'discover' => 'Discover', 'diners' => 'Diners Club', 'ideal' => 'iDEAL', 'bancontact' => 'Bancontact', 'sofort' => 'SOFORT', 'giropay' => 'giropay', 'cartes_bancaires' => 'CB'];
  $paymentMethods = !empty($footer_content['bottom']['payments_enable']) && is_array($footer_content['bottom']['enabled_payments'] ?? null) ? array_values(array_filter($footer_content['bottom']['enabled_payments'], static fn ($payment) => isset($paymentLabels[$payment]))) : [];
  $contactDialogId = 'christmas-footer-contact-dialog';
@endphp

<footer class="site-footer christmas-footer" data-longos-footer>
  @hook('footer.before')
  <div class="christmas-shell christmas-footer__inner">
    <div class="christmas-footer__lead">
      <div class="christmas-footer__brand">
        <a href="{{ shop_route('home.index') }}">
          @if (!empty($footerIntro['logo']))<img src="{{ image_origin($footerIntro['logo']) }}" alt="{{ site_brand_name() }}" loading="lazy" decoding="async">@else<span>{{ site_brand_name() }}</span>@endif
        </a>
        <p>{{ trans('LongosChristmasTheme::theme.footer_note') }}</p>
      </div>
      <a class="christmas-footer__track" href="{{ shop_route('tracking.index') }}"><i class="bi bi-truck" aria-hidden="true"></i><span><small>{{ trans('LongosChristmasTheme::theme.tracking_title') }}</small><strong>{{ __('shop/tracking.title') }}</strong></span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>

    <div class="christmas-footer__grid">
      @foreach ($customGroups as $groupIndex => $group)
        <section class="christmas-footer__column footer-link-wrap">
          <h2><span>{{ $group['title'] }}</span><button class="footer-toggle" type="button" aria-expanded="false" aria-controls="christmas-footer-custom-{{ $groupIndex }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></h2>
          <nav id="christmas-footer-custom-{{ $groupIndex }}" class="footer-collapsible">@foreach($group['links'] as $link)<a href="{{ $link['link'] }}" @if(!empty($link['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ $link['text'] }}</a>@endforeach</nav>
        </section>
      @endforeach

      <section class="christmas-footer__column footer-link-wrap">
        <h2><span>{{ trans('LongosChristmasTheme::theme.support') }}</span><button class="footer-toggle" type="button" aria-expanded="false" aria-controls="christmas-footer-support"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></h2>
        <nav id="christmas-footer-support" class="footer-collapsible"><button type="button" data-footer-contact-open aria-haspopup="dialog" aria-controls="{{ $contactDialogId }}" aria-expanded="false">{{ __('shop/footer.contact_us') }}</button><a href="{{ shop_route('tracking.index') }}">{{ __('shop/footer.track_order') }}</a><a href="{{ shop_route('account.index') }}">{{ trans('LongosChristmasTheme::theme.account') }}</a></nav>
      </section>

      @if ($storePolicies || $design)
        <section class="christmas-footer__column footer-link-wrap">
          <h2><span>{{ trans('LongosChristmasTheme::theme.legal') }}</span><button class="footer-toggle" type="button" aria-expanded="false" aria-controls="christmas-footer-policies"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></h2>
          <nav id="christmas-footer-policies" class="footer-collapsible">@foreach($storePolicies as $policy)<a href="{{ $policy['url'] }}" data-policy-key="{{ $policy['key'] }}">{{ $policy['title'] }}</a>@endforeach</nav>
        </section>
      @endif

      <section class="christmas-footer__payments">
        <h2>{{ trans('LongosChristmasTheme::theme.payments') }}</h2>
        <p>{{ trans('LongosChristmasTheme::theme.payments_copy') }}</p>
        @if($paymentMethods)<div role="list" aria-label="{{ __('shop/footer.accepted_payments') }}">@foreach($paymentMethods as $payment)<span role="listitem">{{ $paymentLabels[$payment] }}</span>@endforeach</div>@endif
      </section>
    </div>

    <div class="christmas-footer__bottom">
      <span>&copy; {{ date('Y') }} {{ site_brand_name() }}. {{ __('shop/footer.all_rights_reserved') }}</span>
      @if (!empty($footer_content['bottom']['copyright']))<span>{!! $footer_content['bottom']['copyright'] !!}</span>@endif
      @if (!check_license())<a href="{{ config('longos.legal.upstream_url') }}" target="_blank" rel="noopener noreferrer">Built on {{ config('longos.legal.upstream_name') }}</a>@endif
    </div>
  </div>
  @hook('footer.after')
</footer>

<div id="{{ $contactDialogId }}" class="longos-contact" data-footer-contact-dialog role="dialog" aria-modal="true" aria-labelledby="{{ $contactDialogId }}-title" hidden>
  <button type="button" class="longos-contact__backdrop" data-footer-contact-close aria-label="{{ __('shop/footer.close_contact') }}" tabindex="-1"></button>
  <section class="longos-contact__panel" role="document">
    <header class="longos-contact__header"><div><span>{{ trans('LongosChristmasTheme::theme.support') }}</span><h2 id="{{ $contactDialogId }}-title">{{ __('shop/footer.contact_us') }}</h2></div><button type="button" class="longos-contact__close" data-footer-contact-close aria-label="{{ __('shop/footer.close_contact') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
    <div class="longos-contact__content">
      <div class="longos-contact__row"><span>{{ __('shop/footer.trade_name') }}</span><strong>{{ site_brand_name() }}</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.email') }}</span><strong>@if($footerEmail)<a href="mailto:{{ $footerEmail }}">{{ $footerEmail }}</a>@else{{ __('shop/footer.not_provided') }}@endif</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.telephone') }}</span><strong>@if($footerPhone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $footerPhone) }}">{{ $footerPhone }}</a>@else{{ __('shop/footer.not_provided') }}@endif</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.physical_address') }}</span><strong>{{ $footerAddress ?: __('shop/footer.not_provided') }}</strong></div>
    </div>
  </section>
</div>
