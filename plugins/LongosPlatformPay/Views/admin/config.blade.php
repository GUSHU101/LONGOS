@extends('admin::layouts.master')

@php
  $settings = is_array($plugin->getSetting()) ? $plugin->getSetting() : [];
  $accounts = [];
  $registryReady = false;
  try {
    $registry = app(\App\Services\PlatformPayment\PlatformPaymentAccountService::class);
    $registryReady = $registry->schemaReady();
    $accounts = $registryReady ? $registry->publicSummaries() : [];
  } catch (\Throwable) {
    $registryReady = false;
  }
  $enabled = (bool) old('status', $settings['status'] ?? false);
  $sharePaypal = (bool) old('share_paypal', $settings['share_paypal'] ?? true);
  $shareStripe = (bool) old('share_stripe', $settings['share_stripe'] ?? false);
  $statusTone = ($platformPaymentRuntimeStatus['tone'] ?? 'danger');
  $statusTone = in_array($statusTone, ['success', 'warning', 'danger'], true) ? $statusTone : 'danger';
@endphp

@section('title', 'LONGOS 平台代收')
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('plugins.index'))

@section('content')
<div class="lpp-settings">
  @if (session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mb-3"/>
  @endif
  @if ($errors->any())
    <div class="alert alert-danger mb-3" role="alert"><strong>设置尚未保存。</strong><ul class="mb-0 mt-2 ps-3">@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif

  <header class="lpp-hero">
    <div>
      <span class="lpp-eyebrow">LONGOS PLATFORM PAY</span>
      <h1>多账户收款路由</h1>
      <p>为每个 PayPal 收款账户生成独立、可审计的回传域名。子站结账始终停留在自己的域名；账户只在创建支付会话时被选定，回跳、捕获与对账始终使用同一账户。</p>
    </div>
    <div class="lpp-hero-status is-{{ $statusTone }}"><span>运行状态</span><strong>{{ $platformPaymentRuntimeStatus['description'] ?? '正在检查' }}</strong></div>
  </header>

  <section class="lpp-flow" aria-label="支付路由流程">
    <div><b>01</b><strong>子站结账</strong><span>顾客在当前子站完成地址与订单确认。</span></div>
    <div><b>02</b><strong>固定收款账户</strong><span>新会话按健康账户轮询并锁定，不会中途换号。</span></div>
    <div><b>03</b><strong>专属安全回跳</strong><span>PayPal 仅返回该账户生成的受控 HTTPS 域名。</span></div>
    <div><b>04</b><strong>返回订单结果</strong><span>LONGOS 校验、捕获后回到原子站的成功页。</span></div>
  </section>

  <aside class="lpp-notice" role="note">
    <strong>合规要求</strong>
    <span>每个自动生成的回传域名都必须由平台 DNS 托管，并在对应的 PayPal 应用/商户配置中获准使用。不得把它用于伪造来源、隐藏真实商户或规避 PayPal 审核。账户暂停后不再分配新订单，已创建订单仍可安全完成和对账。</span>
  </aside>

  <section class="lpp-card">
    <div class="lpp-section-head"><div><h2>PayPal 收款账户池</h2><p>采用“最久未分配优先”的轮询策略。选择发生在支付会话创建时，SDK Client ID、PayPal 订单、回传域名和后续捕获均不可跨账户切换。</p></div><span class="lpp-count">{{ count($accounts) }} 个账户</span></div>
    @if (! $registryReady)
      <div class="alert alert-warning mb-0">账户池数据库尚未就绪。请先通过正常的 Coolify 发布流程完成升级，再刷新此页；不需要在生产容器中手工执行迁移。</div>
    @else
      <div class="lpp-account-grid">
      @forelse ($accounts as $account)
        <article class="lpp-account {{ $account['enabled'] ? '' : 'is-paused' }}">
          <div class="lpp-account-head"><div><h3>{{ $account['label'] }}</h3><span>{{ $account['mode'] === 'live' ? '正式环境' : '沙盒环境' }} · {{ $account['client_id_suffix'] }}</span></div><span class="lpp-badge {{ $account['enabled'] ? 'is-ready' : 'is-muted' }}">{{ $account['enabled'] ? '轮询中' : '已暂停' }}</span></div>
          <dl>
            <div><dt>回传域名</dt><dd><code>https://{{ $account['callback_host'] }}/_longos/pay/…</code></dd></div>
            <div><dt>PayPal 商户 ID</dt><dd>{{ $account['merchant_id'] ?: '由 PayPal 应用默认商户接收' }}</dd></div>
            <div><dt>已分配会话</dt><dd>{{ number_format($account['rotation_count']) }}</dd></div>
            <div><dt>轮询权重</dt><dd>{{ $account['rotation_weight'] }}</dd></div>
            <div><dt>最近分配</dt><dd>{{ $account['last_selected_at'] ?: '尚未分配' }}</dd></div>
            <div><dt>连接状态</dt><dd><span class="lpp-health is-{{ $account['health_status'] }}">{{ $account['health_status'] === 'ready' ? '已验证' : ($account['health_status'] === 'failed' ? '需检查' : '未验证') }}</span>{{ $account['last_health_checked_at'] ? ' · ' . $account['last_health_checked_at'] : '' }}{{ $account['circuit_open_until'] ? ' · 熔断至 ' . $account['circuit_open_until'] : '' }}</dd></div>
          </dl>
          <div class="lpp-account-actions">
            <form method="POST" action="{{ admin_route('plugins.platform_payment_accounts.health', ['account' => $account['id']]) }}">@csrf<button class="btn btn-outline-primary btn-sm" type="submit">验证 PayPal 连接</button></form>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-lpp-copy="https://{{ $account['callback_host'] }}">复制回传域名</button>
          </div>
          <details class="lpp-edit"><summary>编辑账户</summary><form method="POST" action="{{ admin_route('plugins.platform_payment_accounts.update', ['account' => $account['id']]) }}" class="row g-3 mt-1">@csrf @method('PUT')
              <div class="col-md-6"><label class="form-label">账户名称</label><input class="form-control" name="label" required maxlength="96" value="{{ $account['label'] }}"></div>
              <div class="col-md-6"><label class="form-label">环境</label><select class="form-select" name="api_mode"><option value="sandbox" @selected($account['mode'] === 'sandbox')>Sandbox 沙盒</option><option value="live" @selected($account['mode'] === 'live')>Live 正式</option></select></div>
              <div class="col-md-6"><label class="form-label">Client ID <small class="text-secondary">留空保持不变</small></label><input class="form-control" name="client_id" maxlength="512" value="" placeholder="{{ $account['client_id_suffix'] }}"></div>
              <div class="col-md-6"><label class="form-label">Secret <small class="text-secondary">留空保持不变</small></label><input type="password" autocomplete="new-password" class="form-control" name="secret" maxlength="4096"></div>
              <div class="col-md-6"><label class="form-label">Merchant ID <small class="text-secondary">可选</small></label><input class="form-control" name="merchant_id" maxlength="13" value="{{ $account['merchant_id'] }}"></div>
              <div class="col-md-6"><label class="form-label">轮询权重</label><input class="form-control" type="number" min="1" max="100" name="rotation_weight" value="{{ $account['rotation_weight'] }}"><div class="form-text">权重 2 约为权重 1 的两倍新会话量。</div></div>
              <div class="col-md-6 d-flex align-items-end"><input type="hidden" name="enabled" value="0"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" @checked($account['enabled'])> <span class="form-check-label">参与新订单轮询</span></label></div>
              <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">保存账户</button><span class="text-secondary ms-2 small">更换密钥、环境或商户 ID 前，系统会检查是否存在进行中的交易。</span></div>
            </form></details>
        </article>
      @empty
        <div class="lpp-empty">尚未添加收款账户。添加后，LONGOS 会自动生成域名并注册为主站的受控回传域名。</div>
      @endforelse
      </div>

      <details class="lpp-add" @if (!count($accounts)) open @endif><summary><span>添加 PayPal 收款账户</span><small>密钥将加密保存，永不在页面回显。</small></summary>
        <form method="POST" action="{{ admin_route('plugins.platform_payment_accounts.store') }}" class="row g-3 mt-2">@csrf
          <div class="col-md-6"><label class="form-label">账户名称</label><input class="form-control" name="label" required maxlength="96" value="{{ old('label') }}" placeholder="例如：US Store A"></div>
          <div class="col-md-6"><label class="form-label">PayPal 环境</label><select class="form-select" name="api_mode"><option value="sandbox">Sandbox 沙盒</option><option value="live">Live 正式</option></select></div>
          <div class="col-md-6"><label class="form-label">Client ID</label><input class="form-control" name="client_id" required maxlength="512" value="{{ old('client_id') }}"></div>
          <div class="col-md-6"><label class="form-label">Client Secret</label><input class="form-control" type="password" autocomplete="new-password" name="secret" required maxlength="4096"></div>
          <div class="col-md-6"><label class="form-label">Merchant ID <small class="text-secondary">可选</small></label><input class="form-control" name="merchant_id" maxlength="13" value="{{ old('merchant_id') }}"><div class="form-text">若为空，PayPal 使用该应用默认的收款商户。</div></div>
          <div class="col-md-6"><label class="form-label">轮询权重</label><input class="form-control" type="number" min="1" max="100" name="rotation_weight" value="{{ old('rotation_weight', 1) }}"><div class="form-text">默认 1；数值越高，分配到的新支付会话越多。</div></div>
          <div class="col-md-6 d-flex align-items-end"><input type="hidden" name="enabled" value="0"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" checked> <span class="form-check-label">保存后立即加入轮询</span></label></div>
          <div class="col-12"><button class="btn btn-primary" type="submit">创建账户并生成回传域名</button></div>
        </form>
      </details>
    @endif
  </section>

  <section class="lpp-card">
    <div class="lpp-section-head"><div><h2>平台渠道开关</h2><p>账户池管理 PayPal；Stripe 保持现有的主站单渠道配置。保存不会显示任何已保存的密钥。</p></div></div>
    <form action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" class="row g-3">@csrf @method('PUT')
      <input type="hidden" name="managed_paypal_accounts" value="1"><input type="hidden" name="status" value="0"><input type="hidden" name="share_paypal" value="0"><input type="hidden" name="share_stripe" value="0">
      <div class="col-md-4"><label class="lpp-switch"><input type="checkbox" name="status" value="1" @checked($enabled)><span><strong>启用平台代收</strong><small>关闭后子站不会显示此方式。</small></span></label></div>
      <div class="col-md-4"><label class="lpp-switch"><input type="checkbox" name="share_paypal" value="1" @checked($sharePaypal)><span><strong>向子站共享 PayPal</strong><small>使用上方已启用的账户池。</small></span></label></div>
      <div class="col-md-4"><label class="lpp-switch"><input type="checkbox" name="share_stripe" value="1" @checked($shareStripe)><span><strong>向子站共享 Stripe</strong><small>使用下方主站 Stripe 配置。</small></span></label></div>
      <div class="col-md-6"><label class="form-label">Stripe 模式</label><select class="form-select" name="stripe_test_mode"><option value="1" @selected((bool)($settings['stripe_test_mode'] ?? true))>测试</option><option value="0" @selected(!((bool)($settings['stripe_test_mode'] ?? true)))>正式</option></select></div>
      <div class="col-md-6"><label class="form-label">Stripe Publishable Key</label><input class="form-control" name="stripe_publishable_key" maxlength="255" value="{{ old('stripe_publishable_key', $settings['stripe_publishable_key'] ?? '') }}"></div>
      <div class="col-md-6"><label class="form-label">Stripe Secret Key <small class="text-secondary">留空保持不变</small></label><input class="form-control" type="password" autocomplete="new-password" name="stripe_secret_key" maxlength="4096"></div>
      <div class="col-md-6"><label class="form-label">Stripe Webhook Secret <small class="text-secondary">留空保持不变</small></label><input class="form-control" type="password" autocomplete="new-password" name="stripe_webhook_secret" maxlength="4096"></div>
      <div class="col-12"><button type="submit" class="btn btn-primary px-4">保存平台渠道设置</button></div>
    </form>
  </section>
</div>
@endsection

@push('footer')
<style>
.lpp-settings{max-width:1180px}.lpp-hero{display:flex;justify-content:space-between;gap:24px;padding:28px;border:1px solid #d9e5ff;border-radius:18px;background:linear-gradient(135deg,#f6f9ff,#fff 58%);margin-bottom:16px}.lpp-eyebrow{font-size:11px;letter-spacing:.11em;font-weight:800;color:#3867d6}.lpp-hero h1{font-size:25px;margin:6px 0}.lpp-hero p{max-width:740px;margin:0;color:#53627a;line-height:1.7}.lpp-hero-status{min-width:200px;padding:14px 16px;border-radius:12px;background:#fff;border:1px solid #dfe6f1}.lpp-hero-status span,.lpp-hero-status strong{display:block}.lpp-hero-status span{font-size:12px;color:#667085}.lpp-hero-status strong{margin-top:6px;font-size:13px;line-height:1.5}.lpp-hero-status.is-success{border-color:#9bd7b2}.lpp-hero-status.is-danger{border-color:#f5b9b9}.lpp-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}.lpp-flow>div{padding:14px;border:1px solid #e5eaf2;border-radius:12px;background:#fff}.lpp-flow b,.lpp-flow strong,.lpp-flow span{display:block}.lpp-flow b{color:#315fd3;font-size:12px}.lpp-flow strong{margin:6px 0;font-size:14px}.lpp-flow span{font-size:12px;line-height:1.5;color:#667085}.lpp-notice{display:grid;grid-template-columns:auto 1fr;gap:10px;margin-bottom:16px;padding:13px 15px;border:1px solid #f2d28b;border-radius:12px;background:#fffaf0;color:#6d5118;font-size:13px;line-height:1.65}.lpp-card{padding:22px;margin-bottom:16px;border:1px solid #e3e8f0;border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(16,24,40,.035)}.lpp-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding-bottom:15px;margin-bottom:17px;border-bottom:1px solid #edf0f5}.lpp-section-head h2{margin:0 0 5px;font-size:17px}.lpp-section-head p{margin:0;color:#667085;font-size:13px;line-height:1.6}.lpp-count{padding:5px 9px;border-radius:999px;background:#edf4ff;color:#2358c8;font-size:12px;white-space:nowrap}.lpp-account-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.lpp-account{padding:17px;border:1px solid #dae4f1;border-radius:14px;background:#fcfdff}.lpp-account.is-paused{opacity:.72;background:#fafafa}.lpp-account-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.lpp-account h3{margin:0 0 3px;font-size:15px}.lpp-account-head span{font-size:12px;color:#667085}.lpp-badge,.lpp-health{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}.lpp-badge.is-ready,.lpp-health.is-ready{color:#087443;background:#ddf8e6}.lpp-badge.is-muted,.lpp-health.is-unknown{color:#667085;background:#f1f3f6}.lpp-health.is-failed{color:#bb2222;background:#ffe8e8}.lpp-account dl{margin:15px 0 12px}.lpp-account dl>div{display:grid;grid-template-columns:94px 1fr;gap:8px;padding:7px 0;border-bottom:1px solid #eef2f7;font-size:12px}.lpp-account dt{color:#667085;font-weight:400}.lpp-account dd{margin:0;overflow-wrap:anywhere;color:#344054}.lpp-account code{font-size:11px;white-space:normal}.lpp-account-actions{display:flex;gap:8px;align-items:center}.lpp-account-actions form{margin:0}.lpp-edit{margin-top:14px;border-top:1px solid #e9edf3;padding-top:11px}.lpp-edit summary,.lpp-add summary{cursor:pointer;color:#245ad0;font-weight:600;font-size:13px}.lpp-add{margin-top:18px;padding:17px;border:1px dashed #9bbcf8;border-radius:13px;background:#f8fbff}.lpp-add summary{display:flex;justify-content:space-between;gap:10px}.lpp-add small{color:#667085;font-weight:400}.lpp-empty{padding:28px;border:1px dashed #c9d3df;border-radius:12px;color:#667085;text-align:center}.lpp-switch{display:flex;min-height:82px;gap:12px;padding:15px;border:1px solid #e2e8f0;border-radius:12px;background:#fbfcfe;cursor:pointer}.lpp-switch input{margin-top:4px}.lpp-switch strong,.lpp-switch small{display:block}.lpp-switch small{margin-top:5px;color:#667085;font-size:12px;line-height:1.5}@media(max-width:800px){.lpp-hero{display:block}.lpp-hero-status{margin-top:16px}.lpp-flow,.lpp-account-grid{grid-template-columns:1fr}.lpp-notice{grid-template-columns:1fr}.lpp-section-head{display:block}.lpp-count{display:inline-block;margin-top:10px}}
</style>
<script>
document.querySelectorAll('[data-lpp-copy]').forEach((button) => button.addEventListener('click', async () => { try { await navigator.clipboard.writeText(button.dataset.lppCopy); const original=button.textContent; button.textContent='已复制'; setTimeout(()=>button.textContent=original,1600); } catch (_) { window.prompt('复制回传域名：', button.dataset.lppCopy); } }));
</script>
@endpush
