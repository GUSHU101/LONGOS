@extends('layout.mail')

@php
  $primary = site_brand_style()['primary_color'];
  $storeName = site_brand_name();
@endphp

@section('title', __('mail.subject_registration', ['store' => $storeName]))
@section('preheader', __('mail.registration_preheader', ['store' => $storeName]))
@section('mail_category', __('mail.account_ready_badge'))

@section('content')
  <tbody>
    <tr>
      <td>
        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#ecfdf3;color:#027a48;font-size:12px;line-height:16px;font-weight:700;letter-spacing:.02em;">
          {{ __('mail.account_ready_badge') }}
        </span>
        <h1 style="margin:16px 0 10px;font-size:30px;line-height:38px;letter-spacing:-.02em;color:#172033;">
          {{ __('mail.registration_heading', ['store' => $storeName]) }}
        </h1>
        <p style="margin:0 0 24px;font-size:15px;line-height:25px;color:#475467;">
          {{ __('mail.registration_intro', ['name' => $customer->name ?: __('mail.customer_fallback')]) }}
        </p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;background:#f8fafc;border:1px solid #e4e7ec;border-radius:12px;">
          <tr>
            <td style="padding:18px 18px 14px;">
              <h2 style="margin:0 0 12px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.account_benefits_heading') }}</h2>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="width:28px;padding:7px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:{{ $primary }};color:#fff;text-align:center;font-size:12px;line-height:22px;font-weight:800;">&#10003;</span></td>
                  <td style="padding:7px 0 7px 8px;font-size:13px;line-height:20px;color:#475467;"><strong style="color:#172033;">{{ __('mail.benefit_faster_checkout') }}</strong><br>{{ __('mail.benefit_faster_checkout_detail') }}</td>
                </tr>
                <tr>
                  <td style="width:28px;padding:7px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:{{ $primary }};color:#fff;text-align:center;font-size:12px;line-height:22px;font-weight:800;">&#10003;</span></td>
                  <td style="padding:7px 0 7px 8px;font-size:13px;line-height:20px;color:#475467;"><strong style="color:#172033;">{{ __('mail.benefit_live_tracking') }}</strong><br>{{ __('mail.benefit_live_tracking_detail') }}</td>
                </tr>
                <tr>
                  <td style="width:28px;padding:7px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:{{ $primary }};color:#fff;text-align:center;font-size:12px;line-height:22px;font-weight:800;">&#10003;</span></td>
                  <td style="padding:7px 0 7px 8px;font-size:13px;line-height:20px;color:#475467;"><strong style="color:#172033;">{{ __('mail.benefit_support') }}</strong><br>{{ __('mail.benefit_support_detail') }}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin-top:26px;">
          <tr>
            <td align="center">
              <a class="mail-button" href="{{ shop_route('home.index') }}" style="display:inline-block;min-width:230px;padding:14px 22px;border-radius:10px;background:{{ $primary }};color:#ffffff;text-align:center;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;">
                {{ __('mail.start_shopping') }}
              </a>
            </td>
          </tr>
        </table>
        <p style="margin:18px 0 0;font-size:13px;line-height:21px;color:#667085;text-align:center;">
          {{ __('mail.manage_account_prompt') }}
          <a href="{{ shop_route('account.index') }}" style="color:{{ $primary }};text-decoration:underline;">{{ __('mail.open_my_account') }}</a>
        </p>
        <p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #edf0f4;font-size:12px;line-height:19px;color:#98a2b3;">
          {{ __('mail.registration_security') }}
        </p>
      </td>
    </tr>
  </tbody>
@endsection
