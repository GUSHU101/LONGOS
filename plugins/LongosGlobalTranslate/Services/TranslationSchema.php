<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The translation plugin is optional, but a partially applied plugin schema
 * must never turn an administration request or a storefront into a 500.
 *
 * Keep this contract next to the plugin rather than relying on a single
 * sentinel table.  A migration can be recorded before a previous deployment
 * is interrupted, so seeing lgt_settings alone does not prove that the
 * dashboard, workers, or public dictionary can safely query their tables.
 */
final class TranslationSchema
{
    /**
     * This public, compile-time list is also used by the platform deployment
     * gate.  Keep it separate from the column contract below: PHP class
     * constants may reference another class constant, but cannot execute
     * array_keys() while defining a constant.
     *
     * @var list<string>
     */
    public const TABLE_NAMES = [
        'lgt_entries',
        'lgt_translations',
        'lgt_translation_versions',
        'lgt_provider_accounts',
        'lgt_usage_months',
        'lgt_attempts',
        'lgt_usage_events',
        'lgt_jobs',
        'lgt_job_items',
        'lgt_publications',
        'lgt_publication_items',
        'lgt_settings',
        'lgt_country_rules',
        'lgt_glossary_terms',
        'lgt_source_exclusions',
        'lgt_coverage_snapshots',
        'lgt_audit_logs',
        'lgt_locale_maintenance',
        'lgt_siliconflow_rate_models',
        'lgt_siliconflow_rate_calls',
    ];

    /** @var array<string, list<string>> */
    private const TABLES = [
        'lgt_entries' => [
            'id', 'content_key', 'kind', 'entity_id', 'field', 'source_locale',
            'source_format', 'source_revision', 'source_text', 'source_hash',
            'is_public', 'is_tombstoned', 'metadata', 'last_scanned_at', 'created_at', 'updated_at',
        ],
        'lgt_translations' => [
            'id', 'entry_id', 'locale', 'source_hash', 'text', 'status', 'provider',
            'provider_account_id', 'quality_code', 'locked', 'locked_by', 'locked_at',
            'reviewed_by', 'reviewed_at', 'version', 'created_at', 'updated_at',
        ],
        'lgt_translation_versions' => [
            'id', 'translation_id', 'revision', 'source_hash', 'text', 'status', 'reason', 'actor_id',
            'created_at', 'updated_at',
        ],
        'lgt_provider_accounts' => [
            'id', 'provider', 'billing_identity_hash', 'owner_scope', 'account_tier', 'credentials_ciphertext',
            'credential_fingerprint', 'credential_revision', 'free_quota', 'soft_cap',
            'paid_cap', 'external_usage_buffer', 'cycle_timezone', 'qps_limit', 'rpm_limit', 'tpm_limit', 'failover_priority', 'enabled',
            'last_dispatch_at_us', 'dispatch_enabled', 'verified_at', 'verified_by', 'source_link', 'archived_at', 'created_at', 'updated_at',
        ],
        'lgt_usage_months' => [
            'id', 'provider_account_id', 'billing_cycle', 'committed_units', 'reserved_units',
            'sending_units', 'uncertain_units', 'external_usage_buffer', 'soft_limit', 'version', 'created_at', 'updated_at',
        ],
        'lgt_attempts' => [
            'id', 'request_uuid', 'provider_account_id', 'job_item_id', 'billing_cycle', 'state',
            'batch_hash', 'source_units_estimated', 'source_units_reserved', 'request_fingerprint',
            'provider_request_id', 'failure_code', 'uncertainty_reason', 'sent_at', 'finished_at', 'created_at', 'updated_at',
        ],
        'lgt_usage_events' => [
            'id', 'usage_month_id', 'attempt_id', 'event', 'units', 'actor_id', 'reason', 'metadata',
            'created_at', 'updated_at',
        ],
        'lgt_jobs' => [
            'id', 'public_id', 'status', 'mode', 'scope', 'target_locales', 'plan',
            'settings_revision', 'created_by', 'total_items', 'completed_items', 'failed_items', 'version',
            'started_at', 'finished_at', 'archived_at', 'created_at', 'updated_at',
        ],
        'lgt_job_items' => [
            'id', 'job_id', 'entry_id', 'target_locale', 'source_hash', 'status',
            'claim_token', 'attempt_id', 'failure_code', 'version', 'claimed_at', 'completed_at', 'created_at', 'updated_at',
        ],
        'lgt_publications' => [
            'id', 'locale', 'revision', 'state', 'published_by', 'published_at', 'rollback_of', 'coverage_snapshot',
            'created_at', 'updated_at',
        ],
        'lgt_publication_items' => [
            'id', 'publication_id', 'translation_id', 'translation_version', 'source_hash', 'text', 'created_at', 'updated_at',
        ],
        'lgt_settings'       => ['id', 'key', 'value', 'revision', 'created_at', 'updated_at'],
        'lgt_country_rules'  => ['id', 'country', 'locale', 'enabled', 'created_at', 'updated_at'],
        'lgt_glossary_terms' => [
            'id', 'source_locale', 'target_locale', 'term', 'replacement', 'protect', 'created_at', 'updated_at',
        ],
        'lgt_source_exclusions' => [
            'id', 'kind', 'entity_id', 'field', 'reason', 'created_by', 'created_at', 'updated_at',
        ],
        'lgt_coverage_snapshots' => ['id', 'scope', 'report', 'created_by', 'created_at', 'updated_at'],
        'lgt_audit_logs'         => [
            'id', 'actor_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'created_at', 'updated_at',
        ],
        'lgt_locale_maintenance'      => ['id', 'locale', 'created_at', 'updated_at'],
        'lgt_siliconflow_rate_models' => [
            'id', 'model', 'rpm_cap', 'tpm_cap', 'cap_window_at', 'cooldown_until', 'consecutive_429', 'last_pruned_at', 'created_at', 'updated_at',
        ],
        'lgt_siliconflow_rate_calls' => [
            'id', 'provider_account_id', 'model', 'reserved_tokens', 'counted_tokens', 'dispatched_at', 'created_at', 'updated_at',
        ],
    ];

    /** @return list<string> */
    public static function requiredTables(): array
    {
        return self::TABLE_NAMES;
    }

    /**
     * @return array{ready: bool, missing_tables: list<string>, missing_columns: list<string>, inspection_failed: bool}
     */
    public static function inspect(): array
    {
        $missingTables  = [];
        $missingColumns = [];

        try {
            foreach (self::TABLES as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    $missingTables[] = $table;

                    continue;
                }
                $availableColumns = array_flip(Schema::getColumnListing($table));
                foreach ($columns as $column) {
                    if (! isset($availableColumns[$column])) {
                        $missingColumns[] = "{$table}.{$column}";
                    }
                }
            }
        } catch (Throwable) {
            // Do not disclose connection details in an optional plugin's
            // response.  The platform's normal database health reporting can
            // provide the underlying operational detail to an administrator.
            return [
                'ready'             => false,
                'missing_tables'    => [],
                'missing_columns'   => [],
                'inspection_failed' => true,
            ];
        }

        return [
            'ready'             => $missingTables === [] && $missingColumns === [],
            'missing_tables'    => $missingTables,
            'missing_columns'   => $missingColumns,
            'inspection_failed' => false,
        ];
    }

    public static function ready(): bool
    {
        return self::inspect()['ready'];
    }
}
