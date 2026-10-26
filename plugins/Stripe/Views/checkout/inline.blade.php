<style>
  .stripe-inline-checkout { background: #f8fafc; border: 1px solid #dbe3ee; border-radius: 12px; margin-top: 14px; padding: 16px; }
  .stripe-inline-checkout[hidden] { display: none !important; }
  .stripe-inline-checkout__title { color: #172033; font-size: 15px; font-weight: 700; margin: 0 0 4px; }
  .stripe-inline-checkout__intro { color: #667085; font-size: 13px; margin: 0 0 14px; }
  .stripe-inline-checkout__grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
  .stripe-inline-checkout__field--wide { grid-column: 1 / -1; }
  .stripe-inline-checkout label { color: #344054; display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
  .stripe-inline-checkout input, .stripe-inline-element { background: #fff; border: 1px solid #cfd8e5; border-radius: 8px; min-height: 46px; padding: 12px; width: 100%; }
  .stripe-inline-element.StripeElement--focus, .stripe-inline-checkout input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .14); outline: 0; }
  .stripe-inline-element.StripeElement--invalid, .stripe-inline-checkout [aria-invalid="true"] { border-color: #b42318; }
  .stripe-inline-checkout__status { color: #667085; font-size: 13px; margin: 10px 0 0; min-height: 20px; }
  .stripe-inline-checkout__status.is-error { color: #b42318; }
  @media (max-width: 575px) { .stripe-inline-checkout__grid { grid-template-columns: 1fr; } .stripe-inline-checkout__field--wide { grid-column: auto; } }
</style>

<section id="stripe-inline-checkout" class="stripe-inline-checkout" hidden aria-hidden="true" aria-labelledby="stripe-inline-title">
  <h3 id="stripe-inline-title" class="stripe-inline-checkout__title">{{ __('Stripe::common.inline_title') }}</h3>
  <p class="stripe-inline-checkout__intro">{{ __('Stripe::common.inline_intro') }}</p>
  <div class="stripe-inline-checkout__grid">
    <div class="stripe-inline-checkout__field--wide">
      <label for="stripe-inline-cardholder">{{ __('Stripe::common.cardholder_name') }}</label>
      <input id="stripe-inline-cardholder" type="text" maxlength="191" autocomplete="cc-name">
    </div>
    <div class="stripe-inline-checkout__field--wide">
      <label for="stripe-inline-number">{{ __('Stripe::common.cardnum') }}</label>
      <div id="stripe-inline-number" class="stripe-inline-element"></div>
    </div>
    <div>
      <label for="stripe-inline-expiry">{{ __('Stripe::common.expiration_date') }}</label>
      <div id="stripe-inline-expiry" class="stripe-inline-element"></div>
    </div>
    <div>
      <label for="stripe-inline-cvc">{{ __('Stripe::common.cvv') }}</label>
      <div id="stripe-inline-cvc" class="stripe-inline-element"></div>
    </div>
  </div>
  <p id="stripe-inline-status" class="stripe-inline-checkout__status" role="status" aria-live="polite"></p>
</section>

<script>
(() => {
  const panel = document.getElementById('stripe-inline-checkout')
  const status = document.getElementById('stripe-inline-status')
  const cardholder = document.getElementById('stripe-inline-cardholder')
  if (!panel || !status || !cardholder) return

  const publicKey = @json(plugin_setting('stripe.publishable_key', ''));
  const intentUrl = @json(shop_route('stripe.intent'));
  const captureUrl = @json(shop_route('stripe_capture'));
  const messages = {!! \Illuminate\Support\Js::from([
    'loading' => __('Stripe::common.inline_loading'),
    'processing' => __('Stripe::common.inline_processing'),
    'cardholder' => __('Stripe::common.cardholder_required'),
    'incomplete' => __('Stripe::common.card_details_incomplete'),
    'failed' => __('Stripe::common.capture_fail'),
  ]) !!};
  const completeness = {number: false, expiry: false, cvc: false}
  let stripe = null
  let cardNumber = null
  let setupPromise = null

  const setStatus = (message, error = false) => {
    status.textContent = String(message || '')
    status.classList.toggle('is-error', error)
  }
  const loadStripe = () => {
    if (typeof window.Stripe === 'function') return Promise.resolve()
    const existing = document.querySelector('script[data-longos-stripe-sdk]')
    if (existing) {
      return new Promise((resolve, reject) => {
        const timeout = window.setTimeout(() => reject(new Error(messages.failed)), 15000)
        existing.addEventListener('load', () => { window.clearTimeout(timeout); resolve() }, {once: true})
        existing.addEventListener('error', () => { window.clearTimeout(timeout); existing.remove(); reject(new Error(messages.failed)) }, {once: true})
      })
    }

    return new Promise((resolve, reject) => {
      const script = document.createElement('script')
      const timeout = window.setTimeout(() => { script.remove(); reject(new Error(messages.failed)) }, 15000)
      script.src = 'https://js.stripe.com/v3/'
      script.async = true
      script.dataset.longosStripeSdk = '1'
      script.addEventListener('load', () => { window.clearTimeout(timeout); resolve() }, {once: true})
      script.addEventListener('error', () => { window.clearTimeout(timeout); script.remove(); reject(new Error(messages.failed)) }, {once: true})
      document.head.appendChild(script)
    })
  }
  const initialize = () => {
    if (setupPromise) return setupPromise
    setStatus(messages.loading)
    setupPromise = loadStripe().then(() => {
      if (!publicKey || typeof window.Stripe !== 'function') throw new Error(messages.failed)
      stripe = window.Stripe(publicKey)
      const style = {base: {color: '#172033', fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif', fontSize: '16px', '::placeholder': {color: '#98a2b3'}}, invalid: {color: '#b42318'}}
      const elements = stripe.elements({locale: 'auto'})
      cardNumber = elements.create('cardNumber', {style, showIcon: true})
      const expiry = elements.create('cardExpiry', {style})
      const cvc = elements.create('cardCvc', {style})
      cardNumber.mount('#stripe-inline-number')
      expiry.mount('#stripe-inline-expiry')
      cvc.mount('#stripe-inline-cvc')
      cardNumber.on('change', event => { completeness.number = event.complete; setStatus(event.error?.message || '') })
      expiry.on('change', event => { completeness.expiry = event.complete; setStatus(event.error?.message || '') })
      cvc.on('change', event => { completeness.cvc = event.complete; setStatus(event.error?.message || '') })
      setStatus('')
    }).catch(error => {
      setupPromise = null
      setStatus(error?.message || messages.failed, true)
      throw error
    })

    return setupPromise
  }
  const selected = code => {
    const active = String(code || '') === 'stripe'
    panel.hidden = !active
    panel.setAttribute('aria-hidden', active ? 'false' : 'true')
    if (active) initialize().catch(() => undefined)
  }

  window.addEventListener('longos:checkout-payment-changed', event => selected(event.detail?.code))
  window.longosCheckoutPaymentHandlers = window.longosCheckoutPaymentHandlers || {}
  window.longosCheckoutPaymentHandlers.stripe = {
    validate() {
      if (!cardholder.value.trim()) {
        cardholder.setAttribute('aria-invalid', 'true')
        cardholder.focus()
        setStatus(messages.cardholder, true)
        return false
      }
      cardholder.removeAttribute('aria-invalid')
      if (!stripe || !cardNumber || !Object.values(completeness).every(Boolean)) {
        setStatus(setupPromise ? messages.incomplete : messages.loading, true)
        initialize().catch(() => undefined)
        panel.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center'})
        return false
      }

      return true
    },
    async start({orderNumber}) {
      setStatus(messages.processing)
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const headers = {'X-CSRF-TOKEN': csrfToken, Accept: 'application/json'}
      const intentResponse = await window.axios.post(intentUrl, {order_number: orderNumber}, {headers})
      const intentData = intentResponse.data?.data || {}
      if (intentResponse.data?.status !== 'success') throw new Error(intentResponse.data?.message || messages.failed)
      if (intentData.already_succeeded && intentData.redirect_url) {
        window.location.assign(intentData.redirect_url)
        return
      }
      if (!intentData.client_secret) throw new Error(messages.failed)

      const confirmation = await stripe.confirmCardPayment(intentData.client_secret, {
        payment_method: {card: cardNumber, billing_details: {name: cardholder.value.trim()}},
      })
      if (confirmation.error) throw new Error(confirmation.error.message)
      if (confirmation.paymentIntent?.status !== 'succeeded') throw new Error(messages.failed)

      const captureResponse = await window.axios.post(captureUrl, {
        order_number: orderNumber,
        payment_intent_id: confirmation.paymentIntent.id,
      }, {headers})
      const redirectUrl = captureResponse.data?.data?.redirect_url
      if (captureResponse.data?.status !== 'success' || !redirectUrl) {
        throw new Error(captureResponse.data?.message || messages.failed)
      }
      window.location.assign(redirectUrl)
    },
    onError(error) {
      setStatus(error?.response?.data?.message || error?.message || messages.failed, true)
    },
  }

  selected(document.querySelector('#payment-methods-wrap .payment-header.active')?.dataset?.value)
})()
</script>
