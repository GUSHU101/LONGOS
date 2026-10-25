<div id="paypal-button-container" class="mt-4" aria-live="polite"></div>
<div id="paypal-payment-status" class="small text-secondary mt-2" role="status" aria-live="polite"></div>
@php
  $paypalCreateUrl = shop_route('paypal.create');
  $paypalCaptureUrl = shop_route('paypal.capture');
  $paypalOrderNumber = (string) ($order->number ?? '');
  $paypalMode = strtolower((string) ($payment_setting['api_mode'] ?? 'rest'));
  $paypalSandbox = filter_var($payment_setting['sandbox_mode'] ?? true, FILTER_VALIDATE_BOOLEAN);
  $paypalClientId = $paypalSandbox
    ? (string) ($payment_setting['sandbox_client_id'] ?? '')
    : (string) ($payment_setting['live_client_id'] ?? '');
  $paypalMerchantId = $paypalSandbox
    ? (string) ($payment_setting['sandbox_merchant_id'] ?? '')
    : (string) ($payment_setting['live_merchant_id'] ?? '');
  $paypalDisplayCurrency = \Plugin\Paypal\Services\PaypalCurrency::displayCurrency($order);
  $paypalCurrency = \Plugin\Paypal\Services\PaypalCurrency::paymentCurrency($order);
  $paypalSdkQuery = array_filter([
    'client-id' => $paypalClientId,
    'currency' => $paypalCurrency,
    'intent' => 'capture',
    'components' => 'buttons',
    'commit' => 'true',
    'merchant-id' => $paypalMerchantId,
  ], static fn ($value) => $value !== '');
  $paypalSdkUrl = 'https://www.paypal.com/sdk/js?' . http_build_query($paypalSdkQuery, '', '&', PHP_QUERY_RFC3986);
  $paypalMessages = [
    'connecting' => __('Paypal::checkout.connecting'),
    'redirecting' => __('Paypal::checkout.redirecting'),
    'creating' => __('Paypal::checkout.creating'),
    'approve' => __('Paypal::checkout.approve'),
    'confirming' => __('Paypal::checkout.confirming'),
    'completed' => __('Paypal::checkout.completed'),
    'cancelled' => __('Paypal::checkout.cancelled'),
    'failed' => __('Paypal::checkout.failed'),
    'not_completed' => __('Paypal::checkout.not_completed'),
    'start_failed' => __('Paypal::checkout.start_failed'),
    'load_failed' => __('Paypal::checkout.load_failed'),
    'display_failed' => __('Paypal::checkout.display_failed'),
    'unavailable' => __('Paypal::checkout.unavailable'),
    'missing_order_id' => __('Paypal::checkout.missing_order_id'),
  ];
@endphp

@if($paypalCurrency !== $paypalDisplayCurrency)
  <div class="alert alert-info mt-3 mb-0" role="note">
    {{ __('Paypal::checkout.currency_conversion', ['currency' => $paypalDisplayCurrency]) }}
  </div>
@endif

@if($paypalMode === 'nvp')
  <script>
    (function () {
      const orderNumber = {!! json_encode($paypalOrderNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      const messages = @json($paypalMessages);
      const status = document.getElementById('paypal-payment-status');
      if (status) status.textContent = messages.connecting;
      fetch(@json($paypalCreateUrl), {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({orderNumber: orderNumber})
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok) throw new Error(data.message || messages.failed);
          return data;
        });
      }).then(function (orderData) {
        if (orderData.status === 'COMPLETED') {
          if (status) status.textContent = messages.completed;
          window.location.assign(@json(shop_route('checkout.success', ['order_number' => $paypalOrderNumber])));
          return;
        }
        if (!orderData.approval_url) throw new Error(orderData.message || messages.failed);
        if (status) status.textContent = messages.redirecting;
        window.location.assign(orderData.approval_url);
      }).catch(function (error) {
        const message = error.message || messages.start_failed;
        if (status) status.textContent = message;
        if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(message);
      });
    })();
  </script>
@elseif($paypalClientId === '')
  <div class="alert alert-warning mt-4 mb-0">{{ __('Paypal::checkout.not_configured') }}</div>
@else
  <script src="{{ $paypalSdkUrl }}" data-namespace="longosPayPal"></script>
  <script>
    (function () {
      const paypalSdk = window.longosPayPal;
      const messages = @json($paypalMessages);
      const status = document.getElementById('paypal-payment-status');
      const setStatus = function (message) { if (status) status.textContent = message || ''; };
      if (!paypalSdk) {
        setStatus(messages.load_failed);
        if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(messages.load_failed);
        return;
      }
      const orderNumber = {!! json_encode($paypalOrderNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      const requestJson = function (url, payload) {
        return fetch(url, {
          method: 'POST',
          headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: JSON.stringify(payload)
        }).then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (data) {
            if (!response.ok || (data && data.error)) throw new Error(data.message || messages.failed);
            return data;
          });
        });
      };

      const buttons = paypalSdk.Buttons({
        createOrder: function () {
          setStatus(messages.creating);
          return requestJson(@json($paypalCreateUrl), {orderNumber: orderNumber}).then(function (orderData) {
            if (orderData.status === 'COMPLETED') {
              setStatus(messages.completed);
              window.location.assign(@json(shop_route('checkout.success', ['order_number' => $paypalOrderNumber])));
              return new Promise(function () {});
            }
            if (!orderData.id) throw new Error(messages.missing_order_id);
            setStatus(messages.approve);
            return orderData.id;
          });
        },
        onApprove: function (data) {
          setStatus(messages.confirming);
          return requestJson(@json($paypalCaptureUrl), {orderNumber: orderNumber, paypalOrderId: data.orderID}).then(function (orderData) {
            if (orderData.status !== 'COMPLETED') throw new Error(orderData.message || messages.not_completed);
            setStatus(messages.completed);
            window.location.assign(@json(shop_route('checkout.success', ['order_number' => $paypalOrderNumber])));
          });
        },
        onCancel: function () {
          setStatus(messages.cancelled);
        },
        onError: function (error) {
          const message = (error && error.message) || messages.start_failed;
          setStatus(message);
          if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(message);
        }
      });

      if (buttons.isEligible()) {
        buttons.render('#paypal-button-container').catch(function (error) {
          setStatus((error && error.message) || messages.display_failed);
        });
      } else {
        setStatus(messages.unavailable);
      }
    })();
  </script>
@endif
