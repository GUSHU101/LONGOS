<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Models\Order;
use Beike\Models\OrderPayment;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Services\StateMachineService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PlatformPaymentSessionService
{
    public const PAYMENT_CODE = 'longos_platform_pay';

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly PlatformPaymentSettings $settings,
    ) {}

    /** @return array{session: PlatformPaymentSession, token: string, checkout_url: string} */
    public function create(Site $site, Order $order, ?array $settingsSnapshot = null): array
    {
        if ((bool) data_get($site->meta, 'control_site', false)) {
            throw new RuntimeException('主站不能通过平台代收向自身发起支付。');
        }
        $settingsSnapshot ??= $this->settings->snapshot();
        if ($this->settings->runtimeIssuesForSnapshot($settingsSnapshot) !== []) {
            throw new RuntimeException('平台代收当前不可用，请联系平台管理员。');
        }
        $this->assertPayableOrder($order);

        $amount   = PlatformPaymentCurrency::paymentAmount($order);
        $currency = PlatformPaymentCurrency::paymentCurrency($order);
        if (! is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('订单支付金额无效。');
        }

        $activeKey = hash('sha256', $site->id . ':' . $order->id);
        $lock      = Cache::lock('platform-payment:create:' . $activeKey, 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new RuntimeException('订单支付会话正在创建，请稍后重试。');
        }

        try {
            $created = DB::connection('saas')->transaction(function () use ($site, $order, $amount, $currency, $activeKey): array {
                $candidates = PlatformPaymentSession::query()
                    ->where('site_id', $site->id)
                    ->where('order_id', $order->id)
                    ->whereIn('status', [
                        PlatformPaymentSession::STATUS_PENDING,
                        PlatformPaymentSession::STATUS_PROCESSING,
                        PlatformPaymentSession::STATUS_EXPIRED,
                    ])
                    ->lockForUpdate()
                    ->latest('id')
                    ->get();

                foreach ($candidates as $candidate) {
                    if (! $candidate->isOpen()) {
                        if ($candidate->isFinanciallyRecoverable()) {
                            if ($candidate->status !== PlatformPaymentSession::STATUS_EXPIRED) {
                                $candidate->forceFill(['status' => PlatformPaymentSession::STATUS_EXPIRED])->save();
                            }

                            throw new RuntimeException('该订单仍有一笔支付等待供应商对账，为避免重复扣款，暂时不能重新发起。');
                        }

                        $candidate->forceFill([
                            'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                            'active_key' => null,
                        ])->save();

                        continue;
                    }

                    $token = $this->tokenForUuid((string) $candidate->uuid);
                    if (hash_equals((string) $candidate->token_hash, hash('sha256', $token))) {
                        return ['session' => $candidate->fresh(['site.domains']), 'token' => $token];
                    }

                    // Sessions issued before deterministic capability tokens
                    // cannot be reconstructed. An untouched session is safe
                    // to retire; a provider-bound session must finish or
                    // expire before another charge can be attempted.
                    if ($candidate->provider || $candidate->provider_order_id) {
                        throw new RuntimeException('该订单已有支付流程进行中，请返回原支付页或稍后重试。');
                    }
                    $candidate->forceFill([
                        'status'     => PlatformPaymentSession::STATUS_CANCELLED,
                        'active_key' => null,
                    ])->save();
                }

                $uuid    = (string) Str::uuid();
                $token   = $this->tokenForUuid($uuid);
                $session = PlatformPaymentSession::query()->create([
                    'uuid'         => $uuid,
                    'token_hash'   => hash('sha256', $token),
                    'active_key'   => $activeKey,
                    'site_id'      => $site->id,
                    'order_id'     => $order->id,
                    'order_number' => (string) $order->number,
                    'amount'       => number_format($amount, 8, '.', ''),
                    'currency'     => $currency,
                    'locale'       => preg_match('/^[A-Za-z_]{2,16}$/', (string) $order->locale) ? (string) $order->locale : 'en',
                    'status'       => PlatformPaymentSession::STATUS_PENDING,
                    'expires_at'   => now('UTC')->addHours(3),
                ]);

                return ['session' => $session, 'token' => $token];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The database constraint remains the final double-charge guard
            // when two app nodes do not share the same cache lock backend.
            $session = PlatformPaymentSession::query()
                ->with('site.domains')
                ->where('active_key', $activeKey)
                ->first();
            if (! $session || ! $session->isOpen()) {
                throw $exception;
            }

            $token = $this->tokenForUuid((string) $session->uuid);
            if (! hash_equals((string) $session->token_hash, hash('sha256', $token))) {
                throw $exception;
            }

            $created = ['session' => $session, 'token' => $token];
        } finally {
            $lock->release();
        }

        return $this->createdPayload(
            $created['session'],
            $created['token'],
            (string) ($settingsSnapshot['control_url'] ?? ''),
        );
    }

    public function resolve(string $token, bool $allowCompleted = true, bool $allowRecoverableExpired = false): PlatformPaymentSession
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
            $financiallyRecoverable = $session->isFinanciallyRecoverable();
            $recoverable            = $allowRecoverableExpired && $financiallyRecoverable;
            $session->forceFill([
                'status'     => PlatformPaymentSession::STATUS_EXPIRED,
                'active_key' => $financiallyRecoverable ? $session->active_key : null,
            ])->save();

            if (! $recoverable) {
                throw new RuntimeException('支付链接已过期，请返回店铺重新发起支付。');
            }
        }
        if (! $allowCompleted && $session->status === PlatformPaymentSession::STATUS_COMPLETED) {
            throw new RuntimeException('此订单已经支付完成。');
        }
        if (in_array($session->status, [PlatformPaymentSession::STATUS_CANCELLED, PlatformPaymentSession::STATUS_FAILED], true)) {
            throw new RuntimeException('支付会话已结束，请返回店铺重新发起支付。');
        }

        return $session;
    }

    public function resolveProviderResult(string $token): PlatformPaymentSession
    {
        return $this->resolve($token, true, true);
    }

    public function bindProvider(PlatformPaymentSession $session, string $provider, string $providerOrderId): PlatformPaymentSession
    {
        // A channel may be disabled while a customer is returning from the
        // provider. Allow an already-claimed session to finish with valid
        // credentials, but never let a disabled channel claim a new session.
        $providers = hash_equals((string) $session->provider, $provider)
            ? $this->settings->configuredProviders()
            : $this->settings->availableProviders();
        if (! in_array($provider, $providers, true)) {
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
        $configurationLock = Cache::lock(
            PlatformPaymentSettings::CONFIGURATION_LOCK,
            PlatformPaymentSettings::CONFIGURATION_LOCK_SECONDS,
        );

        try {
            $configurationLock->block(10);
        } catch (LockTimeoutException) {
            throw new RuntimeException('支付配置正在更新，请稍后重试。');
        }

        try {
            // Read provider settings only after the lock is acquired. The
            // credentials service reads the control database directly, so a
            // request that began before an administrator save cannot create a
            // transaction with stale worker-local configuration.
            if (! in_array($provider, $this->settings->availableProviders(), true)) {
                throw new RuntimeException('所选支付方式当前不可用。');
            }

            return DB::connection('saas')->transaction(function () use ($session, $provider): PlatformPaymentSession {
                // Site first, session second: deletion uses the same database
                // lock order, preventing a tenant from disappearing between
                // the last readiness check and the remote provider API call.
                $site = Site::query()->whereKey($session->site_id)->lockForUpdate()->first();
                if (! $site || $site->status !== Site::STATUS_ACTIVE
                            || (bool) data_get($site->meta, 'control_site', false)) {
                    throw new RuntimeException('子站当前不可发起支付。');
                }

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
        } finally {
            $configurationLock->release();
        }
    }

    public function releaseUnboundProviderClaim(PlatformPaymentSession $session, string $provider): void
    {
        DB::connection('saas')->transaction(function () use ($session, $provider): void {
            $locked = PlatformPaymentSession::query()->lockForUpdate()->find($session->id);
            if (! $locked
                || $locked->status !== PlatformPaymentSession::STATUS_PROCESSING
                || ! hash_equals((string) $locked->provider, $provider)
                || $locked->provider_order_id !== null) {
                return;
            }

            $locked->forceFill([
                'provider' => null,
                'status'   => PlatformPaymentSession::STATUS_PENDING,
            ])->save();
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

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            $session->refresh();
            if ($session->status === PlatformPaymentSession::STATUS_COMPLETED
                && hash_equals((string) $session->provider_transaction_id, $transactionId)) {
                return $session->loadMissing('site.domains');
            }

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
                'active_key'              => null,
                'provider_transaction_id' => $transactionId,
                'provider_payload'        => $providerPayload,
                'completed_at'            => now('UTC'),
            ])->save();

            return $session->fresh(['site.domains']);
        } finally {
            $lock->release();
        }
    }

    public function cancel(PlatformPaymentSession $session): void
    {
        if ($session->status === PlatformPaymentSession::STATUS_PENDING) {
            $session->forceFill(['status' => PlatformPaymentSession::STATUS_CANCELLED, 'active_key' => null])->save();
        }
    }

    public function tenantSuccessUrl(PlatformPaymentSession $session): string
    {
        return $this->tenants->run($session->site, fn (): string => order_checkout_result_url($session->order_number));
    }

    public function tenantRecoveryUrl(PlatformPaymentSession $session, string $status): string
    {
        $status = in_array($status, ['cancelled', 'error', 'unavailable'], true) ? $status : 'error';

        return $this->tenants->run(
            $session->site,
            fn (): string => order_checkout_result_url($session->order_number, $status)
        );
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

    /** @return array{session: PlatformPaymentSession, token: string, checkout_url: string} */
    private function createdPayload(PlatformPaymentSession $session, string $token, string $controlUrl = ''): array
    {
        $checkoutUrl = $controlUrl !== ''
            ? rtrim($controlUrl, '/') . "/_longos/pay/{$token}"
            : $this->settings->mainUrl("_longos/pay/{$token}");

        return [
            'session'      => $session,
            'token'        => $token,
            'checkout_url' => $checkoutUrl,
        ];
    }

    private function tokenForUuid(string $uuid): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('应用密钥尚未配置，无法创建安全支付链接。');
        }

        return hash_hmac('sha256', 'longos-platform-payment|' . $uuid, $key);
    }

    private function assertOrderSnapshot(Order $order, PlatformPaymentSession $session): void
    {
        if ((string) $order->payment_method_code !== self::PAYMENT_CODE) {
            throw new RuntimeException('订单支付方式已变化，支付结果未入账。');
        }
        $amount = number_format(PlatformPaymentCurrency::paymentAmount($order), 8, '.', '');
        if (! hash_equals((string) $session->amount, $amount)
            || ! hash_equals((string) $session->currency, PlatformPaymentCurrency::paymentCurrency($order))) {
            throw new RuntimeException('订单金额或币种已变化，支付结果未入账。');
        }
    }
}
