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
const scanner = read('Services/ContentScanner.php');
const builderRuntimeTest = read('Tests/verify_builder_display_contract.php');
const publications = read('Services/PublicationService.php');
const qualityGate = read('Services/TranslationQualityGate.php');
const usage = read('Services/UsageLedger.php');
const providers = read('Services/ProviderAccountService.php');
const guard = read('Services/TranslationMaintenanceGuard.php');
const schema = read('Services/TranslationSchema.php');
const migration = read('Migrations/2026_11_09_000008_add_job_archiving.php');
const maintenanceIndexes = read('Migrations/2026_11_09_000009_add_maintenance_query_indexes.php');
const providerArchiving = read('Migrations/2026_11_09_000010_add_provider_archiving.php');
const siliconflow = read('Providers/SiliconFlowProvider.php');
const registry = read('Providers/ProviderRegistry.php');
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
assert.match(registry, /'siliconflow'\s*=>\s*new SiliconFlowProvider/);
assert.match(providers, /'siliconflow'\s*=>\s*\[/);
assert.match(providers, /SiliconFlowProvider::FREE_MODELS/);
assert.match(providers, /SILICONFLOW_FREE_MODEL_ONLY/);
assert.match(siliconflow, /public const DEFAULT_MODEL = 'tencent\/Hunyuan-MT-7B'/);
assert.match(siliconflow, /'XingChenAGI\/Xing4\.0-29B'/);
assert.match(siliconflow, /in_array\(\$credentials\['model'\] \?\? null, self::FREE_MODELS, true\)/);
assert.match(siliconflow, /https:\/\/api\.siliconflow\.cn\/v1\/chat\/completions/);
assert.match(siliconflow, /\$body\s*=\s*\$this->requestBody\(\$request, \(string\) \$credentials\['model'\]\)/);
assert.match(siliconflow, /'stream'\s*=>\s*false/);
assert.match(siliconflow, /'MODEL_OUTPUT_TRUNCATED'/);
assert.match(siliconflow, /'AUTH'/);
assert.match(siliconflow, /'RATE_LIMIT'/);
assert.match(admin, /modelSelector\.replaceChildren\(\)/);
assert.match(admin, /const modelChanged=Boolean\(multi&&editingAccount/);
assert.match(admin, /credentials:rotating\|\|modelChanged\?credentials:\{\}/);
assert.match(providers, /array_key_exists\('model', \$credentials\)/);
assert.match(providers, /\$this->credentials\(\$row\)/);
assert.match(providers, /'dispatch_enabled'\s*=>\s*\$credentialsChanged/);
assert.match(providers, /\$account\['selected_model'\]/);
assert.match(publications, /TranslationMaintenanceGuard::lockLocales/);
assert.match(publications, /chunkById\(250/);
assert.doesNotMatch(publications, /\$translations->get\(/);
assert.doesNotMatch(publications, /->get\(\)->chunk\(/);
assert.doesNotMatch(publications, /lockForUpdate\(\)->max\('revision'\)/);
assert.match(publications, /function samePublishedSnapshot\(/);
assert.match(publications, /recheckMachineResult/);
assert.match(library, /function recheckMachineResult\(/);
for (const marker of ['MODEL_META_OUTPUT', 'SOURCE_LANGUAGE_DOMINANT', 'TARGET_SCRIPT_MISSING', 'automatic_quality_recheck']) {
  assert.ok(qualityGate.includes(marker) || library.includes(marker), marker);
}
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
assert.match(admin, />翻译与译文<\/button>/);
assert.match(admin, /id="lgt-next-button"/);
assert.match(admin, /id="lgt-provider-new"/);
assert.match(admin, /data-lgt-workview="jobs"/);
assert.match(admin, /data-lgt-workview="translations"/);
assert.match(admin, /function\s+renderNextAction|const renderNextAction/);
assert.match(admin, /let dispatchReady = @json\(\$dispatchReady\)/);
assert.match(admin, /const selectWorkview=/);
assert.match(admin, /const setWorkspaceHash=/);
assert.match(admin, /`\$\{url\.pathname\}\$\{url\.search\}\$\{url\.hash\}`/);
assert.doesNotMatch(admin, /replaceState\(null,'',`#lgt-/);
assert.doesNotMatch(admin, /href="#lgt-/);
const adminBase = new URL('/admin/', 'https://tenant.example');
assert.equal(new URL('#lgt-providers', adminBase).pathname, '/admin/', 'A bare hash is unsafe under the admin base URL');
const workspaceUrl = new URL('https://tenant.example/admin/global-translate?scope=all#lgt-overview');
workspaceUrl.hash = 'lgt-providers';
assert.equal(`${workspaceUrl.pathname}${workspaceUrl.search}${workspaceUrl.hash}`, '/admin/global-translate?scope=all#lgt-providers');
assert.match(admin, /if\(currentWorkview==='translations'&&!libraryLoaded\)loadLibrary/);
assert.match(admin, /refreshTimer=setTimeout\(refresh,nextPoll\)/);
assert.doesNotMatch(admin, /setInterval\(refresh,8000\)/);
assert.match(scanner, /function builderDisplayFields\(array \$content\)/);
assert.match(scanner, /function builderPrivateSegment\(string \$segment\)/);
assert.match(scanner, /(?:seo\|meta\|head\|open_graph)/);
assert.match(scanner, /function builderItemHidden\(array \$item\)/);
assert.match(scanner, /\$discovered\s*=\s*self::builderDisplayFields\(\$content\)/);
assert.match(scanner, /\$report\['unsupported_components'\]\[\] = \$rawCode/);
assert.match(scanner, /\$module\['visible'\] \?\? true/);
assert.match(scanner, /'source_revision' => \$revision/);
assert.match(jobs, /\$report\s*=\s*\$this->scanner->scan\(\$kinds, \$actorId\)/);
assert.match(builderRuntimeTest, /ContentScanner::builderDisplayFields/);
assert.match(builderRuntimeTest, /'seo\.title'/);
assert.match(builderRuntimeTest, /'blocks\.0\.cta_text'/);
assert.match(read('Services/StorefrontPresentationConfig.php'), /PLUGIN_RELEASE = '1\.17\.1'/);

const inlineScript = admin.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(inlineScript, 'Admin JS script exists');
new vm.Script(inlineScript.replace(/@json\([^)]*\)/g, '{}'));
for (const name of ['overview','library','publish','languages','providers']) {
  assert.ok(admin.includes(`id="lgt-panel-${name}"`) && admin.includes(`id="lgt-tab-${name}"`), `Navigation has a matching accessible panel: ${name}`);
}
for (const match of inlineScript.matchAll(/\$\('#(lgt-[\w-]+)'\)/g)) {
  assert.ok(admin.includes(`id="${match[1]}"`), `Referenced admin control exists: ${match[1]}`);
}

const config = JSON.parse(read('config.json'));
assert.equal(config.version, 'v1.17.1');
const limiter = read('Services/SiliconFlowRateLimiter.php');
const verification = read('Services/ProviderVerificationService.php');
const siliconflowMigration = read('Migrations/2026_11_09_000011_add_siliconflow_rate_pacing.php');
const budgetModeMigration = read('Migrations/2026_11_10_000012_add_provider_budget_modes.php');
for (const marker of ['lockForUpdate()', "where('dispatched_at', '>', $windowStart)", 'counted_tokens', 'consecutive_429', 'cooldown_until', 'function acquire(', 'function settle(']) assert.ok(limiter.includes(marker), marker);
for (const marker of ['lgt_siliconflow_rate_models','lgt_siliconflow_rate_calls','rpm_limit','tpm_limit']) {assert.ok(schema.includes(marker), marker);assert.ok(siliconflowMigration.includes(marker), marker);}
for (const marker of ['budget_mode','lgt_siliconflow_rate_account_models']) {assert.ok(schema.includes(marker), marker);assert.ok(budgetModeMigration.includes(marker), marker);}
assert.ok(budgetModeMigration.includes('rate_only'));
assert.ok(limiter.includes("where('provider_account_id', $accountId)"));
for (const marker of ['resumeSiliconFlowRateWindow', 'ResumeSiliconFlowTranslationJob::dispatch', '$limiter->acquire(', '$limiter->settle(', 'SILICONFLOW_RATE_WINDOW']) assert.ok(jobs.includes(marker), marker);
assert.ok(verification.includes('$limiter->acquire('));
assert.ok(siliconflow.includes('usage'));assert.ok(siliconflow.includes('tokenCeiling'));
assert.ok(siliconflow.includes("'X-Trace-Id'"));
assert.ok(siliconflow.includes('x-siliconcloud-trace-id'));
const localeResolver = read('Services/PresentationLocaleResolver.php');
const storefront = read('Services/StorefrontPresentationConfig.php');
const shopController = read('Controllers/ShopTranslationController.php');
const countryCatalog = read('Services/CountryLocaleCatalog.php');
const compactCountryCatalog = countryCatalog.replace(/\s+/g, ' ');
const widget = read('Static/public/widget.js');
const widgetStyle = read('Static/public/widget.css');
const view = read('Views/shop/widget.blade.php');
const boot = read('Bootstrap.php');
const localeSettings = read('Services/TranslationSettings.php');
const shopRoutes = read('Routes/shop.php');
for (const marker of ['function trustedCountry(', 'function preferredLocale(', 'geo_browser', 'cookie(self::COOKIE)', 'publishedLocales', 'candidateLocales(']) assert.ok(localeResolver.includes(marker), marker);
for (const marker of ["'CA' => ['en', 'fr']", "'BE' => ['nl', 'fr', 'de']", "'CH' => ['de', 'fr', 'it']"]) assert.ok(compactCountryCatalog.includes(marker), marker);
assert.ok(localeSettings.includes('function presentationActive('));
assert.ok(storefront.includes('array_keys($published)'));
assert.ok(storefront.indexOf('array_keys($published)') > storefront.indexOf("where('state', 'published')"));
assert.ok(storefront.includes("'widget_visible'"));
assert.ok(boot.includes('TranslationSettings::presentationActive($settings)'));
assert.ok(!boot.includes('forRequest(request(), $settings)'));
assert.ok(!view.includes('longos-global-translate-bootstrap'));
assert.ok(view.includes('widget.css') && view.includes('?v=1.17.1'));
assert.ok(widget.includes("request(root.dataset.configUrl, {cache: 'no-store'})"));
assert.ok(widget.includes('root.hidden = !data.widget_visible'));
assert.ok(widget.includes('observeNewPublicModules'));
assert.ok(widget.includes('availableLocales ='));
assert.ok(!widget.includes('inlineBootstrap'));
assert.ok(widgetStyle.includes('.lgt-widget[hidden]'));
assert.ok(shopController.includes('TranslationSettings::presentationActive($settings)'));
assert.ok(shopRoutes.includes("'throttle:300,1'"));
const cloudflare = read('Services/CloudflareVisitorCountry.php');
assert.ok(localeResolver.includes('private readonly CloudflareVisitorCountry $cloudflare'));
assert.ok(localeResolver.indexOf('$this->cloudflare->countryCode($request)') < localeResolver.indexOf('$this->visitorCurrency->countryCode($request)'), 'Verified Cloudflare country takes precedence');
for (const marker of ['CF-IPCountry', "get('REMOTE_ADDR')", 'EDGE_CIDRS', 'ipInCidr(', 'normalizedCountry(', 'cloudflare_trusted_proxy_cidrs', 'cloudflare_proxy_headers_sanitized']) assert.ok(cloudflare.includes(marker), marker);
assert.ok(cloudflare.includes('CloudflareIpRangeService::REQUEST_ATTRIBUTE'));
assert.ok(!cloudflare.includes('$request->ip()'), 'Untrusted forwarded visitor IP must not decide edge trust');
assert.ok(!/headers->get\(['"]X-Forwarded-For/.test(cloudflare), 'No forwarded header should participate in peer verification');
console.log('PASS: active Builder module display discovery/incremental revision preservation/SEO exclusions/SiliconFlow/provider wiring/maintenance and JS static wiring');
console.log('NOTE: PHP lint, migrations, concurrency and storefront tests require the LONGOS host.');
