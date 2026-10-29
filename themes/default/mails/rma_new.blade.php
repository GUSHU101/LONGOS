@extends('layout.mail')

@php
  $primary = site_brand_style()['primary_color'];
  $orderProduct = $rma->orderProduct ?: \Beike\Repositories\OrderProductRepo::find($rma->order_product_id);
  $reasonNames = $rma->reason?->name ? json_decode((string) $rma->reason->name, true) : [];
  $reasonNames = is_array($reasonNames) ? $reasonNames : [];
  $reason = $reasonNames[app()->getLocale()] ?? $reasonNames['en'] ?? (string) collect($reasonNames)->first();
  $returnUrl = shop_route('account.rma.show', ['id' => $rma->id]);
@endphp

@section('title', __('mail.subject_return_received', ['number' => $rma->order?->number]))
@section('preheader', __('mail.return_preheader', ['product' => $orderProduct?->name ?: $rma->product_name]))

@section('content')
  <tbody>
    <tr>
      <td>
        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#eff8ff;color:#175cd3;font-size:12px;line-height:16px;font-weight:700;letter-spacing:.02em;">
          {{ __('mail.return_received_badge') }}
        </span>
        <h1 style="margin:16px 0 10px;font-size:30px;line-height:38px;letter-spacing:-.02em;color:#172033;">
          {{ __('mail.return_heading') }}
        </h1>
        <p style="margin:0 0 22px;font-size:15px;line-height:25px;color:#475467;">
          {{ __('mail.return_intro', ['name' => $rma->name ?: __('mail.customer_fallback')]) }}
        </p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;background:#f8fafc;border:1px solid #e4e7ec;border-radius:12px;">
          <tr>
            <td style="padding:16px 18px;">
              <table role="presentation" class="mail-stack" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
                <tr>
                  <td style="width:34%;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.return_reference') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">#{{ $rma->id }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.return_type') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $rma->type_format }}</div>
                  </td>
                  <td style="width:33%;padding-left:14px;vertical-align:top;">
                    <div style="font-size:11px;line-height:16px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#98a2b3;">{{ __('mail.return_status') }}</div>
                    <div style="margin-top:4px;font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $rma->status_format }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        @if ($orderProduct)
          <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.return_item_heading') }}</h2>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border:1px solid #e4e7ec;border-radius:12px;">
            <tr>
              <td style="width:82px;padding:14px;vertical-align:middle;">
                <img class="mail-product-image" src="{{ image_resize($orderProduct->image, 144, 144) }}" width="64" height="64" alt="{{ $orderProduct->name }}" style="display:block;width:64px;height:64px;border:0;border-radius:10px;object-fit:cover;background:#f2f4f7;">
              </td>
              <td style="padding:14px 14px 14px 0;vertical-align:middle;">
                <div style="font-size:14px;line-height:20px;font-weight:700;color:#172033;">{{ $orderProduct->name }}</div>
                <div style="margin-top:4px;font-size:13px;line-height:19px;color:#667085;">{{ __('mail.return_quantity', ['quantity' => $rma->quantity]) }}</div>
                @if ($reason !== '')
                  <div style="margin-top:4px;font-size:13px;line-height:19px;color:#667085;">{{ __('mail.return_reason', ['reason' => $reason]) }}</div>
                @endif
              </td>
            </tr>
          </table>
        @endif

        @if (trim((string) ($rma['comment'] ?? '')) !== '')
          <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.return_comment_heading') }}</h2>
          <div style="padding:14px 16px;border:1px solid #e4e7ec;border-radius:10px;background:#f8fafc;font-size:13px;line-height:21px;color:#475467;">
            {!! nl2br(e((string) ($rma['comment'] ?? ''))) !!}
          </div>
        @endif

        <h2 style="margin:26px 0 10px;font-size:16px;line-height:24px;color:#172033;">{{ __('mail.return_next_heading') }}</h2>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:{{ $primary }};color:#fff;text-align:center;font-size:12px;line-height:22px;font-weight:800;">1</span></td>
            <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.return_step_review') }}</strong><br>{{ __('mail.return_step_review_detail') }}</td>
          </tr>
          <tr>
            <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:#e4e7ec;color:#475467;text-align:center;font-size:12px;line-height:22px;font-weight:800;">2</span></td>
            <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.return_step_instructions') }}</strong><br>{{ __('mail.return_step_instructions_detail') }}</td>
          </tr>
          <tr>
            <td style="width:28px;padding:6px 0;vertical-align:top;"><span style="display:block;width:22px;height:22px;border-radius:999px;background:#e4e7ec;color:#475467;text-align:center;font-size:12px;line-height:22px;font-weight:800;">3</span></td>
            <td style="padding:6px 0 6px 8px;font-size:13px;line-height:21px;color:#475467;"><strong style="color:#172033;">{{ __('mail.return_step_resolution') }}</strong><br>{{ __('mail.return_step_resolution_detail') }}</td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin-top:26px;">
          <tr>
            <td align="center">
              <a class="mail-button" href="{{ $returnUrl }}" style="display:inline-block;min-width:240px;padding:14px 22px;border-radius:10px;background:{{ $primary }};color:#ffffff;text-align:center;text-decoration:none;font-size:15px;line-height:20px;font-weight:800;">
                {{ __('mail.view_return_status') }}
              </a>
            </td>
          </tr>
        </table>
        <p style="margin:18px 0 0;font-size:12px;line-height:19px;color:#98a2b3;text-align:center;">
          {{ __('mail.return_account_note') }}
        </p>
      </td>
    </tr>
  </tbody>
@endsection
