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
@endphp

@if($paypalCurrency !== $paypalDisplayCurrency)
  <div class="alert alert-info mt-3 mb-0" role="note">
    PayPal will process this payment in USD because {{ $paypalDisplayCurrency }} is not reliably supported for this merchant account.
  </div>
@endif

@if($paypalMode === 'nvp')
  <script>
    (function () {
      const orderNumber = {!! json_encode($paypalOrderNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      const status = document.getElementById('paypal-payment-status');
      if (status) status.textContent = 'Connecting to PayPal…';
      fetch(@json($paypalCreateUrl), {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({orderNumber: orderNumber})
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok) throw new Error(data.message || 'PayPal payment failed.');
          return data;
        });
      }).then(function (orderData) {
        if (orderData.status === 'COMPLETED') {
          if (status) status.textContent = 'Payment completed. Redirecting…';
          window.location.assign(@json(shop_route('checkout.success', ['order_number' => $paypalOrderNumber])));
          return;
        }
        if (!orderData.approval_url) throw new Error(orderData.message || 'PayPal payment failed.');
        if (status) status.textContent = 'Redirecting to PayPal…';
        window.location.assign(orderData.approval_url);
      }).catch(function (error) {
        if (status) status.textContent = error.message || 'PayPal payment could not be started.';
        layer.msg(error.message || 'PayPal payment could not be started.');
      });
    })();
  </script>
@elseif($paypalClientId === '')
  <div class="alert alert-warning mt-4 mb-0">PayPal is not configured. Please contact the store administrator.</div>
@else
  <script src="{{ $paypalSdkUrl }}" data-namespace="longosPayPal"></script>
  <script>
    (function () {
      const paypalSdk = window.longosPayPal;
      const status = document.getElementById('paypal-payment-status');
      const setStatus = function (message) { if (status) status.textContent = message || ''; };
      if (!paypalSdk) { setStatus('PayPal checkout could not be loaded.'); layer.msg('PayPal checkout could not be loaded.'); return; }
      const orderNumber = {!! json_encode($paypalOrderNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      const requestJson = function (url, payload) {
        return fetch(url, {
          method: 'POST',
          headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: JSON.stringify(payload)
        }).then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (data) {
            const providerDetail = data && data.error && data.error.details && data.error.details[0] ? data.error.details[0].description : '';
            if (!response.ok || (data && data.error)) throw new Error(data.message || providerDetail || 'PayPal payment failed.');
            return data;
          });
        });
      };

      const buttons = paypalSdk.Buttons({
        createOrder: function () {
          setStatus('Creating a secure PayPal payment…');
          return requestJson(@json($paypalCreateUrl), {orderNumber: orderNumber}).then(function (orderData) {
            if (orderData.status === 'COMPLETED') {
              setStatus('Payment completed. Redirecting…');
              window.location.assign(@json(shop_route('checkout.success', ['order_number' => $paypalOrderNumber])));
              return new Promise(function () {});
            }
            if (!orderData.id) throw new Error('PayPal did not return an order id.');
            setStatus('Continue in the PayPal window to approve the payment.');
            return orderData.id;
          });
        },
        onApprove: function (data) {
          setStatus('Confirming your payment… Please do not close this page.');
          return requestJson(@json($paypalCaptureUrl), {orderNumber: orderNumber, paypalOrderId: data.orderID}).then(function (orderData) {
            if (orderData.status !== 'COMPLETED') throw new Error(orderData.message || 'PayPal payment is not completed.');
            setStatus('Payment completed. Redirecting…');
            window.location.assign("{{ shop_route('checkout.success', ['order_number' => $paypalOrderNumber]) }}");
          });
        },
        onCancel: function () {
          setStatus('PayPal payment was cancelled. You can try again.');
        },
        onError: function (error) {
          console.error('PayPal checkout error', error);
          setStatus((error && error.message) || 'PayPal checkout could not be started.');
          layer.msg((error && error.message) || 'PayPal checkout could not be started.');
        }
      });

      if (buttons.isEligible()) {
        buttons.render('#paypal-button-container').catch(function (error) {
          setStatus((error && error.message) || 'PayPal checkout could not be displayed.');
        });
      } else {
        setStatus('PayPal is not available for this browser, account, or currency.');
      }
    })();
  </script>
@endif
