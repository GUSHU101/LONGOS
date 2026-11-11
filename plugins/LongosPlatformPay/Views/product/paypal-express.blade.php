<div class="product-paypal-express">
  <button
    class="btn btn-paypal-express product-cta fw-bold"
    type="button"
    :disabled="!product.quantity || product.active != 1"
    @click="addCart(true, $event.currentTarget, 'paypal')"
    aria-label="{{ trans('LongosPlatformPay::common.paypal_express') }}">
    <span class="product-paypal-express__mark" aria-hidden="true"><span>Pay</span><span>Pal</span></span>
  </button>
</div>
