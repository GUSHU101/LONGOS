@extends('layout.master')

@section('body-class', 'page-order-recovery')
@section('title', __('shop/order_recovery.title') . ' · ' . site_brand_name())

@push('header')
  <meta name="robots" content="noindex,nofollow">
  <style>
    .recovery-shell{max-width:860px;margin:48px auto 72px;padding:0 20px}.recovery-card{background:#fff;border:1px solid #eceff3;border-radius:18px;box-shadow:0 18px 50px rgba(15,23,42,.08);overflow:hidden}.recovery-head{padding:32px;background:linear-gradient(135deg,#fff7ed,#fff);border-bottom:1px solid #f3f4f6}.recovery-head h1{font-size:30px;margin:0 0 10px}.recovery-head p{margin:0;color:#64748b}.recovery-body{padding:28px 32px}.recovery-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}.recovery-meta div{background:#f8fafc;border-radius:10px;padding:12px}.recovery-meta span{display:block;color:#64748b;font-size:12px;margin-bottom:4px}.recovery-products{width:100%;border-collapse:collapse}.recovery-products th,.recovery-products td{padding:12px 8px;border-bottom:1px solid #eef2f7;text-align:left}.recovery-products img{width:54px;height:54px;object-fit:cover;border-radius:8px}.recovery-discount{margin:22px 0;padding:16px;border:1px dashed #fb923c;background:#fff7ed;border-radius:10px}.recovery-code{font-size:22px;letter-spacing:2px;font-weight:700;color:#c2410c}.recovery-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.recovery-actions form{margin:0}.recovery-primary{border:0;background:#fd560f;color:#fff;border-radius:8px;padding:13px 22px;font-weight:600}.recovery-secondary{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:8px;padding:12px 18px}.recovery-unavailable{padding:14px 16px;background:#fef2f2;color:#991b1b;border-radius:8px;margin-top:20px}@media(max-width:640px){.recovery-meta{grid-template-columns:1fr}.recovery-head,.recovery-body{padding:22px}.recovery-products th:nth-child(1),.recovery-products td:nth-child(1){display:none}}
  </style>
@endpush

@section('content')
  <main class="recovery-shell">
    <section class="recovery-card" aria-labelledby="recovery-title">
      <header class="recovery-head">
        <h1 id="recovery-title">{{ __('shop/order_recovery.heading') }}</h1>
        <p>{{ __('shop/order_recovery.description') }}</p>
      </header>
      <div class="recovery-body">
        @if (session('success'))
          <x-shop-alert type="success" msg="{{ session('success') }}" class="mb-4"/>
        @endif
        @if ($errors->any())
          <x-shop-alert type="danger" msg="{{ $errors->first() }}" class="mb-4"/>
        @endif

        <div class="recovery-meta">
          <div><span>{{ __('shop/order_recovery.order_number') }}</span><strong>{{ $order->number }}</strong></div>
          <div><span>{{ __('shop/order_recovery.order_date') }}</span><strong>{{ $order->created_at }}</strong></div>
          <div><span>{{ __('shop/order_recovery.order_total') }}</span><strong>{{ $order->total_format }}</strong></div>
        </div>

        <table class="recovery-products">
          <thead><tr><th>{{ __('product.image') }}</th><th>{{ __('product.name') }}</th><th>{{ __('cart.quantity') }}</th></tr></thead>
          <tbody>
          @foreach ($order->orderProducts as $product)
            <tr>
              <td><img src="{{ image_origin($product->image) }}" alt=""></td>
              <td>{{ $product->name }}</td>
              <td>{{ $product->quantity }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>

        @if ($recovery->discount_code)
          <div class="recovery-discount">
            <div>{{ __('shop/order_recovery.discount_attached') }}</div>
            <div class="recovery-code">{{ $recovery->discount_code }}</div>
          </div>
        @endif

        @if ($canRestore)
          <p class="small text-muted mt-4 mb-2">{{ __('shop/order_recovery.rebuild_notice') }}</p>
          <div class="recovery-actions">
            <form method="post" action="{{ shop_route('order_recovery.restore', ['token' => $token]) }}">
              @csrf
              <button class="recovery-primary" type="submit">{{ __('shop/order_recovery.restore_checkout') }}</button>
            </form>
            <form method="post" action="{{ shop_route('order_recovery.suppress', ['token' => $token]) }}">
              @csrf
              <button class="recovery-secondary" type="submit">{{ __('shop/order_recovery.stop_reminders') }}</button>
            </form>
          </div>
        @else
          <div class="recovery-unavailable" role="status">{{ __('shop/order_recovery.no_longer_available') }}</div>
        @endif
      </div>
    </section>
  </main>
@endsection
