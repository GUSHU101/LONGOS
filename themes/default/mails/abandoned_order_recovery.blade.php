@extends('layout.mail')

@php
  $primary = site_brand_style()['primary_color'];
  $itemCount = $order->orderProducts->sum('quantity');
@endphp

@section('title', $template['subject'])
@section('preheader', __('shop/order_recovery.email_preheader', ['number' => $order->number, 'total' => $order->total_format]))
@section('mail_category', __('shop/order_recovery.saved_order_badge'))

@section('content')
  <tbody>
    <tr>
      <td>
        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#fff7ed;color:#c2410c;font-size:12px;line-height:16px;font-weight:700;letter-spacing:.02em;">
          {{ __('shop/order_recovery.saved_order_badge') }}
        </span>
        <h1 style="margin:16px 0 10px;font-size:30px;line-height:38px;letter-spacing:-.02em;color:#172033;">
          {{ $template['heading'] }}
        </h1>
        <div style="margin:0 0 22px;font-size:15px;line-height:25px;color:#475467;">
          {!! nl2br(e($template['message'])) !!}
        </div>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;background:#f8fafc;border:1px solid #e4e7ec;border-radius:12px;">
          <tr>
            <td style="padding:18px 20px;">
              <table role="presentation" class="mail-stack" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="width:34%;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('shop/order_recovery.order_number') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">#{{ $order->number }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('shop/order_recovery.items') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $itemCount }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('shop/order_recovery.order_total') }}</div>
                    <div style="margin-top:4px;font-size:16px;line-height:20px;font-weight:800;color:#172033;">{{ $order->total_format }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('shop/order_recovery.saved_items') }}</h2>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
          @foreach ($order->orderProducts->take(3) as $product)
            <tr>
              <td style="width:72px;padding:10px 0;border-top:1px solid #edf0f4;vertical-align:middle;">
                <img class="mail-product-image" src="{{ image_resize($product->image, 144, 144) }}" width="64" height="64" alt="{{ $product->name }}" style="display:block;width:64px;height:64px;border:0;border-radius:10px;object-fit:cover;background:#f2f4f7;">
              </td>
              <td style="padding:10px 12px;border-top:1px solid #edf0f4;vertical-align:middle;">
                <div style="font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $product->name }}</div>
                <div style="margin-top:4px;font-size:13px;line-height:18px;color:#667085;">{{ __('mail.quantity_price', ['quantity' => $product->quantity, 'price' => currency_format($product->price, $order->currency_code, $order->currency_value)]) }}</div>
              </td>
            </tr>
          @endforeach
        </table>
        @if ($order->orderProducts->count() > 3)
          <p style="margin:8px 0 0;font-size:12px;line-height:18px;color:#667085;">{{ __('mail.more_items', ['count' => $order->orderProducts->count() - 3]) }}</p>
        @endif

        @if ($discount && $template['discount_message'])
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;margin-top:22px;background:#fffaeb;border:1px solid #fedf89;border-radius:12px;">
            <tr>
              <td style="padding:16px 18px;color:#93370d;font-size:13px;line-height:21px;">
                <strong style="display:block;margin-bottom:6px;color:#7a2e0e;">{{ __('shop/order_recovery.discount_attached') }}</strong>
                {{ $template['discount_message'] }}
                <span style="display:inline-block;margin-top:10px;padding:7px 12px;border:1px dashed #f79009;border-radius:8px;background:#ffffff;color:#b54708;font-size:18px;line-height:22px;font-weight:800;letter-spacing:.08em;">{{ $discount->code }}</span>
              </td>
            </tr>
          </table>
        @endif

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin-top:26px;">
          <tr>
            <td align="center">
              <a class="mail-button" href="{{ $recoveryUrl }}" style="display:inline-block;min-width:260px;padding:14px 22px;border-radius:10px;background:{{ $primary }};color:#ffffff;text-align:center;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;">
                {{ $template['button_label'] }}
              </a>
            </td>
          </tr>
        </table>

        <p style="margin:18px 0 0;font-size:13px;line-height:21px;color:#667085;text-align:center;">
          {{ __('shop/order_recovery.stock_notice') }}
        </p>
        <p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #edf0f4;font-size:12px;line-height:19px;color:#98a2b3;">
          {{ __('shop/order_recovery.email_security_note') }}
        </p>
      </td>
    </tr>
  </tbody>
@endsection
