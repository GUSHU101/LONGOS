<script src="{{ asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js') }}"></script>
<script src="https://js.stripe.com/v3/"></script>

@php
  $stripeDisplayCurrency = \Plugin\Stripe\Services\StripeCurrency::displayCurrency($order);
  $stripePaymentCurrency = \Plugin\Stripe\Services\StripeCurrency::paymentCurrency($order);
@endphp

@if($stripePaymentCurrency !== $stripeDisplayCurrency)
  <div class="alert alert-info mt-3 mb-0" role="note">
    Your card will be charged in USD because {{ $stripeDisplayCurrency }} is not supported by Stripe.
  </div>
@endif

<link rel="stylesheet" href="{{ asset('plugin/stripe/css/demo.css') }}">
<div class="mt-5" id="stripe-form" v-cloak>
  <hr class="mb-4">
  <div class="checkout-black">
    <h5 class="checkout-title">{{ __('Stripe::common.title_info') }}</h5>
    <div class="mb-4">
      <img src="{{ plugin_origin('stripe','/image/pay-image.png') }}" class="img-fluid" alt="Stripe">
    </div>
    <div class="stripe-card w-max-600">
      <div class="mb-3">
        <label class="mb-2" for="stripe-cardholder-name">Cardholder Name</label>
        <input id="stripe-cardholder-name" type="text" autocomplete="cc-name" maxlength="191"
               :class="['form-control', errors.cardholderName ? 'border-danger' : '']"
               v-model.trim="cardholderName" @input="errors.cardholderName = ''"
               placeholder="Cardholder Name" style="height: 40px; border-color: #dee2e6">
        <div v-if="errors.cardholderName" class="text-danger mt-2" role="alert">@{{ errors.cardholderName }}</div>
      </div>

      <div class="mb-3">
        <label class="mb-2" for="card-number-element">{{ __('Stripe::common.cardnum') }}</label>
        <div id="card-number-element" :class="['px-2 border card-input', errors.cardNumber ? 'border-danger' : '']"></div>
        <div class="text-danger mt-2" v-if="errors.cardNumber" role="alert">@{{ errors.cardNumber }}</div>
      </div>
      <div class="mb-3">
        <label class="mb-2" for="card-expiry-element">{{ __('Stripe::common.expiration_date') }}</label>
        <div id="card-expiry-element" :class="['px-2 border card-input', errors.cardExpiry ? 'border-danger' : '']"></div>
        <div class="text-danger mt-2" v-if="errors.cardExpiry" role="alert">@{{ errors.cardExpiry }}</div>
      </div>
      <div class="mb-3">
        <label class="mb-2" for="card-cvc-element">{{ __('Stripe::common.cvv') }}</label>
        <div id="card-cvc-element" :class="['px-2 border card-input', errors.cardCvc ? 'border-danger' : '']"></div>
        <div class="text-danger mt-2" v-if="errors.cardCvc" role="alert">@{{ errors.cardCvc }}</div>
      </div>
    </div>

    <button class="btn btn-primary btn-lg" type="button" :disabled="submitting || !ready"
            @click="submitPayment" :aria-busy="submitting ? 'true' : 'false'">
      <span v-if="submitting">Processing…</span>
      <span v-else>{{ __('Stripe::common.btn_submit') }}</span>
    </button>
    <div v-if="setupError" class="text-danger mt-3" role="alert">@{{ setupError }}</div>
  </div>
</div>

<script>
(() => {
  let cardNumberElement = null
  let stripe = null
  const orderNumber = @json($order->number ?? '')
  const publicKey = @json(plugin_setting('stripe.publishable_key', ''))
  const intentUrl = @json(shop_route('stripe.intent'))
  const captureUrl = @json(shop_route('stripe_capture'))
  const successUrl = @json(shop_route('checkout.success'))
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
  const headers = {'X-CSRF-TOKEN': csrfToken, Accept: 'application/json'}

  new Vue({
    el: '#stripe-form',
    data: {
      cardholderName: '',
      errors: {cardNumber: '', cardExpiry: '', cardCvc: '', cardholderName: ''},
      ready: false,
      submitting: false,
      setupError: '',
    },
    mounted() {
      if (!publicKey || typeof window.Stripe !== 'function') {
        this.setupError = @json(__('Stripe::common.capture_fail'))
        return
      }
      try {
        stripe = window.Stripe(publicKey)
        const style = {base: {color: '#32325d', fontFamily: 'Arial, sans-serif', lineHeight: '40px', fontSize: '16px', '::placeholder': {color: '#aab7c4'}}, invalid: {color: '#b42318'}}
        const elements = stripe.elements({locale: 'auto'})
        cardNumberElement = elements.create('cardNumber', {style, showIcon: true})
        const expiry = elements.create('cardExpiry', {style})
        const cvc = elements.create('cardCvc', {style})
        cardNumberElement.mount('#card-number-element')
        expiry.mount('#card-expiry-element')
        cvc.mount('#card-cvc-element')
        cardNumberElement.on('change', event => { this.errors.cardNumber = event.error?.message || '' })
        expiry.on('change', event => { this.errors.cardExpiry = event.error?.message || '' })
        cvc.on('change', event => { this.errors.cardCvc = event.error?.message || '' })
        this.ready = true
      } catch (error) {
        this.setupError = error.message || @json(__('Stripe::common.capture_fail'))
      }
    },
    methods: {
      async submitPayment() {
        if (!this.cardholderName) {
          this.errors.cardholderName = 'Please fill out a cardholder name.'
          return
        }
        if (!stripe || !cardNumberElement || this.submitting) return

        this.submitting = true
        const loading = window.layer?.load?.(2, {shade: [0.3, '#fff']})
        try {
          const intentResponse = await window.axios.post(intentUrl, {order_number: orderNumber}, {headers})
          const intentData = intentResponse.data?.data || {}
          if (intentResponse.data?.status !== 'success' || !intentData.client_secret) {
            if (!intentData.already_succeeded) {
              throw new Error(intentResponse.data?.message || @json(__('Stripe::common.capture_fail')))
            }
          }

          let paymentIntentId = intentData.payment_intent_id
          if (!intentData.already_succeeded) {
            const confirmation = await stripe.confirmCardPayment(intentData.client_secret, {
              payment_method: {card: cardNumberElement, billing_details: {name: this.cardholderName}}
            })
            if (confirmation.error) throw new Error(confirmation.error.message)
            if (confirmation.paymentIntent?.status !== 'succeeded') {
              throw new Error(@json(__('Stripe::common.capture_fail')))
            }
            paymentIntentId = confirmation.paymentIntent.id
          }

          const captureResponse = await window.axios.post(captureUrl, {
            order_number: orderNumber,
            payment_intent_id: paymentIntentId,
          }, {headers})
          if (captureResponse.data?.status !== 'success') {
            throw new Error(captureResponse.data?.message || @json(__('Stripe::common.capture_fail')))
          }

          const redirect = new URL(successUrl, window.location.origin)
          redirect.searchParams.set('order_number', orderNumber)
          window.location.assign(redirect.toString())
        } catch (error) {
          window.layer?.msg?.(error.response?.data?.message || error.message || @json(__('Stripe::common.capture_fail')))
        } finally {
          this.submitting = false
          if (loading !== undefined) window.layer?.close?.(loading)
        }
      },
    },
  })
})()
</script>
