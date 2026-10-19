@extends('layout.master')

@section('body-class', 'page-order-tracking')
@section('title', __('shop/tracking.title') . ' · ' . site_brand_name())
@section('description', __('shop/tracking.meta_description'))

@push('header')
  <meta name="robots" content="noindex,follow">
@endpush

@section('content')
  <div class="order-tracking" data-order-tracking
        data-tracking-url="{{ locale() === 'zh_cn' ? 'https://www.17track.net/zh-cn/track?nums=' : 'https://www.17track.net/en/track?nums=' }}"
        data-storage-key="longos_order_tracking_history"
        data-invalid-message="{{ __('shop/tracking.invalid') }}"
        data-opening-label="{{ __('shop/tracking.opening') }}"
        data-default-label="{{ __('shop/tracking.submit') }}"
        data-history-label="{{ __('shop/tracking.track_shipment', ['number' => ':number']) }}">
    <div class="order-tracking__shell">
      <section class="order-tracking__card" aria-labelledby="order-tracking-title">
        <header class="order-tracking__header">
          <span class="order-tracking__header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M3 6h11v11H3V6Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
              <path d="M14 9.5h3.6L21 13v4h-7V9.5Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
              <circle cx="7" cy="18" r="2" stroke="currentColor" stroke-width="1.7"/>
              <circle cx="17.5" cy="18" r="2" stroke="currentColor" stroke-width="1.7"/>
            </svg>
          </span>
          <div>
            <p class="order-tracking__eyebrow">{{ __('shop/tracking.eyebrow') }}</p>
            <h1 id="order-tracking-title">{{ __('shop/tracking.heading') }}</h1>
            <p>{{ __('shop/tracking.description') }}</p>
          </div>
        </header>

        <div class="order-tracking__guest-note">
          <i class="bi bi-person-check" aria-hidden="true"></i>
          <span><strong>{{ __('shop/tracking.no_login_title') }}</strong>{{ __('shop/tracking.no_login_description') }}</span>
        </div>

        <form class="order-tracking__form" data-tracking-form novalidate>
          <div class="order-tracking__field">
            <label for="public-tracking-number">{{ __('shop/tracking.tracking_number') }}</label>
            <div class="order-tracking__input-wrap">
              <svg class="order-tracking__input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 6h11v11H3V6Z" stroke="currentColor" stroke-width="1.8"/>
                <path d="M14 9.5h3.6L21 13v4h-7V9.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <circle cx="7" cy="18" r="2" stroke="currentColor" stroke-width="1.8"/>
                <circle cx="17.5" cy="18" r="2" stroke="currentColor" stroke-width="1.8"/>
              </svg>
              <input id="public-tracking-number" class="order-tracking__input" type="text"
                     name="tracking_number" placeholder="{{ __('shop/tracking.placeholder') }}"
                     autocomplete="off" autocapitalize="characters" spellcheck="false"
                     minlength="8" maxlength="40" aria-describedby="tracking-number-help tracking-number-error tracking-number-status"
                     data-tracking-input required aria-required="true">
            </div>
            <p id="tracking-number-help" class="order-tracking__field-help">{{ __('shop/tracking.input_help') }}</p>
          </div>

          <button class="order-tracking__button" type="submit" data-tracking-button>
            <span class="order-tracking__spinner" aria-hidden="true"></span>
            <span data-button-text>{{ __('shop/tracking.submit') }}</span>
            <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
          </button>
        </form>

        <div id="tracking-number-error" class="order-tracking__message order-tracking__message--error" role="alert" data-tracking-error hidden></div>
        <div id="tracking-number-status" class="order-tracking__message order-tracking__message--success" role="status" aria-live="polite" data-tracking-success hidden>
          <i class="bi bi-check-circle" aria-hidden="true"></i>
          <span>{{ __('shop/tracking.opening_secure_page') }}</span>
        </div>

        <div class="order-tracking__trust" role="list" aria-label="{{ __('shop/tracking.benefits') }}">
          <span role="listitem"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('shop/tracking.real_time') }}</span>
          <span role="listitem"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('shop/tracking.global_support') }}</span>
          <span role="listitem"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('shop/tracking.secure_redirect') }}</span>
        </div>

        <p class="order-tracking__carriers">{{ __('shop/tracking.supported_carriers') }}</p>

        <section class="order-tracking__recent" data-tracking-recent hidden aria-labelledby="recent-tracking-title">
          <div class="order-tracking__recent-head">
            <h2 id="recent-tracking-title">{{ __('shop/tracking.recent_searches') }}</h2>
            <button type="button" data-clear-tracking-history>{{ __('shop/tracking.clear_history') }}</button>
          </div>
          <div class="order-tracking__recent-list" data-tracking-recent-list></div>
        </section>

        <details class="order-tracking__help">
          <summary>{{ __('shop/tracking.help_title') }}</summary>
          <p>{{ __('shop/tracking.help_body') }}</p>
        </details>

        <p class="order-tracking__privacy"><i class="bi bi-shield-lock" aria-hidden="true"></i>{{ __('shop/tracking.external_notice') }}</p>
      </section>
    </div>
  </div>
@endsection
