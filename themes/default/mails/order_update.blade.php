@extends('layout.mail')

@php
  $primary = site_brand_style()['primary_color'];
  $status = in_array((string) $order->status, ['paid', 'shipped', 'completed', 'cancelled', 'refunding'], true)
    ? (string) $order->status
    : 'updated';
  $firstShipment = $order->orderShipments->first();
  $carrierTrackingUrl = $firstShipment ? \Beike\Services\ShipmentService::trackingUrl($firstShipment) : null;
  $ctaUrl = $status === 'shipped' && $carrierTrackingUrl ? $carrierTrackingUrl : order_access_url($order);
  $ctaLabel = $status === 'shipped' && $carrierTrackingUrl
    ? __('mail.track_delivery')
    : __('mail.order_cta_' . $status);
  $badgeColors = match ($status) {
    'paid' => ['#ecfdf3', '#027a48'],
    'shipped' => ['#eff8ff', '#175cd3'],
    'completed' => ['#ecfdf3', '#027a48'],
    'cancelled' => ['#f2f4f7', '#475467'],
    'refunding' => ['#fffaeb', '#b54708'],
    default => ['#f2f4f7', '#475467'],
  };
@endphp

@section('title', __('mail.subject_order_' . $status, ['number' => $order->number]))
@section('preheader', __('mail.order_preheader_' . $status, ['number' => $order->number]))
@section('mail_category', __('mail.order_badge_' . $status))

@section('content')
  <tbody>
    <tr>
      <td>
        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:{{ $badgeColors[0] }};color:{{ $badgeColors[1] }};font-size:12px;line-height:16px;font-weight:700;letter-spacing:.02em;">
          {{ __('mail.order_badge_' . $status) }}
        </span>
        <h1 style="margin:16px 0 10px;font-size:30px;line-height:38px;letter-spacing:-.02em;color:#172033;">
          {{ __('mail.order_heading_' . $status) }}
        </h1>
        <p style="margin:0 0 22px;font-size:15px;line-height:25px;color:#475467;">
          {{ __('mail.order_message_' . $status, ['name' => $order->customer_name ?: __('mail.customer_fallback')]) }}
        </p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;background:#f8fafc;border:1px solid #e4e7ec;border-radius:12px;">
          <tr>
            <td style="padding:18px 20px;">
              <table role="presentation" class="mail-stack" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="width:34%;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.order_number_label') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">#{{ $order->number }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.order_status_label') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $order->status_format }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.order_total_label') }}</div>
                    <div style="margin-top:4px;font-size:16px;line-height:20px;font-weight:800;color:#172033;">{{ $order->total_format }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        @if (in_array($status, ['paid', 'shipped'], true))
          <h2 style="margin:26px 0 12px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.what_happens_next') }}</h2>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
            <tr>
              <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:{{ $primary }};color:#fff;text-align:center;font-size:12px;line-height:22px;font-weight:800;">1</span></td>
              <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.next_confirmed') }}</strong><br>{{ __('mail.next_confirmed_detail') }}</td>
            </tr>
            <tr>
              <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:#e4e7ec;color:#475467;text-align:center;font-size:12px;line-height:22px;font-weight:800;">2</span></td>
              <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.next_preparing') }}</strong><br>{{ __('mail.next_preparing_detail') }}</td>
            </tr>
            <tr>
              <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:#e4e7ec;color:#475467;text-align:center;font-size:12px;line-height:22px;font-weight:800;">3</span></td>
              <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.next_tracking') }}</strong><br>{{ __('mail.next_tracking_detail') }}</td>
            </tr>
          </table>
        @endif

        @if ($order->orderShipments->count())
          <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.shipment_details') }}</h2>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border:1px solid #e4e7ec;border-radius:12px;">
            @foreach ($order->orderShipments as $ship)
              @php
                $trackingUrl = \Beike\Services\ShipmentService::trackingUrl($ship);
              @endphp
              <tr>
                <td style="padding:14px 16px;{{ $loop->first ? '' : 'border-top:1px solid #edf0f4;' }}">
                  <div style="font-size:14px;line-height:21px;font-weight:700;color:#172033;">{{ $ship->express_company }}</div>
                  <div style="margin-top:3px;font-size:13px;line-height:20px;color:#667085;">{{ __('mail.tracking_number', ['number' => $ship->express_number]) }}</div>
                  @if ($trackingUrl)
                    <a href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:7px;color:{{ $primary }};font-size:13px;line-height:20px;font-weight:700;text-decoration:none;">{{ __('mail.track_this_shipment') }} &rarr;</a>
                  @endif
                </td>
              </tr>
            @endforeach
          </table>
        @else
          @if ($status === 'paid')
            <p style="margin:24px 0 0;padding:13px 15px;border-radius:10px;background:#f8fafc;font-size:13px;line-height:21px;color:#475467;">
              {{ __('mail.tracking_available_after_dispatch') }}
            </p>
          @endif
        @endif

        <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.order_items_heading') }}</h2>
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

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin-top:26px;">
          <tr>
            <td align="center">
              <a class="mail-button" href="{{ $ctaUrl }}" @if ($carrierTrackingUrl && $status === 'shipped') target="_blank" rel="noopener noreferrer" @endif style="display:inline-block;min-width:250px;padding:14px 22px;border-radius:10px;background:{{ $primary }};color:#ffffff;text-align:center;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;">
                {{ $ctaLabel }}
              </a>
            </td>
          </tr>
        </table>
        <p style="margin:18px 0 0;font-size:12px;line-height:19px;color:#98a2b3;text-align:center;">
          {{ __('mail.secure_order_link_note') }}
        </p>
      </td>
    </tr>
  </tbody>
@endsection
