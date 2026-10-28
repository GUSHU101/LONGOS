<?php

/**
 * SettingController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-29 16:02:15
 * @modified   2022-06-29 16:02:15
 */

namespace Beike\Admin\Http\Controllers;

use App\Mail\CloudflareMailDnsService;
use App\Mail\MailDiagnosticsService;
use App\Mail\MailRelayRuntimeSettings;
use App\Mail\MailSenderProfileService;
use App\Saas\TenantManager;
use Beike\Admin\Http\Resources\CustomerGroupDetail;
use Beike\Admin\Services\MarketingService;
use Beike\Admin\Services\SettingService;
use Beike\Admin\Services\SettingsReturnUrl;
use Beike\Libraries\Weight;
use Beike\Models\AdminUser;
use Beike\Models\MailLog;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\CustomerGroupRepo;
use Beike\Repositories\SettingRepo;
use Beike\Repositories\StorePolicyRepo;
use Beike\Services\ApiTokenService;
use Beike\Services\CurrencyService;
use Beike\Services\LegacyRedirectService;
use Beike\Services\StorePolicyTemplateService;
use Beike\Services\TimezoneService;
use Beike\Services\VisitorCurrencyService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SettingController extends Controller
{
    /**
     * 显示系统设置页面
     *
     * @return mixed
     */
    public function index()
    {
        $data = [
            'links' => $this->getSettingLinks(),
        ];

        $data = hook_filter('admin.setting.index.data', $data);

        return view('admin::pages.setting', $data);
    }

    public function basicSettings()
    {
        $timezone = TimezoneService::configured();

        $data = [
            'timezones'       => TimezoneService::options(),
            'timezone'        => $timezone,
            'timezone_now'    => now($timezone)->format('Y-m-d H:i:s P'),
        ];
        $data = hook_filter('admin.setting.basic.data', $data);

        return view('admin::pages.setting.basic', $data);
    }

    public function storeSettings()
    {
        $taxAddress = [
            ['value' => 'shipping', 'label' => trans('admin/setting.shipping_address')],
            ['value' => 'payment', 'label' => trans('admin/setting.payment_address')],
        ];

        $data = [
            'countries'       => CountryRepo::listEnabled(),
            'tax_address'     => $taxAddress,
            'customer_groups' => CustomerGroupDetail::collection(CustomerGroupRepo::list())->jsonSerialize(),
            'weight_classes'  => Weight::getWeightUnits(),
        ];

        $data = hook_filter('admin.setting.store.data', $data);

        return view('admin::pages.setting.store', $data);
    }

    public function checkoutSettings()
    {
        $taxAddress = [
            ['value' => 'shipping', 'label' => trans('admin/setting.shipping_address')],
            ['value' => 'payment', 'label' => trans('admin/setting.payment_address')],
        ];

        $visitorCurrency = new VisitorCurrencyService;
        $data            = [
            'tax_address'           => $taxAddress,
            'currency_rate_status'  => CurrencyService::getInstance()->rateStatus(),
            'currency_geo_status'   => $visitorCurrency->diagnostics(request()),
        ];

        $data = hook_filter('admin.setting.checkout.data', $data);

        return view('admin::pages.setting.checkout', $data);
    }

    /** Verify the saved provider credential and refresh this tenant now. */
    public function refreshCurrencyRates(Request $request): JsonResponse
    {
        $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();
        $key      = 'currency-rate-refresh:' . sha1($request->getHost() . '|' . $identity);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return json_fail('操作过于频繁，请稍后再试。', [], 429);
        }
        RateLimiter::hit($key, 60);

        $currency = CurrencyService::getInstance();
        if (! $currency->isConfigured()) {
            return json_fail('请先填写并保存有效的汇率 API Key。');
        }

        try {
            $rates = $currency->verifyAndRefreshConfiguredRates();
        } catch (Throwable $exception) {
            Log::warning('Manual currency rate refresh could not be persisted.', ['exception' => $exception::class]);

            return json_fail('汇率已获取但无法写入数据库，请检查 exchange_rates 表和数据库权限。');
        }

        if ($rates === []) {
            $reason = (string) ($currency->lastRefreshOutcome()['reason'] ?? 'provider_failed');

            return json_fail(match ($reason) {
                'invalid_or_inactive_key' => '刷新失败：API Key 无效或 ExchangeRate-API 账户未激活。',
                'provider_quota_reached'  => '刷新失败：ExchangeRate-API 账户配额已用尽。',
                'provider_unavailable'    => '刷新失败：ExchangeRate-API 服务暂时不可用，已保留原有汇率。',
                'network_error'           => '刷新失败：服务器无法连接 ExchangeRate-API，请检查 DNS、防火墙和出站 HTTPS。',
                'provider_payload_incomplete', 'provider_payload_invalid', 'provider_rejected' => '刷新失败：汇率服务返回了不完整或不可用的数据。',
                default => '刷新失败，请检查 API Key、ExchangeRate-API 账户状态和服务器外网连接。',
            });
        }

        return json_success('汇率已验证并更新。', $currency->rateStatus());
    }

    public function pictureSettings()
    {
        // Kept as a route-level compatibility redirect for old bookmarks and
        // extension links after picture settings were merged into Basic.
        return redirect(admin_route('settings.basic') . '#brand-assets');
    }

    public function policySettings()
    {
        $data = [
            'policies'      => StorePolicyRepo::settings(),
            'policyProfile' => StorePolicyTemplateService::profile(),
        ];
        $data = hook_filter('admin.setting.policies.data', $data);

        return view('admin::pages.setting.policies', $data);
    }

    public function seoSettings()
    {
        $data = [
            'redirects' => LegacyRedirectService::redirects(),
        ];
        $data = hook_filter('admin.setting.seo.data', $data);

        return view('admin::pages.setting.seo', $data);
    }

    /** Generate a reviewable draft without persisting or publishing it. */
    public function generatePolicies(Request $request): JsonResponse
    {
        $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();
        $key      = 'store-policy-generate:' . sha1($request->getHost() . '|' . $identity);

        $response = null;
        $allowed  = RateLimiter::attempt($key, 10, function () use ($request, &$response): bool {
            try {
                $profile  = StorePolicyTemplateService::normalizeProfile($request->input('profile'), true);
                $policies = StorePolicyRepo::normalizeSettings(StorePolicyTemplateService::generate($profile));
                $response = json_success(trans('admin/setting.policy_generate_success'), [
                    'profile'  => $profile,
                    'policies' => $policies,
                ]);
            } catch (\InvalidArgumentException $e) {
                $response = json_fail($e->getMessage());
            }

            return true;
        }, 60);

        if (! $allowed) {
            return json_fail(trans('admin/setting.policy_generate_rate_limited'), [], 429);
        }

        return $response ?? json_fail(trans('admin/setting.policy_generate_failed'));
    }

    public function expressSettings()
    {
        $data = [];
        $data = hook_filter('admin.setting.express.data', $data);

        return view('admin::pages.setting.express', $data);
    }

    public function mailSettings(MailRelayRuntimeSettings $runtimeSettings)
    {
        $canManageRelay = $this->canManagePlatformMailRelay();
        $data           = [
            'canManageMailRelay' => $canManageRelay,
            'mailRelayRuntime'   => $canManageRelay ? $runtimeSettings->status() : null,
        ];
        $data = hook_filter('admin.setting.mail.data', $data);

        return view('admin::pages.setting.mail', $data);
    }

    /** Persist the non-secret, platform-wide mail egress topology. */
    public function storeMailRelaySettings(
        Request $request,
        MailRelayRuntimeSettings $runtimeSettings,
    ): mixed {
        abort_unless($this->canManagePlatformMailRelay(), 403);

        $returnUrl = admin_route('settings.mail') . '#mail-relay-topology';

        try {
            if ($request->string('operation')->lower()->value() === 'reset') {
                $runtimeSettings->reset();
                $message = trans('admin/setting.mail_relay_runtime_reset');
            } else {
                $input = $request->input('mail_relay');
                if (! is_array($input)) {
                    throw new InvalidArgumentException(trans('admin/setting.mail_relay_runtime_invalid'));
                }
                $runtimeSettings->save($input);
                $message = trans('admin/setting.mail_relay_runtime_saved');
            }

            if (app()->resolved('mail.manager')) {
                app('mail.manager')->forgetMailers();
            }

            return redirect($returnUrl)->with('success', $message);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return redirect($returnUrl)->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Unable to persist the platform mail relay topology.', [
                'exception' => $exception::class,
            ]);

            return redirect($returnUrl)->withInput()->with('error', trans('admin/setting.mail_relay_runtime_save_failed'));
        }
    }

    public function mailDiagnostics(Request $request, MailDiagnosticsService $diagnostics): JsonResponse
    {
        $key = 'admin-mail-diagnostics:' . ($request->user()?->getAuthIdentifier() ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return json_fail(trans('admin/setting.mail_diagnostics_rate_limited'), [], 429);
        }
        RateLimiter::hit($key, 60);

        return json_success(trans('admin/setting.mail_diagnostics_complete'), $diagnostics->diagnose());
    }

    private function canManagePlatformMailRelay(): bool
    {
        $user = auth(AdminUser::AUTH_GUARD)->user();
        if (! $user?->is_root) {
            return false;
        }
        if (! config('saas.enabled')) {
            return true;
        }

        return (bool) data_get(current_site()?->meta, 'control_site', false)
            && app(TenantManager::class)->isControlHost(request()->getHost());
    }

    /** Repair the public outbound-mail DNS and return a fresh health report. */
    public function syncMailDns(
        Request $request,
        CloudflareMailDnsService $dns,
    ): JsonResponse {
        $key = 'admin-mail-dns-sync:' . ($request->user()?->getAuthIdentifier() ?: $request->ip());
        if (RateLimiter::tooManyAttempts($key, 2)) {
            return json_fail(trans('admin/setting.mail_dns_sync_rate_limited'), [], 429);
        }
        RateLimiter::hit($key, 60);

        if (! $dns->configured()) {
            return json_fail(trans('admin/setting.mail_dns_sync_not_configured'));
        }

        $lock = Cache::lock('longos:cloudflare-mail-dns-sync', 240);
        if (! $lock->get()) {
            return json_fail(trans('admin/setting.mail_dns_sync_locked'), [], 409);
        }

        try {
            $result = $dns->sync();
        } catch (Throwable $exception) {
            Log::warning('Manual Cloudflare mail DNS sync failed.', ['exception' => $exception::class]);

            return json_fail($exception->getMessage());
        } finally {
            $lock->release();
        }

        return json_success(trans('admin/setting.mail_dns_sync_complete', [
            'created' => count($result['created']),
            'updated' => count($result['updated']),
            'removed' => count($result['removed']),
        ]), [
            'sync' => $result,
        ]);
    }

    /**
     * 更新系统设置
     *
     * @throws \Throwable
     */
    public function store(Request $request): mixed
    {
        $settings     = $request->except(['_token', '_method', 'return_url']);
        if (config('saas.enabled')) {
            unset($settings['admin_name']);
        }
        $oldAdminName = admin_name();
        $returnUrl    = SettingsReturnUrl::resolve(
            $request->input('return_url'),
            $request->getSchemeAndHttpHost(),
            $oldAdminName,
            admin_route('settings.index')
        );

        if (isset($settings['show_price_after_login'])) {
            if ($settings['show_price_after_login']) {
                $settings['guest_checkout'] = false;
            }
        }

        try {
            SettingService::storeSettings($settings);
        } catch (Exception $e) {
            return redirect($returnUrl)->withInput()->with('error', $e->getMessage());
        }

        $newAdminName = $settings['admin_name'] ?? $oldAdminName;

        if (! empty($settings['admin_name'])) {
            $returnUrl = SettingsReturnUrl::replaceAdminPrefix($returnUrl, $oldAdminName, $newAdminName);
        }

        return redirect($returnUrl)->with('success', trans('common.updated_success'));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function updateValues(Request $request): JsonResponse
    {
        $settings = $request->except(['_token', '_method', 'return_url']);
        if (config('saas.enabled')) {
            unset($settings['admin_name']);
        }

        try {
            SettingService::storeSettings($settings);

            return json_success(trans('common.updated_success'));
        } catch (Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * Sends a synchronous test message using the saved mail configuration.
     * The check intentionally bypasses the queue so administrators receive an
     * immediate delivery result instead of a false-positive queued response.
     */
    public function testMail(Request $request): JsonResponse
    {
        $data   = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        $engine = (string) system_setting('base.mail_engine', '');

        if ($engine === '') {
            return json_fail(trans('admin/setting.mail_test_configure_first'));
        }

        $rateLimitKey = 'admin-mail-test:' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return json_fail(trans('admin/setting.mail_test_rate_limited'));
        }

        RateLimiter::hit($rateLimitKey, 60);
        $sender      = app(MailSenderProfileService::class)->resolve(MailSenderProfileService::DEFAULT);
        $fromAddress = $sender['address'];
        if (! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return json_fail(trans('admin/setting.mail_test_invalid_from'));
        }
        if ($engine === 'self_hosted') {
            try {
                app(MailSenderProfileService::class)->assertAuthorized($fromAddress);
            } catch (Throwable $exception) {
                return json_fail($exception->getMessage());
            }
        }

        $subject = trans('admin/setting.mail_test_subject', ['store' => site_brand_name()]);
        $body    = trans('admin/setting.mail_test_body', ['store' => site_brand_name(), 'time' => now()->toDateTimeString()]);

        try {
            Mail::raw($body, function ($message) use ($data, $subject, $sender): void {
                $message->from($sender['address'], $sender['name'] ?: null)
                    ->to($data['recipient'])
                    ->subject($subject);
                if ($sender['reply_to'] !== '') {
                    $message->replyTo($sender['reply_to']);
                }
                $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-BK-Mail-Type', 'system_test');
                $message->getSymfonyMessage()->getHeaders()->addTextHeader(
                    'X-Longos-Sender-Category',
                    MailSenderProfileService::DEFAULT,
                );
            });

            return json_success(trans($engine === 'log' ? 'admin/setting.mail_test_logged' : 'admin/setting.mail_test_sent'));
        } catch (Throwable $e) {
            $safeError = $this->redactMailTestError($e->getMessage());
            Log::warning('Admin mail configuration test failed.', [
                'engine'    => $engine,
                'recipient' => $data['recipient'],
                'exception' => $e::class,
                'reason'    => $safeError,
            ]);
            $this->markTestMailFailed($data['recipient'], $subject, $body, $engine, $e);

            return json_fail($this->mailTestFailureMessage($safeError));
        }
    }

    /**
     * A synchronous test message is already recorded by MailEventListener.
     * Mark that pending row as failed instead of creating a second audit row.
     */
    private function markTestMailFailed(string $recipient, string $subject, string $body, string $engine, Throwable $exception): void
    {
        $error = $this->redactMailTestError($exception->getMessage());

        try {
            $mailLog = MailLog::query()
                ->where('to_email', $recipient)
                ->where('subject', $subject)
                ->where('mail_type', 'system_test')
                ->where('status', MailLog::STATUS_PENDING)
                ->where('created_at', '>=', now()->subMinute())
                ->latest('id')
                ->first();

            if ($mailLog) {
                $mailLog->update([
                    'status'        => MailLog::STATUS_FAILED,
                    'error_message' => $error,
                ]);

                return;
            }

            // Logging must never hide a delivery failure if an event listener
            // is unavailable (for example during a rolling deployment).
            $this->recordTestMail($recipient, $subject, $body, $engine, MailLog::STATUS_FAILED, $error);
        } catch (Throwable $logException) {
            Log::warning('Unable to mark failed mail configuration test.', ['exception' => $logException::class]);
        }
    }

    private function redactMailTestError(string $message): string
    {
        return MailLog::sanitizeError($message);
    }

    private function mailTestFailureMessage(string $safeError): string
    {
        if ($safeError === '') {
            return trans('admin/setting.mail_test_failed');
        }

        return trans('admin/setting.mail_test_failed_with_reason', [
            'error' => mb_substr($safeError, 0, 500),
        ]);
    }

    private function recordTestMail(string $recipient, string $subject, string $body, string $engine, string $status, ?string $error = null): void
    {
        try {
            MailLog::query()->create([
                'to_email'      => $recipient,
                'from_email'    => (string) config('mail.from.address', ''),
                'from_name'     => (string) config('mail.from.name', ''),
                'subject'       => $subject,
                'content'       => $body,
                'mail_type'     => 'system_test',
                'transport'     => $engine,
                'status'        => $status,
                'error_message' => $error ? mb_substr($error, 0, 1000) : null,
                'headers'       => [],
                'attachments'   => [],
                'sent_at'       => $status === MailLog::STATUS_SENT ? now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Unable to record mail configuration test.', ['exception' => $e::class]);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function storeDeveloperToken(Request $request): JsonResponse
    {
        $developerToken           = (string) $request->get('developer_token');
        $normalizedDeveloperToken = $this->normalizeDeveloperToken($developerToken);

        if ($normalizedDeveloperToken === '') {
            return json_fail(trans('admin/setting.developer_token_required'));
        }

        $domain      = request()->getHost();
        $checkResult = MarketingService::getInstance()->checkToken($domain, $normalizedDeveloperToken);

        if (empty($checkResult['exist'])) {
            return json_fail(trans('admin/setting.developer_token_check_failed'));
        }

        $result = $this->storeDeveloperTokenAndSyncSignatureSecret($developerToken);

        return json_success($result['message'], $result['data']);
    }

    /**
     * 保存开发者令牌，并尝试自动获取签名密钥
     */
    protected function storeDeveloperTokenAndSyncSignatureSecret(string $developerToken): array
    {
        $normalizedDeveloperToken = $this->normalizeDeveloperToken($developerToken);

        if ($normalizedDeveloperToken === '') {
            return [
                'message' => trans('admin/setting.developer_token_required'),
                'data'    => [
                    'developer_token_saved'         => false,
                    'signature_secret_auto_fetched' => false,
                ],
            ];
        }

        $previousDeveloperToken = $this->normalizeDeveloperToken((string) (system_setting('base.developer_token') ?? ''));
        $this->storeSystemSettingValue('developer_token', $normalizedDeveloperToken);

        if ($previousDeveloperToken !== '' && $previousDeveloperToken !== $normalizedDeveloperToken) {
            $this->makeApiTokenService()->clearTokens();
        }

        try {
            $secret = $this->makeApiTokenService()->fetchSignatureSecret($normalizedDeveloperToken);

            return [
                'message' => trans('admin/setting.developer_token_save_success'),
                'data'    => [
                    'developer_token_saved'         => true,
                    'signature_secret_auto_fetched' => true,
                    'secret_length'                 => strlen($secret),
                    'secret_preview'                => $this->buildSecretPreview($secret),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'message' => trans('admin/setting.developer_token_save_partial'),
                'data'    => [
                    'developer_token_saved'         => true,
                    'signature_secret_auto_fetched' => false,
                    'signature_secret_error'        => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * 方便单测替身覆盖持久化行为
     */
    protected function storeSystemSettingValue(string $name, mixed $value): void
    {
        SettingRepo::storeValue($name, $value);
    }

    /**
     * 方便单测替身覆盖 API 服务
     */
    protected function makeApiTokenService(): ApiTokenService
    {
        return new ApiTokenService;
    }

    protected function buildSecretPreview(string $secret): string
    {
        if (strlen($secret) <= 16) {
            return $secret;
        }

        return substr($secret, 0, 8) . '...' . substr($secret, -6);
    }

    /**
     * 统一清理开发者令牌，避免空白字符导致鉴权失败。
     */
    protected function normalizeDeveloperToken(string $developerToken): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($developerToken));

        return is_string($normalized) ? $normalized : '';
    }

    /**
     * Get the setting links for the settings page
     *
     * @return array
     */
    protected function getSettingLinks(): array
    {
        $items = [
            [
                'title' => trans('admin/common.settings.basic'),
                'child' => [
                    [
                        'title'     => trans('admin/common.settings.basic'),
                        'sub_title' => trans('admin/setting.basic_help'),
                        'url'       => admin_route('settings.basic'),
                        'icon'      => 'bi bi-archive',
                    ],
                    [
                        'title'     => trans('admin/common.settings.store_settings'),
                        'sub_title' => trans('admin/setting.store_help'),
                        'url'       => admin_route('settings.store_settings'),
                        'icon'      => 'bi bi-shop',
                    ],
                    [
                        'title'     => trans('admin/common.settings.checkout'),
                        'sub_title' => trans('admin/setting.checkout_help'),
                        'url'       => admin_route('settings.checkout'),
                        'icon'      => 'bi bi-cart-check',
                    ],
                    [
                        'title'     => trans('admin/common.settings.policies'),
                        'sub_title' => trans('admin/setting.policies_card_help'),
                        'url'       => admin_route('settings.policies'),
                        'icon'      => 'bi bi-file-earmark-text',
                    ],
                    [
                        'title'     => trans('admin/common.settings.seo'),
                        'sub_title' => trans('admin/setting.seo_card_help'),
                        'url'       => admin_route('settings.seo'),
                        'icon'      => 'bi bi-signpost-split',
                    ],
                    [
                        'title'     => trans('admin/common.admin_users_index'),
                        'sub_title' => trans('admin/setting.admin_users_help'),
                        'url'       => admin_route('admin_users.index'),
                        'icon'      => 'bi bi-person-gear',
                    ],
                    [
                        'title'     => trans('admin/common.settings.mail'),
                        'sub_title' => trans('admin/setting.mail_help'),
                        'url'       => admin_route('settings.mail'),
                        'icon'      => 'bi bi-envelope',
                    ],
                    [
                        'title'     => trans('admin/common.account_index'),
                        'sub_title' => trans('admin/setting.account_help'),
                        'url'       => admin_route('account.index'),
                        'icon'      => 'bi bi-person-circle',
                    ],
                ],
            ],
            [
                'title' => trans('admin/setting.practical_functions'),
                'child' => [
                    [
                        'title'     => trans('admin/common.tax_rates_index'),
                        'sub_title' => trans('admin/setting.tax_rates_help'),
                        'url'       => admin_route('tax_rates.index'),
                        'icon'      => 'bi bi-cash-coin',
                    ],
                    [
                        'title'     => trans('admin/common.tax_classes_index'),
                        'sub_title' => trans('admin/setting.tax_classes_help'),
                        'url'       => admin_route('tax_classes.index'),
                        'icon'      => 'bi bi-cash',
                    ],
                    [
                        'title'     => trans('admin/common.countries_index'),
                        'sub_title' => trans('admin/setting.countries_help'),
                        'url'       => admin_route('countries.index'),
                        'icon'      => 'bi bi-buildings',
                    ],
                    [
                        'title'     => trans('admin/common.zones_index'),
                        'sub_title' => trans('admin/setting.zones_help'),
                        'url'       => admin_route('zones.index'),
                        'icon'      => 'bi bi-building',
                    ],
                    [
                        'title'     => trans('admin/common.regions_index'),
                        'sub_title' => trans('admin/setting.regions_help'),
                        'url'       => admin_route('regions.index'),
                        'icon'      => 'bi bi-collection',
                    ],
                    [
                        'title'     => trans('order.express_company'),
                        'sub_title' => trans('admin/setting.express_help'),
                        'url'       => admin_route('settings.express'),
                        'icon'      => 'bi bi-truck',
                    ],
                    [
                        'title'     => trans('admin/common.mail_logs_index'),
                        'sub_title' => trans('admin/setting.mail_logs_help'),
                        'url'       => admin_route('mail_logs.index'),
                        'icon'      => 'bi bi-envelope-check',
                    ],
                ],
            ],
        ];

        $user        = auth(\Beike\Models\AdminUser::AUTH_GUARD)->user();
        $showUpdater = $user?->is_root && (! config('saas.enabled') || (
            current_site()
            && (bool) data_get(current_site()?->meta, 'control_site', false)
            && app(\App\Saas\TenantManager::class)->isControlHost(request()->getHost())
        ));
        if ($showUpdater) {
            $items[0]['child'][] = [
                'title'     => trans('admin/setting.system_update_title'),
                'sub_title' => trans('admin/setting.system_update_help'),
                'url'       => admin_route('system_updates.index'),
                'icon'      => 'bi bi-cloud-arrow-down',
            ];
        }

        return hook_filter('admin.setting.links', $items);
    }

    /**
     * 获取签名密钥（用于本地签名）
     *
     * @return JsonResponse
     */
    public function fetchSignatureSecret(): JsonResponse
    {
        try {
            $tokenService = new \Beike\Services\ApiTokenService;
            $secret       = $tokenService->fetchSignatureSecret();

            return json_success(trans('admin/setting.signature_secret_fetch_success'), [
                'secret_length'  => strlen($secret),
                'secret_preview' => substr($secret, 0, 8) . '...' . substr($secret, -8),
            ]);
        } catch (\Exception $e) {
            return json_fail(trans('admin/setting.signature_secret_fetch_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * 获取签名密钥状态
     *
     * @return JsonResponse
     */
    public function getSignatureSecretStatus(): JsonResponse
    {
        try {
            $secret = system_setting('base.signature_secret');

            return json_success(trans('admin/setting.signature_secret_status_fetch_success'), [
                'has_secret'     => ! empty($secret),
                'secret_length'  => $secret ? strlen($secret) : 0,
                'secret_preview' => $secret ? substr($secret, 0, 8) . '...' . substr($secret, -8) : '',
            ]);
        } catch (\Exception $e) {
            return json_fail(trans('admin/setting.signature_secret_status_fetch_failed') . ': ' . $e->getMessage());
        }
    }
}
