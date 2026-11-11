<?php

namespace Plugin\LongosInstantTranslate\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\LongosInstantTranslate\Providers\ProviderRegistry;
use Plugin\LongosInstantTranslate\Providers\SiliconFlowProvider;

final class ProviderAccountService
{
    public const PROVIDERS = ['siliconflow', 'aliyun', 'volcengine', 'baidu', 'deepl', 'google', 'azure'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @return array<string,array<string,mixed>> */
    public static function profiles(): array
    {
        return [
            'siliconflow' => [
                'label'  => '硅基流动（免费 AI 翻译）',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'SiliconFlow API Key', 'type' => 'password'],
                    ['key' => 'model', 'label' => '模型', 'type' => 'select', 'default' => SiliconFlowProvider::DEFAULT_MODEL],
                ],
                'models' => [
                    SiliconFlowProvider::DEFAULT_MODEL => 'Hunyuan-MT-7B · 专用翻译（当前免费）',
                    'XingChenAGI/Xing4.0-29B'          => 'Xing4.0-29B · 通用模型（当前免费）',
                ],
                'rate_only' => true,
            ],
            'aliyun' => [
                'label'  => '阿里云机器翻译',
                'fields' => [
                    ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'type' => 'text'],
                    ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'type' => 'password'],
                ],
            ],
            'volcengine' => [
                'label'  => '火山引擎机器翻译',
                'fields' => [
                    ['key' => 'access_key_id', 'label' => 'Access Key（AK）', 'type' => 'text'],
                    ['key' => 'secret_access_key', 'label' => 'Secret Key（SK）', 'type' => 'password'],
                ],
            ],
            'baidu' => [
                'label'  => '百度翻译开放平台',
                'fields' => [
                    ['key' => 'appid', 'label' => 'APP ID', 'type' => 'text'],
                    ['key' => 'secret', 'label' => '密钥', 'type' => 'password'],
                ],
            ],
            'deepl' => [
                'label'  => 'DeepL API',
                'fields' => [
                    ['key' => 'auth_key', 'label' => 'DeepL Auth Key', 'type' => 'password'],
                    ['key' => 'api_plan', 'label' => 'API 计划（free / pro）', 'type' => 'text', 'default' => 'free'],
                ],
            ],
            'google' => [
                'label'  => 'Google Cloud Translation（Basic v2）',
                'fields' => [
                    ['key' => 'api_key', 'label' => '受限服务器端 API Key', 'type' => 'password'],
                ],
            ],
            'azure' => [
                'label'  => 'Microsoft Azure Translator',
                'fields' => [
                    ['key' => 'subscription_key', 'label' => 'Translator Key', 'type' => 'password'],
                    ['key' => 'region', 'label' => '资源区域（global / eastus）', 'type' => 'text', 'default' => 'global'],
                ],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function accounts(): array
    {
        if (! LibrarySchema::ready()) {
            return [];
        }

        return DB::table('lit_provider_accounts as accounts')
            ->leftJoin('lit_provider_usage as usage', function ($join): void {
                $join->on('usage.provider_account_id', '=', 'accounts.id')->where('usage.period', now()->format('Y-m'));
            })
            ->orderBy('accounts.priority')->orderBy('accounts.id')
            ->get(['accounts.*', 'usage.committed_units', 'usage.reserved_units', 'usage.uncertain_units', 'usage.request_count'])
            ->map(static function (object $account): array {
                return [
                    'id'                       => (int) $account->id,
                    'provider'                 => (string) $account->provider,
                    'label'                    => (string) ($account->label ?: 'API account'),
                    'priority'                 => (int) $account->priority,
                    'qps_limit'                => (int) $account->qps_limit,
                    'rpm_limit'                => (int) $account->rpm_limit,
                    'tpm_limit'                => (int) $account->tpm_limit,
                    'monthly_character_limit'  => (int) $account->monthly_character_limit,
                    'enabled'                  => (bool) $account->enabled,
                    'dispatch_enabled'         => (bool) $account->dispatch_enabled,
                    'verified_at'              => $account->verified_at,
                    'cooldown_until'           => $account->cooldown_until,
                    'last_error_code'          => $account->last_error_code,
                    'credentials_configured'   => trim((string) $account->credentials_ciphertext) !== '',
                    'usage'                    => [
                        'committed' => (int) ($account->committed_units ?? 0),
                        'reserved'  => (int) ($account->reserved_units ?? 0),
                        'uncertain' => (int) ($account->uncertain_units ?? 0),
                        'requests'  => (int) ($account->request_count ?? 0),
                    ],
                ];
            })->all();
    }

    /** @param array<string,mixed> $input @param array<string,string> $credentials */
    public function save(array $input, array $credentials): int
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        $label    = trim((string) ($input['label'] ?? ''));
        if (! in_array($provider, self::PROVIDERS, true) || $label === '' || mb_strlen($label) > 64) {
            throw ValidationException::withMessages(['provider' => 'Invalid translation provider account.']);
        }

        $credentials = array_map(static fn (mixed $value): string => trim((string) $value), $credentials);
        $this->providers->get($provider)->validateCredentialShape($credentials);
        $identity = hash('sha256', $provider . "\0" . mb_strtolower($label, 'UTF-8'));
        $existing = DB::table('lit_provider_accounts')->where('provider', $provider)->where('billing_identity_hash', $identity)->first();
        $values   = [
            'label'                    => $label,
            'credentials_ciphertext'   => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
            'credential_fingerprint'   => hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)),
            'credential_revision'      => max(1, (int) ($existing->credential_revision ?? 0) + 1),
            'priority'                 => max(1, min(1000, (int) ($input['priority'] ?? 100))),
            'qps_limit'                => max(1, min(10, (int) ($input['qps_limit'] ?? 1))),
            'rpm_limit'                => max(1, min(10000, (int) ($input['rpm_limit'] ?? 60))),
            'tpm_limit'                => max(1, min(5000000, (int) ($input['tpm_limit'] ?? 80000))),
            'monthly_character_limit'  => $provider === 'siliconflow' ? 0 : max(0, (int) ($input['monthly_character_limit'] ?? 0)),
            'enabled'                  => (bool) ($input['enabled'] ?? false),
            'dispatch_enabled'         => false,
            'verified_at'              => null,
            'cooldown_until'           => null,
            'last_error_code'          => null,
            'last_trace_id'            => null,
            'updated_at'               => now(),
        ];

        if ($existing) {
            DB::table('lit_provider_accounts')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('lit_provider_accounts')->insertGetId([
            'provider'              => $provider,
            'billing_identity_hash' => $identity,
            ...$values,
            'created_at'            => now(),
        ]);
    }

    /** @return array<string,string> */
    public function credentials(object $account): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString((string) $account->credentials_ciphertext), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['provider' => 'PROVIDER_CREDENTIALS_UNREADABLE']);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['provider' => 'PROVIDER_CREDENTIALS_UNREADABLE']);
        }

        return array_map(static fn (mixed $value): string => (string) $value, $decoded);
    }

    public function setDispatch(int $id, bool $enabled): void
    {
        $account = DB::table('lit_provider_accounts')->where('id', $id)->first();
        if (! $account || ($enabled && (! $account->enabled || ! $account->verified_at))) {
            throw ValidationException::withMessages(['provider' => 'Verify and enable the account before dispatching.']);
        }
        DB::table('lit_provider_accounts')->where('id', $id)->update([
            'dispatch_enabled' => $enabled,
            'updated_at'       => now(),
        ]);
    }

    public function disableAfterAuthFailure(int $id, string $code, ?string $traceId): void
    {
        DB::table('lit_provider_accounts')->where('id', $id)->update([
            'dispatch_enabled' => false,
            'verified_at'      => null,
            'last_error_code'  => mb_substr($code, 0, 64),
            'last_trace_id'    => $traceId ? mb_substr($traceId, 0, 128) : null,
            'updated_at'       => now(),
        ]);
    }
}
