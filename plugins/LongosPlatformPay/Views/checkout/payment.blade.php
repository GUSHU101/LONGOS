<div class="longos-embedded-pay mt-3" id="longos-platform-pay" data-session-url="{{ route('shop.longos_platform_pay.session', [], false) }}">
  <style>
    .longos-embedded-pay{--lp-blue:#155eef;--lp-line:#d0d5dd;--lp-muted:#667085;--lp-danger:#b42318}.longos-pay-intro{padding:14px 16px;border:1px solid #b2ccff;border-radius:12px;background:#eff4ff;color:#1849a9}.longos-pay-intro p{margin:.35rem 0 0}.longos-pay-methods{display:grid;gap:14px;margin-top:18px}.longos-pay-method{padding:18px;border:1px solid var(--lp-line);border-radius:14px;background:#fff}.longos-pay-method h2{margin:0 0 4px;font-size:1.1rem}.longos-pay-method p{margin:0 0 14px;color:var(--lp-muted)}.longos-stripe-card{min-height:48px;padding:14px;border:1px solid var(--lp-line);border-radius:10px;background:#fff}.longos-stripe-card:focus-within{border-color:var(--lp-blue);box-shadow:0 0 0 4px rgba(21,94,239,.18)}.longos-pay-error{min-height:1.5rem;margin-top:8px;color:var(--lp-danger)}.longos-pay-status{min-height:1.5rem;margin-top:14px;color:var(--lp-muted)}.longos-pay-status.text-danger{color:var(--lp-danger)!important}.longos-pay-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}@media(max-width:575.98px){.longos-pay-method{padding:15px}.longos-pay-actions .btn{width:100%}}
  </style>

  <div class="longos-pay-intro" role="note">
    <strong>{{ trans('LongosPlatformPay::common.secure_title') }}</strong>
    <p>{{ trans('LongosPlatformPay::common.secure_help') }}</p>
    <p class="small mb-0" id="longos-platform-pay-amount"></p>
  </div>

  <div class="longos-pay-status" id="longos-platform-pay-status" role="status" aria-live="polite" tabindex="-1">
    {{ trans('LongosPlatformPay::common.initializing') }}
  </div>

  <div class="longos-pay-methods" id="longos-platform-pay-methods" aria-label="{{ trans('LongosPlatformPay::common.methods_label') }}" hidden>
    <section class="longos-pay-method" id="longos-paypal-method" aria-labelledby="longos-paypal-heading" hidden>
      <h2 id="longos-paypal-heading">PayPal</h2>
      <p>{{ trans('LongosPlatformPay::common.paypal_help') }}</p>
      <div id="longos-paypal-buttons"></div>
    </section>

    <section class="longos-pay-method" id="longos-stripe-method" aria-labelledby="longos-stripe-heading" hidden>
      <h2 id="longos-stripe-heading">{{ trans('LongosPlatformPay::common.card') }}</h2>
      <p>{{ trans('LongosPlatformPay::common.card_help') }}</p>
      <form id="longos-stripe-form">
        <label for="longos-stripe-card" class="form-label fw-semibold">{{ trans('LongosPlatformPay::common.card_details') }}</label>
        <div id="longos-stripe-card" class="longos-stripe-card"></div>
        <div id="longos-stripe-error" class="longos-pay-error" role="alert" aria-live="polite"></div>
        <button id="longos-stripe-submit" class="btn btn-primary btn-lg" type="submit">
          {{ trans('LongosPlatformPay::common.pay_now') }}
        </button>
      </form>
    </section>
  </div>

  <div class="longos-pay-actions">
    <button id="longos-platform-pay-retry" class="btn btn-outline-primary" type="button" hidden>
      {{ trans('LongosPlatformPay::common.retry') }}
    </button>
    <a id="longos-platform-pay-fallback" class="btn btn-link" href="#" hidden>
      {{ trans('LongosPlatformPay::common.fallback') }}
    </a>
  </div>
</div>

<script>
(() => {
  const root = document.getElementById('longos-platform-pay')
  if (!root || root.dataset.bound === '1') return
  root.dataset.bound = '1'

  const status = document.getElementById('longos-platform-pay-status')
  const methods = document.getElementById('longos-platform-pay-methods')
  const paypalMethod = document.getElementById('longos-paypal-method')
  const stripeMethod = document.getElementById('longos-stripe-method')
  const retry = document.getElementById('longos-platform-pay-retry')
  const fallback = document.getElementById('longos-platform-pay-fallback')
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
  const headers = {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}
  let providerLocked = false

  window.addEventListener('pageshow', event => {
    // A restored payment form may represent an order completed while the
    // customer was authenticating with a provider. Never expose stale CTAs.
    if (event.persisted) window.location.reload()
  })

  const setStatus = (message, error = false) => {
    status.textContent = message
    status.classList.toggle('text-danger', error)
    if (error) status.focus?.()
  }
  const request = async (url, body = {}, timeoutMs = 35000) => {
    const controller = new AbortController()
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs)
    try {
      const response = await fetch(url, {method:'POST',credentials:'same-origin',headers,body:JSON.stringify(body),signal:controller.signal})
      const payload = await response.json().catch(() => null)
      if (!response.ok || payload?.status !== 'success') throw new Error(payload?.message || @json(trans('LongosPlatformPay::common.failed')))
      return payload.data || {}
    } catch (error) {
      if (error?.name === 'AbortError') throw new Error(@json(trans('LongosPlatformPay::common.timeout')))
      throw error
    } finally {
      window.clearTimeout(timeout)
    }
  }
  const loadScript = (id, source) => new Promise((resolve, reject) => {
    if (document.getElementById(id)?.dataset.loaded === '1') { resolve(); return }
    const existing = document.getElementById(id)
    if (existing) existing.remove()
    const script = document.createElement('script')
    const timeout = window.setTimeout(() => { script.remove(); reject(new Error(@json(trans('LongosPlatformPay::common.sdk_timeout')))) }, 20000)
    script.id = id
    script.src = source
    script.async = true
    script.onload = () => { window.clearTimeout(timeout); script.dataset.loaded = '1'; resolve() }
    script.onerror = () => { window.clearTimeout(timeout); script.remove(); reject(new Error(@json(trans('LongosPlatformPay::common.sdk_failed')))) }
    document.head.appendChild(script)
  })
  const lockProvider = provider => {
    if (providerLocked && providerLocked !== provider) throw new Error(@json(trans('LongosPlatformPay::common.locked')))
    providerLocked = provider
    ;[paypalMethod, stripeMethod].forEach(method => { if (!method.hidden) method.classList.toggle('d-none', method.id !== `longos-${provider}-method`) })
  }
  const unlockUnboundProvider = provider => {
    if (providerLocked !== provider) return
    providerLocked = false
    ;[paypalMethod, stripeMethod].forEach(method => method.classList.remove('d-none'))
  }
  const finish = data => {
    if (!data.redirect_url) throw new Error(@json(trans('LongosPlatformPay::common.missing_redirect')))
    window.location.assign(data.redirect_url)
  }
  const showFallback = data => {
    if (!data?.checkout_url) return
    fallback.href = data.checkout_url
    fallback.hidden = false
  }
  const wait = delay => new Promise(resolve => window.setTimeout(resolve, delay))
  const recoverCompletion = async data => {
    setStatus(@json(trans('LongosPlatformPay::common.checking')))
    for (const delay of [0, 1000, 2000]) {
      if (delay) await wait(delay)
      try {
        const result = await request(data.endpoints.status, {}, 8000)
        if (result.completed && result.redirect_url) {
          finish(result)
          return true
        }
      } catch (error) {
        // The original provider result remains authoritative. A status probe
        // is best-effort and must never replace its actionable error.
      }
    }
    return false
  }

  const mountPaypal = async data => {
    const query = new URLSearchParams({
      'client-id': data.paypal.client_id,
      currency: data.amount.currency,
      intent: 'capture',
      components: 'buttons',
    })
    if (data.paypal.merchant_id) query.set('merchant-id', data.paypal.merchant_id)
    await loadScript('longos-paypal-sdk', `https://www.paypal.com/sdk/js?${query.toString()}`)
    if (!window.paypal?.Buttons) throw new Error(@json(trans('LongosPlatformPay::common.paypal_load')))

    paypalMethod.hidden = false
    await window.paypal.Buttons({
      style:{layout:'vertical',shape:'rect',label:'paypal'},
      createOrder: async () => {
        lockProvider('paypal'); setStatus(@json(trans('LongosPlatformPay::common.connect_paypal')))
        try { const result = await request(data.endpoints.paypal_create); return result.id }
        catch (error) { unlockUnboundProvider('paypal'); throw error }
      },
      onApprove: async result => {
        try {
          setStatus(@json(trans('LongosPlatformPay::common.verify_paypal')))
          finish(await request(data.endpoints.paypal_capture, {paypal_order_id:result.orderID}))
        } catch (error) {
          if (await recoverCompletion(data)) return
          setStatus(error?.message || @json(trans('LongosPlatformPay::common.failed')), true); showFallback(data)
        }
      },
      onCancel: () => { setStatus(@json(trans('LongosPlatformPay::common.cancelled')), true); showFallback(data) },
      onError: error => { setStatus(error?.message || @json(trans('LongosPlatformPay::common.failed')), true); showFallback(data) },
    }).render('#longos-paypal-buttons')
  }

  const mountStripe = async data => {
    await loadScript('longos-stripe-sdk', 'https://js.stripe.com/v3/')
    if (typeof window.Stripe !== 'function') throw new Error(@json(trans('LongosPlatformPay::common.stripe_load')))

    stripeMethod.hidden = false
    const stripe = window.Stripe(data.stripe.publishable_key)
    const card = stripe.elements({locale:'auto'}).create('card', {hidePostalCode:false})
    const fieldError = document.getElementById('longos-stripe-error')
    card.mount('#longos-stripe-card')
    card.on('change', event => { fieldError.textContent = event.error?.message || '' })
    document.getElementById('longos-stripe-submit').textContent = @json(trans('LongosPlatformPay::common.pay_amount', ['amount' => '__AMOUNT__'])).replace('__AMOUNT__', `${data.amount.currency} ${data.amount.value}`)

    document.getElementById('longos-stripe-form').addEventListener('submit', async event => {
      event.preventDefault()
      const button = document.getElementById('longos-stripe-submit')
      if (button.disabled) return
      let intentCreated = false
      try {
        lockProvider('stripe'); button.disabled = true; button.setAttribute('aria-busy','true')
        fieldError.textContent = ''; setStatus(@json(trans('LongosPlatformPay::common.create_stripe')))
        const intent = await request(data.endpoints.stripe_intent)
        intentCreated = true
        const confirmation = await stripe.confirmCardPayment(intent.client_secret, {
          payment_method:{card},
          return_url:data.return_url,
        })
        if (confirmation.error) throw new Error(confirmation.error.message)
        if (confirmation.paymentIntent?.status === 'processing') {
          if (await recoverCompletion(data)) return
          throw new Error(@json(trans('LongosPlatformPay::common.processing')))
        }
        if (confirmation.paymentIntent?.status !== 'succeeded') throw new Error(@json(trans('LongosPlatformPay::common.stripe_incomplete')))
        setStatus(@json(trans('LongosPlatformPay::common.verifying')))
        try {
          finish(await request(data.endpoints.stripe_complete, {payment_intent_id:confirmation.paymentIntent.id}))
        } catch (error) {
          if (await recoverCompletion(data)) return
          throw error
        }
      } catch (error) {
        if (!intentCreated) unlockUnboundProvider('stripe')
        const message = error?.message || @json(trans('LongosPlatformPay::common.failed'))
        fieldError.textContent = message; setStatus(message, true); showFallback(data)
        button.disabled = false; button.removeAttribute('aria-busy'); button.focus()
      }
    })
  }

  const initialize = async () => {
    let sessionData = null
    retry.hidden = true
    fallback.hidden = true
    setStatus(@json(trans('LongosPlatformPay::common.initializing')))
    try {
      const data = sessionData = await request(root.dataset.sessionUrl, {order_number:@json($order->number ?? '')})
      const providers = Array.isArray(data.providers) ? data.providers : []
      if (!providers.length) throw new Error(@json(trans('LongosPlatformPay::common.unavailable')))
      document.getElementById('longos-platform-pay-amount').textContent = @json(trans('LongosPlatformPay::common.amount', ['amount' => '__AMOUNT__'])).replace('__AMOUNT__', `${data.amount.currency} ${data.amount.value}`)
      methods.hidden = false

      const mounts = []
      if (providers.includes('paypal') && data.paypal?.client_id) mounts.push(mountPaypal(data).catch(error => { paypalMethod.hidden = true; throw error }))
      if (providers.includes('stripe') && data.stripe?.publishable_key) mounts.push(mountStripe(data).catch(error => { stripeMethod.hidden = true; throw error }))
      const results = await Promise.allSettled(mounts)
      if (!results.some(result => result.status === 'fulfilled')) {
        throw results.find(result => result.status === 'rejected')?.reason || new Error(@json(trans('LongosPlatformPay::common.unavailable')))
      }
      setStatus(@json(trans('LongosPlatformPay::common.choose_method')))
    } catch (error) {
      setStatus(error?.message || @json(trans('LongosPlatformPay::common.failed')), true)
      showFallback(sessionData)
      retry.hidden = false
    }
  }

  retry.addEventListener('click', () => window.location.reload())
  initialize()
})()
</script>
