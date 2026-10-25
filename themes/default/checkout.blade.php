@extends('layout.master')

@section('body-class', 'page-checkout')
@section('title', site_brand_name() . ' · ' . __('shop/checkout.index'))
@section('minimal-checkout', '1')

@php
  $checkoutPaymentUrlTemplate = shop_route('orders.pay', ['number' => '__ORDER_NUMBER__']);
  $checkoutTotalFormat = data_get($totals, (count($totals) - 1) . '.amount_format', '');
  $checkoutPaymentBrandLabels = [
    'paypal' => 'PayPal', 'visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express',
    'discover' => 'Discover', 'jcb' => 'JCB', 'diners' => 'Diners Club', 'unionpay' => 'UnionPay',
    'cartes_bancaires' => 'Cartes Bancaires',
  ];
@endphp

@push('header')
  <meta name="robots" content="noindex,nofollow,noarchive">
  <script src="{{ asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js') }}"></script>
  <style>
    body.page-checkout { background: #f7f7f5 !important; color: #171717; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 14px !important; line-height: 1.5; }
    .page-checkout *, .page-checkout *::before, .page-checkout *::after { box-sizing: border-box; }
    .page-checkout button, .page-checkout input, .page-checkout select, .page-checkout textarea { font: inherit; }
    .page-checkout button { cursor: pointer; }
    .page-checkout a { color: inherit; }
    .page-checkout [v-cloak] { display: none; }
    .page-checkout :focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
    .radio-line-item:focus-visible, .checkout-address-choice:focus-visible, .checkout-address-choice__edit:focus-visible { box-shadow: inset 0 0 0 3px #2563eb; outline: 0; }
    .page-checkout [aria-busy="true"] { cursor: wait; opacity: .7; }
    .radio-line-item { cursor: pointer; }
    .checkout-error-summary { align-items: flex-start; background: #fff4f2; border: 1px solid #e6a49c; border-radius: 8px; color: #7a271a; display: flex; gap: 12px; margin: 18px auto 0; max-width: 1120px; padding: 14px 16px; }
    .checkout-error-summary[hidden] { display: none !important; }
    .checkout-error-summary__icon { font-size: 18px; line-height: 1.35; }
    .checkout-error-summary__content { flex: 1; min-width: 0; }
    .checkout-error-summary__title { display: block; font-size: 14px; margin-bottom: 2px; }
    .checkout-error-summary__message { font-size: 14px; margin: 0; overflow-wrap: anywhere; }
    .checkout-error-summary__dismiss { align-items: center; background: transparent; border: 0; border-radius: 5px; color: inherit; display: inline-flex; flex: 0 0 44px; font-size: 22px; height: 44px; justify-content: center; margin: -9px -9px -9px 0; padding: 0; }

    .checkout-header { align-items: center; background: #fff; border-bottom: 1px solid #e8e8e8; display: flex; height: 70px; justify-content: center; position: relative; }
    .checkout-header .logo { color: #171717; font-size: 20px; font-weight: 600; letter-spacing: 3px; text-decoration: none; text-transform: uppercase; }
    .back-cart { align-items: center; color: #555 !important; display: flex; font-size: 13px; gap: 8px; left: 32px; position: absolute; text-decoration: none; }
    .back-cart:hover { color: #111 !important; }
    .back-arrow { font-size: 19px; line-height: 1; }
    .checkout-progress { background: #fff; border-bottom: 1px solid #e8e8e8; padding: 16px max(20px, calc((100vw - 720px) / 2)); }
    .checkout-progress .steps-wrap::before { border-bottom-width: 1px; top: 11px; }
    .checkout-progress .steps-wrap > li { background: #fff; min-width: 90px; padding-inline: 8px; }
    .checkout-progress .number-wrap { background: #fff; margin-bottom: 5px; padding-inline: 7px; }
    .checkout-progress .number { border-width: 1px; font-size: 11px; height: 24px; width: 24px; }
    .checkout-progress .title { font-size: 11px; font-weight: 650; }

    .checkout-wrapper { display: grid; gap: 65px; grid-template-columns: minmax(0, 1fr) 390px; margin: 0 auto; max-width: 1180px; padding: 45px 30px 80px; }
    .checkout-left, .checkout-right { min-width: 0; }
    .section { margin-bottom: 38px; }
    .section-header { align-items: center; display: flex; justify-content: space-between; margin-bottom: 18px; }
    .section-title { color: #171717; font-size: 18px; font-weight: 600; letter-spacing: -.2px; margin: 0; }
    .section-subtitle { color: #666; font-size: 12px; }
    .checkout-login-link { background: none; border: 0; color: #171717; padding: 0; text-decoration: underline; }

    .express-payment { margin-bottom: 38px; }
    .express-title { align-items: center; color: #666; display: flex; font-size: 12px; gap: 13px; margin-bottom: 14px; }
    .express-title::before, .express-title::after { background: #dedede; content: ""; flex: 1; height: 1px; }
    .express-buttons { display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); }
    .express-btn { align-items: center; background: #fff; border: 1px solid #d7d7d7; border-radius: 6px; color: #171717; display: flex; font-weight: 600; gap: 7px; height: 50px; justify-content: center; transition: .2s; }
    .express-btn:hover { border-color: #111; transform: translateY(-1px); }
    .express-btn img { max-height: 24px; max-width: 72px; object-fit: contain; }

    .field { margin-bottom: 14px; }
    .field label { color: #333; display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; }
    .field input, .field select, .field textarea, .discount-form input { background: #fff; border: 1px solid #d7d7d7; border-radius: 6px !important; box-shadow: none !important; color: #111; outline: none; padding: 0 13px; transition: border-color .18s, box-shadow .18s; width: 100%; }
    .field input, .field select, .discount-form input { height: 49px; }
    .field select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23171717' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E"); background-position: right 14px center; background-repeat: no-repeat; background-size: 16px; cursor: pointer; padding-right: 44px; }
    .field select:hover:not(:disabled) { border-color: #8a8983; }
    .field textarea { min-height: 90px; padding-bottom: 12px; padding-top: 12px; resize: vertical; }
    .field input::placeholder, .field textarea::placeholder { color: #767676; }
    .field input:focus, .field select:focus, .field textarea:focus, .discount-form input:focus { border-color: #111; box-shadow: 0 0 0 1px #111 !important; }
    .row-2, .country-phone-grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
    .address-location-grid { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(130px, .7fr); }
    .phone-wrapper { display: grid; gap: 0; grid-template-columns: 76px 1fr; }
    .phone-country { align-items: center; background: #f5f5f3; border: 1px solid #d7d7d7; border-radius: 6px 0 0 6px; color: #555; display: flex; font-size: 12px; font-weight: 600; justify-content: center; }
    .phone-wrapper input { border-radius: 0 6px 6px 0 !important; }
    .field-help { color: #666; font-size: 12px; margin-top: 6px; }
    .field-error { align-items: center; color: #b42318; display: flex; font-size: 12px; gap: 9px; justify-content: space-between; margin-top: 7px; }
    .field-retry { background: transparent; border: 0; color: #8a1c13; font-weight: 600; min-height: 32px; padding: 0 4px; text-decoration: underline; }
    .checkbox { align-items: flex-start; color: #666; display: flex; font-size: 12px; gap: 9px; margin-top: 13px; }
    .checkbox input { height: 16px; margin-top: 1px; width: 16px; }
    .address-helper { align-items: center; color: #666; display: flex; font-size: 12px; gap: 6px; margin-top: 9px; }
    .address-helper-icon { align-items: center; background: #f0f0f0; border-radius: 50%; display: flex; font-size: 9px; height: 15px; justify-content: center; width: 15px; }
    .address-autocomplete__control { position: relative; }
    .address-suggestions { background: #fff; border: 1px solid #d7d7d7; border-radius: 8px; box-shadow: 0 14px 34px rgba(17,17,17,.13); left: 0; margin-top: 6px; max-height: 290px; overflow-y: auto; position: absolute; right: 0; top: 100%; z-index: 40; }
    .address-suggestion { background: #fff; border: 0; border-bottom: 1px solid #ededed; color: #171717; display: block; min-height: 52px; padding: 10px 13px; text-align: left; width: 100%; }
    .address-suggestion:last-child { border-bottom: 0; }
    .address-suggestion:hover, .address-suggestion[aria-selected="true"] { background: #f5f5f3; }
    .address-suggestion strong, .address-suggestion small { display: block; }
    .address-suggestion strong { font-size: 13px; font-weight: 600; }
    .address-suggestion small { color: #666; font-size: 11px; margin-top: 2px; }
    .address-autocomplete-status { min-height: 18px; }
    .address-attribution { color: #777; font-size: 10px; margin-top: 2px; }
    .address-attribution a { text-decoration: underline; }
    .field select:disabled { background: #f5f5f3; color: #666; cursor: not-allowed; }
    .checkout-address-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px; }
    .address-save-button { background: #fff; border: 1px solid #111; border-radius: 6px; color: #111; min-height: 44px; padding: 0 16px; }
    .address-save-button:hover { background: #111; color: #fff; }

    .checkout-saved-addresses { background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 18px; overflow: hidden; }
    .checkout-address-row { align-items: stretch; border-bottom: 1px solid #e7e7e7; display: flex; }
    .checkout-address-row:last-of-type { border-bottom: 0; }
    .checkout-address-choice { align-items: flex-start; background: #fff; border: 0; color: #171717; display: flex; flex: 1; gap: 12px; padding: 16px; text-align: left; }
    .checkout-address-choice:hover { background: #fafafa; }
    .checkout-address-choice__radio { border: 1px solid #bbb; border-radius: 50%; flex: 0 0 18px; height: 18px; margin-top: 2px; width: 18px; }
    .checkout-address-row.active .checkout-address-choice__radio { border: 5px solid #111; }
    .checkout-address-choice strong, .checkout-address-choice small { display: block; }
    .checkout-address-choice small { color: #666; font-size: 12px; margin-top: 2px; }
    .checkout-address-choice__edit { background: #fff; border: 0; color: #333; font-size: 12px; padding: 16px; text-decoration: underline; }
    .checkout-address-row:hover .checkout-address-choice, .checkout-address-row:hover .checkout-address-choice__edit, .checkout-address-row.active .checkout-address-choice, .checkout-address-row.active .checkout-address-choice__edit { background: #fafafa; }
    .checkout-new-address { background: #fff; border: 0; border-top: 1px solid #e7e7e7; font-size: 12px; padding: 13px 16px; text-align: left; width: 100%; }

    .delivery-options { background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .delivery-options:empty { display: none; }
    .delivery-option { background: #fff; border-bottom: 1px solid #e7e7e7; color: #171717; }
    .delivery-option:last-child { border-bottom: 0; }
    .delivery-option:hover, .delivery-option.selected { background: #fafafa; }
    .delivery-option__selector { align-items: center; background: transparent; border: 0; color: inherit; display: flex; gap: 12px; min-height: 64px; padding: 16px; text-align: left; width: 100%; }
    .shipping-quote-fields { border-top: 1px solid #ececec; padding: 14px 16px 16px 52px; }
    .shipping-quote-fields :is(input, select, textarea) { max-width: 100%; }
    .radio { border: 1px solid #bbb; border-radius: 50%; flex: 0 0 18px; height: 18px; width: 18px; }
    .delivery-option.selected .radio { border: 5px solid #111; }
    .delivery-icon { max-height: 30px; max-width: 48px; object-fit: contain; }
    .delivery-content { flex: 1; min-width: 0; }
    .delivery-name { font-size: 13px; font-weight: 600; }
    .delivery-description { color: #666; font-size: 12px; margin-top: 2px; }
    .delivery-price { font-size: 13px; font-weight: 600; margin-left: 10px; white-space: nowrap; }
    .checkout-empty-state { background: #fff; border: 1px solid #ddd; border-radius: 8px; color: #666; font-size: 12px; padding: 16px; }

    .payment-box { background: #fff; border: 1px solid #d8d8d8; border-radius: 8px; overflow: hidden; }
    .payment-box > .checkout-empty-state { border: 0; border-radius: 0; }
    .payment-option { border-bottom: 1px solid #e5e5e5; }
    .payment-option:last-of-type { border-bottom: 0; }
    .payment-header { align-items: center; background: #fff; border: 0; color: #171717; display: flex; gap: 12px; justify-content: space-between; min-height: 57px; padding: 13px 16px; text-align: left; width: 100%; }
    .payment-header:hover, .payment-option.selected .payment-header { background: #fafafa; }
    .payment-method { align-items: center; display: flex; flex: 1 1 auto; font-size: 13px; font-weight: 600; gap: 9px; min-width: 0; }
    .payment-radio { border: 1px solid #bbb; border-radius: 50%; height: 17px; width: 17px; }
    .payment-option.selected .payment-radio { border: 5px solid #111; }
    .payment-provider-icon { max-height: 25px; max-width: 72px; object-fit: contain; }
    .payment-brand-list { align-items: center; display: flex; flex: 0 1 auto; flex-wrap: wrap; gap: 5px; justify-content: flex-end; max-width: 58%; }
    .payment-brand { align-items: center; background: #fff; border: 1px solid #d7d7d7; border-radius: 4px; color: #333; display: inline-flex; font-size: 10px; font-weight: 750; min-height: 26px; padding: 3px 7px; white-space: nowrap; }
    .payment-brand--paypal { color: #003087; }
    .payment-brand--visa { color: #1434cb; font-style: italic; }
    .payment-brand--amex { background: #2e77bc; border-color: #2e77bc; color: #fff; }
    .payment-brand--mastercard { color: #c00016; }
    @media (max-width: 520px) {
      .payment-header.has-payment-brands { align-items: flex-start; flex-direction: column; }
      .payment-brand-list { justify-content: flex-start; margin-left: 26px; max-width: calc(100% - 26px); }
    }
    .payment-fields { border-top: 1px solid #e5e5e5; padding: 18px; }
    .payment-description { color: #666; font-size: 12px; line-height: 1.6; margin: 0; }
    .payment-note { align-items: center; background: #fafafa; border: 1px solid #ededed; border-radius: 5px; color: #555; display: flex; font-size: 12px; gap: 9px; margin-top: 12px; padding: 11px 12px; }
    .payment-lock { font-size: 14px; }
    .pay-button { align-items: center; background: #171714 !important; border: 1px solid #171714 !important; border-radius: 9px !important; box-shadow: 0 7px 18px rgba(17,17,17,.16) !important; color: #fff !important; display: flex; font-size: 14px; font-weight: 650; gap: 12px; height: 58px; justify-content: space-between; margin-top: 22px; padding: 0 18px; transition: background-color .18s, border-color .18s, box-shadow .18s, transform .18s; width: 100%; }
    .pay-button__label { align-items: center; display: inline-flex; gap: 9px; }
    .pay-button__icon, .pay-button__arrow { flex: 0 0 auto; height: 18px; width: 18px; }
    .pay-button__arrow { transition: transform .18s ease; }
    .checkout-submit-total { border-left: 1px solid rgba(255,255,255,.24); margin-left: auto; padding-left: 14px; white-space: nowrap; }
    .pay-button:hover:not(:disabled) { background: #30302c !important; border-color: #30302c !important; box-shadow: 0 9px 22px rgba(17,17,17,.2) !important; transform: translateY(-1px); }
    .pay-button:hover:not(:disabled) .pay-button__arrow { transform: translateX(2px); }
    .pay-button:active:not(:disabled) { box-shadow: none !important; transform: translateY(0); }
    .pay-button:focus-visible { box-shadow: 0 0 0 4px rgba(23,23,20,.18) !important; outline: 2px solid #171714; outline-offset: 3px; }
    .pay-button[aria-busy="true"] { justify-content: center; }
    .pay-button:disabled { background: #deddd8 !important; border-color: #d2d1cb !important; box-shadow: none !important; color: #73716b !important; cursor: not-allowed; opacity: 1; transform: none; }
    .pay-button:disabled .checkout-submit-total { border-left-color: rgba(23,23,20,.16); }
    .trust-center { border-top: 1px solid #e5e5e5; margin-top: 25px; padding-top: 22px; }
    .security-features { display: flex; flex-wrap: wrap; gap: 10px 17px; justify-content: center; margin-top: 15px; }
    .security-feature { align-items: center; color: #666; display: flex; font-size: 12px; gap: 5px; }
    .security-icon { font-size: 12px; }
    .guarantee-box { background: #fff; border: 1px solid #e5e5e5; border-radius: 7px; display: grid; grid-template-columns: repeat(3, 1fr); margin-top: 18px; overflow: hidden; }
    .guarantee-item { border-right: 1px solid #e7e7e7; padding: 13px 8px; text-align: center; }
    .guarantee-item:last-child { border-right: 0; }
    .guarantee-icon { font-size: 17px; margin-bottom: 4px; }
    .guarantee-title { font-size: 12px; font-weight: 600; }
    .guarantee-text { color: #666; font-size: 11px; margin-top: 2px; }

    .order-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 10px; padding: 24px; position: sticky; top: 20px; }
    .order-title { font-size: 18px; font-weight: 600; margin-bottom: 21px; }
    .product { align-items: center; border-bottom: 1px solid #ededed; display: grid; gap: 14px; grid-template-columns: 76px minmax(0, 1fr) auto; padding: 0 0 21px; }
    .product + .product { padding-top: 21px; }
    .product-image { align-items: center; aspect-ratio: 4 / 5; background: #f1f1ef; border-radius: 8px; color: #666; display: flex; font-size: 11px; justify-content: center; overflow: visible; position: relative; width: 76px; }
    .product-image img { border-radius: 8px; height: 100%; object-fit: contain; padding: 4px; width: 100%; }
    .product-quantity { align-items: center; background: #666; border-radius: 50%; color: #fff; display: flex; font-size: 10px; height: 20px; justify-content: center; position: absolute; right: -7px; top: -7px; width: 20px; }
    .product-info { flex: 1; min-width: 0; }
    .product-name { font-size: 13px; font-weight: 650; line-height: 1.4; }
    .product-name a { color: #171717; text-decoration: none; }
    .product-name a:hover { text-decoration: underline; text-underline-offset: 2px; }
    .product-option { color: #666; font-size: 12px; margin-top: 4px; }
    .product-unit { color: #777; font-size: 11px; margin-top: 6px; }
    .product-price { align-self: center; font-size: 13px; font-weight: 650; white-space: nowrap; }
    .checkout-discount { border-bottom: 1px solid #ededed; }
    .discount-toggle { cursor: pointer; display: flex; font-size: 12px; font-weight: 500; justify-content: space-between; list-style: none; padding: 17px 0; }
    .discount-toggle::-webkit-details-marker { display: none; }
    .discount-toggle::after { color: #777; content: "+"; }
    .checkout-discount[open] .discount-toggle::after { content: "−"; }
    .discount-form { display: flex; gap: 8px; padding: 0 0 17px; }
    .discount-form input { min-width: 0; }
    .discount-form button { background: #fff; border: 1px solid #111; border-radius: 6px; color: #111; padding: 0 14px; white-space: nowrap; }
    .summary { padding-top: 19px; }
    .summary-row { color: #666; display: flex; font-size: 12px; justify-content: space-between; margin-bottom: 12px; }
    .summary-row.total { border-top: 1px solid #e5e5e5; color: #111; font-size: 17px; font-weight: 600; margin-top: 16px; padding-top: 18px; }
    .order-security { border-top: 1px solid #ededed; margin-top: 23px; padding-top: 19px; }
    .order-security-header { align-items: center; display: flex; font-size: 12px; font-weight: 600; gap: 8px; }
    .order-security-text { color: #666; font-size: 12px; line-height: 1.6; margin-top: 6px; }

    .mobile-pay-bar { background: rgba(255,255,255,.96); backdrop-filter: blur(10px); border-top: 1px solid #ddd; bottom: 0; display: none; gap: 10px; left: 0; padding: 10px 15px calc(10px + env(safe-area-inset-bottom)); position: fixed; right: 0; z-index: 100; }
    .mobile-pay-total { display: flex; flex-direction: column; justify-content: center; width: 95px; }
    .mobile-pay-total span:first-child { color: #666; font-size: 11px; }
    .mobile-pay-total strong { font-size: 15px; }
    .mobile-pay-button { align-items: center; background: #171714; border: 1px solid #171714; border-radius: 8px; box-shadow: 0 5px 14px rgba(17,17,17,.16); color: #fff; display: inline-flex; flex: 1; font-size: 14px; font-weight: 650; gap: 7px; height: 52px; justify-content: center; padding-inline: 14px; }
    .mobile-pay-button svg { height: 16px; width: 16px; }
    .mobile-pay-button:disabled { background: #deddd8; border-color: #d2d1cb; box-shadow: none; color: #73716b; cursor: not-allowed; }

    @media (max-width: 950px) {
      .checkout-wrapper { gap: 35px; grid-template-columns: 1fr; max-width: 680px; }
      .checkout-right { order: -1; }
      .order-card { position: static; }
    }
    @media (max-width: 600px) {
      body.page-checkout { background: #fff !important; }
      .checkout-header { height: 58px; }
      .checkout-header .logo { font-size: 17px; letter-spacing: 2.5px; }
      .checkout-progress { padding: 12px 8px; }
      .checkout-progress .steps-wrap > li { min-width: 72px; padding-inline: 3px; }
      .checkout-progress .title { font-size: 10px; }
      .back-cart { font-size: 0; left: 15px; }
      .back-arrow { font-size: 21px; }
      .checkout-wrapper { display: flex; flex-direction: column; gap: 0; max-width: none; padding: 0 17px 100px; }
      .checkout-error-summary { margin: 12px 17px 0; }
      .checkout-right { margin: 0 -17px; order: -1; }
      .order-card { background: #fafaf9; border: 0; border-bottom: 1px solid #e7e7e7; border-radius: 0; padding: 18px; }
      .order-title { font-size: 15px; margin-bottom: 15px; }
      .product { gap: 12px; grid-template-columns: 64px minmax(0, 1fr) auto; }
      .product-image { height: 80px; width: 64px; }
      .product { padding-bottom: 17px; }
      .discount-toggle { padding: 14px 0; }
      .summary { padding-top: 15px; }
      .order-security { display: none; }
      .section { margin-bottom: 31px; }
      .section-title { font-size: 17px; }
      .express-payment { margin-top: 28px; }
      .express-buttons { grid-template-columns: 1fr; }
      .row-2, .country-phone-grid { gap: 9px; grid-template-columns: 1fr; }
      .address-location-grid { grid-template-columns: 1fr; }
      .field input, .field select, .discount-form input { height: 50px; }
      .field input, .field select, .field textarea, .discount-form input { font-size: 16px; }
      .payment-fields { padding: 15px; }
      .payment-header { padding: 13px 14px; }
      .pay-button { font-size: 16px; height: 56px; margin-top: 20px; }
      .trust-center { margin-top: 22px; }
      .guarantee-item { padding: 11px 5px; }
      .guarantee-title { font-size: 12px; }
      .guarantee-text { font-size: 11px; }
      .mobile-pay-bar { display: flex; }
    }
    @media (prefers-reduced-motion: reduce) {
      .page-checkout *, .page-checkout *::before, .page-checkout *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
    }
  </style>
@endpush

@section('content')
  <header class="checkout-header">
    <a class="back-cart" href="{{ shop_route('carts.index') }}">
      <span class="back-arrow" aria-hidden="true">←</span>
      <span>{{ __('shop/checkout.back_to_cart') }}</span>
    </a>
    <a class="logo" href="{{ shop_route('home.index') }}">{{ site_brand_name() }}</a>
  </header>
  <div class="checkout-progress">
    @include('shared.steps', ['steps' => 2])
  </div>
  <noscript><div class="alert alert-warning m-3" role="alert">{{ __('shop/checkout.javascript_required') }}</div></noscript>
  @if (session('success'))
    <div class="alert alert-success mx-auto mt-3 mb-0" style="max-width:1180px" role="status">{{ session('success') }}</div>
  @endif
  <div id="checkout-error-summary" class="checkout-error-summary" role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1" hidden>
    <span class="checkout-error-summary__icon" aria-hidden="true">!</span>
    <div class="checkout-error-summary__content">
      <strong class="checkout-error-summary__title">{{ __('shop/checkout.checkout_error_title') }}</strong>
      <p class="checkout-error-summary__message"></p>
    </div>
    <button type="button" class="checkout-error-summary__dismiss" aria-label="{{ __('shop/checkout.dismiss_error') }}">×</button>
  </div>
  <div class="checkout-wrapper">
    <section class="checkout-left" aria-label="{{ __('shop/checkout.index') }}">
      <h1 class="visually-hidden">{{ __('shop/checkout.index') }}</h1>
      @php
        $expressPaymentMethods = collect($payment_methods)->filter(function ($payment) {
          $identity = strtolower(($payment['code'] ?? '') . ' ' . ($payment['name'] ?? ''));
          return str_contains($identity, 'apple') || str_contains($identity, 'google') || str_contains($identity, 'paypal');
        })->take(3);
      @endphp
      @if ($expressPaymentMethods->isNotEmpty())
        <div class="express-payment">
          <div class="express-title">{{ __('shop/checkout.express_checkout') }}</div>
          <div class="express-buttons">
            @foreach ($expressPaymentMethods as $payment)
              <button class="express-btn" type="button" data-express-payment="{{ $payment['code'] }}">
                @if (!empty($payment['icon']))
                  <img src="{{ $payment['icon'] }}" alt="">
                @endif
                <span>{{ $payment['name'] ?? $payment['code'] }}</span>
              </button>
            @endforeach
          </div>
        </div>
      @endif

      @hook('checkout.body.header')
      @hookwrapper('checkout._address')
      @include('checkout._address_inline')
      @endhookwrapper

      @if ($shipping_require)
        @hookwrapper('checkout.shipping_method')
        <section class="section checkout-black">
          <div class="section-header"><h2 class="section-title">{{ __('shop/checkout.delivery_method') }}</h2></div>
          <div class="delivery-options" id="shipping-methods-wrap" role="radiogroup" aria-label="{{ __('shop/checkout.delivery_method') }}">
            @foreach ($shipping_methods as $methods)
              @foreach (($methods['quotes'] ?? []) as $shipping)
                @php
                  $shippingSelected = $shipping['code'] == $current['shipping_method_code'];
                @endphp
                <div class="delivery-option {{ $shippingSelected ? 'selected' : '' }}" data-shipping-quote="{{ $shipping['code'] }}">
                  <button type="button"
                          class="delivery-option__selector radio-line-item {{ $shippingSelected ? 'active' : '' }}"
                          data-key="shipping_method_code" data-value="{{ $shipping['code'] }}" role="radio"
                          tabindex="{{ $shippingSelected ? '0' : '-1' }}"
                          aria-checked="{{ $shippingSelected ? 'true' : 'false' }}">
                    <span class="radio" aria-hidden="true"></span>
                    @if (!empty($shipping['icon']))
                      <img src="{{ $shipping['icon'] }}" class="delivery-icon" alt="">
                    @endif
                    <span class="delivery-content">
                      <span class="delivery-name">{{ $shipping['name'] ?? $shipping['code'] }}</span>
                      @if (!empty($shipping['description']))
                        <span class="delivery-description">{{ strip_tags((string) $shipping['description']) }}</span>
                      @endif
                    </span>
                    <span class="delivery-price">{{ $shipping['cost_format'] ?? currency_format($shipping['cost'] ?? 0) }}</span>
                  </button>
                  @if (!empty($shipping['safe_html']))
                    <div class="shipping-quote-fields" data-shipping-quote-fields @if(!$shippingSelected) hidden @endif>{!! $shipping['safe_html'] !!}</div>
                  @endif
                </div>
              @endforeach
            @endforeach
          </div>
          @if (empty($shipping_methods))
            <div class="checkout-empty-state checkout-shipping-empty-state">{{ __('shop/checkout.shipping_address_needed') }}</div>
          @endif
        </section>
        @endhookwrapper
      @endif

      <section class="section checkout-black">
        <div class="section-header">
          <h2 class="section-title">{{ __('shop/checkout.payment_method') }}</h2>
          <span class="section-subtitle">🔒 {{ __('shop/checkout.secure_payment') }}</span>
        </div>
        <div class="payment-box" id="payment-methods-wrap" role="radiogroup" aria-label="{{ __('shop/checkout.payment_method') }}">
          @forelse ($payment_methods as $payment)
            <div class="payment-option {{ $payment['code'] == $current['payment_method_code'] ? 'selected' : '' }}">
              <button type="button" class="payment-header radio-line-item {{ !empty($payment['brand_marks']) ? 'has-payment-brands' : '' }} {{ $payment['code'] == $current['payment_method_code'] ? 'active' : '' }}"
                      data-key="payment_method_code" data-value="{{ $payment['code'] }}" role="radio"
                      tabindex="{{ $payment['code'] == $current['payment_method_code'] ? '0' : '-1' }}"
                      aria-checked="{{ $payment['code'] == $current['payment_method_code'] ? 'true' : 'false' }}">
                <span class="payment-method"><span class="payment-radio" aria-hidden="true"></span>{{ $payment['name'] ?? $payment['code'] }}</span>
                @if (!empty($payment['brand_marks']))
                  <span class="payment-brand-list" role="list" aria-label="{{ __('shop/checkout.accepted_payment_methods') }}">
                    @foreach (array_slice((array) $payment['brand_marks'], 0, 5) as $brand)
                      @if (isset($checkoutPaymentBrandLabels[$brand]))
                        <span class="payment-brand payment-brand--{{ $brand }}" role="listitem">{{ $checkoutPaymentBrandLabels[$brand] }}</span>
                      @endif
                    @endforeach
                  </span>
                @elseif (!empty($payment['icon']))
                  <img src="{{ $payment['icon'] }}" class="payment-provider-icon" alt="">
                @endif
              </button>
              @if ($payment['code'] == $current['payment_method_code'])
                <div class="payment-fields">
                  @if (!empty($payment['description']))
                    <p class="payment-description">{{ strip_tags((string) $payment['description']) }}</p>
                  @endif
                  <div class="payment-note"><span class="payment-lock" aria-hidden="true">🔒</span><span>{{ __('shop/checkout.payment_provider_note') }}</span></div>
                </div>
              @endif
            </div>
          @empty
            <div class="checkout-empty-state">{{ __('shop/carts.invalid_payment_method') }}</div>
          @endforelse
        </div>
        @if ($shipping_require)
          <div class="checkbox"><span aria-hidden="true">✓</span><span>{{ __('shop/checkout.billing_uses_shipping') }}</span></div>
        @endif
        @hook('checkout.bottom')
        @hookwrapper('checkout.confirm')
        <button class="pay-button" type="button" data-submit-checkout id="submit-checkout"
                {{ empty($payment_methods) || ($shipping_require && empty($shipping_methods)) ? 'disabled' : '' }}>
          <span class="pay-button__label">
            <svg class="pay-button__icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <span>{{ __('shop/checkout.submit_order') }}</span>
          </span>
          <span class="checkout-submit-total">{{ $checkoutTotalFormat }}</span>
          <svg class="pay-button__arrow" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M14 7l5 5-5 5"/></svg>
        </button>
        @endhookwrapper

        <div class="trust-center" aria-label="{{ __('shop/checkout.checkout_security') }}">
          <div class="security-features">
            <div class="security-feature"><span class="security-icon" aria-hidden="true">🔒</span>{{ __('shop/checkout.encrypted_payment') }}</div>
            <div class="security-feature"><span class="security-icon" aria-hidden="true">✓</span>{{ __('shop/checkout.secure_checkout') }}</div>
            <div class="security-feature"><span class="security-icon" aria-hidden="true">🛡</span>{{ __('shop/checkout.card_data_protected') }}</div>
          </div>
          <div class="guarantee-box">
            <div class="guarantee-item"><div class="guarantee-icon" aria-hidden="true">🔒</div><div class="guarantee-title">{{ __('shop/checkout.secure_payment_title') }}</div><div class="guarantee-text">{{ __('shop/checkout.encrypted_checkout') }}</div></div>
            <div class="guarantee-item"><div class="guarantee-icon" aria-hidden="true">↻</div><div class="guarantee-title">{{ __('shop/checkout.shipment_tracking') }}</div><div class="guarantee-text">{{ __('shop/checkout.tracking_delivery_progress') }}</div></div>
            <div class="guarantee-item"><div class="guarantee-icon" aria-hidden="true">♡</div><div class="guarantee-title">{{ __('shop/checkout.customer_support') }}</div><div class="guarantee-text">{{ __('shop/checkout.support_help') }}</div></div>
          </div>
        </div>
      </section>
    </section>

    <aside class="checkout-right" aria-label="{{ __('shop/checkout.order_summary') }}">
      <div class="order-card">
        <div class="order-title">{{ __('shop/checkout.order_summary') }}</div>
        @hookwrapper('checkout.products')
        <div class="products-wrap">
          @foreach ($carts['carts'] as $cart)
            <div class="product">
              <a class="product-image" href="{{ $cart['url'] ?? '#' }}" aria-label="{{ $cart['name'] ?? '' }}">
                <img src="{{ image_resize($cart['image'] ?? '', 160, 200) }}" alt="{{ $cart['name'] ?? '' }}" width="80" height="100" loading="lazy" decoding="async">
                <span class="product-quantity" aria-label="{{ __('shop/carts.quantity') }}: {{ $cart['quantity'] ?? 0 }}">{{ $cart['quantity'] ?? 0 }}</span>
              </a>
              <div class="product-info">
                <div class="product-name"><a href="{{ $cart['url'] ?? '#' }}">{{ $cart['name'] ?? '' }}</a></div>
                @if ($cart['variant_labels'] ?? false)
                  <div class="product-option">{{ $cart['variant_labels'] }}</div>
                @endif
                <div class="product-unit">{{ $cart['price_format'] ?? '' }} × {{ $cart['quantity'] ?? 0 }}</div>
              </div>
              <div class="product-price">{{ $cart['subtotal_format'] ?? $cart['price_format'] ?? '' }}</div>
            </div>
          @endforeach
        </div>
        @endhookwrapper

        <details class="checkout-discount" {{ !empty($current['extra']['discount_code']) ? 'open' : '' }}>
          <summary class="discount-toggle">{{ __('shop/account.coupon') }}</summary>
          <div class="discount-form">
            <label class="visually-hidden" for="discount-code">{{ __('shop/account.coupon') }}</label>
            <input id="discount-code" value="{{ $current['extra']['discount_code'] ?? '' }}" placeholder="{{ __('shop/account.coupon') }}">
            <button id="apply-discount-code" type="button">{{ __('common.confirm') }}</button>
          </div>
        </details>

        <div class="summary checkout-totals">
          @foreach ($totals as $total)
            <div class="summary-row {{ $loop->last ? 'total' : '' }}"><span>{{ $total['title'] }}</span><span>{{ $total['amount_format'] }}</span></div>
          @endforeach
        </div>
        <div class="order-security">
          <div class="order-security-header"><span aria-hidden="true">🔒</span>{{ __('shop/checkout.secure_checkout') }}</div>
          <div class="order-security-text">{{ __('shop/checkout.order_security_text') }}</div>
        </div>
        @hook('checkout.total.footer')
      </div>
    </aside>
  </div>

  <div id="checkout-feedback" class="visually-hidden" role="status" aria-live="polite"></div>
  <div class="mobile-pay-bar" aria-label="{{ __('shop/checkout.mobile_submit_label') }}">
    <div class="mobile-pay-total"><span>{{ __('common.text_total') }}</span><strong class="mobile-checkout-total">{{ $checkoutTotalFormat }}</strong></div>
    <button type="button" class="mobile-pay-button" id="mobile-submit-checkout" data-submit-checkout
            {{ empty($payment_methods) || ($shipping_require && empty($shipping_methods)) ? 'disabled' : '' }}>
      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
      <span>{{ __('shop/checkout.submit_order') }}</span>
      <span class="mobile-checkout-total">{{ $checkoutTotalFormat }}</span>
    </button>
  </div>

  @hook('checkout.footer')
@endsection

@push('add-scripts')
  <script>
    const checkoutMessages = {!! \Illuminate\Support\Js::from([
      'unableUpdate' => __('shop/checkout.unable_update_checkout'),
      'unablePlaceOrder' => __('shop/checkout.unable_place_order'),
      'invalidOrderResponse' => __('shop/checkout.invalid_order_response'),
      'shippingAddressNeeded' => __('shop/checkout.shipping_address_needed'),
      'completeShippingDetails' => __('shop/checkout.complete_shipping_details'),
      'providerSecurity' => __('shop/checkout.payment_provider_note'),
      'invalidPayment' => __('shop/carts.invalid_payment_method'),
      'orderProcessing' => __('shop/checkout.order_processing'),
      'checkoutUpdated' => __('shop/checkout.checkout_updated'),
    ]) !!};
    const checkoutPaymentBrandLabels = {!! \Illuminate\Support\Js::from($checkoutPaymentBrandLabels) !!};
    const checkoutPaymentBrandsLabel = {!! \Illuminate\Support\Js::from(__('shop/checkout.accepted_payment_methods')) !!};
    let isSubmittingOrder = false;
    const announceCheckout = (message) => $('#checkout-feedback').text(String(message || ''));
    const clearCheckoutError = () => {
      $('#checkout-error-summary').attr('hidden', true).find('.checkout-error-summary__message').text('');
    };
    const showCheckoutError = (message) => {
      const text = String(message || checkoutMessages.unableUpdate).slice(0, 500);
      const summary = document.getElementById('checkout-error-summary');
      if (!summary) return;
      summary.querySelector('.checkout-error-summary__message').textContent = text;
      summary.hidden = false;
      announceCheckout(text);
      summary.focus({preventScroll: false});
    };
    const checkoutErrorMessage = (error, fallback) => {
      const responseMessage = error?.response?.data?.message || error?.responseJSON?.message || error?.checkoutMessage;
      return typeof responseMessage === 'string' && responseMessage.trim() !== '' ? responseMessage : fallback;
    };
    const checkoutFailure = (message) => Object.assign(new Error(String(message || '')), {checkoutMessage: String(message || '')});
    window.showCheckoutError = showCheckoutError;
    window.clearCheckoutError = clearCheckoutError;
    const escapeCheckoutText = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
    const stripCheckoutMarkup = (value) => {
      const template = document.createElement('template');
      template.innerHTML = String(value ?? '');
      return template.content.textContent || '';
    };
    const safeCheckoutImageUrl = (value) => {
      if (!value) return '';
      try {
        const url = new URL(String(value), window.location.origin);
        return ['http:', 'https:'].includes(url.protocol) ? escapeCheckoutText(url.href) : '';
      } catch (error) {
        return '';
      }
    };
    const renderCheckoutPaymentBrands = (brands) => {
      if (!Array.isArray(brands)) return '';
      const normalized = Array.from(new Set(brands.map((brand) => String(brand || '').toLowerCase())
        .filter((brand) => Object.prototype.hasOwnProperty.call(checkoutPaymentBrandLabels, brand)))).slice(0, 5);
      if (!normalized.length) return '';
      const items = normalized.map((brand) => {
        const safeCode = brand.replace(/[^a-z0-9_-]/g, '');
        return `<span class="payment-brand payment-brand--${safeCode}" role="listitem">${escapeCheckoutText(checkoutPaymentBrandLabels[brand])}</span>`;
      }).join('');
      return `<span class="payment-brand-list" role="list" aria-label="${escapeCheckoutText(checkoutPaymentBrandsLabel)}">${items}</span>`;
    };

    $(document).ready(function () {
      $(document).on('click', '.checkout-error-summary__dismiss', clearCheckoutError);

      $(document).on('click', '#shipping-methods-wrap .delivery-option__selector, #payment-methods-wrap .payment-header', function (event) {
        if (isSubmittingOrder || $(this).hasClass('active') || $(this).closest('[role="radiogroup"]').attr('aria-busy') === 'true') return;
        const key = $(this).hasClass('delivery-option__selector') ? 'shipping_method_code' : 'payment_method_code';
        const value = $(this).data('value');
        const extra = key === 'shipping_method_code' ? {shipping_quote_data: collectShippingQuoteData(value)} : {};
        updateCheckout(key, value, undefined, extra);
      });

      $(document).on('change', '#shipping-methods-wrap [data-shipping-quote-fields] input, #shipping-methods-wrap [data-shipping-quote-fields] select, #shipping-methods-wrap [data-shipping-quote-fields] textarea', function () {
        $(this).removeAttr('aria-invalid');
        const quoteCode = $(this).closest('[data-shipping-quote]').data('shipping-quote');
        const selectedCode = $('#shipping-methods-wrap .delivery-option__selector.active').data('value');
        if (!quoteCode || String(quoteCode) !== String(selectedCode)) return;
        updateCheckout('shipping_method_code', selectedCode, undefined, {shipping_quote_data: collectShippingQuoteData(selectedCode)});
      });

      $(document).on('keydown', '#shipping-methods-wrap .delivery-option__selector, #payment-methods-wrap .payment-header', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          $(this).trigger('click');
        }

        if (['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft'].includes(event.key)) {
          event.preventDefault();
          const options = $(this).closest('[role="radiogroup"]').find('.delivery-option__selector, .payment-header');
          const direction = ['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1;
          const next = (options.index(this) + direction + options.length) % options.length;
          options.eq(next).trigger('focus').trigger('click');
        }
      });

      $('#submit-checkout').click(function () {
        if (isSubmittingOrder || checkoutUpdatePending > 0) {
          return;
        }

        if (!checkoutAddressApp.readyForSubmit) {
          checkoutAddressApp.focusAddressEditor();
          showCheckoutError(checkoutAddressApp.messages.saveChanges);
          layer.msg(checkoutAddressApp.messages.saveChanges);
          return;
        }

        const address = config.isLogin ? checkoutAddressApp.form.shipping_address_id : checkoutAddressApp.source.guest_shipping_address;
        const payment = config.isLogin ? checkoutAddressApp.form.payment_address_id : checkoutAddressApp.source.guest_payment_address;

        if (checkoutAddressApp.shippingRequired && !address) {
          showCheckoutError('{{ __('shop/checkout.error_address') }}');
          layer.msg('{{ __('shop/checkout.error_address') }}', () => {
          })
          return;
        }

        if (!payment) {
          showCheckoutError('{{ __('shop/checkout.error_payment_address') }}');
          layer.msg('{{ __('shop/checkout.error_payment_address') }}', () => {
          })
          return;
        }

        const selectedShippingCode = $('#shipping-methods-wrap .delivery-option__selector.active').data('value');
        if (checkoutAddressApp.shippingRequired && !validateSelectedShippingFields(selectedShippingCode)) {
          showCheckoutError(checkoutMessages.completeShippingDetails);
          layer.msg(checkoutMessages.completeShippingDetails);
          return;
        }

        const data = {
          checkout_token: {!! \Illuminate\Support\Js::from($checkout_confirm_token ?? '') !!},
        }
        if (selectedShippingCode) data.shipping_quote_data = collectShippingQuoteData(selectedShippingCode);

        isSubmittingOrder = true;
        clearCheckoutError();
        const $submitButton = $(this);
        const $submitButtons = $('[data-submit-checkout]');
        const originalLabel = $submitButton.html();
        $submitButtons.attr('aria-busy', 'true');
        window.syncCheckoutSubmitState();
        announceCheckout(checkoutMessages.orderProcessing);
        $submitButton.html('<span class="pay-button__label"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>{{ __('shop/checkout.submit_order') }}</span></span>');

        $http.post(window.longosStorefrontRoutes.checkoutConfirm, data, {hmsg: true, hload: true}).then((res) => {
          if (!res || !res.number) {
            throw checkoutFailure(checkoutMessages.invalidOrderResponse);
          }

          const paymentTemplate = {!! json_encode($checkoutPaymentUrlTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
          const paymentUrl = new URL(paymentTemplate.replace('__ORDER_NUMBER__', encodeURIComponent(res.number)), window.location.origin);
          paymentUrl.searchParams.set('type', 'create');
          window.location.assign(paymentUrl.toString());
        }).catch((error) => {
          isSubmittingOrder = false;
          $submitButton.html(originalLabel);
          $submitButtons.removeAttr('aria-busy');
          window.syncCheckoutSubmitState();
          const message = checkoutErrorMessage(error, checkoutMessages.unablePlaceOrder);
          showCheckoutError(message);
          layer.msg(message);
        })
      });

      $(document).on('click', '.guest-checkout-login', function () {
        bk.openLogin();
      });

      $(document).on('click', '[data-express-payment]', function () {
        const $button = $(this);
        const code = $(this).data('express-payment');
        $button.prop('disabled', true).attr('aria-busy', 'true');
        updateCheckout('payment_method_code', code, () => {
          const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          document.querySelector('#payment-methods-wrap')?.scrollIntoView({behavior: reducedMotion ? 'auto' : 'smooth', block: 'center'});
        }).finally(() => $button.prop('disabled', false).removeAttr('aria-busy'));
      });

      $('#apply-discount-code').click(function () {
        const $button = $(this);
        $button.prop('disabled', true);
        updateCheckout('discount_code', $('#discount-code').val(), (res) => {
          $('#discount-code').val((res.current.extra && res.current.extra.discount_code) || '')
          announceCheckout('{{ __('shop/account.coupon') }} {{ __('common.success') }}');
          layer.msg('{{ __('shop/account.coupon') }} {{ __('common.success') }}')
        }).finally(() => $button.prop('disabled', false));
      });
    });

    $('#mobile-submit-checkout').on('click', function () { $('#submit-checkout').trigger('click'); });

    const checkoutRequiresShipping = {!! \Illuminate\Support\Js::from((bool) $shipping_require) !!};
    let checkoutHasShipping = !checkoutRequiresShipping || {!! \Illuminate\Support\Js::from(! empty($shipping_methods)) !!};
    let checkoutHasPayment = {!! \Illuminate\Support\Js::from(! empty($payment_methods)) !!};
    let checkoutUpdateQueue = Promise.resolve();
    let checkoutUpdatePending = 0;

    window.syncCheckoutSubmitState = () => {
      const addressReady = !window.checkoutAddressApp || checkoutAddressApp.readyForSubmit;
      const disabled = isSubmittingOrder || checkoutUpdatePending > 0 || !checkoutHasShipping || !checkoutHasPayment || !addressReady;
      $('[data-submit-checkout]').prop('disabled', disabled);
    };

    const enqueueCheckoutUpdate = (payload) => {
      checkoutUpdatePending += 1;
      window.syncCheckoutSubmitState();
      const request = checkoutUpdateQueue
        .catch(() => undefined)
        .then(() => $http.put(window.longosStorefrontRoutes.checkoutUpdate, payload, {hmsg: true, hload: true}));

      checkoutUpdateQueue = request.catch(() => undefined);

      return request.finally(() => {
        checkoutUpdatePending = Math.max(0, checkoutUpdatePending - 1);
        window.syncCheckoutSubmitState();
      });
    };

    const collectShippingQuoteData = (code) => {
      const fields = {};
      if (!code) return {code: '', fields};
      const $scope = $('#shipping-methods-wrap [data-shipping-quote]').filter(function () {
        return String($(this).data('shipping-quote')) === String(code);
      }).first();
      $scope.find('[data-shipping-quote-fields] input[name], [data-shipping-quote-fields] select[name], [data-shipping-quote-fields] textarea[name]').each(function () {
        if (this.disabled) return;
        if ((this.type === 'checkbox' || this.type === 'radio') && !this.checked) return;
        const name = String(this.name || '').trim();
        if (!/^[A-Za-z0-9_.-]{1,80}$/.test(name)) return;
        const value = String($(this).val() ?? '').slice(0, 1000);
        fields[name] = Object.prototype.hasOwnProperty.call(fields, name) ? `${fields[name]},${value}` : value;
      });
      return {code: String(code), fields};
    };

    const validateSelectedShippingFields = (code) => {
      if (!checkoutRequiresShipping || !code) return !checkoutRequiresShipping;
      const $scope = $('#shipping-methods-wrap [data-shipping-quote]').filter(function () {
        return String($(this).data('shipping-quote')) === String(code);
      }).first();
      let firstInvalid = null;
      $scope.find('[data-shipping-quote-fields] input, [data-shipping-quote-fields] select, [data-shipping-quote-fields] textarea').each(function () {
        if (this.disabled) return;
        const valid = typeof this.checkValidity !== 'function' || this.checkValidity();
        $(this).attr('aria-invalid', valid ? null : 'true');
        if (!valid && !firstInvalid) firstInvalid = this;
      });
      if (firstInvalid) {
        firstInvalid.focus({preventScroll: false});
        if (typeof firstInvalid.reportValidity === 'function') firstInvalid.reportValidity();
      }

      return !firstInvalid;
    };

    const updateCheckout = (key, value, callback, extraPayload = {}) => {
      const groupSelector = key === 'shipping_method_code' ? '#shipping-methods-wrap' : '#payment-methods-wrap';

      $(groupSelector).attr('aria-busy', 'true');
      clearCheckoutError();
      const request = enqueueCheckoutUpdate(Object.assign({[key]: value}, extraPayload)).then((res) => {
        if (!res || res.status === 'fail') {
          throw checkoutFailure(res?.message || checkoutMessages.unableUpdate);
        }

        updateTotal(res.totals);
        updateShippingMethods(res.shipping_methods, res.current.shipping_method_code);
        updatePaymentMethods(res.payment_methods, res.current.payment_method_code);
        announceCheckout(checkoutMessages.checkoutUpdated);

        if (typeof callback === 'function') callback(res);
        return res;
      }).catch((error) => {
        const message = checkoutErrorMessage(error, checkoutMessages.unableUpdate);
        showCheckoutError(message);
        layer.msg(message);
        return null;
      }).finally(() => {
        $(groupSelector).removeAttr('aria-busy');
      });

      return request;
    }

    const updateTotal = (totals = []) => {
      totals = Array.isArray(totals) ? totals : [];
      $('.checkout-totals').html(totals.map((item, index) => `<div class="summary-row ${index === totals.length - 1 ? 'total' : ''}"><span>${escapeCheckoutText(item.title)}</span><span>${escapeCheckoutText(item.amount_format)}</span></div>`).join(''));
      const finalTotal = totals.length ? escapeCheckoutText(totals[totals.length - 1].amount_format) : '';
      $('.mobile-checkout-total').html(finalTotal);
      $('.checkout-submit-total').html(finalTotal);
    }

    const updateShippingMethods = (data = [], shipping_method_code) => {
      data = Array.isArray(data) ? data : [];
      const restoreFocus = document.activeElement?.closest?.('#shipping-methods-wrap') !== null;
      let html = '';
      let optionIndex = 0;

      data.forEach((methods) => {
        const quotes = Array.isArray(methods.quotes) ? methods.quotes : [];
        quotes.forEach((quote) => {
          const selected = String(shipping_method_code) === String(quote.code);
          const icon = safeCheckoutImageUrl(quote.icon);
          const tabIndex = selected || (!shipping_method_code && optionIndex === 0) ? 0 : -1;
          html += `<div class="delivery-option ${selected ? 'selected' : ''}" data-shipping-quote="${escapeCheckoutText(quote.code)}">
            <button type="button" class="delivery-option__selector radio-line-item ${selected ? 'active' : ''}" data-key="shipping_method_code" data-value="${escapeCheckoutText(quote.code)}" role="radio" tabindex="${tabIndex}" aria-checked="${selected ? 'true' : 'false'}">
              <span class="radio" aria-hidden="true"></span>
              ${icon ? `<img src="${icon}" class="delivery-icon" alt="">` : ''}
              <span class="delivery-content">
                <span class="delivery-name">${escapeCheckoutText(quote.name || quote.code)}</span>
                ${quote.description ? `<span class="delivery-description">${escapeCheckoutText(stripCheckoutMarkup(quote.description))}</span>` : ''}
              </span>
              <span class="delivery-price">${escapeCheckoutText(quote.cost_format || '')}</span>
            </button>
            ${quote.safe_html ? `<div class="shipping-quote-fields" data-shipping-quote-fields ${selected ? '' : 'hidden'}>${quote.safe_html}</div>` : ''}
          </div>`;
          optionIndex += 1;
        })
      })

      $('#shipping-methods-wrap').replaceWith('<div class="delivery-options" id="shipping-methods-wrap" role="radiogroup" aria-label="{{ __('shop/checkout.delivery_method') }}">' + html + '</div>');
      if (restoreFocus) $('#shipping-methods-wrap .delivery-option__selector[tabindex="0"]').trigger('focus');
      checkoutHasShipping = !checkoutRequiresShipping || data.some(method => Array.isArray(method.quotes) && method.quotes.length);
      $('.checkout-shipping-empty-state').remove();
      if (checkoutRequiresShipping && !checkoutHasShipping) {
        $('#shipping-methods-wrap').after(`<div class="checkout-empty-state checkout-shipping-empty-state">${escapeCheckoutText(checkoutMessages.shippingAddressNeeded)}</div>`);
      }
      window.syncCheckoutSubmitState();
    }

    const updatePaymentMethods = (data = [], payment_method_code) => {
      data = Array.isArray(data) ? data : [];
      const restoreFocus = document.activeElement?.closest?.('#payment-methods-wrap') !== null;
      let html = '';

      data.forEach((item, index) => {
        const selected = String(payment_method_code) === String(item.code);
        const icon = safeCheckoutImageUrl(item.icon);
        const brands = renderCheckoutPaymentBrands(item.brand_marks);
        html += `<div class="payment-option ${selected ? 'selected' : ''}">
          <button type="button" class="payment-header radio-line-item ${brands ? 'has-payment-brands' : ''} ${selected ? 'active' : ''}" data-key="payment_method_code" data-value="${escapeCheckoutText(item.code)}" role="radio" tabindex="${selected || (!payment_method_code && index === 0) ? '0' : '-1'}" aria-checked="${selected ? 'true' : 'false'}">
            <span class="payment-method"><span class="payment-radio" aria-hidden="true"></span>${escapeCheckoutText(item.name || item.code)}</span>
            ${brands || (icon ? `<img src="${icon}" class="payment-provider-icon" alt="">` : '')}
          </button>
          ${selected ? `<div class="payment-fields">${item.description ? `<p class="payment-description">${escapeCheckoutText(stripCheckoutMarkup(item.description))}</p>` : ''}<div class="payment-note"><span class="payment-lock" aria-hidden="true">🔒</span><span>${escapeCheckoutText(checkoutMessages.providerSecurity)}</span></div></div>` : ''}
        </div>`;
      })

      if (!html) html = `<div class="checkout-empty-state">${escapeCheckoutText(checkoutMessages.invalidPayment)}</div>`;
      $('#payment-methods-wrap').replaceWith('<div class="payment-box" id="payment-methods-wrap" role="radiogroup" aria-label="{{ __('shop/checkout.payment_method') }}">' + html + '</div>');
      if (restoreFocus) $('#payment-methods-wrap .payment-header[tabindex="0"]').trigger('focus');
      checkoutHasPayment = data.length > 0;
      window.syncCheckoutSubmitState();
    }

    window.syncCheckoutSubmitState();
  </script>
@endpush
