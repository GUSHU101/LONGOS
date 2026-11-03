<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * All plugin workflow data belongs to the active tenant connection. LONGOS
 * already isolates that connection via a tenant database or a table prefix;
 * an application-supplied tenant ID would create an IDOR risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lgt_entries')) {
            Schema::create('lgt_entries', function (Blueprint $t): void {
                $t->id();
                $t->char('content_key', 64)->unique('lgt_entry_key');
                $t->string('kind', 32);
                $t->unsignedBigInteger('entity_id');
                $t->string('field', 128);
                $t->string('source_locale', 24)->default('en');
                $t->string('source_format', 16)->default('plain');
                $t->string('source_revision', 64)->nullable();
                $t->longText('source_text');
                $t->char('source_hash', 64);
                $t->boolean('is_public')->default(true);
                $t->boolean('is_tombstoned')->default(false);
                $t->json('metadata')->nullable();
                $t->timestamp('last_scanned_at')->nullable();
                $t->timestamps();
                $t->index(['kind', 'entity_id', 'is_public'], 'lgt_entry_public');
                $t->index(['source_hash'], 'lgt_entry_source_hash');
            });
        }

        if (! Schema::hasTable('lgt_translations')) {
            Schema::create('lgt_translations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('entry_id');
                $t->string('locale', 24);
                $t->char('source_hash', 64);
                $t->longText('text')->nullable();
                $t->string('status', 24)->default('pending');
                $t->string('provider', 24)->nullable();
                $t->unsignedBigInteger('provider_account_id')->nullable();
                $t->string('quality_code', 64)->nullable();
                $t->boolean('locked')->default(false);
                $t->unsignedBigInteger('locked_by')->nullable();
                $t->timestamp('locked_at')->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['entry_id', 'locale'], 'lgt_text_locale');
                $t->index(['locale', 'status'], 'lgt_text_locale_status');
            });
        }

        if (! Schema::hasTable('lgt_translation_versions')) {
            Schema::create('lgt_translation_versions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('translation_id');
                $t->unsignedBigInteger('revision');
                $t->char('source_hash', 64);
                $t->longText('text');
                $t->string('status', 24);
                $t->string('reason', 64)->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->timestamps();
                $t->unique(['translation_id', 'revision'], 'lgt_version_revision');
            });
        }

        if (! Schema::hasTable('lgt_provider_accounts')) {
            Schema::create('lgt_provider_accounts', function (Blueprint $t): void {
                $t->id();
                $t->string('provider', 24);
                $t->char('billing_identity_hash', 64);
                $t->string('owner_scope', 16)->default('tenant');
                $t->string('account_tier', 48)->nullable();
                $t->text('credentials_ciphertext')->nullable();
                $t->char('credential_fingerprint', 64)->nullable();
                $t->unsignedBigInteger('credential_revision')->default(1);
                $t->unsignedBigInteger('free_quota')->default(0);
                $t->unsignedBigInteger('soft_cap')->default(0);
                $t->unsignedBigInteger('paid_cap')->default(0);
                $t->unsignedBigInteger('external_usage_buffer')->default(0);
                $t->string('cycle_timezone', 64)->default('UTC');
                $t->unsignedSmallInteger('qps_limit')->default(1);
                $t->unsignedSmallInteger('failover_priority')->default(100);
                // Microsecond epoch, updated under the account row lock
                // immediately before an external request.  This is durable
                // per tenant and therefore remains correct across workers.
                $t->unsignedBigInteger('last_dispatch_at_us')->default(0);
                $t->boolean('enabled')->default(false);
                $t->boolean('dispatch_enabled')->default(false);
                $t->timestamp('verified_at')->nullable();
                $t->unsignedBigInteger('verified_by')->nullable();
                $t->string('source_link', 512)->nullable();
                $t->timestamps();
                $t->unique(['provider', 'billing_identity_hash'], 'lgt_account_billing');
            });
        }

        if (! Schema::hasTable('lgt_usage_months')) {
            Schema::create('lgt_usage_months', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('provider_account_id');
                $t->string('billing_cycle', 7);
                $t->unsignedBigInteger('committed_units')->default(0);
                $t->unsignedBigInteger('reserved_units')->default(0);
                $t->unsignedBigInteger('sending_units')->default(0);
                $t->unsignedBigInteger('uncertain_units')->default(0);
                $t->unsignedBigInteger('external_usage_buffer')->default(0);
                $t->unsignedBigInteger('soft_limit')->default(0);
                $t->unsignedBigInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['provider_account_id', 'billing_cycle'], 'lgt_usage_month_unique');
            });
        }

        if (! Schema::hasTable('lgt_attempts')) {
            Schema::create('lgt_attempts', function (Blueprint $t): void {
                $t->id();
                $t->uuid('request_uuid')->unique('lgt_attempt_request');
                $t->unsignedBigInteger('provider_account_id');
                $t->unsignedBigInteger('job_item_id')->nullable();
                $t->string('billing_cycle', 7);
                $t->string('state', 32)->default('planned');
                $t->char('batch_hash', 64);
                $t->unsignedBigInteger('source_units_estimated');
                $t->unsignedBigInteger('source_units_reserved');
                $t->char('request_fingerprint', 64);
                $t->string('provider_request_id', 128)->nullable();
                $t->string('failure_code', 64)->nullable();
                $t->string('uncertainty_reason', 96)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index(['provider_account_id', 'state'], 'lgt_attempt_account_state');
                $t->index(['job_item_id'], 'lgt_attempt_item');
            });
        }

        if (! Schema::hasTable('lgt_usage_events')) {
            Schema::create('lgt_usage_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('usage_month_id');
                $t->unsignedBigInteger('attempt_id')->nullable();
                $t->string('event', 24);
                $t->unsignedBigInteger('units');
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('reason', 128)->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->index(['usage_month_id', 'created_at'], 'lgt_usage_event_month');
            });
        }

        if (! Schema::hasTable('lgt_jobs')) {
            Schema::create('lgt_jobs', function (Blueprint $t): void {
                $t->id();
                $t->uuid('public_id')->unique('lgt_job_public');
                $t->string('status', 24)->default('planned');
                $t->string('mode', 24)->default('preview_only');
                $t->json('scope');
                $t->json('target_locales');
                $t->json('plan')->nullable();
                $t->unsignedBigInteger('settings_revision')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('total_items')->default(0);
                $t->unsignedBigInteger('completed_items')->default(0);
                $t->unsignedBigInteger('failed_items')->default(0);
                $t->unsignedBigInteger('version')->default(1);
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at'], 'lgt_job_status');
            });
        }

        if (! Schema::hasTable('lgt_job_items')) {
            Schema::create('lgt_job_items', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('job_id');
                $t->unsignedBigInteger('entry_id');
                $t->string('target_locale', 24);
                $t->char('source_hash', 64);
                $t->string('status', 24)->default('ready');
                $t->unsignedBigInteger('attempt_id')->nullable();
                $t->unsignedBigInteger('version')->default(1);
                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->unique(['job_id', 'entry_id', 'target_locale'], 'lgt_job_item_unique');
                $t->index(['job_id', 'status'], 'lgt_job_item_status');
            });
        }

        if (! Schema::hasTable('lgt_publications')) {
            Schema::create('lgt_publications', function (Blueprint $t): void {
                $t->id();
                $t->string('locale', 24);
                $t->unsignedBigInteger('revision');
                $t->string('state', 24)->default('draft');
                $t->unsignedBigInteger('published_by')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->unsignedBigInteger('rollback_of')->nullable();
                $t->json('coverage_snapshot')->nullable();
                $t->timestamps();
                $t->unique(['locale', 'revision'], 'lgt_publication_revision');
                $t->index(['locale', 'state'], 'lgt_publication_state');
            });
        }

        if (! Schema::hasTable('lgt_publication_items')) {
            Schema::create('lgt_publication_items', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('publication_id');
                $t->unsignedBigInteger('translation_id');
                $t->unsignedBigInteger('translation_version');
                $t->char('source_hash', 64);
                $t->longText('text');
                $t->timestamps();
                $t->unique(['publication_id', 'translation_id'], 'lgt_publication_item');
                $t->index(['publication_id', 'source_hash'], 'lgt_publication_source');
            });
        }

        if (! Schema::hasTable('lgt_settings')) {
            Schema::create('lgt_settings', function (Blueprint $t): void {
                $t->id();
                $t->string('key', 96)->unique('lgt_setting_key');
                $t->json('value')->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('lgt_country_rules')) {
            Schema::create('lgt_country_rules', function (Blueprint $t): void {
                $t->id();
                $t->string('country', 2)->unique('lgt_country_rule');
                $t->string('locale', 24);
                $t->boolean('enabled')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('lgt_glossary_terms')) {
            Schema::create('lgt_glossary_terms', function (Blueprint $t): void {
                $t->id();
                $t->string('source_locale', 24)->default('en');
                $t->string('target_locale', 24)->nullable();
                $t->string('term', 512);
                $t->string('replacement', 512)->nullable();
                $t->boolean('protect')->default(true);
                $t->timestamps();
                $t->unique(['source_locale', 'target_locale', 'term'], 'lgt_glossary_term');
            });
        }

        if (! Schema::hasTable('lgt_source_exclusions')) {
            Schema::create('lgt_source_exclusions', function (Blueprint $t): void {
                $t->id();
                $t->string('kind', 32);
                $t->unsignedBigInteger('entity_id')->nullable();
                $t->string('field', 128)->nullable();
                $t->string('reason', 128)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['kind', 'entity_id'], 'lgt_exclusion_source');
            });
        }

        if (! Schema::hasTable('lgt_coverage_snapshots')) {
            Schema::create('lgt_coverage_snapshots', function (Blueprint $t): void {
                $t->id();
                $t->string('scope', 32)->default('scan');
                $t->json('report');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('lgt_audit_logs')) {
            Schema::create('lgt_audit_logs', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 64);
                $t->string('subject_type', 64)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->timestamps();
                $t->index(['subject_type', 'subject_id'], 'lgt_audit_subject');
            });
        }
    }

    public function down(): void
    {
        // Preserve translation assets, request journals and encrypted
        // credentials during a code rollback. Destructive cleanup is a
        // separately authorised, backup-gated operator action.
    }
};
