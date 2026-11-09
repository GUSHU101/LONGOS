// Lightweight release checks for workspaces without the LONGOS host runtime.
// Run: node LongosGlobalTranslate/Tests/verify_source_contracts.mjs
// These are static wiring checks, not replacements for Laravel/MySQL tests.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = path => readFileSync(join(root, path), 'utf8');
const controller = read('Controllers/AdminTranslationController.php');
const routes = read('Routes/admin.php');
const library = read('Services/TranslationLibrary.php');
const jobs = read('Services/TranslationJobService.php');
const publications = read('Services/PublicationService.php');
const usage = read('Services/UsageLedger.php');
const providers = read('Services/ProviderAccountService.php');
const guard = read('Services/TranslationMaintenanceGuard.php');
const schema = read('Services/TranslationSchema.php');
const migration = read('Migrations/2026_11_09_000008_add_job_archiving.php');
const maintenanceIndexes = read('Migrations/2026_11_09_000009_add_maintenance_query_indexes.php');
const providerArchiving = read('Migrations/2026_11_09_000010_add_provider_archiving.php');
const admin = read('Views/admin/index.blade.php');

for (const [endpoint, action] of [
  ['providers/{provider}', 'deleteProvider'],
  ['jobs/{job}/cancel', 'cancelJob'],
  ['jobs/{job}/recover-claims', 'recoverJobClaims'],
  ['jobs/{job}', 'archiveJob'],
  ['translations/bulk', 'deleteTranslations'],
  ['library', 'resetLibraries'],
]) {
  assert.ok(routes.includes(`'${endpoint}'`) && routes.includes(`::class, '${action}'`), `Route ${endpoint}`);
  assert.ok(controller.includes(`function ${action}(`), `Controller action ${action}`);
}

assert.match(library, /function resetLocales\(/);
assert.match(library, /function deleteTranslations\(/);
assert.match(library, /lgt_publication_items'\)->whereIn\('translation_id', \$ids\)->delete\(\)/);
assert.match(library, /LOCALE_HAS_INFLIGHT_REQUEST/);
assert.match(controller, /leftJoin\('lgt_translations as t'/);
assert.match(jobs, /function cancelJob\(/);
assert.match(jobs, /function archiveJob\(/);
assert.match(jobs, /function recoverTimedOutClaims\(/);
assert.match(jobs, /status !== 'running'/);
assert.match(jobs, /JOB_CANCELLED_BEFORE_SEND/);
assert.match(guard, /lockForUpdate\(\)/);
assert.match(migration, /lgt_locale_maintenance/);
assert.match(migration, /archived_at/);
assert.match(schema, /lgt_locale_maintenance/);
assert.match(schema, /'archived_at'/);
assert.match(providerArchiving, /lgt_provider_accounts/);
assert.match(providerArchiving, /lgt_provider_archived_at/);
assert.match(routes, /Route::delete\('providers\/\{provider\}'/);
assert.match(controller, /'confirm_delete'\s*=>\s*\['required', 'accepted'\]/);
assert.match(providers, /public function archive\(/);
assert.match(providers, /whereIn\('state', \[UsageLedger::RESERVED, UsageLedger::SENDING\]\)/);
assert.match(providers, /'credentials_ciphertext'\s*=>\s*null/);
assert.match(providers, /'credential_fingerprint'\s*=>\s*null/);
assert.match(providers, /'archived_at'\s*=>\s*now\(\)/);
assert.match(providers, /provider_account_archived/);
assert.match(providers, /whereNull\('archived_at'\)->orderBy/);
assert.match(providers, /Reinstating an archived billing identity must retain/);
assert.match(jobs, /whereNull\('archived_at'\)->where\('owner_scope', 'tenant'\)/);
assert.match(usage, /\$account->archived_at !== null/);
assert.match(admin, /lgt-provider-delete/);
assert.match(admin, /confirm_delete:true/);
assert.match(publications, /TranslationMaintenanceGuard::lockLocales/);
assert.match(publications, /chunkById\(250/);
assert.doesNotMatch(publications, /\$translations->get\(/);
assert.doesNotMatch(publications, /->get\(\)->chunk\(/);
assert.doesNotMatch(publications, /lockForUpdate\(\)->max\('revision'\)/);
assert.match(publications, /function samePublishedSnapshot\(/);
assert.match(jobs, /\$otherActiveByPair/);
assert.match(jobs, /\$alreadyClaimed/);
assert.match(jobs, /DUPLICATE_ACTIVE_REQUEST/);
assert.match(jobs, /PRIOR_BILLING_OUTCOME_UNRESOLVED/);
assert.match(jobs, /UsageLedger::COMMITTED/);
assert.match(controller, /PRIOR_BILLING_OUTCOME_UNRESOLVED/);
assert.match(maintenanceIndexes, /lgt_job_pair_state/);
assert.match(maintenanceIndexes, /lgt_published_translation/);
assert.match(usage, /Canonical order: account -> billing month -> attempt/);
assert.match(providers, /function syncCurrentMonthBudget\(/);
assert.match(providers, /function assertBillingTimezoneSafe\(/);
assert.match(providers, /'soft_limit'\s*=>\s*\$newLimit/);
assert.match(providers, /'external_usage_buffer'\s*=>/);
assert.match(providers, /'cycle_timezone' => '该账户已有额度台账/);
const transition = usage.slice(usage.indexOf('private function transition('));
assert.ok(transition.indexOf("DB::table('lgt_usage_months')") < transition.indexOf("DB::table('lgt_attempts')->where('id', $attemptId)->lockForUpdate()"), 'Ledger transition locks month before attempt');
const finishItem = jobs.slice(jobs.indexOf('private function finishItem('), jobs.indexOf('private function failureCode('));
assert.ok(finishItem.indexOf("DB::table('lgt_jobs')->where('id', $jobId)->lockForUpdate()") < finishItem.indexOf("DB::table('lgt_job_items')->where('id', $itemId)->lockForUpdate()"), 'Finishing a job item uses job-first lock order');
assert.match(admin, /lgt-library-delete-selected/);
assert.match(admin, /lgt-library-bulk-reset-form/);
assert.match(admin, /恢复超时请求/);
assert.match(admin, /id="lgt-custom-workflow"/);
assert.match(admin, /id="lgt-provider-details"/);
assert.match(admin, /id="lgt-country-options"/);
assert.match(admin, /id="lgt-publish-options"/);
assert.match(admin, /id="lgt-library-maintenance"/);
assert.match(admin, /id="lgt-library-bulk-locales" class="lgt-languages lgt-reset-locales"/);
assert.match(admin, /#lgt-library-bulk-locales input\[type="checkbox"\]:checked/);
assert.doesNotMatch(admin, /按 Ctrl \/ ⌘ 多选/);
assert.match(admin, />翻译与任务<\/button>/);

const inlineScript = admin.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(inlineScript, 'Admin JS script exists');
new vm.Script(inlineScript.replace(/@json\([^)]*\)/g, '{}'));

const config = JSON.parse(read('config.json'));
assert.equal(config.version, 'v1.8.0');
console.log('PASS: route/controller/provider removal/library/deduplication/streaming publication/ledger locks/schema/JS static wiring');
console.log('NOTE: PHP lint, migrations, concurrency and storefront tests require the LONGOS host.');
