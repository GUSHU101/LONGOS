<div class="mt-4" id="longos-platform-pay">
  <div class="alert alert-light border" role="note">
    <strong>{{ trans('LongosPlatformPay::common.secure_title') }}</strong>
    <div class="small mt-1">{{ trans('LongosPlatformPay::common.secure_help') }}</div>
  </div>
  <button id="longos-platform-pay-button" class="btn btn-primary btn-lg" type="button" aria-describedby="longos-platform-pay-status">
    {{ trans('LongosPlatformPay::common.continue') }}
  </button>
  <div id="longos-platform-pay-status" class="mt-2 small" role="status" aria-live="polite"></div>
</div>

<script>
(() => {
  const button = document.getElementById('longos-platform-pay-button')
  const status = document.getElementById('longos-platform-pay-status')
  if (!button || button.dataset.bound === '1') return
  button.dataset.bound = '1'
  button.addEventListener('click', async () => {
    if (button.disabled) return
    button.disabled = true
    button.setAttribute('aria-busy', 'true')
    status.className = 'mt-2 small text-secondary'
    status.textContent = @json(trans('LongosPlatformPay::common.connecting'))
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
      const response = await fetch(@json(shop_route('longos_platform_pay.session')), {
        method: 'POST', credentials: 'same-origin',
        headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body: JSON.stringify({order_number: @json($order->number ?? '')}),
      })
      const payload = await response.json().catch(() => null)
      if (!response.ok || payload?.status !== 'success' || !payload?.data?.checkout_url) {
        throw new Error(payload?.message || @json(trans('LongosPlatformPay::common.failed')))
      }
      window.location.assign(payload.data.checkout_url)
    } catch (error) {
      status.className = 'mt-2 small text-danger'
      status.textContent = error?.message || @json(trans('LongosPlatformPay::common.failed'))
      button.disabled = false
      button.removeAttribute('aria-busy')
      button.focus()
    }
  })
})()
</script>
