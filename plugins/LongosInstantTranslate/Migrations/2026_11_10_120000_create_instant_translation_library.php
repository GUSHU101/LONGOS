<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugin\LongosInstantTranslate\Services\TranslationLibrary;

return new class extends Migration
{
    private const PROVIDERS = ['aliyun', 'volcengine', 'baidu', 'deepl', 'google', 'azure', 'siliconflow'];

    public function up(): void
    {
        if (! Schema::hasTable('lit_translation_entries')) {
            Schema::create('lit_translation_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('source_locale', 16)->default('en');
                $table->string('target_locale', 16);
                $table->char('source_hash', 64);
                $table->longText('source_text');
                $table->longText('translated_text')->nullable();
                $table->string('source_kind', 32)->default('catalog');
                $table->string('status', 24)->default('pending');
                $table->string('provider', 24)->nullable();
                $table->unsignedBigInteger('provider_account_id')->nullable();
                $table->string('quality_code', 64)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('trace_id', 128)->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['source_locale', 'target_locale', 'source_hash'], 'lit_entry_locale_hash');
                $table->index(['status', 'next_attempt_at'], 'lit_entry_dispatch');
                $table->index(['target_locale', 'status'], 'lit_entry_lookup');
            });
        }

        if (! Schema::hasTable('lit_provider_accounts')) {
            Schema::create('lit_provider_accounts', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 24);
                $table->char('billing_identity_hash', 64);
                $table->string('label', 64)->nullable();
                $table->text('credentials_ciphertext')->nullable();
                $table->char('credential_fingerprint', 64)->nullable();
                $table->unsignedBigInteger('credential_revision')->default(1);
                $table->unsignedInteger('priority')->default(100);
                $table->unsignedSmallInteger('qps_limit')->default(1);
                $table->unsignedInteger('rpm_limit')->default(60);
                $table->unsignedBigInteger('tpm_limit')->default(80000);
                $table->unsignedBigInteger('monthly_character_limit')->default(0);
                $table->unsignedBigInteger('last_dispatch_at_us')->default(0);
                $table->timestamp('rate_window_at')->nullable();
                $table->unsignedInteger('rate_request_count')->default(0);
                $table->unsignedBigInteger('rate_token_estimate')->default(0);
                $table->boolean('enabled')->default(false);
                $table->boolean('dispatch_enabled')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('cooldown_until')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->string('last_trace_id', 128)->nullable();
                $table->timestamps();
                $table->unique(['provider', 'billing_identity_hash'], 'lit_provider_identity');
                $table->index(['enabled', 'dispatch_enabled', 'priority'], 'lit_provider_dispatch');
            });
        }

        if (! Schema::hasTable('lit_provider_usage')) {
            Schema::create('lit_provider_usage', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('provider_account_id');
                $table->string('period', 7);
                $table->unsignedBigInteger('committed_units')->default(0);
                $table->unsignedBigInteger('reserved_units')->default(0);
                $table->unsignedBigInteger('uncertain_units')->default(0);
                $table->unsignedBigInteger('request_count')->default(0);
                $table->timestamps();
                $table->unique(['provider_account_id', 'period'], 'lit_usage_account_period');
            });
        }

        if (! Schema::hasTable('lit_glossary_terms')) {
            Schema::create('lit_glossary_terms', function (Blueprint $table): void {
                $table->id();
                $table->string('source_locale', 16)->default('en');
                $table->string('target_locale', 16)->nullable();
                $table->string('term', 512);
                $table->string('replacement', 512)->nullable();
                $table->boolean('protect')->default(true);
                $table->timestamps();
                $table->unique(['source_locale', 'target_locale', 'term'], 'lit_glossary_unique');
            });
        }

        $this->importLegacyAccounts();
        $this->importLegacyGlossary();
        $this->importLegacyTranslations();
        $this->retireLegacyRegistration();
    }

    private function importLegacyAccounts(): void
    {
        if (! Schema::hasTable('lgt_provider_accounts')) {
            return;
        }

        $query = DB::table('lgt_provider_accounts');
        if (Schema::hasColumn('lgt_provider_accounts', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        $query->orderBy('id')->chunk(100, function ($accounts): void {
            foreach ($accounts as $account) {
                $provider = strtolower(trim((string) ($account->provider ?? '')));
                if (! in_array($provider, self::PROVIDERS, true) || trim((string) ($account->credentials_ciphertext ?? '')) === '') {
                    continue;
                }

                $identity = trim((string) ($account->billing_identity_hash ?? ''));
                if (! preg_match('/^[a-f0-9]{64}$/i', $identity)) {
                    $identity = hash('sha256', "legacy\0{$provider}\0" . (int) $account->id);
                }

                DB::table('lit_provider_accounts')->updateOrInsert(
                    ['provider' => $provider, 'billing_identity_hash' => strtolower($identity)],
                    [
                        'label'                   => mb_substr(trim((string) ($account->account_tier ?? 'Legacy account')), 0, 64),
                        'credentials_ciphertext'  => (string) $account->credentials_ciphertext,
                        'credential_fingerprint'  => $account->credential_fingerprint ?? null,
                        'credential_revision'     => max(1, (int) ($account->credential_revision ?? 1)),
                        'priority'                => max(1, min(1000, (int) ($account->failover_priority ?? 100))),
                        'qps_limit'               => max(1, min(10, (int) ($account->qps_limit ?? 1))),
                        'rpm_limit'               => max(1, min(10000, (int) ($account->rpm_limit ?? 60))),
                        'tpm_limit'               => max(1, min(5000000, (int) ($account->tpm_limit ?? 80000))),
                        'monthly_character_limit' => max(0, (int) ($account->soft_cap ?? 0)),
                        'last_dispatch_at_us'     => max(0, (int) ($account->last_dispatch_at_us ?? 0)),
                        'enabled'                 => (bool) ($account->enabled ?? false),
                        'dispatch_enabled'        => (bool) ($account->dispatch_enabled ?? false),
                        'verified_at'             => $account->verified_at ?? null,
                        'updated_at'              => now(),
                        'created_at'              => $account->created_at ?? now(),
                    ],
                );
                if (Schema::hasTable('lgt_usage_months')) {
                    $newAccountId = DB::table('lit_provider_accounts')
                        ->where('provider', $provider)->where('billing_identity_hash', strtolower($identity))->value('id');
                    foreach (DB::table('lgt_usage_months')->where('provider_account_id', $account->id)->get() as $usage) {
                        $period = trim((string) ($usage->billing_cycle ?? ''));
                        if ($newAccountId && preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
                            DB::table('lit_provider_usage')->updateOrInsert([
                                'provider_account_id' => $newAccountId,
                                'period'              => $period,
                            ], [
                                'committed_units' => max(0, (int) ($usage->committed_units ?? 0)),
                                // Old in-flight reservations cannot finish after the package is retired.
                                // Keep them conservative and visible instead of silently restoring budget.
                                'reserved_units'  => 0,
                                'uncertain_units' => max(0, (int) ($usage->uncertain_units ?? 0))
                                    + max(0, (int) ($usage->reserved_units ?? 0))
                                    + max(0, (int) ($usage->sending_units ?? 0)),
                                'request_count' => 0,
                                'created_at'    => $usage->created_at ?? now(),
                                'updated_at'    => now(),
                            ]);
                        }
                    }
                }
            }
        });
    }

    private function importLegacyGlossary(): void
    {
        if (! Schema::hasTable('lgt_glossary_terms')) {
            return;
        }

        DB::table('lgt_glossary_terms')->orderBy('id')->chunk(200, function ($terms): void {
            foreach ($terms as $term) {
                $value = trim((string) ($term->term ?? ''));
                if ($value === '') {
                    continue;
                }
                DB::table('lit_glossary_terms')->updateOrInsert([
                    'source_locale' => mb_substr((string) ($term->source_locale ?? 'en'), 0, 16),
                    'target_locale' => $term->target_locale ? mb_substr((string) $term->target_locale, 0, 16) : null,
                    'term'          => mb_substr($value, 0, 512),
                ], [
                    'replacement' => $term->replacement ? mb_substr((string) $term->replacement, 0, 512) : null,
                    'protect'     => (bool) ($term->protect ?? true),
                    'updated_at'  => now(),
                    'created_at'  => $term->created_at ?? now(),
                ]);
            }
        });
    }

    private function importLegacyTranslations(): void
    {
        if (! Schema::hasTable('lgt_entries') || ! Schema::hasTable('lgt_translations')) {
            return;
        }

        DB::table('lgt_translations as translations')
            ->join('lgt_entries as entries', 'entries.id', '=', 'translations.entry_id')
            ->whereIn('translations.status', ['translated', 'needs_review', 'approved'])
            ->whereNotNull('translations.text')
            ->select([
                'translations.id', 'translations.locale', 'translations.text', 'translations.status',
                'translations.provider', 'translations.quality_code', 'translations.created_at',
                'translations.updated_at', 'entries.source_locale', 'entries.source_text', 'entries.kind',
            ])
            ->orderBy('translations.id')
            ->chunk(200, function ($translations): void {
                foreach ($translations as $translation) {
                    $source = TranslationLibrary::normalize((string) $translation->source_text);
                    $text   = trim((string) $translation->text);
                    $target = strtolower(str_replace('_', '-', trim((string) $translation->locale)));
                    if ($source === '' || $text === '' || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $target) !== 1) {
                        continue;
                    }

                    $ready = in_array((string) $translation->status, ['translated', 'approved'], true)
                        && trim((string) ($translation->quality_code ?? '')) === '';
                    DB::table('lit_translation_entries')->updateOrInsert([
                        'source_locale' => 'en',
                        'target_locale' => $target,
                        'source_hash'   => TranslationLibrary::sourceHash($source),
                    ], [
                        'source_text'       => $source,
                        'translated_text'   => $text,
                        'source_kind'       => mb_substr((string) ($translation->kind ?? 'legacy'), 0, 32),
                        'status'            => $ready ? 'ready' : 'review',
                        'provider'          => $translation->provider ?: 'legacy',
                        'quality_code'      => $translation->quality_code,
                        'last_seen_at'      => now(),
                        'completed_at'      => $ready ? ($translation->updated_at ?? now()) : null,
                        'updated_at'        => $translation->updated_at ?? now(),
                        'created_at'        => $translation->created_at ?? now(),
                    ]);
                }
            });
    }

    private function retireLegacyRegistration(): void
    {
        if (Schema::hasTable('settings')) {
            $oldStatus = DB::table('settings')
                ->where('type', 'plugin')->where('space', 'longos_global_translate')->where('name', 'status')
                ->value('value');
            if ((string) $oldStatus === '1') {
                DB::table('settings')
                    ->where('type', 'plugin')->where('space', 'longos_instant_translate')->where('name', 'status')
                    ->update(['value' => '1', 'updated_at' => now()]);
            }
            DB::table('settings')
                ->where('type', 'plugin')->where('space', 'longos_global_translate')->where('name', 'status')
                ->update(['value' => '0', 'updated_at' => now()]);
        }

        if (Schema::hasTable('plugins')) {
            DB::table('plugins')->where('code', 'longos_global_translate')->delete();
        }
    }

    public function down(): void
    {
        // Translation libraries, encrypted credentials and the untouched
        // legacy lgt_* backup are deliberately retained on code rollback.
    }
};
