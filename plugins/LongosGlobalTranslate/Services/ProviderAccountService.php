<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProviderAccountService
{
    public const PROVIDERS = ['aliyun', 'volcengine', 'baidu'];

    /**
     * The API credential contract is provider-specific. Keep this metadata
     * beside the validation contract so the admin form can never describe one
     * provider while submitting keys intended for another.
     *
     * @return array<string, array{label:string,credential_fields:list<array{key:string,label:string,placeholder:string,help:string}>,account_help:string,quota_help:string}>
     */
    public static function profiles(): array
    {
        return [
            'aliyun' => [
                'label'             => '阿里云机器翻译',
                'credential_fields' => [
                    ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'placeholder' => 'LTAI…', 'help' => '使用 RAM 用户的 AccessKey ID，不要使用主账号密钥。'],
                    ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'placeholder' => '输入 AccessKey Secret', 'help' => '仅在保存时提交，并立即加密。'],
                ],
                'account_help' => '建议填写阿里云账号别名或 RAM 用户标识，便于区分额度。',
                'quota_help'   => '填写本计费周期可安全使用的免费字符额度；V1 不会产生超额付费。',
            ],
            'volcengine' => [
                'label'             => '火山引擎机器翻译',
                'credential_fields' => [
                    ['key' => 'access_key_id', 'label' => 'Access Key（AK）', 'placeholder' => 'AKLT…', 'help' => '填写火山引擎访问控制（IAM）的 Access Key。'],
                    ['key' => 'secret_access_key', 'label' => 'Secret Key（SK）', 'placeholder' => '输入 Secret Key', 'help' => '填写与上述 AK 配对的 Secret Key。'],
                ],
                'account_help' => '建议填写火山引擎账号别名或 IAM 用户标识，便于区分额度。',
                'quota_help'   => '填写本计费周期可安全使用的免费字符额度；V1 不会产生超额付费。',
            ],
            'baidu' => [
                'label'             => '百度翻译开放平台',
                'credential_fields' => [
                    ['key' => 'appid', 'label' => 'APP ID', 'placeholder' => '输入百度翻译 APP ID', 'help' => '在百度翻译开放平台的应用详情中获取。'],
                    ['key' => 'secret', 'label' => '密钥', 'placeholder' => '输入应用密钥', 'help' => '填写与 APP ID 配对的应用密钥。'],
                ],
                'account_help' => '建议填写百度翻译应用名称或账户标识，便于区分额度。',
                'quota_help'   => '填写本计费周期可安全使用的免费字符额度；V1 不会产生超额付费。',
            ],
        ];
    }

    /** @param array<string, string> $credentials */
    public function save(array $input, array $credentials, ?int $actorId): array
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('UNKNOWN_PROVIDER');
        }
        $billingIdentity = trim((string) ($input['billing_identity'] ?? ''));
        if ($billingIdentity === '' || mb_strlen($billingIdentity) > 255) {
            throw new InvalidArgumentException('INVALID_BILLING_IDENTITY');
        }
        $this->validateCredentialShape($provider, $credentials);
        $identityHash = hash('sha256', strtolower($provider) . "\0" . $billingIdentity);
        $ciphertext   = Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $provider, $identityHash, $ciphertext, $credentials, $actorId): array {
            $row = DB::table('lgt_provider_accounts')
                ->where('provider', $provider)
                ->where('billing_identity_hash', $identityHash)
                ->lockForUpdate()
                ->first();
            $values = [
                'owner_scope'            => 'tenant',
                'account_tier'           => $this->short($input['account_tier'] ?? null, 48),
                'credentials_ciphertext' => $ciphertext,
                'credential_fingerprint' => hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)),
                'credential_revision'    => $row ? (int) $row->credential_revision + 1 : 1,
                'free_quota'             => $this->units($input['free_quota'] ?? 0),
                'soft_cap'               => $this->units($input['soft_cap'] ?? 0),
                // V1 never authorizes automatic paid overage.
                'paid_cap'              => 0,
                'external_usage_buffer' => $this->units($input['external_usage_buffer'] ?? 0),
                'cycle_timezone'        => $this->timezone((string) ($input['cycle_timezone'] ?? 'UTC')),
                'qps_limit'             => min(10, max(1, (int) ($input['qps_limit'] ?? 1))),
                // Lower numbers are selected first. This is deliberately
                // independent of provider type so a tenant can order Baidu,
                // Aliyun and Volcengine as primary/secondary/tertiary.
                'failover_priority'     => min(1000, max(1, (int) ($input['failover_priority'] ?? 100))),
                'enabled'               => (bool) ($input['enabled'] ?? false),
                'dispatch_enabled'      => false,
                'verified_at'           => null,
                'verified_by'           => null,
                'source_link'           => $this->url($input['source_link'] ?? null),
                'updated_at'            => now(),
            ];
            if ($row === null) {
                $values += ['provider' => $provider, 'billing_identity_hash' => $identityHash, 'created_at' => now()];
                $id = DB::table('lgt_provider_accounts')->insertGetId($values);
            } else {
                DB::table('lgt_provider_accounts')->where('id', $row->id)->update($values);
                $id = (int) $row->id;
            }
            $this->audit('provider_credentials_updated', 'provider_account', $id, $actorId, [], [
                'provider'            => $provider,
                'credential_revision' => $values['credential_revision'],
            ]);

            return $this->publicAccount((array) DB::table('lgt_provider_accounts')->where('id', $id)->first());
        });
    }

    /** @return array<string, string> */
    public function credentials(object|array $account): array
    {
        $ciphertext = is_array($account) ? (string) ($account['credentials_ciphertext'] ?? '') : (string) ($account->credentials_ciphertext ?? '');
        if ($ciphertext === '') {
            throw new InvalidArgumentException('PROVIDER_CREDENTIALS_MISSING');
        }

        try {
            $credentials = json_decode(Crypt::decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidArgumentException('PROVIDER_CREDENTIALS_UNREADABLE');
        }
        if (! is_array($credentials)) {
            throw new InvalidArgumentException('PROVIDER_CREDENTIALS_UNREADABLE');
        }

        return array_map(static fn (mixed $value): string => (string) $value, $credentials);
    }

    /** @return list<array<string, mixed>> */
    public function publicAccounts(): array
    {
        return DB::table('lgt_provider_accounts')->orderBy('failover_priority')->orderBy('id')->get()->map(
            fn (object $account): array => $this->publicAccount((array) $account)
        )->all();
    }

    /** @return array<string, mixed> */
    public function markVerified(int $accountId, ?int $actorId): array
    {
        return DB::transaction(function () use ($accountId, $actorId): array {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null || ! $account->enabled || (int) $account->paid_cap !== 0) {
                throw new InvalidArgumentException('PROVIDER_ACCOUNT_NOT_VERIFIABLE');
            }
            DB::table('lgt_provider_accounts')->where('id', $accountId)->update([
                'verified_at' => now(), 'verified_by' => $actorId, 'updated_at' => now(),
            ]);
            $this->audit('provider_verified', 'provider_account', $accountId, $actorId, [], ['provider' => $account->provider]);

            return $this->publicAccount((array) DB::table('lgt_provider_accounts')->where('id', $accountId)->first());
        });
    }

    /** @return array<string, mixed> */
    public function setDispatch(int $accountId, bool $enabled, ?int $actorId): array
    {
        return DB::transaction(function () use ($accountId, $enabled, $actorId): array {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null) {
                throw new InvalidArgumentException('PROVIDER_ACCOUNT_NOT_FOUND');
            }
            if ($enabled && (! $account->enabled || $account->verified_at === null || (int) $account->paid_cap !== 0)) {
                throw new InvalidArgumentException('PROVIDER_ACCOUNT_NOT_DISPATCHABLE');
            }
            DB::table('lgt_provider_accounts')->where('id', $accountId)->update(['dispatch_enabled' => $enabled, 'updated_at' => now()]);
            $this->audit('provider_dispatch_changed', 'provider_account', $accountId, $actorId, ['dispatch_enabled' => (bool) $account->dispatch_enabled], ['dispatch_enabled' => $enabled]);

            return $this->publicAccount((array) DB::table('lgt_provider_accounts')->where('id', $accountId)->first());
        });
    }

    /** @return array<string, mixed> */
    private function publicAccount(array $account): array
    {
        $configured = ! empty($account['credentials_ciphertext']);
        unset($account['credentials_ciphertext'], $account['credential_fingerprint'], $account['billing_identity_hash']);
        $account['credentials_configured'] = $configured;
        $account['paid_cap']               = 0;

        return $account;
    }

    /** @param array<string, string> $credentials */
    private function validateCredentialShape(string $provider, array $credentials): void
    {
        $profile = self::profiles()[$provider] ?? null;
        if ($profile === null) {
            throw new InvalidArgumentException('UNKNOWN_PROVIDER');
        }
        $required = array_column($profile['credential_fields'], 'key');
        foreach ($required as $key) {
            if (! isset($credentials[$key]) || trim($credentials[$key]) === '' || mb_strlen($credentials[$key]) > 1024) {
                throw new InvalidArgumentException('INVALID_PROVIDER_CREDENTIALS');
            }
        }
    }

    private function units(mixed $value): int
    {
        return min(10_000_000_000, max(0, filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0));
    }

    private function short(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function url(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) ? mb_substr($url, 0, 512) : null;
    }

    private function audit(string $action, string $type, int $id, ?int $actorId, array $before, array $after): void
    {
        DB::table('lgt_audit_logs')->insert([
            'actor_id'     => $actorId,
            'action'       => $action,
            'subject_type' => $type,
            'subject_id'   => $id,
            'before'       => json_encode($before),
            'after'        => json_encode($after),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
