<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Models\Order;
use Beike\Models\OrderPayment;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Services\StateMachineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugin\Paypal\Services\PaypalCurrency;
use RuntimeException;

class PlatformPaymentSessionService
{
    public const PAYMENT_CODE = 'longos_platform_pay';

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    /** @return array{session: PlatformPaymentSession, token: string, checkout_url: string} */
    public function create(Site $site, Order $order): array
    {
        if ((bool) data_get($site->meta, 'control_site', false)) {
            throw new RuntimeException('主站不能通过平台代收向自身发起支付。');
        }
        if ($this->settings->issues() !== []) {
            throw new RuntimeException('平台代收当前不可用，请联系平台管理员。');
        }
        $this->assertPayableOrder($order);

        $amount   = PaypalCurrency::paymentAmount($order);
        $currency = PaypalCurrency::paymentCurrency($order);
        if (! is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('订单支付金额无效。');
        }

        $token   = bin2hex(random_bytes(32));
        $session = PlatformPaymentSession::query()->create([
            'uuid'         => (string) Str::uuid(),
            'token_hash'   => hash('sha256', $token),
            'site_id'      => $site->id,
            'order_id'     => $order->id,
            'order_number' => (string) $order->number,
            'amount'       => number_format($amount, 8, '.', ''),
            'currency'     => $currency,
            'locale'       => preg_match('/^[A-Za-z_]{2,16}$/', (string) $order->locale) ? (string) $order->locale : 'en',
            'status'       => PlatformPaymentSession::STATUS_PENDING,
            'expires_at'   => now()->addHours(2),
        ]);

        return [
            'session'      => $session,
            'token'        => $token,
            'checkout_url' => $this->settings->mainUrl("_longos/pay/{$token}"),
        ];
    }

    public function resolve(string $token, bool $allowCompleted = true): PlatformPaymentSession
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new RuntimeException('支付链接无效。');
        }

        $session = PlatformPaymentSession::query()
            ->with('site.domains')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        if (! $session || ! $session->site || $session->site->status !== Site::STATUS_ACTIVE) {
            throw new RuntimeException('支付链接不存在或已失效。');
        }
        if ($session->expires_at?->isPast() && $session->status !== PlatformPaymentSession::STATUS_COMPLETED) {
            $session->forceFill(['status' => PlatformPaymentSession::STATUS_EXPIRED])->save();

            throw new RuntimeException('支付链接已过期，请返回店铺重新发起支付。');
        }
        if (! $allowCompleted && $session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            throw new RuntimeException('此订单已经支付完成。');
        }
        if (in_array($session->status, [PlatformPaymentSession::STATUS_CANCELLED, PlatformPaymentSession::STATUS_FAILED], true)) {
            throw new RuntimeException('支付会话已结束，请返回店铺重新发起支付。');
        }

        return $session;
    }

    public function bindProvider(PlatformPaymentSession $session, string $provider, string $providerOrderId): PlatformPaymentSession
    {
        if (! in_array($provider, $this->settings->configuredProviders(), true)) {
            throw new RuntimeException('所选支付方式当前不可用。');
        }

        return DB::connection('saas')->transaction(function () use ($session, $provider, $providerOrderId): PlatformPaymentSession {
            $locked = PlatformPaymentSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status === PlatformPaymentSession::STATUS_COMPLETED
                && hash_equals((string) $locked->provider, $provider)
                && hash_equals((string) $locked->provider_order_id, $providerOrderId)) {
                return $locked->fresh(['site.domains']);
            }
            if (in_array($locked->status, [PlatformPaymentSession::STATUS_PROCESSING, PlatformPaymentSession::STATUS_EXPIRED], true)
                && hash_equals((string) $locked->provider, $provider)
                && hash_equals((string) $locked->provider_order_id, $providerOrderId)) {
                return $locked->fresh(['site.domains']);
            }
            if (! $locked->isOpen()) {
                throw new RuntimeException('支付会话已结束。');
            }
            if ($locked->provider && ! hash_equals($locked->provider, $provider)) {
                throw new RuntimeException('支付方式在当前会话中已经确定。');
            }
            if ($locked->provider_order_id && ! hash_equals($locked->provider_order_id, $providerOrderId)) {
                throw new RuntimeException('支付会话已绑定其他交易。');
            }
            $locked->forceFill([
                'provider'          => $provider,
                'provider_order_id' => $providerOrderId,
                'status'            => PlatformPaymentSession::STATUS_PROCESSING,
            ])->save();

            return $locked->fresh(['site.domains']);
        }, 3);
    }

    public function claimProvider(PlatformPaymentSession $session, string $provider): PlatformPaymentSession
    {
        if (! in_array($provider, $this->settings->configuredProviders(), true)) {
            throw new RuntimeException('所选支付方式当前不可用。');
        }

        return DB::connection('saas')->transaction(function () use ($session, $provider): PlatformPaymentSession {
            $locked = PlatformPaymentSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new RuntimeException('支付会话已结束。');
            }
            if ($locked->provider && ! hash_equals((string) $locked->provider, $provider)) {
                throw new RuntimeException('支付方式在当前会话中已经确定。');
            }
            $locked->forceFill([
                'provider' => $provider,
                'status'   => PlatformPaymentSession::STATUS_PROCESSING,
            ])->save();

            return $locked->fresh(['site.domains']);
        }, 3);
    }

    public function assertProviderBinding(PlatformPaymentSession $session, string $provider, string $providerOrderId): void
    {
        $session->refresh();
        if (! hash_equals((string) $session->provider, $provider)
            || ! hash_equals((string) $session->provider_order_id, $providerOrderId)) {
            throw new RuntimeException('支付交易未绑定到当前会话。');
        }
    }

    /** @param array<string, mixed> $providerPayload */
    public function complete(PlatformPaymentSession $session, string $provider, string $providerOrderId, string $transactionId, array $providerPayload): PlatformPaymentSession
    {
        $lock = Cache::lock('platform-payment:complete:' . $session->uuid, 120);
        if (! $lock->get()) {
            throw new RuntimeException('支付结果正在处理中，请稍后刷新。');
        }

        try {
            $session->refresh()->loadMissing('site.domains');
            if ($session->status === PlatformPaymentSession::STATUS_COMPLETED) {
                if (! hash_equals((string) $session->provider_transaction_id, $transactionId)) {
                    throw new RuntimeException('支付会话已经由其他交易完成。');
                }

                return $session;
            }
            if (! hash_equals((string) $session->provider, $provider)
                || ! hash_equals((string) $session->provider_order_id, $providerOrderId)) {
                throw new RuntimeException('支付结果与当前会话不匹配。');
            }

            $this->tenants->run($session->site, function () use ($session, $provider, $providerOrderId, $transactionId, $providerPayload): void {
                DB::transaction(function () use ($session, $provider, $providerOrderId, $transactionId, $providerPayload): void {
                    $order = Order::query()->whereKey($session->order_id)->lockForUpdate()->first();
                    if (! $order || ! hash_equals((string) $session->order_number, (string) $order->number)) {
                        throw new RuntimeException('子站订单不存在或已改变。');
                    }
                    $this->assertOrderSnapshot($order, $session);

                    if ((string) $order->status !== StateMachineService::UNPAID) {
                        $existing = OrderPayment::query()->where('order_id', $order->id)->first();
                        if ($existing && hash_equals((string) $existing->transaction_id, $transactionId)) {
                            return;
                        }

                        throw new RuntimeException('子站订单已由其他支付交易处理。');
                    }

                    OrderPaymentRepo::createOrUpdatePayment($order->id, [
                        'transaction_id' => $transactionId,
                        'request'        => [
                            'stage'             => 'platform_capture',
                            'provider'          => $provider,
                            'platform_session'  => $session->uuid,
                            'provider_order_id' => $providerOrderId,
                        ],
                        'response' => $providerPayload,
                    ]);
                    StateMachineService::getInstance($order)->markPaid();
                }, 3);
            });

            $session->forceFill([
                'status'                  => PlatformPaymentSession::STATUS_COMPLETED,
                'provider_transaction_id' => $transactionId,
                'provider_payload'        => $providerPayload,
                'completed_at'            => now(),
            ])->save();

            return $session->fresh(['site.domains']);
        } finally {
            $lock->release();
        }
    }

    public function cancel(PlatformPaymentSession $session): void
    {
        if ($session->status === PlatformPaymentSession::STATUS_PENDING) {
            $session->forceFill(['status' => PlatformPaymentSession::STATUS_CANCELLED])->save();
        }
    }

    public function tenantSuccessUrl(PlatformPaymentSession $session): string
    {
        return $this->tenants->run($session->site, fn (): string => shop_route('checkout.success', [
            'order_number' => $session->order_number,
        ]));
    }

    public function tenantCheckoutUrl(PlatformPaymentSession $session): string
    {
        return $this->tenants->run($session->site, fn (): string => shop_route('checkout.index'));
    }

    private function assertPayableOrder(Order $order): void
    {
        if ((string) $order->payment_method_code !== self::PAYMENT_CODE) {
            throw new RuntimeException('订单未选择平台代收。');
        }
        if ((string) $order->status !== StateMachineService::UNPAID) {
            throw new RuntimeException('订单已不再等待付款。');
        }
    }

    private function assertOrderSnapshot(Order $order, PlatformPaymentSession $session): void
    {
        if ((string) $order->payment_method_code !== self::PAYMENT_CODE) {
            throw new RuntimeException('订单支付方式已变化，支付结果未入账。');
        }
        $amount = number_format(PaypalCurrency::paymentAmount($order), 8, '.', '');
        if (! hash_equals((string) $session->amount, $amount)
            || ! hash_equals((string) $session->currency, PaypalCurrency::paymentCurrency($order))) {
            throw new RuntimeException('订单金额或币种已变化，支付结果未入账。');
        }
    }
}
