@php
  $inlineProviders = array_values(array_intersect(['paypal', 'stripe'], (array) $providers));
  $defaultProvider = $inlineProviders[0] ?? '';
@endphp
<style>
  .longos-platform-inline { background:#f8fafc; border:1px solid #dbe3ee; border-radius:12px; margin-top:14px; padding:16px; }
  .longos-platform-inline[hidden] { display:none!important; }
  .longos-platform-inline h3 { color:#172033; font-size:15px; font-weight:700; margin:0 0 4px; }
  .longos-platform-inline__intro { color:#667085; font-size:13px; margin:0 0 14px; }
  .longos-platform-inline__providers { display:grid; gap:8px; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); }
  .longos-platform-inline__provider { align-items:center; background:#fff; border:1px solid #cfd8e5; border-radius:9px; cursor:pointer; display:flex; font-weight:650; gap:9px; min-height:48px; padding:10px 12px; }
  .longos-platform-inline__provider:has(input:checked) { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
  .longos-platform-inline__provider input { accent-color:#2563eb; height:18px; width:18px; }
  .longos-platform-inline__card { background:#fff; border:1px solid #cfd8e5; border-radius:8px; margin-top:12px; min-height:48px; padding:14px 12px; }
  .longos-platform-inline__card.StripeElement--focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.14); }
  .longos-platform-inline__card.StripeElement--invalid { border-color:#b42318; }
  .longos-platform-inline__status { color:#667085; font-size:13px; margin:10px 0 0; min-height:20px; }
  .longos-platform-inline__status.is-error { color:#b42318; }
  .longos-platform-inline__fallback { display:inline-block; font-size:13px; margin-top:8px; }
  .longos-platform-inline__fallback[hidden] { display:none!important; }
</style>

<section id="longos-platform-inline" class="longos-platform-inline" hidden aria-hidden="true" aria-labelledby="longos-platform-inline-title">
  <h3 id="longos-platform-inline-title">{{ trans('LongosPlatformPay::common.inline_title') }}</h3>
  <p class="longos-platform-inline__intro">{{ trans('LongosPlatformPay::common.inline_intro') }}</p>
  <div class="longos-platform-inline__providers" role="radiogroup" aria-label="{{ trans('LongosPlatformPay::common.methods_label') }}">
    @foreach ($inlineProviders as $provider)
      <label class="longos-platform-inline__provider">
        <input type="radio" name="longos_platform_inline_provider" value="{{ $provider }}" {{ $provider === $defaultProvider ? 'checked' : '' }}>
        <span>{{ $provider === 'paypal' ? 'PayPal' : trans('LongosPlatformPay::common.card') }}</span>
      </label>
    @endforeach
  </div>
  @if (in_array('stripe', $inlineProviders, true))
    <div id="longos-platform-inline-card" class="longos-platform-inline__card" {{ $defaultProvider === 'stripe' ? '' : 'hidden' }}></div>
  @endif
  <p id="longos-platform-inline-status" class="longos-platform-inline__status" role="status" aria-live="polite"></p>
  <a id="longos-platform-inline-fallback" class="longos-platform-inline__fallback" href="#" hidden>{{ trans('LongosPlatformPay::common.fallback') }}</a>
</section>

<script>
(() => {
  const root = document.getElementById('longos-platform-inline')
  const status = document.getElementById('longos-platform-inline-status')
  const fallback = document.getElementById('longos-platform-inline-fallback')
  const cardHost = document.getElementById('longos-platform-inline-card')
  if (!root || !status || !fallback) return

  const config = {!! \Illuminate\Support\Js::from([
    'providers' => $inlineProviders,
    'stripePublishableKey' => $stripePublishableKey,
    'sessionUrl' => route('shop.longos_platform_pay.session', [], false),
  ]) !!};
  const messages = {!! \Illuminate\Support\Js::from([
    'loading' => trans('LongosPlatformPay::common.inline_loading'),
    'processing' => trans('LongosPlatformPay::common.inline_processing'),
    'choose' => trans('LongosPlatformPay::common.inline_choose'),
    'cardIncomplete' => trans('LongosPlatformPay::common.card_incomplete'),
    'failed' => trans('LongosPlatformPay::common.failed'),
    'timeout' => trans('LongosPlatformPay::common.timeout'),
    'configurationChanged' => trans('LongosPlatformPay::common.configuration_changed'),
  ]) !!};
  let stripe = null
  let card = null
  let cardComplete = false
  let stripeSetup = null
  let recoveryData = null

  const provider = () => root.querySelector('input[name="longos_platform_inline_provider"]:checked')?.value || ''
  const setStatus = (message, error = false) => {
    status.textContent = String(message || '')
    status.classList.toggle('is-error', error)
  }
  const request = async (url, body = {}, timeoutMs = 35000) => {
    const controller = new AbortController()
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs)
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
    try {
      const response = await fetch(url, {
        method:'POST', credentials:'same-origin', signal:controller.signal,
        headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body:JSON.stringify(body),
      })
      const payload = await response.json().catch(() => null)
      if (!response.ok || payload?.status !== 'success') throw new Error(payload?.message || messages.failed)
      return payload.data || {}
    } catch (error) {
      if (error?.name === 'AbortError') throw new Error(messages.timeout)
      if (error instanceof TypeError) throw new Error(messages.failed)
      throw error
    } finally {
      window.clearTimeout(timeout)
    }
  }
  const loadStripe = () => {
    if (typeof window.Stripe === 'function') return Promise.resolve()
    return new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-longos-stripe-sdk],script[src="https://js.stripe.com/v3/"]')
      if (existing?.dataset.loaded === '1' || typeof window.Stripe === 'function') { resolve(); return }
      const script = existing || document.createElement('script')
      const timeout = window.setTimeout(() => { if (!existing) script.remove(); reject(new Error(messages.failed)) }, 15000)
      const done = callback => () => { window.clearTimeout(timeout); callback() }
      script.addEventListener('load', done(() => { script.dataset.loaded = '1'; resolve() }), {once:true})
      script.addEventListener('error', done(() => { if (!existing) script.remove(); reject(new Error(messages.failed)) }), {once:true})
      if (!existing) {
        script.src = 'https://js.stripe.com/v3/'
        script.async = true
        script.dataset.longosStripeSdk = '1'
        document.head.appendChild(script)
      }
    })
  }
  const initializeStripe = () => {
    if (!cardHost || !config.stripePublishableKey) return Promise.reject(new Error(messages.failed))
    if (stripeSetup) return stripeSetup
    setStatus(messages.loading)
    stripeSetup = loadStripe().then(() => {
      stripe = window.Stripe(config.stripePublishableKey)
      card = stripe.elements({locale:'auto'}).create('card', {hidePostalCode:false})
      card.on('change', event => { cardComplete = event.complete; setStatus(event.error?.message || '') })
      card.mount(cardHost)
      setStatus('')
    }).catch(error => {
      stripeSetup = null
      setStatus(error?.message || messages.failed, true)
      throw error
    })
    return stripeSetup
  }
  const showProvider = () => {
    const selected = provider()
    if (cardHost) cardHost.hidden = selected !== 'stripe'
    if (selected === 'stripe') initializeStripe().catch(() => undefined)
    else setStatus('')
  }
  const isTrustedPaypalUrl = value => {
    try {
      const url = new URL(String(value || ''))
      return url.protocol === 'https:' && ['www.paypal.com', 'www.sandbox.paypal.com'].includes(url.hostname.toLowerCase())
    } catch (error) { return false }
  }
  const wait = delay => new Promise(resolve => window.setTimeout(resolve, delay))
  const recoverCompletion = async data => {
    for (const delay of [0, 1000, 2000]) {
      if (delay) await wait(delay)
      try {
        const result = await request(data.endpoints.status, {}, 8000)
        if (result.completed && result.redirect_url) {
          window.location.assign(result.redirect_url)
          return true
        }
      } catch (error) {}
    }
    return false
  }
  const selectedForCheckout = code => {
    const active = String(code || '') === 'longos_platform_pay'
    root.hidden = !active
    root.setAttribute('aria-hidden', active ? 'false' : 'true')
    if (active) showProvider()
  }

  root.addEventListener('change', event => {
    if (event.target.matches('input[name="longos_platform_inline_provider"]')) showProvider()
  })
  window.addEventListener('longos:checkout-payment-changed', event => selectedForCheckout(event.detail?.code))
  window.longosCheckoutPaymentHandlers = window.longosCheckoutPaymentHandlers || {}
  window.longosCheckoutPaymentHandlers.longos_platform_pay = {
    validate() {
      if (!provider()) { setStatus(messages.choose, true); return false }
      if (provider() === 'stripe' && (!stripe || !card || !cardComplete)) {
        setStatus(messages.cardIncomplete, true)
        initializeStripe().catch(() => undefined)
        root.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'center'})
        return false
      }
      return true
    },
    async start({orderNumber}) {
      fallback.hidden = true
      setStatus(messages.processing)
      const data = recoveryData = await request(config.sessionUrl, {order_number:orderNumber})
      const selected = provider()
      if (!Array.isArray(data.providers) || !data.providers.includes(selected)) throw new Error(messages.configurationChanged)
      if (data.checkout_url) { fallback.href = data.checkout_url; fallback.hidden = false }

      if (selected === 'paypal') {
        const created = await request(data.endpoints.paypal_create)
        if (!isTrustedPaypalUrl(created.approval_url)) throw new Error(messages.failed)
        window.location.assign(created.approval_url)
        return
      }

      if (data.stripe?.publishable_key !== config.stripePublishableKey) throw new Error(messages.configurationChanged)
      const intent = await request(data.endpoints.stripe_intent)
      const confirmation = await stripe.confirmCardPayment(intent.client_secret, {
        payment_method:{card}, return_url:data.return_url,
      })
      if (confirmation.error) throw new Error(confirmation.error.message)
      if (confirmation.paymentIntent?.status === 'processing') {
        if (await recoverCompletion(data)) return
        throw new Error(messages.processing)
      }
      if (confirmation.paymentIntent?.status !== 'succeeded') throw new Error(messages.failed)
      try {
        const completed = await request(data.endpoints.stripe_complete, {payment_intent_id:confirmation.paymentIntent.id})
        if (!completed.redirect_url) throw new Error(messages.failed)
        window.location.assign(completed.redirect_url)
      } catch (error) {
        if (await recoverCompletion(data)) return
        throw error
      }
    },
    onError(error) {
      setStatus(error?.response?.data?.message || error?.message || messages.failed, true)
      if (recoveryData?.checkout_url) { fallback.href = recoveryData.checkout_url; fallback.hidden = false }
    },
  }

  selectedForCheckout(document.querySelector('#payment-methods-wrap .payment-header.active')?.dataset?.value)
})()
</script>
