<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Plugin\LongosGlobalTranslate\Providers\SiliconFlowProvider;

final class ProviderAccountService
{
    public const BUDGET_METERED = 'metered';

    public const BUDGET_RATE_ONLY = 'rate_only';

    private UsageLedger $ledger;

    public function __construct(?UsageLedger $ledger = null)
    {
        $this->ledger = $ledger ?? new UsageLedger;
    }

    public const PROVIDERS = ['aliyun', 'volcengine', 'baidu', 'deepl', 'google', 'azure', 'siliconflow'];

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
            'deepl' => [
                'label'             => 'DeepL API',
                'credential_fields' => [
                    ['key' => 'auth_key', 'label' => 'DeepL Auth Key', 'placeholder' => '输入 DeepL API 密钥', 'help' => '在 DeepL API 控制台创建；只会加密保存。', 'input_type' => 'password'],
                    ['key' => 'api_plan', 'label' => 'API 计划', 'placeholder' => 'free 或 pro', 'help' => 'DeepL API Free 填 free；DeepL API Pro 填 pro。该值只决定官方 API 域名。', 'input_type' => 'text'],
                ],
                'account_help' => '建议填写 DeepL API 项目或密钥用途，便于区分额度。',
                'quota_help'   => '填写该密钥本计费周期可安全使用的字符数；系统不会突破本地上限。',
            ],
            'google' => [
                'label'             => 'Google Cloud Translation（Basic v2）',
                'credential_fields' => [
                    ['key' => 'api_key', 'label' => 'Google Cloud API Key', 'placeholder' => '输入受限制的 API Key', 'help' => '仅使用已限制到 Cloud Translation API 的服务器端 API Key；不会写入 URL。', 'input_type' => 'password'],
                ],
                'account_help' => '建议填写 Google Cloud 项目或密钥用途，便于区分额度。',
                'quota_help'   => '填写该 API Key 可安全使用的字符额度；系统只使用本地限额内的请求。',
            ],
            'azure' => [
                'label'             => 'Microsoft Azure Translator',
                'credential_fields' => [
                    ['key' => 'subscription_key', 'label' => 'Azure Translator Key', 'placeholder' => '输入 Translator Key', 'help' => '来自 Azure Translator 或 AI Services 资源的密钥；只会加密保存。', 'input_type' => 'password'],
                    ['key' => 'region', 'label' => '资源区域', 'placeholder' => 'global 或 eastus', 'help' => '全局 Translator 资源填 global；区域或多服务资源填 Azure 资源区域，例如 eastus。', 'input_type' => 'text'],
                ],
                'account_help' => '建议填写 Azure 订阅、资源名称或用途，便于区分额度。',
                'quota_help'   => '填写该 Azure 资源本周期可安全使用的字符额度；本地上限始终优先。',
            ],
            'siliconflow' => [
                'label'             => '硅基流动（免费 AI 翻译）',
                'credential_fields' => [
                    ['key' => 'api_key', 'label' => 'SiliconFlow API Key', 'placeholder' => '输入硅基流动 API Key', 'help' => '从硅基流动控制台创建。密钥加密保管，不会传给前台。', 'input_type' => 'password'],
                    ['key' => 'model', 'label' => '免费翻译模型', 'placeholder' => SiliconFlowProvider::DEFAULT_MODEL, 'help' => '仅提供当前官方价格页标注免费的模型；更换模型后需重新验证，不会改用付费模型。', 'input_type' => 'text'],
                ],
                'default_model' => SiliconFlowProvider::DEFAULT_MODEL,
                'model_options' => [
                    ['value' => 'tencent/Hunyuan-MT-7B', 'label' => 'Hunyuan-MT-7B · 专用翻译（默认）'],
                    ['value' => 'XingChenAGI/Xing4.0-29B', 'label' => 'Xing4.0-29B · 通用 AI 翻译'],
                ],
                'account_help' => '填写硅基流动用户账户的唯一标识。官方限流按用户账户而非 API Key 计算；同一用户的不同 Key 必须复用这条账户记录。',
                'quota_help'   => '当前免费模型不使用字符或周期总额度，也不叠加本地 QPS；只以用户账户 + 模型的 RPM/TPM 作为总上限。',
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
            $budgetMode = $this->budgetMode($provider);
            $values     = [
                'owner_scope'            => 'tenant',
                'account_tier'           => $this->short($input['account_tier'] ?? null, 48),
                'budget_mode'            => $budgetMode,
                'credentials_ciphertext' => $ciphertext,
                'credential_fingerprint' => hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)),
                'credential_revision'    => $row ? (int) $row->credential_revision + 1 : 1,
                'free_quota'             => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($input['free_quota'] ?? 0),
                'soft_cap'               => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($input['soft_cap'] ?? 0),
                // V1 never authorizes automatic paid overage.
                'paid_cap'              => 0,
                'external_usage_buffer' => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($input['external_usage_buffer'] ?? 0),
                'cycle_timezone'        => $this->timezone((string) ($input['cycle_timezone'] ?? 'UTC')),
                'qps_limit'             => min(10, max(1, (int) ($input['qps_limit'] ?? 1))),
                'rpm_limit'             => min(10000, max(1, (int) ($input['rpm_limit'] ?? 1000))),
                'tpm_limit'             => min(5000000, max(1, (int) ($input['tpm_limit'] ?? 80000))),
                // Lower numbers are selected first. This is deliberately
                // independent of provider type so a tenant can order Baidu,
                // Aliyun and Volcengine as primary/secondary/tertiary.
                'failover_priority'     => min(1000, max(1, (int) ($input['failover_priority'] ?? 100))),
                'enabled'               => (bool) ($input['enabled'] ?? false),
                'dispatch_enabled'      => false,
                // Re-adding the same billing identity is an explicit
                // credential rotation, not a fresh quota account. Reuse
                // this row so the historical usage ledger is not reset.
                'archived_at'           => null,
                'verified_at'           => null,
                'verified_by'           => null,
                'source_link'           => $this->url($input['source_link'] ?? null),
                'updated_at'            => now(),
            ];
            if ($row !== null && $row->archived_at !== null) {
                // Reinstating an archived billing identity must retain the
                // old cycle timezone even if the add form defaults to UTC.
                $values['cycle_timezone'] = (string) $row->cycle_timezone;
            }
            $this->assertBillingTimezoneSafe($row, (string) $values['cycle_timezone']);
            if ($row === null) {
                $values += ['provider' => $provider, 'billing_identity_hash' => $identityHash, 'created_at' => now()];
                $id = DB::table('lgt_provider_accounts')->insertGetId($values);
            } else {
                DB::table('lgt_provider_accounts')->where('id', $row->id)->update($values);
                $id = (int) $row->id;
            }
            $this->syncCurrentMonthBudget($id, $values);
            $this->audit('provider_credentials_updated', 'provider_account', $id, $actorId, [], [
                'provider'            => $provider,
                'credential_revision' => $values['credential_revision'],
            ]);

            return $this->publicAccount((array) DB::table('lgt_provider_accounts')->where('id', $id)->first());
        });
    }

    /**
     * Update the operational configuration of an existing provider account.
     *
     * The retained credential is never read for the browser and is therefore
     * not required to amend a quota, priority or rate limit. Supplying a new
     * complete credential pair is an intentional rotation: it invalidates the
     * verification and closes dispatch until the replacement is verified.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $credentials
     * @return array<string, mixed>
     */
    public function update(int $accountId, array $input, array $credentials, ?int $actorId): array
    {
        $credentials = array_filter($credentials, static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return DB::transaction(function () use ($accountId, $input, $credentials, $actorId): array {
            $row = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($row === null || $row->archived_at !== null) {
                throw new InvalidArgumentException('PROVIDER_ACCOUNT_NOT_FOUND');
            }

            // Switching a free model must not make an operator re-enter the
            // encrypted API Key. A model-only update is accepted exclusively
            // for SiliconFlow; changing models revokes verification/dispatch.
            if ((string) $row->provider === 'siliconflow'
                && array_key_exists('model', $credentials)
                && ! array_key_exists('api_key', $credentials)) {
                $existing = $this->credentials($row);
                if (($existing['model'] ?? null) === $credentials['model']) {
                    $credentials = [];
                } else {
                    $inFlight = DB::table('lgt_attempts')->where('provider_account_id', $accountId)
                        ->whereIn('state', [UsageLedger::RESERVED, UsageLedger::SENDING])->exists();
                    $claimed = DB::table('lgt_job_items as item')
                        ->join('lgt_attempts as attempt', 'attempt.id', '=', 'item.attempt_id')
                        ->where('attempt.provider_account_id', $accountId)
                        ->where('item.status', 'claimed')->exists();
                    if ($inFlight || $claimed) {
                        throw ValidationException::withMessages([
                            'model' => '该账户仍有在途翻译请求，请暂停并等待结算后再切换模型。',
                        ]);
                    }
                    $credentials = ['api_key' => $existing['api_key'] ?? '', 'model' => $credentials['model']];
                }
            }

            $credentialsChanged = $credentials !== [];
            if ($credentialsChanged) {
                $this->validateCredentialShape((string) $row->provider, $credentials);
            }

            $value      = static fn (string $key, mixed $current): mixed => array_key_exists($key, $input) ? $input[$key] : $current;
            $enabled    = (bool) $value('enabled', $row->enabled);
            $budgetMode = $this->budgetMode((string) $row->provider);
            $values     = [
                'account_tier'           => $this->short($value('account_tier', $row->account_tier), 48),
                'budget_mode'            => $budgetMode,
                'free_quota'             => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($value('free_quota', $row->free_quota)),
                'soft_cap'               => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($value('soft_cap', $row->soft_cap)),
                // V1 never authorizes automatic paid overage.
                'paid_cap'               => 0,
                'external_usage_buffer'  => $budgetMode === self::BUDGET_RATE_ONLY ? 0 : $this->units($value('external_usage_buffer', $row->external_usage_buffer)),
                'cycle_timezone'         => $this->timezone((string) $value('cycle_timezone', $row->cycle_timezone)),
                'qps_limit'              => min(10, max(1, (int) $value('qps_limit', $row->qps_limit))),
                'rpm_limit'              => min(10000, max(1, (int) $value('rpm_limit', $row->rpm_limit))),
                'tpm_limit'              => min(5000000, max(1, (int) $value('tpm_limit', $row->tpm_limit))),
                'failover_priority'      => min(1000, max(1, (int) $value('failover_priority', $row->failover_priority))),
                'enabled'                => $enabled,
                // Never silently re-enable delivery. A credential rotation or
                // disabling the account closes the dispatch gate; plain quota
                // edits preserve the existing operator decision.
                'dispatch_enabled'      => $credentialsChanged || ! $enabled ? false : (bool) $row->dispatch_enabled,
                'source_link'           => $this->url($value('source_link', $row->source_link)),
                'updated_at'            => now(),
            ];

            if ($credentialsChanged) {
                $values += [
                    'credentials_ciphertext' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                    'credential_fingerprint' => hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)),
                    'credential_revision'    => (int) $row->credential_revision + 1,
                    'verified_at'            => null,
                    'verified_by'            => null,
                ];
            }

            $this->assertBillingTimezoneSafe($row, (string) $values['cycle_timezone']);
            DB::table('lgt_provider_accounts')->where('id', $accountId)->update($values);
            $this->syncCurrentMonthBudget($accountId, $values);
            $this->audit('provider_account_updated', 'provider_account', $accountId, $actorId, [
                'account_tier'        => $row->account_tier,
                'free_quota'          => (int) $row->free_quota,
                'soft_cap'            => (int) $row->soft_cap,
                'qps_limit'           => (int) $row->qps_limit,
                'rpm_limit'           => (int) $row->rpm_limit,
                'tpm_limit'           => (int) $row->tpm_limit,
                'failover_priority'   => (int) $row->failover_priority,
                'enabled'             => (bool) $row->enabled,
                'dispatch_enabled'    => (bool) $row->dispatch_enabled,
                'credentials_changed' => false,
            ], [
                'account_tier'        => $values['account_tier'],
                'free_quota'          => $values['free_quota'],
                'soft_cap'            => $values['soft_cap'],
                'qps_limit'           => $values['qps_limit'],
                'rpm_limit'           => $values['rpm_limit'],
                'tpm_limit'           => $values['tpm_limit'],
                'failover_priority'   => $values['failover_priority'],
                'enabled'             => $values['enabled'],
                'dispatch_enabled'    => $values['dispatch_enabled'],
                'credentials_changed' => $credentialsChanged,
            ]);

            return $this->publicAccount((array) DB::table('lgt_provider_accounts')->where('id', $accountId)->first());
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
        return DB::table('lgt_provider_accounts')->whereNull('archived_at')->orderBy('failover_priority')->orderBy('id')->get()->map(
            fn (object $account): array => $this->publicAccount((array) $account)
        )->all();
    }

    /**
     * Remove an API account from active configuration without destroying
     * its usage ledger, verified request history or audit trail. The stored
     * credential is erased; this is not a physical billing-row deletion.
     */
    public function archive(int $accountId, ?int $actorId): void
    {
        DB::transaction(function () use ($accountId, $actorId): void {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null || $account->archived_at !== null) {
                throw ValidationException::withMessages(['provider' => '该服务商账户不存在或已被删除，请刷新列表。']);
            }

            // Reserve() holds the same account row before it inserts an
            // attempt, so no new reservation can slip past this check.
            // An already sent request must settle before we erase its key.
            $unsettled = DB::table('lgt_attempts')
                ->where('provider_account_id', $accountId)
                ->whereIn('state', [UsageLedger::RESERVED, UsageLedger::SENDING])
                ->exists();
            $claimed = DB::table('lgt_job_items as item')
                ->join('lgt_attempts as attempt', 'attempt.id', '=', 'item.attempt_id')
                ->where('attempt.provider_account_id', $accountId)
                ->where('item.status', 'claimed')
                ->exists();
            if ($unsettled || $claimed) {
                throw ValidationException::withMessages([
                    'provider' => '该账户仍有正在处理的翻译请求。请先停止相关任务并等待结算，超时请求请在任务列表中执行保守恢复后再删除。',
                ]);
            }

            DB::table('lgt_provider_accounts')->where('id', $accountId)->update([
                'enabled'                 => false,
                'dispatch_enabled'        => false,
                'credentials_ciphertext'  => null,
                'credential_fingerprint'  => null,
                'credential_revision'     => (int) $account->credential_revision + 1,
                'verified_at'             => null,
                'verified_by'             => null,
                'account_tier'            => null,
                'source_link'             => null,
                'archived_at'             => now(),
                'updated_at'              => now(),
            ]);
            $this->audit('provider_account_archived', 'provider_account', $accountId, $actorId, [
                'provider'         => $account->provider,
                'enabled'          => (bool) $account->enabled,
                'dispatch_enabled' => (bool) $account->dispatch_enabled,
            ], ['archived' => true, 'credentials_erased' => true]);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function markVerified(int $accountId, ?int $actorId): array
    {
        return DB::transaction(function () use ($accountId, $actorId): array {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null || $account->archived_at !== null || ! $account->enabled || (int) $account->paid_cap !== 0) {
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
            if ($account === null || $account->archived_at !== null) {
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

    /**
     * Stop a provider that has explicitly told us that this account cannot
     * accept work. This is deliberately narrower than generic failures: a
     * timeout may have reached the upstream service and must remain an
     * operator-visible uncertain attempt. The accepted reasons are either
     * explicit pre-translation provider replies or local credential errors
     * discovered before a request enters the sending state.
     */
    public function suspendDispatchForSafety(int $accountId, string $reason): void
    {
        $reason = strtoupper(trim($reason));
        if (! in_array($reason, [
            'AUTH', 'NOT_OPENED', 'BALANCE_EXHAUSTED',
            'PROVIDER_CREDENTIALS_MISSING', 'PROVIDER_CREDENTIALS_UNREADABLE', 'INVALID_PROVIDER_CREDENTIALS',
        ], true)) {
            return;
        }

        DB::transaction(function () use ($accountId, $reason): void {
            $account = DB::table('lgt_provider_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null || $account->archived_at !== null || ! (bool) $account->dispatch_enabled) {
                return;
            }
            DB::table('lgt_provider_accounts')->where('id', $accountId)->update([
                'dispatch_enabled' => false,
                'updated_at'       => now(),
            ]);
            $this->audit('provider_dispatch_suspended', 'provider_account', $accountId, null, [
                'dispatch_enabled' => true,
            ], [
                'dispatch_enabled' => false,
                'reason'           => $reason,
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function publicAccount(array $account): array
    {
        $configured = ! empty($account['credentials_ciphertext']);
        $usage      = $this->ledger->summary($account);
        if (($account['provider'] ?? null) === 'siliconflow' && $configured) {
            try {
                $stored = $this->credentials($account);
                // The model ID is public configuration; the API Key never is.
                $account['selected_model'] = in_array($stored['model'] ?? null, SiliconFlowProvider::FREE_MODELS, true)
                    ? $stored['model'] : null;
            } catch (InvalidArgumentException) {
                $account['selected_model'] = null;
            }
        }
        unset($account['credentials_ciphertext'], $account['credential_fingerprint'], $account['billing_identity_hash']);
        $account['credentials_configured'] = $configured;
        $account['paid_cap']               = 0;
        $account['local_usage']            = $usage;

        return $account;
    }

    /**
     * The billing timezone is part of the ledger's identity. Switching it
     * after a month was recorded could effectively restart local free quota
     * by selecting another YYYY-MM record for the same provider account.
     */
    private function assertBillingTimezoneSafe(?object $account, string $timezone): void
    {
        if ($account === null || (string) $account->cycle_timezone === $timezone) {
            return;
        }
        if (DB::table('lgt_usage_months')->where('provider_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([
                'cycle_timezone' => '该账户已有额度台账，为防重复使用免费额度，不能直接更改计费时区。请保留原时区，或配置新的独立计费账户。',
            ]);
        }
    }

    /**
     * Quota edits previously left the current lgt_usage_months.soft_limit
     * frozen at its first value, making an approved increase ineffective and
     * a decrease invisible. Update only the current cycle's safety fields;
     * never reset committed, reserved, sending or uncertain usage.
     * Called inside the account-row-locked save/update transaction.
     *
     * @param array<string,mixed> $values
     */
    private function syncCurrentMonthBudget(int $accountId, array $values): void
    {
        $cycle = now()->setTimezone((string) $values['cycle_timezone'])->format('Y-m');
        $month = DB::table('lgt_usage_months')->where('provider_account_id', $accountId)
            ->where('billing_cycle', $cycle)->lockForUpdate()->first();
        if ($month === null) {
            return;
        }
        $freeQuota     = (int) $values['free_quota'];
        $configuredCap = (int) $values['soft_cap'];
        $newLimit      = ($values['budget_mode'] ?? self::BUDGET_METERED) === self::BUDGET_RATE_ONLY
            ? 0
            : max(0, $configuredCap > 0 ? min($freeQuota, $configuredCap) : (int) floor($freeQuota * 0.9));
        DB::table('lgt_usage_months')->where('id', $month->id)->update([
            'soft_limit'            => $newLimit,
            'external_usage_buffer' => (int) $values['external_usage_buffer'],
            'version'               => (int) $month->version + 1,
            'updated_at'            => now(),
        ]);
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
        if ($provider === 'siliconflow' && ! in_array($credentials['model'], SiliconFlowProvider::FREE_MODELS, true)) {
            throw new InvalidArgumentException('SILICONFLOW_FREE_MODEL_ONLY');
        }
    }

    private function budgetMode(string $provider): string
    {
        // This is server-derived. A browser cannot mark an arbitrary paid
        // provider as unmetered by adding a request field.
        return $provider === 'siliconflow' ? self::BUDGET_RATE_ONLY : self::BUDGET_METERED;
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
