@extends('layout.mail')

@php
  $primary = site_brand_style()['primary_color'];
  $paymentUrl = order_payment_url($order, true);
  $orderUrl = order_access_url($order);
  $itemCount = $order->orderProducts->sum('quantity');
@endphp

@section('title', __('mail.subject_payment_pending', ['number' => $order->number]))
@section('preheader', __('mail.payment_pending_preheader', ['number' => $order->number, 'total' => $order->total_format]))
@section('mail_category', __('mail.payment_pending_badge'))

@section('content')
  <tbody>
    <tr>
      <td>
        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#fff7ed;color:#c2410c;font-size:12px;line-height:16px;font-weight:700;letter-spacing:.02em;">
          {{ __('mail.payment_pending_badge') }}
        </span>
        <h1 style="margin:16px 0 10px;font-size:30px;line-height:38px;letter-spacing:-.02em;color:#172033;">
          {{ __('mail.payment_pending_heading') }}
        </h1>
        <p style="margin:0 0 22px;font-size:15px;line-height:25px;color:#475467;">
          {{ __('mail.payment_pending_intro', ['name' => $order->customer_name ?: __('mail.customer_fallback')]) }}
        </p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;background:#f8fafc;border:1px solid #e4e7ec;border-radius:12px;">
          <tr>
            <td style="padding:18px 20px;">
              <table role="presentation" class="mail-stack" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="width:50%;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.order_number_label') }}</div>
                    <div style="margin-top:4px;font-size:15px;line-height:22px;font-weight:700;color:#172033;">#{{ $order->number }}</div>
                  </td>
                  <td style="width:50%;padding-left:18px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.order_total_label') }}</div>
                    <div style="margin-top:4px;font-size:18px;line-height:22px;font-weight:800;color:#172033;">{{ $order->total_format }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <h2 style="margin:26px 0 12px;font-size:16px;line-height:24px;color:#172033;">
          {{ trans_choice('mail.items_reserved', $itemCount, ['count' => $itemCount]) }}
        </h2>
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
          <p style="margin:8px 0 0;font-size:12px;line-height:18px;color:#667085;">
            {{ __('mail.more_items', ['count' => $order->orderProducts->count() - 3]) }}
          </p>
        @endif

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin-top:26px;">
          <tr>
            <td align="center">
              <a class="mail-button" href="{{ $paymentUrl }}" style="display:inline-block;min-width:240px;padding:14px 22px;border-radius:10px;background:{{ $primary }};color:#ffffff;text-align:center;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;">
                {{ __('mail.complete_payment') }}
              </a>
            </td>
          </tr>
        </table>

        <p style="margin:18px 0 0;font-size:13px;line-height:21px;color:#667085;text-align:center;">
          {{ __('mail.payment_link_note') }}
          <a href="{{ $orderUrl }}" style="color:{{ $primary }};text-decoration:underline;">{{ __('mail.view_saved_order') }}</a>
        </p>
        <p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #edf0f4;font-size:12px;line-height:19px;color:#98a2b3;">
          {{ __('mail.payment_pending_security') }}
        </p>
      </td>
    </tr>
  </tbody>
@endsection
