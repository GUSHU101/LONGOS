@extends('layout.mail')

@section('content')
  <tbody>
    <tr>
      <td style="width:3.2%;max-width:30px;"></td>
      <td style="max-width:540px;text-align:left;padding:0 2px;">
        <h1 style="font-size:22px;line-height:32px;margin:0 0 18px;color:#1f2937;">
          {{ $template['heading'] }}
        </h1>
        <div style="font-size:14px;color:#374151;line-height:24px;margin:0 0 18px;">
          {!! nl2br(e($template['message'])) !!}
        </div>

        <table style="width:100%;border-collapse:collapse;margin:16px 0 18px;">
          <tbody>
            <tr>
              <td style="padding:9px 10px;border:1px solid #e5e7eb;background:#f9fafb;font-size:13px;">{{ __('shop/order_recovery.order_number') }}</td>
              <td style="padding:9px 10px;border:1px solid #e5e7eb;font-size:13px;font-weight:600;">{{ $order->number }}</td>
            </tr>
            <tr>
              <td style="padding:9px 10px;border:1px solid #e5e7eb;background:#f9fafb;font-size:13px;">{{ __('shop/order_recovery.order_total') }}</td>
              <td style="padding:9px 10px;border:1px solid #e5e7eb;font-size:13px;font-weight:600;">{{ $order->total_format }}</td>
            </tr>
          </tbody>
        </table>

        @if ($discount && $template['discount_message'])
          <div style="padding:14px 16px;margin:0 0 18px;background:#fff7ed;border:1px dashed #fb923c;border-radius:6px;color:#9a3412;font-size:14px;line-height:22px;">
            {{ $template['discount_message'] }}
            <div style="font-size:20px;letter-spacing:2px;font-weight:700;color:#c2410c;margin-top:8px;">{{ $discount->code }}</div>
          </div>
        @endif

        <a href="{{ $recoveryUrl }}" style="display:block;background:#fd560f;color:#fff;text-align:center;text-decoration:none;font-size:16px;font-weight:600;line-height:48px;border-radius:5px;margin:20px 0;">
          {{ $template['button_label'] }}
        </a>

        <p style="font-size:12px;line-height:20px;color:#6b7280;margin:18px 0 0;">
          {{ __('shop/order_recovery.email_security_note') }}
        </p>
      </td>
      <td style="width:3.2%;max-width:30px;"></td>
    </tr>
  </tbody>
@endsection
