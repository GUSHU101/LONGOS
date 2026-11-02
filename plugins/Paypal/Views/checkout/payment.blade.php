<div id="paypal-button-container" class="mt-4" aria-live="polite"></div>
<div id="paypal-payment-status" class="small text-secondary mt-2" role="status" aria-live="polite"></div>
@php
  $paypalCreateUrl = shop_route('paypal.create');
  $paypalCaptureUrl = shop_route('paypal.capture');
  $paypalStatusUrl = shop_route('orders.status', ['number' => (string) ($order->number ?? '')]);
  $paypalSuccessUrl = order_checkout_result_url($order);
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
      const orderStatusUrl = @json($paypalStatusUrl);
      const successUrl = @json($paypalSuccessUrl);
      const wait = function (delay) { return new Promise(function (resolve) { window.setTimeout(resolve, delay); }); };
      const recoverCompletion = async function () {
        for (const delay of [0, 1000, 2000]) {
          if (delay) await wait(delay);
          const recoveryController = new AbortController();
          const recoveryTimeout = window.setTimeout(function () { recoveryController.abort(); }, 8000);
          try {
            const response = await fetch(orderStatusUrl, {
              credentials: 'same-origin', cache: 'no-store', signal: recoveryController.signal,
              headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
            });
            const payload = await response.json().catch(function () { return {}; });
            if (response.ok && payload && payload.data && payload.data.payment_confirmed) {
              window.location.assign(successUrl);
              return true;
            }
          } catch (error) {
          } finally {
            window.clearTimeout(recoveryTimeout);
          }
        }
        return false;
      };
      if (status) status.textContent = messages.connecting;
      const controller = new AbortController();
      const timeout = window.setTimeout(function () { controller.abort(); }, 35000);
      fetch(@json($paypalCreateUrl), {
        method: 'POST', signal: controller.signal,
        headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({orderNumber: orderNumber})
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok) {
            const detail = [data.message || messages.failed, data.reference || ''].filter(Boolean).join(' · ');
            throw new Error(detail);
          }
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
      }).catch(async function (error) {
        if (await recoverCompletion()) return;
        const message = error.message || messages.start_failed;
        if (status) status.textContent = message;
        if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(message);
      }).finally(function () {
        window.clearTimeout(timeout);
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
      const orderStatusUrl = @json($paypalStatusUrl);
      const successUrl = @json($paypalSuccessUrl);
      const wait = function (delay) { return new Promise(function (resolve) { window.setTimeout(resolve, delay); }); };
      const recoverCompletion = async function () {
        for (const delay of [0, 1000, 2000]) {
          if (delay) await wait(delay);
          const controller = new AbortController();
          const timeout = window.setTimeout(function () { controller.abort(); }, 8000);
          try {
            const response = await fetch(orderStatusUrl, {
              credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
              headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
            });
            const payload = await response.json().catch(function () { return {}; });
            if (response.ok && payload && payload.data && payload.data.payment_confirmed) {
              window.location.assign(successUrl);
              return true;
            }
          } catch (error) {
          } finally {
            window.clearTimeout(timeout);
          }
        }
        return false;
      };
      const requestJson = function (url, payload) {
        const controller = new AbortController();
        const timeout = window.setTimeout(function () { controller.abort(); }, 35000);
        return fetch(url, {
          method: 'POST', signal: controller.signal,
          headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: JSON.stringify(payload)
        }).then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (data) {
            if (!response.ok || (data && data.error)) {
              const detail = [data.message || messages.failed, data.reference || ''].filter(Boolean).join(' · ');
              throw new Error(detail);
            }
            return data;
          });
        }).catch(function (error) {
          if (error && error.name === 'AbortError') throw new Error(messages.failed);
          throw error;
        }).finally(function () {
          window.clearTimeout(timeout);
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
          }).catch(async function (error) {
            if (await recoverCompletion()) return;
            const message = (error && error.message) || messages.failed;
            setStatus(message);
            if (window.layer && typeof window.layer.msg === 'function') window.layer.msg(message);
          });
        },
        onCancel: function () {
          setStatus(messages.cancelled);
        },
        onError: async function (error) {
          if (await recoverCompletion()) return;
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
