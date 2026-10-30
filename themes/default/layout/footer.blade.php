@php
  $footerIntro = is_array($footer_content['content']['intro'] ?? null) ? $footer_content['content']['intro'] : [];
  $footerContact = is_array($footer_content['content']['contact'] ?? null) ? $footer_content['content']['contact'] : [];
  $policyProfile = \Beike\Services\StorePolicyTemplateService::profile();
  $footerEmail = trim((string) (($policyProfile['contact_email'] ?? '') ?: system_setting('base.email', '')));
  $footerPhone = trim((string) (($policyProfile['contact_phone'] ?? '') ?: ($footerContact['telephone'] ?? '') ?: system_setting('base.telephone', '')));
  $footerAddress = trim((string) (($policyProfile['business_address'] ?? '') ?: ($footerContact['address'] ?? '')));
  $footerBrandCopy = trim((string) ($footerIntro['text'] ?? ''));
  $footerBrandLogo = trim((string) ($footerIntro['logo'] ?? ''));
  $brandNamePattern = preg_quote(site_brand_name(), '#');
  $footerBrandCopy = preg_replace('#^\s*<p>\s*<(?:strong|b)>\s*' . $brandNamePattern . '\s*</(?:strong|b)>\s*</p>\s*#iu', '', $footerBrandCopy, 1) ?? $footerBrandCopy;

  $storePolicies = array_values(array_filter(
    is_array($store_policies ?? null) ? $store_policies : [],
    static fn ($policy) => is_array($policy) && !empty($policy['url']) && !empty($policy['title'])
  ));
  $serviceItems = array_values(array_filter(
    is_array($footer_content['services']['items'] ?? null) ? $footer_content['services']['items'] : [],
    static fn ($item) => is_array($item) && ($item['show'] ?? true) !== false && trim((string) ($item['title'] ?? '')) !== ''
  ));
  $showProtection = !empty($footer_content['services']['enable']) && !empty($serviceItems);

  $customGroups = [];
  foreach (['link1', 'link2', 'link3'] as $groupKey) {
    $group = is_array($footer_content['content'][$groupKey] ?? null) ? $footer_content['content'][$groupKey] : [];
    $links = array_values(array_filter(
      is_array($group['links'] ?? null) ? $group['links'] : [],
      static fn ($link) => is_array($link) && !empty($link['link']) && trim((string) ($link['text'] ?? '')) !== ''
    ));
    if (trim((string) ($group['title'] ?? '')) !== '' && $links) {
      $customGroups[] = ['title' => (string) $group['title'], 'links' => $links];
    }
  }

  $paymentLabels = [
    'visa' => 'VISA', 'mastercard' => 'Mastercard', 'amex' => 'AMEX', 'paypal' => 'PayPal',
    'apple_pay' => 'Apple Pay', 'google_pay' => 'G Pay', 'shop_pay' => 'Shop Pay', 'klarna' => 'Klarna',
    'afterpay' => 'Afterpay', 'unionpay' => 'UnionPay', 'jcb' => 'JCB', 'discover' => 'Discover',
    'diners' => 'Diners Club', 'ideal' => 'iDEAL', 'bancontact' => 'Bancontact', 'sofort' => 'SOFORT',
    'giropay' => 'giropay', 'cartes_bancaires' => 'CB',
  ];
  $paymentMethods = !empty($footer_content['bottom']['payments_enable']) && is_array($footer_content['bottom']['enabled_payments'] ?? null)
    ? array_values(array_filter($footer_content['bottom']['enabled_payments'], static fn ($payment) => isset($paymentLabels[$payment])))
    : [];
  $contactDialogId = 'longos-footer-contact-dialog';
@endphp

<footer class="site-footer longos-footer" data-longos-footer>
  @hook('footer.before')
  <div class="longos-footer__inner footer-shell container-fluid">
    <section class="longos-footer__top" aria-label="{{ site_brand_name() }}">
      <div class="longos-footer__brand">
        <a class="longos-footer__brand-link" href="{{ shop_route('home.index') }}" aria-label="{{ site_brand_name() }}">
          @if ($footerBrandLogo !== '')
            <img class="longos-footer__brand-logo" src="{{ image_origin($footerBrandLogo) }}" alt="{{ site_brand_name() }}" loading="lazy" decoding="async">
          @else
            {{ site_brand_name() }}
          @endif
        </a>
        @if ($footerBrandCopy !== '')
          <div class="longos-footer__brand-copy">{!! $footerBrandCopy !!}</div>
        @else
          <p class="longos-footer__brand-copy">{{ __('shop/footer.brand_message') }}</p>
        @endif

        @if (!empty($footerIntro['social_network']))
          <nav class="longos-footer__social" aria-label="Social links">
            @foreach ($footerIntro['social_network'] as $item)
              @if (!empty($item['show']) && !empty($item['link']))
                @php
                  $socialHost = parse_url($item['link'], PHP_URL_HOST);
                  $socialLabel = $socialHost ? preg_replace('/^www\./i', '', $socialHost) : site_brand_name();
                @endphp
                <a href="{{ $item['link'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $socialLabel }}">
                  @if(!empty($item['image']))<img src="{{ image_origin($item['image']) }}" alt="" loading="lazy" decoding="async">@else<i class="bi bi-arrow-up-right" aria-hidden="true"></i>@endif
                </a>
              @endif
            @endforeach
          </nav>
        @endif
      </div>

      @if ($showProtection)
        <div class="longos-footer__protection" role="list" aria-label="{{ __('shop/footer.shopping_protection') }}">
          @foreach (array_slice($serviceItems, 0, 4) as $item)
            <span class="longos-footer__protection-item" role="listitem">
              <i class="bi bi-check2" aria-hidden="true"></i>
              <span><strong>{{ $item['title'] }}</strong>@if(!empty($item['sub_title']))<small>{{ $item['sub_title'] }}</small>@endif</span>
            </span>
          @endforeach
        </div>
      @endif
    </section>

    <div class="longos-footer__main">
      @if ($design || $storePolicies)
        <section class="longos-footer__column footer-link-wrap">
          <h2 class="longos-footer__heading">
            <span>{{ __('shop/footer.policies') }}</span>
            <button class="footer-toggle" type="button" aria-expanded="false" aria-controls="longos-footer-policies" aria-label="{{ __('shop/footer.policies') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
          </h2>
          <nav id="longos-footer-policies" class="longos-footer__links footer-collapsible" aria-label="{{ __('shop/footer.policies') }}">
            @foreach ($storePolicies as $policy)<a href="{{ $policy['url'] }}" data-policy-key="{{ $policy['key'] }}">{{ $policy['title'] }}</a>@endforeach
            @if ($design && !$storePolicies)<span class="longos-footer__empty">{{ __('shop/footer.no_policies') }}</span>@endif
          </nav>
        </section>
      @endif

      <section class="longos-footer__column footer-link-wrap">
        <h2 class="longos-footer__heading">
          <span>{{ __('shop/footer.customer_support') }}</span>
          <button class="footer-toggle" type="button" aria-expanded="false" aria-controls="longos-footer-support" aria-label="{{ __('shop/footer.customer_support') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
        </h2>
        <nav id="longos-footer-support" class="longos-footer__links footer-collapsible" aria-label="{{ __('shop/footer.customer_support') }}">
          <button type="button" class="longos-footer__link-button" data-footer-contact-open aria-haspopup="dialog" aria-controls="{{ $contactDialogId }}" aria-expanded="false">{{ __('shop/footer.contact_us') }}</button>
          <a href="{{ shop_route('tracking.index') }}">{{ __('shop/footer.track_order') }}</a>
        </nav>
      </section>

      @foreach ($customGroups as $groupIndex => $group)
        <section class="longos-footer__column footer-link-wrap">
          <h2 class="longos-footer__heading">
            <span>{{ $group['title'] }}</span>
            <button class="footer-toggle" type="button" aria-expanded="false" aria-controls="longos-footer-custom-{{ $groupIndex }}" aria-label="{{ $group['title'] }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
          </h2>
          <nav id="longos-footer-custom-{{ $groupIndex }}" class="longos-footer__links footer-collapsible" aria-label="{{ $group['title'] }}">
            @foreach ($group['links'] as $link)<a href="{{ $link['link'] }}" @if(!empty($link['new_window'])) target="_blank" rel="noopener noreferrer" @endif>{{ $link['text'] }}</a>@endforeach
          </nav>
        </section>
      @endforeach

      @if ($design || $paymentMethods)
        <section class="longos-footer__payments-area">
          <div class="longos-footer__payments-title">
            <div><h2 class="longos-footer__heading">{{ __('shop/footer.secure_payments') }}</h2><p>{{ __('shop/footer.secure_payments_help') }}</p></div>
            <span class="longos-footer__secure-badge"><i aria-hidden="true"></i>{{ __('shop/footer.secure_badge') }}</span>
          </div>
          @if ($paymentMethods)
            <div class="longos-footer__payments" role="list" aria-label="{{ __('shop/footer.accepted_payments') }}">
              @foreach ($paymentMethods as $payment)<span class="longos-footer__payment longos-footer__payment--{{ $payment }}" role="listitem" aria-label="{{ $paymentLabels[$payment] }}"><b>{{ $paymentLabels[$payment] }}</b></span>@endforeach
            </div>
          @elseif ($design)
            <span class="longos-footer__empty">{{ __('shop/footer.accepted_payments') }}</span>
          @endif
        </section>
      @endif
    </div>

    <div class="longos-footer__bottom">
      <div class="longos-footer__copyright">
        <span>&copy; {{ date('Y') }} {{ site_brand_name() }}. {{ __('shop/footer.all_rights_reserved') }}</span>
        @if (!empty($footer_content['bottom']['copyright']))<span>{!! $footer_content['bottom']['copyright'] !!}</span>@endif
      </div>
    </div>
  </div>
  @hook('footer.after')
</footer>

<div id="{{ $contactDialogId }}" class="longos-contact" data-footer-contact-dialog role="dialog" aria-modal="true" aria-labelledby="{{ $contactDialogId }}-title" hidden>
  <button type="button" class="longos-contact__backdrop" data-footer-contact-close aria-label="{{ __('shop/footer.close_contact') }}" tabindex="-1"></button>
  <section class="longos-contact__panel" role="document">
    <header class="longos-contact__header">
      <div><span>{{ __('shop/footer.customer_support') }}</span><h2 id="{{ $contactDialogId }}-title">{{ __('shop/footer.contact_us') }}</h2></div>
      <button type="button" class="longos-contact__close" data-footer-contact-close aria-label="{{ __('shop/footer.close_contact') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="longos-contact__content">
      <div class="longos-contact__row"><span>{{ __('shop/footer.trade_name') }}</span><strong>{{ site_brand_name() }}</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.legal_entity') }}</span><strong>{{ $policyProfile['legal_name'] ?: $policyProfile['business_name'] }}</strong></div>
      @if (!empty($policyProfile['registration_number']))
        <div class="longos-contact__row"><span>{{ __('shop/footer.registration_number') }}</span><strong>{{ $policyProfile['registration_number'] }}</strong></div>
      @endif
      <div class="longos-contact__row"><span>{{ __('shop/footer.email') }}</span><strong>@if($footerEmail)<a href="mailto:{{ $footerEmail }}">{{ $footerEmail }}</a>@else{{ __('shop/footer.not_provided') }}@endif</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.telephone') }}</span><strong>@if($footerPhone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $footerPhone) }}">{{ $footerPhone }}</a>@else{{ __('shop/footer.not_provided') }}@endif</strong></div>
      <div class="longos-contact__row"><span>{{ __('shop/footer.physical_address') }}</span><strong>{{ $footerAddress ?: __('shop/footer.not_provided') }}</strong></div>
    </div>
  </section>
</div>
