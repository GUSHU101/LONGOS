<!doctype html>
@php
  $paymentDecimals = \App\Services\PlatformPayment\PlatformPaymentCurrency::decimalPlaces((string) $session->currency);
  $paymentAmount = \App\Services\PlatformPayment\PlatformPaymentAmount::format((string) $session->amount, $paymentDecimals);
  $paymentAmountLabel = $session->currency . ' ' . $paymentAmount;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>{{ __('platform_pay.title') }} · {{ platform_brand_name() }}</title>
  @if(in_array('stripe', $providers, true))<link rel="preconnect" href="https://js.stripe.com">@endif
  @if(in_array('paypal', $providers, true))<link rel="preconnect" href="https://www.paypal.com">@endif
  <style>
    :root{color-scheme:light;--blue:#155eef;--ink:#101828;--muted:#667085;--line:#d0d5dd;--soft:#f2f4f7;--danger:#b42318;--focus:#84adff}
    *{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:var(--ink);font:16px/1.5 ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .shell{width:min(100% - 32px,720px);margin:40px auto}.brand{display:flex;align-items:center;gap:10px;margin-bottom:20px;font-weight:800;letter-spacing:.04em}.brand-mark{display:grid;width:34px;height:34px;border-radius:10px;background:var(--blue);color:#fff;place-items:center}
    .card{overflow:hidden;border:1px solid #e4e7ec;border-radius:20px;background:#fff;box-shadow:0 18px 46px rgba(16,24,40,.08)}.head{padding:28px;border-bottom:1px solid #eaecf0}.eyebrow{margin:0 0 6px;color:var(--blue);font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.head h1{margin:0;font-size:clamp(1.5rem,5vw,2rem);line-height:1.2}.store{margin:8px 0 0;color:var(--muted)}.amount{margin-top:22px;font-size:2rem;font-weight:800;font-variant-numeric:tabular-nums}.body{padding:28px}.notice{padding:14px 16px;border:1px solid #b2ccff;border-radius:12px;background:#eff4ff;color:#1849a9;font-size:.9rem}
    .methods{display:grid;gap:14px;margin-top:22px}.method{border:1px solid var(--line);border-radius:14px;padding:18px}.method h2{margin:0 0 6px;font-size:1.05rem}.method p{margin:0 0 14px;color:var(--muted);font-size:.9rem}.stripe-card{min-height:48px;padding:14px;border:1px solid var(--line);border-radius:10px;background:#fff}.stripe-card:focus-within{border-color:var(--blue);box-shadow:0 0 0 4px var(--focus)}button,.button{display:inline-flex;min-height:48px;align-items:center;justify-content:center;border:0;border-radius:10px;padding:11px 18px;background:var(--blue);color:#fff;font:inherit;font-weight:750;cursor:pointer;text-decoration:none}button:hover{background:#004eeb}button:focus-visible,.button:focus-visible{outline:3px solid var(--focus);outline-offset:2px}button:disabled{cursor:wait;opacity:.65}.cancel{margin-top:18px;background:transparent;color:#344054}.cancel:hover{background:var(--soft)}.status{min-height:24px;margin-top:14px;color:var(--muted)}.status.error,.notice.error{color:var(--danger)}.notice.error{border-color:#fecdca;background:#fef3f2}.footer{padding:18px 28px;border-top:1px solid #eaecf0;color:var(--muted);font-size:.8rem}.hidden{display:none!important}
    @media(max-width:520px){.shell{width:100%;margin:0}.card{min-height:100vh;border:0;border-radius:0;box-shadow:none}.head,.body{padding:22px}.footer{padding:18px 22px}}
    @media(prefers-reduced-motion:no-preference){button{transition:background-color .15s ease,opacity .15s ease}}
  </style>
  @if(in_array('stripe', $providers, true))<script src="https://js.stripe.com/v3/" defer></script>@endif
  @if(in_array('paypal', $providers, true))
    <script src="https://www.paypal.com/sdk/js?client-id={{ rawurlencode($paypalClientId) }}&currency={{ rawurlencode($session->currency) }}&intent=capture&components=buttons@if($paypalMerchantId !== '')&merchant-id={{ rawurlencode($paypalMerchantId) }}@endif" data-namespace="LongosPlatformPayPal" defer></script>
  @endif
</head>
<body>
<main class="shell">
  <div class="brand"><span class="brand-mark" aria-hidden="true">L</span><span>{{ platform_brand_name() }}</span></div>
  <section class="card" aria-labelledby="payment-title">
    <header class="head">
      <p class="eyebrow">{{ __('platform_pay.eyebrow') }}</p>
      <h1 id="payment-title">{{ __('platform_pay.title') }}</h1>
      <p class="store">{{ __('platform_pay.collected_by', ['platform' => platform_brand_name(), 'store' => $session->site->name]) }}</p>
      <div class="amount" aria-label="{{ __('platform_pay.amount_label') }}">{{ $paymentAmountLabel }}</div>
    </header>
    <div class="body">
      @if($providers === [])
        <div class="notice error" role="alert"><strong>{{ __('platform_pay.status.unavailable') }}</strong><br>{{ __('platform_pay.unavailable_help') }}</div>
      @else
        <div class="notice" role="note">{{ __('platform_pay.notice', ['store' => $session->site->name]) }}</div>
      @endif
      @if($providers !== [])
      <div class="methods" aria-label="{{ __('platform_pay.methods_label') }}">
        @if(in_array('paypal', $providers, true))
          <section class="method" id="paypal-method" aria-labelledby="paypal-heading">
            <h2 id="paypal-heading">PayPal</h2><p>{{ __('platform_pay.paypal_help') }}</p>
            <div id="paypal-buttons"></div>
          </section>
        @endif
        @if(in_array('stripe', $providers, true))
          <section class="method" id="stripe-method" aria-labelledby="stripe-heading">
            <h2 id="stripe-heading">{{ __('platform_pay.card') }}</h2><p>{{ __('platform_pay.card_help') }}</p>
            <form id="stripe-form">
              <label for="stripe-card" style="display:block;margin-bottom:8px;font-weight:650">{{ __('platform_pay.card_details') }}</label>
              <div id="stripe-card" class="stripe-card"></div>
              <button id="stripe-submit" type="submit" style="margin-top:14px" disabled aria-busy="true">{{ __('platform_pay.pay', ['amount' => $paymentAmountLabel]) }}</button>
            </form>
          </section>
        @endif
      </div>
      <div id="payment-status" class="status" role="status" aria-live="polite" tabindex="-1"></div>
      @endif
      <a class="button {{ $providers === [] ? '' : 'cancel' }}" href="{{ $cancelUrl }}">{{ $providers === [] ? __('platform_pay.return_to_store') : __('platform_pay.cancel') }}</a>
    </div>
    <footer class="footer">{{ __('platform_pay.expires', ['time' => $session->expires_at->format('Y-m-d H:i T')]) }}</footer>
  </section>
</main>
<script>
window.addEventListener('DOMContentLoaded', () => {
  const token = @json($token);
  const apiRoot = `/_longos/pay/${encodeURIComponent(token)}`
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
  const status = document.getElementById('payment-status')
  const headers = {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}
  let providerLocked = false
  const setStatus = (message, error = false) => { status.textContent = message; status.classList.toggle('error', error) }
  const methodUnavailable = (method, message) => {
    method?.classList.add('hidden')
    const hasAlternative = [...document.querySelectorAll('.method')].some(item => !item.classList.contains('hidden'))
    if (!hasAlternative) { setStatus(message, true); status.focus?.() }
  }
  const request = async (path, body = {}) => {
    const controller = new AbortController()
    const timeout = window.setTimeout(() => controller.abort(), 35000)
    try {
      const response = await fetch(`${apiRoot}/${path}`, {method:'POST',credentials:'same-origin',headers,body:JSON.stringify(body),signal:controller.signal})
      const payload = await response.json().catch(() => null)
      if (!response.ok || payload?.status !== 'success') throw new Error(payload?.message || @json(__('platform_pay.status.unavailable')))
      return payload.data || {}
    } catch (error) {
      if (error?.name === 'AbortError') throw new Error(@json(__('platform_pay.status.timeout')))
      if (error instanceof TypeError) throw new Error(@json(__('platform_pay.status.unavailable')))
      throw error
    } finally {
      window.clearTimeout(timeout)
    }
  }
  const lockProvider = (provider) => {
    if (providerLocked && providerLocked !== provider) throw new Error(@json(__('platform_pay.status.locked')))
    providerLocked = provider
    document.querySelectorAll('.method').forEach(method => method.classList.toggle('hidden', method.id !== `${provider}-method`))
  }
  const unlockUnboundProvider = provider => {
    if (providerLocked !== provider) return
    providerLocked = false
    document.querySelectorAll('.method').forEach(method => method.classList.remove('hidden'))
  }
  const finish = data => { if (!data.redirect_url) throw new Error(@json(__('platform_pay.status.missing_redirect'))); window.location.assign(data.redirect_url) }
  const wait = delay => new Promise(resolve => window.setTimeout(resolve, delay))
  const recoverCompletion = async () => {
    setStatus(@json(__('platform_pay.status.checking')))
    for (const delay of [0, 1000, 2000]) {
      if (delay) await wait(delay)
      try {
        const result = await request('status', {}, 8000)
        if (result.completed && result.redirect_url) { finish(result); return true }
      } catch (error) {
        // Best-effort status probes must not replace the original provider error.
      }
    }
    return false
  }

  @if(in_array('paypal', $providers, true))
  let paypalLoadAttempts = 0
  const mountPaypal = () => {
    if (!window.LongosPlatformPayPal?.Buttons) {
      if (++paypalLoadAttempts >= 100) {
        methodUnavailable(document.getElementById('paypal-method'), @json(__('platform_pay.status.paypal_load')))
        return
      }
      setTimeout(mountPaypal, 100); return
    }
    try {
      const buttons = window.LongosPlatformPayPal.Buttons({
        style:{layout:'vertical',shape:'rect',label:'paypal'},
        createOrder: async () => {
          lockProvider('paypal'); setStatus(@json(__('platform_pay.status.connect_paypal')))
          try { const data = await request('paypal/create'); return data.id }
          catch (error) { unlockUnboundProvider('paypal'); throw error }
        },
        onApprove: async data => {
          try {
            setStatus(@json(__('platform_pay.status.verify_paypal')))
            finish(await request('paypal/capture',{paypal_order_id:data.orderID}))
          } catch (error) {
            if (await recoverCompletion()) return
            setStatus(error?.message || @json(__('platform_pay.status.paypal_failed')), true)
            status.focus?.()
          }
        },
        onCancel: () => setStatus(@json(__('platform_pay.status.cancelled')), true),
        onError: async () => {
          if (await recoverCompletion()) return
          setStatus(@json(__('platform_pay.status.paypal_failed')), true); status.focus?.()
        },
      })
      if (typeof buttons.isEligible === 'function' && !buttons.isEligible()) {
        methodUnavailable(document.getElementById('paypal-method'), @json(__('platform_pay.status.paypal_unavailable')))
        return
      }
      buttons.render('#paypal-buttons').catch(() => methodUnavailable(document.getElementById('paypal-method'), @json(__('platform_pay.status.paypal_load'))))
    } catch (error) {
      methodUnavailable(document.getElementById('paypal-method'), @json(__('platform_pay.status.paypal_load')))
    }
  }
  mountPaypal()
  @endif

  @if(in_array('stripe', $providers, true))
  let stripeLoadAttempts = 0
  const mountStripe = () => {
    if (typeof window.Stripe !== 'function') {
      if (++stripeLoadAttempts >= 100) {
        methodUnavailable(document.getElementById('stripe-method'), @json(__('platform_pay.status.stripe_failed')))
        return
      }
      setTimeout(mountStripe, 100); return
    }
    let stripe
    let card
    let readyTimeout
    let setupSettled = false
    const button = document.getElementById('stripe-submit')
    const failSetup = () => {
      if (setupSettled) return
      setupSettled = true
      window.clearTimeout(readyTimeout)
      button.disabled = true
      button.removeAttribute('aria-busy')
      try { card?.unmount() } catch (ignored) {}
      methodUnavailable(document.getElementById('stripe-method'), @json(__('platform_pay.status.stripe_failed')))
    }
    try {
      stripe = window.Stripe(@json($stripePublishableKey))
      card = stripe.elements({locale:'auto'}).create('card',{hidePostalCode:false})
      readyTimeout = window.setTimeout(failSetup, 15000)
      card.on('ready', () => {
        if (setupSettled) return
        setupSettled = true
        window.clearTimeout(readyTimeout)
        button.disabled = false
        button.removeAttribute('aria-busy')
      })
      card.on('loaderror', failSetup)
      card.mount('#stripe-card')
      card.on('change', event => { if (event.error) setStatus(event.error.message, true) })
    } catch (error) {
      failSetup()
      return
    }
    document.getElementById('stripe-form').addEventListener('submit', async event => {
      event.preventDefault()
      if (button.disabled) return
      let intentCreated = false
      try {
        lockProvider('stripe'); button.disabled = true; button.setAttribute('aria-busy','true'); setStatus(@json(__('platform_pay.status.create_stripe')))
        const intent = await request('stripe/intent')
        intentCreated = true
        const confirmation = await stripe.confirmCardPayment(intent.client_secret, {
          payment_method:{card},
          return_url:`${window.location.origin}/_longos/pay/${token}/return/stripe`,
        })
        if (confirmation.error) throw new Error(confirmation.error.message)
        if (confirmation.paymentIntent?.status !== 'succeeded') throw new Error(@json(__('platform_pay.status.stripe_incomplete')))
        setStatus(@json(__('platform_pay.status.verify')))
        finish(await request('stripe/complete',{payment_intent_id:confirmation.paymentIntent.id}))
      } catch (error) {
        if (!intentCreated) unlockUnboundProvider('stripe')
        if (intentCreated && await recoverCompletion()) return
        setStatus(error?.message || @json(__('platform_pay.status.stripe_failed')), true); button.disabled = false; button.removeAttribute('aria-busy'); button.focus()
      }
    })
  }
  mountStripe()
  @endif
})
</script>
</body>
</html>
