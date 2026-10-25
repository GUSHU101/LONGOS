<?php

/**
 * PaypalController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-10 18:57:56
 * @modified   2022-08-10 18:57:56
 */

namespace Plugin\Paypal\Controllers;

use Beike\Models\OrderPayment;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\Paypal\Services\PaypalService;
use Throwable;

class PaypalController
{
    private ?PaypalService $paypalService = null;

    /**
     * Init Paypal
     *
     * @throws \Throwable
     */
    private function initPaypal($order, bool $allowSettledOrder = false): PaypalService
    {
        $this->paypalService = new PaypalService($order, $allowSettledOrder);

        return $this->paypalService;
    }

    /**
     * 创建 paypal 订单
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $data        = $this->getRequestData($request);
            $orderNumber = $this->getOrderNumberFromData($data, $request);

            if ($orderNumber === '') {
                return response()->json(['message' => 'orderNumber is required'], 422);
            }

            $order = $this->resolvePaypalOrder($orderNumber);

            return $this->withPaymentLock('create', (int) $order->id, function () use ($order, $orderNumber): JsonResponse {
                $paypalService = $this->initPaypal($order->fresh(), true);
                $storedResult  = $this->getAnyStoredCompletedResult((int) $order->id);
                if ($storedResult !== null) {
                    $storedIsNvp = $this->isStoredNvpResult($storedResult);
                    if ($paypalService->isNvpApi() !== $storedIsNvp) {
                        throw new \RuntimeException('Stored PayPal payment does not match the configured API mode.');
                    }
                    $providerId = (string) ($storedIsNvp ? ($storedResult['token'] ?? '') : ($storedResult['id'] ?? ''));
                    $paypalService->validateStoredCapture($storedResult, $providerId);
                    $safeResult = PaypalService::safeProviderResult($storedResult, $storedIsNvp);
                    $this->persistSafeResult($order->id, $paypalService, $safeResult, $providerId);
                    $this->markOrderPaid($paypalService, $order, $safeResult);
                    $safeResult['recovered'] = true;

                    return response()->json($safeResult);
                }

                $reusable = $this->getReusableCreatedResult((int) $order->id, $paypalService->isNvpApi());
                if ($reusable !== null) {
                    $reusable['recovered'] = true;

                    return response()->json($reusable);
                }

                $paypalOrder = $paypalService->isNvpApi()
                    ? $paypalService->createNvpOrder($this->getNvpCheckoutUrls($orderNumber))
                    : $paypalService->createOrder();

                if (! is_array($paypalOrder) || trim((string) ($paypalOrder['id'] ?? '')) === '') {
                    throw new \RuntimeException('PayPal did not return a payment order id.');
                }
                $safeResult = PaypalService::safeProviderResult($paypalOrder, $paypalService->isNvpApi());
                OrderPaymentRepo::createOrUpdatePayment($order->id, [
                    'transaction_id' => (string) $safeResult['id'],
                    'request'        => [
                        'stage'        => 'create',
                        'order_number' => $orderNumber,
                        'api_mode'     => $paypalService->getApiMode(),
                    ],
                    'response' => $safeResult,
                ]);

                return response()->json($safeResult);
            });
        } catch (Throwable $e) {
            return $this->paymentError($e, 'PayPal order creation failed.');
        }
    }

    /**
     * 客户同意后扣款回调
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function capture(Request $request): JsonResponse
    {
        try {
            $data        = $this->getRequestData($request);
            $orderNumber = $this->getOrderNumberFromData($data, $request);

            if ($orderNumber === '') {
                return response()->json(['message' => 'orderNumber is required'], 422);
            }

            $order = $this->resolvePaypalOrder($orderNumber);

            return $this->withPaymentLock('capture', (int) $order->id, function () use ($data, $request, $order): JsonResponse {
                $order         = $order->fresh();
                $token         = $this->getNvpToken($data, $request);
                $payerId       = $this->getNvpPayerId($data, $request);
                $isNvpRequest  = $token !== '' || $payerId !== '';
                $paypalOrderId = $isNvpRequest
                    ? $token
                    : trim((string) ($data['paypalOrderId'] ?? $data['paypal_order_id'] ?? ''));

                if ($paypalOrderId === '') {
                    return response()->json(['message' => $isNvpRequest
                        ? 'PayPal token and payer id are required'
                        : 'paypalOrderId is required'], 422);
                }

                $this->assertProviderOrderBinding((int) $order->id, $paypalOrderId);
                $storedResult  = $this->getStoredCompletedResult((int) $order->id, $paypalOrderId);
                $paypalService = $this->initPaypal($order, true);
                if ($paypalService->isNvpApi() !== $isNvpRequest) {
                    throw new \RuntimeException('PayPal callback does not match the configured API mode.');
                }

                if ($storedResult !== null) {
                    $paypalService->validateStoredCapture($storedResult, $paypalOrderId);
                    $safeResult = PaypalService::safeProviderResult($storedResult, $isNvpRequest);
                    $this->persistSafeResult($order->id, $paypalService, $safeResult, $paypalOrderId);
                    $this->markOrderPaid($paypalService, $order, $safeResult);

                    return response()->json($safeResult);
                }

                OrderPaymentRepo::createOrUpdatePayment($order->id, [
                    'request' => $this->safeCaptureRequest($data),
                ]);

                if ($isNvpRequest) {
                    if ($token === '' || $payerId === '') {
                        return response()->json(['message' => 'PayPal token and payer id are required'], 422);
                    }
                    $result = $paypalService->captureNvpPayment($token, $payerId);
                } else {
                    $result = $paypalService->captureRestPayment($paypalOrderId);
                }

                $safeResult = PaypalService::safeProviderResult($result, $isNvpRequest);
                $this->persistSafeResult($order->id, $paypalService, $safeResult, $paypalOrderId);
                $this->markOrderPaid($paypalService, $order, $safeResult);

                return response()->json($safeResult);
            });
        } catch (Throwable $e) {
            return $this->paymentError($e, 'PayPal payment capture failed.');
        }
    }

    public function nvpReturn(Request $request): RedirectResponse
    {
        $data        = $request->all();
        $orderNumber = $this->getOrderNumberFromData($data, $request);
        $token       = $this->getNvpToken($data, $request);
        $payerId     = $this->getNvpPayerId($data, $request);

        if ($orderNumber === '' || $token === '' || $payerId === '') {
            return $this->redirectWithMessage($request, $this->getFailureRedirectUrl($orderNumber), 'error', trans('Paypal::checkout.failed'));
        }

        try {
            $order = $this->resolvePaypalOrder($orderNumber, false);

            return $this->withPaymentLock('return', (int) $order->id, function () use ($order, $token, $payerId, $data, $request, $orderNumber): RedirectResponse {
                $order = $order->fresh();
                $this->assertProviderOrderBinding((int) $order->id, $token);
                $storedResult  = $this->getStoredCompletedResult((int) $order->id, $token);
                $paypalService = $this->initPaypal($order, true);
                if (! $paypalService->isNvpApi()) {
                    throw new \RuntimeException('PayPal callback does not match the configured API mode.');
                }

                if ($storedResult !== null) {
                    $paypalService->validateStoredCapture($storedResult, $token);
                    $safeResult = PaypalService::safeProviderResult($storedResult, true);
                    $this->persistSafeResult($order->id, $paypalService, $safeResult, $token);
                    $this->markOrderPaid($paypalService, $order, $safeResult);

                    return $this->redirectWithMessage($request, $this->getSuccessRedirectUrl($orderNumber), 'success', trans('Paypal::checkout.completed'));
                }

                OrderPaymentRepo::createOrUpdatePayment($order->id, ['request' => $this->safeCaptureRequest($data)]);
                $result = $paypalService->captureNvpPayment($token, $payerId);
                $paypalService->validateStoredCapture($result, $token);
                $safeResult = PaypalService::safeProviderResult($result, true);
                $this->persistSafeResult($order->id, $paypalService, $safeResult, $token);
                $this->markOrderPaid($paypalService, $order, $safeResult);

                $paid = $paypalService->isPaymentSuccessful($safeResult);

                return $this->redirectWithMessage(
                    $request,
                    $paid ? $this->getSuccessRedirectUrl($orderNumber) : $this->getFailureRedirectUrl($orderNumber),
                    $paid ? 'success' : 'error',
                    $paid ? trans('Paypal::checkout.completed') : trans('Paypal::checkout.not_completed')
                );
            });
        } catch (Throwable $e) {
            return $this->redirectWithMessage($request, $this->getFailureRedirectUrl($orderNumber), 'error', trans('Paypal::checkout.failed'));
        }
    }

    public function nvpCancel(Request $request): RedirectResponse
    {
        $data        = $request->all();
        $orderNumber = $this->getOrderNumberFromData($data, $request);

        return $this->redirectWithMessage($request, $this->getFailureRedirectUrl($orderNumber), 'error', trans('Paypal::checkout.cancelled'));
    }

    public function nvpNotify(Request $request)
    {
        $data        = $request->all();
        $orderNumber = $this->getOrderNumberFromData($data, $request);
        $rawBody     = $request->getContent();

        if ($orderNumber === '' || $rawBody === '') {
            return response('INVALID', 400);
        }

        try {
            $order = $this->resolvePaypalOrder($orderNumber, false);

            return $this->withPaymentLock('ipn', (int) $order->id, function () use ($order, $rawBody, $data) {
                $order         = $order->fresh();
                $paypalService = $this->initPaypal($order, true);
                if (! $paypalService->isNvpApi() || ! $paypalService->verifyNvpNotification($rawBody)) {
                    Log::warning('PayPal IPN verification was rejected.', ['order_id' => $order->id]);

                    // A completed verification round-trip with INVALID is a
                    // permanent rejection, not a transient server failure.
                    return response('INVALID', 200);
                }

                try {
                    $result = $paypalService->validateNvpNotification($data);
                } catch (\RuntimeException $exception) {
                    Log::critical('Verified PayPal IPN did not match the local order.', [
                        'order_id'  => $order->id,
                        'exception' => $exception::class,
                        'message'   => $this->redactProviderMessage($exception->getMessage()),
                    ]);

                    return response('IGNORED', 200);
                }
                $boundToken = $this->getBoundNvpToken((int) $order->id);
                if ($boundToken !== '') {
                    // IPN can arrive before the customer's browser return.
                    // Preserve the approved checkout token so that the later
                    // callback can still prove its local order binding.
                    $result['token'] = $boundToken;
                }
                OrderPaymentRepo::createOrUpdatePayment($order->id, [
                    'transaction_id' => $result['id'],
                    'callback'       => $result,
                    'response'       => $result,
                ]);
                $this->markOrderPaid($paypalService, $order, $result);

                return response('OK', 200);
            });
        } catch (Throwable $exception) {
            Log::warning('PayPal IPN processing failed.', [
                'order_number' => mb_substr($orderNumber, 0, 128),
                'exception'    => $exception::class,
                'message'      => $this->redactProviderMessage($exception->getMessage()),
            ]);

            return response('RETRY', 500);
        }
    }

    private function markOrderPaid(PaypalService $paypalService, $order, array $result): void
    {
        if ($paypalService->isPaymentSuccessful($result)) {
            StateMachineService::getInstance($order)->markPaid();
        }
    }

    private function persistSafeResult(int $orderId, PaypalService $paypalService, array $result, string $fallbackId): void
    {
        OrderPaymentRepo::createOrUpdatePayment($orderId, [
            'transaction_id' => $paypalService->getCaptureTransactionId($result) ?: $fallbackId,
            'response'       => $result,
        ]);
    }

    private function getRequestData(Request $request): array
    {
        $data = \json_decode($request->getContent(), true);

        return is_array($data) ? $data + $request->all() : $request->all();
    }

    private function resolvePaypalOrder(string $orderNumber, bool $requireGuestOwnership = true): object
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($orderNumber, $customer);
        if (! $order) {
            throw new \RuntimeException('Order was not found.');
        }

        if (! $customer && $requireGuestOwnership) {
            $guestOrders = array_map('strval', session('guest_order_numbers', []));
            if (! in_array((string) $orderNumber, $guestOrders, true)) {
                throw new \RuntimeException('This order is not available in the current guest session.');
            }
        }

        if ((string) ($order->payment_method_code ?? '') !== 'paypal') {
            throw new \RuntimeException('This order is not configured for PayPal.');
        }

        return $order;
    }

    private function paymentError(Throwable $exception, string $fallback): JsonResponse
    {
        Log::warning($fallback, [
            'exception' => $exception::class,
            'message'   => $this->redactProviderMessage($exception->getMessage()),
        ]);

        return response()->json(['message' => $fallback], 422);
    }

    private function assertProviderOrderBinding(int $orderId, string $providerOrderId): void
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment) {
            throw new \RuntimeException('PayPal payment order is not bound to this local order. Please reload the payment page.');
        }

        $response   = $this->decodePaymentResponse($payment->response);
        $candidates = array_filter([
            trim((string) $payment->transaction_id),
            trim((string) ($response['id'] ?? '')),
            trim((string) ($response['token'] ?? '')),
        ]);
        foreach ($candidates as $candidate) {
            if (hash_equals((string) $candidate, $providerOrderId)) {
                return;
            }
        }

        throw new \RuntimeException('PayPal payment order does not match this local order.');
    }

    private function getStoredCompletedResult(int $orderId, string $providerOrderId): ?array
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment) {
            return null;
        }

        $result   = $this->decodePaymentResponse($payment->response);
        $boundIds = array_filter([
            trim((string) ($result['id'] ?? '')),
            trim((string) ($result['token'] ?? '')),
        ]);
        $status  = strtoupper(trim((string) ($result['status'] ?? '')));
        $matches = false;
        foreach ($boundIds as $boundId) {
            if (hash_equals((string) $boundId, $providerOrderId)) {
                $matches = true;

                break;
            }
        }
        if (! $matches || $status !== 'COMPLETED') {
            return null;
        }

        return $result;
    }

    private function getAnyStoredCompletedResult(int $orderId): ?array
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment) {
            return null;
        }

        $result = $this->decodePaymentResponse($payment->response);

        return strtoupper(trim((string) ($result['status'] ?? ''))) === 'COMPLETED' ? $result : null;
    }

    private function isStoredNvpResult(array $result): bool
    {
        return isset($result['token'])
            || isset($result['verified_ipn'])
            || (isset($result['amount'], $result['currency'], $result['order_number']) && ! isset($result['purchase_units']));
    }

    private function getBoundNvpToken(int $orderId): string
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment) {
            return '';
        }

        $result = $this->decodePaymentResponse($payment->response);
        $token  = trim((string) ($result['token'] ?? ''));

        return preg_match('/^[A-Za-z0-9-]{10,128}$/', $token) === 1 ? $token : '';
    }

    private function getReusableCreatedResult(int $orderId, bool $nvp): ?array
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment || ! $payment->updated_at || $payment->updated_at->lt(now()->subHours(2))) {
            return null;
        }

        $result      = $this->decodePaymentResponse($payment->response);
        $providerId  = trim((string) ($result['id'] ?? ''));
        $resultIsNvp = isset($result['token'], $result['approval_url']);
        if ($providerId === '' || $resultIsNvp !== $nvp) {
            return null;
        }

        $allowed = $nvp
            ? ['SUCCESS', 'SUCCESSWITHWARNING']
            : ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'];

        return in_array(strtoupper(trim((string) ($result['status'] ?? ''))), $allowed, true) ? $result : null;
    }

    private function safeCaptureRequest(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'orderNumber', 'order_number', 'paypalOrderId', 'paypal_order_id',
            'token', 'paypalToken',
        ]));
    }

    private function withPaymentLock(string $operation, int $orderId, callable $callback): mixed
    {
        $lock = Cache::lock('paypal:payment:' . $orderId, 60);
        if (! $lock->get()) {
            throw new \RuntimeException('A PayPal payment request is already being processed.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function decodePaymentResponse($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function redactProviderMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        $message = preg_replace('/(client[_ -]?secret|access[_ -]?token|authorization|api[_ -]?username|password|signature|pwd|payer[_ -]?id)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }

    private function getNvpCheckoutUrls(string $orderNumber): array
    {
        // Return/cancel/notify endpoints must remain under the merchant's own
        // domain. Never accept callback URLs from a browser request.
        return [
            'return_url' => $this->getCallbackUrl('return', $orderNumber),
            'cancel_url' => $this->getCallbackUrl('cancel', $orderNumber),
            'notify_url' => $this->getCallbackUrl('notify', $orderNumber),
        ];
    }

    private function getCallbackUrl(string $action, string $orderNumber): string
    {
        return tenant_operational_route('paypal.nvp.' . $action, ['order_number' => $orderNumber]);
    }

    private function getOrderNumberFromData(array $data, Request $request): string
    {
        return trim((string) ($data['orderNumber']
            ?? $data['order_number']
            ?? $data['invoice']
            ?? $data['custom']
            ?? $request->input('orderNumber')
            ?? $request->input('order_number')
            ?? ''));
    }

    private function getNvpToken(array $data, Request $request): string
    {
        return trim((string) ($data['token'] ?? $data['paypalToken'] ?? $request->input('token', '')));
    }

    private function getNvpPayerId(array $data, Request $request): string
    {
        return trim((string) ($data['PayerID'] ?? $data['payerId'] ?? $data['payer_id'] ?? $request->input('PayerID') ?? $request->input('payerId') ?? $request->input('payer_id') ?? ''));
    }

    private function getSuccessRedirectUrl(string $orderNumber): string
    {
        return shop_route('checkout.success', ['order_number' => $orderNumber]);
    }

    private function getFailureRedirectUrl(string $orderNumber): string
    {
        if ($orderNumber !== '') {
            return shop_route('orders.pay', ['number' => $orderNumber]);
        }

        return shop_route('checkout.index');
    }

    private function redirectWithMessage(Request $request, string $url, string $key, string $message): RedirectResponse
    {
        $response = redirect($url);

        if ($request->hasSession()) {
            $response->with($key, $message);
        }

        return $response;
    }
}
