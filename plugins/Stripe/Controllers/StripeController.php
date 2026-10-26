<?php

namespace Plugin\Stripe\Controllers;

use Beike\Models\OrderPayment;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Beike\Shop\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\Stripe\Services\StripeService;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeController extends Controller
{
    /** Create or recover the order's SCA-capable PaymentIntent. */
    public function intent(Request $request): JsonResponse
    {
        try {
            $data  = $request->validate(['order_number' => ['required', 'string', 'max:128']]);
            $order = $this->resolveStripeOrder($data['order_number']);

            return $this->withPaymentLock((int) $order->id, function () use ($order): JsonResponse {
                $order = $order->fresh();
                if ((string) $order->status !== StateMachineService::UNPAID) {
                    throw new \RuntimeException('This order is no longer awaiting payment.');
                }

                $service      = new StripeService($order);
                $existing     = OrderPayment::query()->where('order_id', $order->id)->first();
                $existingId   = trim((string) ($existing?->transaction_id ?? ''));
                $result       = null;
                if (str_starts_with($existingId, 'pi_')) {
                    $result = $service->paymentIntentForCheckout($existingId);
                    if (($result['status'] ?? '') === 'succeeded') {
                        $audit = Arr::except($result, ['client_secret']);
                        OrderPaymentRepo::createOrUpdatePayment($order->id, [
                            'transaction_id' => $result['id'],
                            'response'       => $audit,
                        ]);
                        StateMachineService::getInstance($order)->markPaid();

                        return json_success('Stripe payment already completed.', [
                            'payment_intent_id'  => $result['id'],
                            'already_succeeded'  => true,
                            'redirect_url'       => order_checkout_result_url($order),
                        ]);
                    }
                    if (($result['status'] ?? '') === 'canceled') {
                        $result = $service->createPaymentIntent('replacement-' . $existingId);
                    }
                }
                $result ??= $service->createPaymentIntent();
                $clientSecret = $result['client_secret'];
                $audit        = Arr::except($result, ['client_secret']);
                OrderPaymentRepo::createOrUpdatePayment($order->id, [
                    'transaction_id' => $result['id'],
                    'request'        => ['stage' => 'intent', 'order_number' => $order->number],
                    'response'       => $audit,
                ]);

                return json_success('Stripe PaymentIntent created.', [
                    'payment_intent_id' => $result['id'],
                    'client_secret'     => $clientSecret,
                    'already_succeeded' => false,
                ]);
            });
        } catch (\Throwable $exception) {
            return $this->paymentError($exception, 'Stripe payment could not be initialized.');
        }
    }

    /**
     * Finalize a PaymentIntent after Stripe.js completes any 3DS challenge.
     * The legacy token branch remains only for already-cached checkout pages.
     */
    public function capture(Request $request): JsonResponse
    {
        try {
            $data  = $request->validate([
                'order_number'      => ['required', 'string', 'max:128'],
                'payment_intent_id' => ['nullable', 'string', 'max:160', 'regex:/^pi_[A-Za-z0-9_]+$/'],
                'token'             => ['nullable', 'string', 'max:160', 'regex:/^tok_[A-Za-z0-9_]+$/'],
            ]);
            $order = $this->resolveStripeOrder($data['order_number']);

            return $this->withPaymentLock((int) $order->id, function () use ($order, $data): JsonResponse {
                $order     = $order->fresh();
                $paymentId = trim((string) ($data['payment_intent_id'] ?? ''));

                if ($paymentId !== '' && $this->isCompletedReplay($order, $paymentId)) {
                    return json_success(trans('Stripe::common.capture_success'), [
                        'redirect_url' => order_checkout_result_url($order),
                    ]);
                }
                if ((string) $order->status !== StateMachineService::UNPAID) {
                    throw new \RuntimeException('This order is no longer awaiting payment.');
                }

                $service = new StripeService($order);
                if ($paymentId !== '') {
                    $this->assertProviderBinding((int) $order->id, $paymentId);
                    $result = $service->confirmPaymentIntent($paymentId);
                } else {
                    $result    = $service->capture($data);
                    $paymentId = (string) $result['id'];
                }

                OrderPaymentRepo::createOrUpdatePayment($order->id, [
                    'transaction_id' => $paymentId,
                    'request'        => [
                        'stage'             => 'confirm',
                        'order_number'      => $order->number,
                        'payment_intent_id' => $data['payment_intent_id'] ?? null,
                    ],
                    'response' => $result,
                ]);
                StateMachineService::getInstance($order)->markPaid();

                return json_success(trans('Stripe::common.capture_success'), [
                    'redirect_url' => order_checkout_result_url($order),
                ]);
            });
        } catch (\Throwable $exception) {
            return $this->paymentError($exception, 'Payment could not be completed.');
        }
    }

    /** Stripe signature-authenticated asynchronous payment confirmation. */
    public function callback(Request $request): JsonResponse
    {
        try {
            $webhookSecret = trim((string) plugin_setting('stripe.webhook_secret', ''));
            $payload       = $request->getContent();
            $signature     = (string) $request->header('Stripe-Signature', '');
            if (! str_starts_with($webhookSecret, 'whsec_') || $signature === '') {
                Log::warning('Stripe callback rejected because webhook verification is not configured.');

                return json_fail('Invalid Stripe signature', [], 400);
            }

            $event       = Webhook::constructEvent($payload, $signature, $webhookSecret);
            $requestData = $event->toArray();
            $eventId     = trim((string) ($requestData['id'] ?? ''));
            $type        = (string) ($requestData['type'] ?? '');
            if (! in_array($type, ['payment_intent.succeeded', 'charge.succeeded'], true)) {
                return json_success('Stripe event ignored.');
            }
            if (preg_match('/^evt_[A-Za-z0-9_]{8,128}$/', $eventId) !== 1) {
                return json_fail('Invalid Stripe event id', [], 400);
            }

            $object      = (array) ($requestData['data']['object'] ?? []);
            // PaymentIntent card payments also emit charge.succeeded. Their
            // canonical completion signal is payment_intent.succeeded, and
            // Stripe does not guarantee PI metadata is copied to the charge.
            if ($type === 'charge.succeeded' && ! empty($object['payment_intent'])) {
                return json_success('Stripe PaymentIntent charge event ignored.');
            }
            $orderNumber = trim((string) ($object['metadata']['order_number'] ?? ''));
            if ($orderNumber === '') {
                return json_fail('Missing order metadata', [], 400);
            }
            $order = OrderRepo::getOrderByNumber($orderNumber);
            if (! $order || (string) $order->payment_method_code !== 'stripe') {
                return json_fail('Invalid Stripe order', [], 400);
            }

            return $this->withPaymentLock((int) $order->id, function () use ($order, $eventId, $type, $object): JsonResponse {
                $order       = $order->fresh();
                $service     = new StripeService($order, true);
                $payment     = $service->validateWebhookPayment($object, $type);
                $providerId  = (string) $payment['id'];
                $eventReplay = $this->storedCallbackEventId((int) $order->id) === $eventId;

                if (! $eventReplay) {
                    OrderPaymentRepo::createOrUpdatePayment($order->id, [
                        'transaction_id' => $providerId,
                        'callback'       => [
                            'event_id'   => $eventId,
                            'event_type' => $type,
                            'payment'    => $payment,
                        ],
                    ]);
                }

                if ((string) $order->status === StateMachineService::UNPAID) {
                    StateMachineService::getInstance($order)->markPaid();
                } elseif (! in_array((string) $order->status, StateMachineService::getValidStatuses(), true)) {
                    Log::critical('Stripe payment succeeded for an order requiring manual reconciliation.', [
                        'order_number' => $order->number,
                        'status'       => $order->status,
                        'event_id'     => $eventId,
                    ]);

                    return json_fail('Stripe order requires reconciliation.', [], 409);
                }

                return json_success(trans('Stripe::common.capture_success'));
            });
        } catch (SignatureVerificationException|\UnexpectedValueException $exception) {
            Log::warning('Stripe signature verification failed.', ['exception' => $exception::class]);

            return json_fail('Invalid Stripe signature', [], 400);
        } catch (\Throwable $exception) {
            Log::error('Stripe callback could not be processed.', [
                'exception' => $exception::class,
                'message'   => mb_substr($exception->getMessage(), 0, 300),
            ]);

            return json_fail('Stripe callback could not be processed.', [], 500);
        }
    }

    private function resolveStripeOrder(string $orderNumber): object
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($orderNumber, $customer);
        if (! $order) {
            throw new \RuntimeException('Order was not found.');
        }
        if (! $customer) {
            $guestOrders = array_map('strval', session('guest_order_numbers', []));
            if (! in_array((string) $orderNumber, $guestOrders, true)) {
                throw new \RuntimeException('This order is not available in the current guest session.');
            }
        }
        if ((string) ($order->payment_method_code ?? '') !== 'stripe') {
            throw new \RuntimeException('This order is not configured for Stripe.');
        }

        return $order;
    }

    private function assertProviderBinding(int $orderId, string $providerId): void
    {
        $payment = OrderPayment::query()->where('order_id', $orderId)->first();
        if (! $payment || ! hash_equals((string) $payment->transaction_id, $providerId)) {
            throw new \RuntimeException('Stripe PaymentIntent is not bound to this order.');
        }
    }

    private function isCompletedReplay(object $order, string $providerId): bool
    {
        if (! in_array((string) $order->status, StateMachineService::getValidStatuses(), true)) {
            return false;
        }

        $payment = OrderPayment::query()->where('order_id', $order->id)->first();

        return $payment && hash_equals((string) $payment->transaction_id, $providerId);
    }

    private function storedCallbackEventId(int $orderId): string
    {
        $payment  = OrderPayment::query()->where('order_id', $orderId)->first();
        $callback = json_decode((string) ($payment?->callback ?? ''), true);

        return is_array($callback) ? (string) ($callback['event_id'] ?? '') : '';
    }

    private function withPaymentLock(int $orderId, callable $callback): mixed
    {
        $lock = Cache::lock('stripe:payment:' . $orderId, 90);
        if (! $lock->get()) {
            throw new \RuntimeException('A Stripe payment request is already being processed.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function paymentError(\Throwable $exception, string $message): JsonResponse
    {
        Log::warning($message, ['exception' => $exception::class]);

        return json_fail($message);
    }
}
