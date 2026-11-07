<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\Domain;
use App\Saas\Models\PlatformPaymentAccount;
use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use App\Saas\SiteLifecycleService;
use App\Saas\Support\DomainName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Central account registry for Platform Pay.
 *
 * This service deliberately has no child-tenant database access.  The
 * selected account, callback host and eventual provider order are all stored
 * on the central payment session, making round-robin selection deterministic
 * and audit-friendly.
 */
final class PlatformPaymentAccountService
{
    public function __construct(private readonly SiteLifecycleService $lifecycle) {}

    public function schemaReady(): bool
    {
        try {
            return Schema::connection('saas')->hasTable('saas_platform_payment_accounts');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return Collection<int, PlatformPaymentAccount> */
    public function accounts(): Collection
    {
        if (! $this->schemaReady()) {
            return new Collection;
        }

        return PlatformPaymentAccount::query()
            ->paypal()
            ->orderByDesc('enabled')
            ->orderBy('last_selected_at')
            ->orderBy('id')
            ->get();
    }

    public function hasManagedPaypalAccounts(): bool
    {
        return $this->schemaReady() && PlatformPaymentAccount::query()->paypal()->exists();
    }

    /** @return Collection<int, PlatformPaymentAccount> */
    public function activePaypalAccounts(): Collection
    {
        if (! $this->schemaReady()) {
            return new Collection;
        }

        return PlatformPaymentAccount::query()
            ->paypal()
            ->enabled()
            ->where(static function ($query): void {
                $query->whereNull('circuit_open_until')
                    ->orWhere('circuit_open_until', '<=', now('UTC'));
            })
            ->orderByRaw("CASE health_status WHEN 'ready' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN last_selected_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('(rotation_count / NULLIF(rotation_weight, 0))')
            ->orderBy('last_selected_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Reserve the least-recently-used active PayPal account.  The caller is
     * already in the central session transaction, so the row lock and the
     * session insert commit together.
     */
    public function selectNextActivePaypalAccount(?int $exceptAccountId = null): ?PlatformPaymentAccount
    {
        if (! $this->schemaReady()) {
            return null;
        }

        $query = PlatformPaymentAccount::query()
            ->paypal()
            ->enabled()
            ->where(static function ($query): void {
                $query->whereNull('circuit_open_until')
                    ->orWhere('circuit_open_until', '<=', now('UTC'));
            })
            ->orderByRaw("CASE health_status WHEN 'ready' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN last_selected_at IS NULL THEN 0 ELSE 1 END')
            // Weighted fair routing: weight 2 receives roughly twice the
            // traffic of weight 1 without changing an existing session.
            ->orderByRaw('(rotation_count / NULLIF(rotation_weight, 0))')
            ->orderBy('last_selected_at')
            ->orderBy('id');
        if ($exceptAccountId) {
            $query->where('id', '!=', $exceptAccountId);
        }
        $account = $query->lockForUpdate()->first();

        if (! $account) {
            return null;
        }

        $account->forceFill([
            'rotation_count'   => (int) $account->rotation_count + 1,
            'last_selected_at' => now('UTC'),
        ])->save();

        return $account->fresh();
    }

    /** @return array<string, mixed> */
    public function runtimeConfiguration(PlatformPaymentAccount $account): array
    {
        if ($account->provider !== PlatformPaymentAccount::PROVIDER_PAYPAL) {
            throw new RuntimeException('不支持的收款账户类型。');
        }

        try {
            $secret = trim(Crypt::decryptString((string) $account->secret_ciphertext));
        } catch (\Throwable) {
            throw new RuntimeException('收款账户密钥无法解密，请重新保存该账户。');
        }

        return [
            'account_id'    => (int) $account->id,
            'account_uuid'  => (string) $account->uuid,
            'account_label' => (string) $account->label,
            'api_mode'      => 'rest',
            'mode'          => (string) $account->api_mode,
            'client_id'     => trim((string) $account->client_id),
            'secret'        => $secret,
            'merchant_id'   => strtoupper(trim((string) $account->merchant_id)),
            'callback_host' => (string) $account->callback_host,
            'enabled'       => (bool) $account->enabled,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function publicSummaries(): array
    {
        return $this->accounts()->map(static function (PlatformPaymentAccount $account): array {
            return [
                'id'                    => (int) $account->id,
                'uuid'                  => (string) $account->uuid,
                'label'                 => (string) $account->label,
                'mode'                  => (string) $account->api_mode,
                'client_id_suffix'      => self::suffix((string) $account->client_id),
                'merchant_id'           => (string) $account->merchant_id,
                'callback_host'         => (string) $account->callback_host,
                'enabled'               => (bool) $account->enabled,
                'rotation_weight'       => (int) $account->rotation_weight,
                'rotation_count'        => (int) $account->rotation_count,
                'last_selected_at'      => $account->last_selected_at?->toIso8601String(),
                'health_status'         => (string) $account->health_status,
                'consecutive_failures'  => (int) $account->consecutive_failures,
                'circuit_open_until'    => $account->circuit_open_until?->toIso8601String(),
                'last_health_checked_at'=> $account->last_health_checked_at?->toIso8601String(),
            ];
        })->all();
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): PlatformPaymentAccount
    {
        $uuid = (string) Str::uuid();
        $host = $this->generatedCallbackHost($uuid);
        $this->assertControlSiteReady();

        $account = PlatformPaymentAccount::query()->create([
            'uuid'              => $uuid,
            'provider'          => PlatformPaymentAccount::PROVIDER_PAYPAL,
            'label'             => trim((string) $values['label']),
            'api_mode'          => (string) $values['api_mode'],
            'client_id'         => trim((string) $values['client_id']),
            'secret_ciphertext' => Crypt::encryptString(trim((string) $values['secret'])),
            'merchant_id'       => strtoupper(trim((string) ($values['merchant_id'] ?? ''))),
            'callback_host'     => $host,
            'enabled'           => filter_var($values['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'rotation_weight'   => max(1, min(100, (int) ($values['rotation_weight'] ?? 1))),
            'health_status'     => 'unknown',
        ]);

        try {
            $this->publishCallbackDomain($account);
        } catch (\Throwable $exception) {
            $account->delete();

            throw $exception;
        }

        return $account->fresh();
    }

    /** @param array<string, mixed> $values */
    public function update(PlatformPaymentAccount $account, array $values): PlatformPaymentAccount
    {
        if ($account->provider !== PlatformPaymentAccount::PROVIDER_PAYPAL) {
            throw new RuntimeException('不支持的收款账户类型。');
        }

        $changingCredentials = (string) $account->api_mode !== (string) $values['api_mode']
            || ! hash_equals((string) $account->client_id, trim((string) $values['client_id']))
            || strtoupper((string) $account->merchant_id) !== strtoupper(trim((string) ($values['merchant_id'] ?? '')))
            || trim((string) ($values['secret'] ?? '')) !== '';
        if ($changingCredentials && $this->hasOpenSessions($account)) {
            throw new RuntimeException('该账户仍有进行中或待对账的支付，不能修改密钥、环境或商户标识。请先等待对账完成。');
        }

        $fields = [
            'label'       => trim((string) $values['label']),
            'api_mode'    => (string) $values['api_mode'],
            'client_id'   => trim((string) $values['client_id']),
            'merchant_id' => strtoupper(trim((string) ($values['merchant_id'] ?? ''))),
            'enabled'     => filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'rotation_weight' => max(1, min(100, (int) ($values['rotation_weight'] ?? 1))),
        ];
        if (trim((string) ($values['secret'] ?? '')) !== '') {
            $fields['secret_ciphertext'] = Crypt::encryptString(trim((string) $values['secret']));
            $fields['health_status']     = 'unknown';
        }

        $account->forceFill($fields)->save();

        return $account->fresh();
    }

    public function markHealth(PlatformPaymentAccount $account, string $status): void
    {
        $ready = $status === 'ready';
        $account->forceFill([
            'health_status'        => $ready ? 'ready' : (in_array($status, ['ready', 'failed'], true) ? 'failed' : 'unknown'),
            'consecutive_failures' => $ready ? 0 : (int) $account->consecutive_failures,
            'circuit_open_until'   => $ready ? null : $account->circuit_open_until,
            'last_health_checked_at'=> now('UTC'),
        ])->save();
    }

    /**
     * A circuit opens only after three consecutive technical failures. One
     * transient upstream timeout should not destabilize the whole account
     * pool; a successful manual health probe closes the circuit immediately.
     */
    public function recordFailure(PlatformPaymentAccount $account): void
    {
        DB::connection('saas')->transaction(function () use ($account): void {
            $locked = PlatformPaymentAccount::query()->lockForUpdate()->find($account->id);
            if (! $locked) {
                return;
            }
            $failures = min(65535, (int) $locked->consecutive_failures + 1);
            $locked->forceFill([
                'health_status'         => 'failed',
                'consecutive_failures'  => $failures,
                'circuit_open_until'    => $failures >= 3 ? now('UTC')->addMinutes(10) : $locked->circuit_open_until,
                'last_health_checked_at'=> now('UTC'),
            ])->save();
        }, 3);
    }

    /**
     * Whether an account is explicitly unavailable for a new buyer payment.
     * Existing provider-bound sessions must never use this signal to move to
     * another merchant; they stay on their original account for capture and
     * reconciliation.
     */
    public function isUnavailableForNewSessions(PlatformPaymentAccount $account): bool
    {
        return ! $account->enabled
            || ($account->circuit_open_until !== null && $account->circuit_open_until->isFuture());
    }

    public function isCallbackHost(string $host): bool
    {
        if (! $this->schemaReady()) {
            return false;
        }

        try {
            $host = DomainName::normalize($host);
        } catch (\Throwable) {
            return false;
        }

        return PlatformPaymentAccount::query()
            ->paypal()
            ->whereNotNull('callback_host')
            ->where('callback_host', $host)
            ->exists();
    }

    public function hasOpenSessions(PlatformPaymentAccount $account): bool
    {
        if (! $this->schemaReady()) {
            return false;
        }

        return PlatformPaymentSession::query()
            ->where('payment_account_id', $account->id)
            ->where(function ($query): void {
                $query->whereIn('status', [
                    PlatformPaymentSession::STATUS_PENDING,
                    PlatformPaymentSession::STATUS_PROCESSING,
                ])->orWhere(function ($query): void {
                    $query->where('status', PlatformPaymentSession::STATUS_EXPIRED)
                        ->whereNotNull('provider_order_id')
                        ->where('created_at', '>=', now('UTC')->subDays(PlatformPaymentSession::RECOVERY_WINDOW_DAYS));
                });
            })
            ->exists();
    }

    private function generatedCallbackHost(string $uuid): string
    {
        try {
            $base = DomainName::normalize((string) config('saas.base_domain'));
        } catch (\Throwable $exception) {
            throw new RuntimeException('请先配置有效的 SAAS_BASE_DOMAIN，才能自动生成 PayPal 回传域名。', 0, $exception);
        }

        return DomainName::normalize('pay-' . substr(str_replace('-', '', $uuid), 0, 16) . '.' . $base);
    }

    private function publishCallbackDomain(PlatformPaymentAccount $account): void
    {
        $site = $this->controlSite();
        $domain = Domain::query()->where('host', $account->callback_host)->first();
        if ($domain && (int) $domain->site_id !== (int) $site->id) {
            throw new RuntimeException('自动生成的回传域名已被其他站点占用。');
        }

        if (! $domain || ! $domain->isRoutable()) {
            $this->lifecycle->addDomain($site, 'https://' . $account->callback_host, false);
        }
    }

    private function controlSite(): Site
    {
        $site = Site::query()->with('domains')->where('status', Site::STATUS_ACTIVE)->get()
            ->first(static fn (Site $candidate): bool => (bool) data_get($candidate->meta, 'control_site', false));
        if (! $site) {
            throw new RuntimeException('LONGOS 主站尚未就绪，不能发布收款回传域名。');
        }

        return $site;
    }

    private function assertControlSiteReady(): void
    {
        $this->controlSite();
    }

    private static function suffix(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : '••••' . substr($value, -6);
    }
}
