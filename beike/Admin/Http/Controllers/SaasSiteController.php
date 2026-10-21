<?php

namespace Beike\Admin\Http\Controllers;

use App\Mail\MailSenderProfileService;
use App\Saas\DomainVerificationService;
use App\Saas\IdempotencyService;
use App\Saas\Models\Domain;
use App\Saas\Models\MailSenderDomain;
use App\Saas\Models\Site;
use App\Saas\SiteDefinitionFactory;
use App\Saas\SiteDeletionService;
use App\Saas\SiteHealthChecker;
use App\Saas\SiteLifecycleService;
use App\Saas\SiteProvisioner;
use App\Saas\Support\DomainName;
use App\Saas\Support\IdempotencyConflict;
use App\Saas\Support\IdempotencyResult;
use App\Saas\Support\SensitiveValueRedactor;
use App\Saas\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SaasSiteController extends Controller
{
    public function page(Request $request, TenantManager $tenants, MailSenderProfileService $senders)
    {
        $this->authorizeControlPlane($request, $tenants);

        return view('admin::pages.saas.sites', [
            'baseDomain'            => (string) config('saas.base_domain', ''),
            'dnsTargets'            => (array) config('saas.dns_targets', []),
            'databaseMode'          => (string) config('saas.database_mode', 'prefix'),
            'adminPath'             => trim((string) admin_name(), '/'),
            'platformSenderDomain'  => strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.')),
            'senderDomainOwners'    => MailSenderDomain::query()
                ->pluck('site_id', 'domain')
                ->map(static fn (mixed $siteId): string => (string) $siteId)
                ->all(),
            'senderDomains'         => array_values(array_filter(
                $senders->configuredDomains(),
                static fn (string $domain): bool => $domain !== strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.')),
            )),
        ]);
    }

    public function index(Request $request, TenantManager $tenants): JsonResponse
    {
        $this->authorizeControlPlane($request, $tenants);

        $sites = Site::query()->with(['domains', 'mailSenderDomains'])->orderByDesc('id')->paginate(
            min(max((int) $request->integer('per_page', 20), 1), 100)
        );

        return response()->json($sites);
    }

    public function batchStore(
        Request $request,
        TenantManager $tenants,
        SiteDefinitionFactory $definitions,
        SiteProvisioner $provisioner,
        IdempotencyService $idempotency,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);

        try {
            $this->prepareProvisioningRuntime();
        } catch (Throwable $exception) {
            return response()->json([
                'message' => SensitiveValueRedactor::redact($exception->getMessage()),
            ], 503, [
                'Cache-Control' => 'no-store, private',
                'Pragma'        => 'no-cache',
            ]);
        }

        $validated = $request->validate([
            'sites'    => ['required', 'array', 'min:1', 'max:' . max(1, (int) config('saas.max_batch_size', 100))],
            'sites.*'  => ['required', 'array'],
            'migrate'  => ['sometimes', 'boolean'],
            'seed'     => ['sometimes', 'boolean'],
            'defaults' => ['sometimes', 'array'],
        ]);

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' && config('saas.idempotency.required', true)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => '批量建站必须通过 Idempotency-Key 请求头提供 UUID。',
            ]);
        }
        if ($key !== '' && ! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Idempotency-Key 必须是有效的 UUID。',
            ]);
        }

        $operation = function (?callable $heartbeat = null) use ($validated, $definitions, $provisioner): IdempotencyResult {
            $items = array_map(
                fn (array $item): array => $definitions->normalize($item, (array) ($validated['defaults'] ?? [])),
                $validated['sites']
            );
            $results = $provisioner->provisionMany(
                $items,
                (bool) ($validated['migrate'] ?? true),
                (bool) ($validated['seed'] ?? true),
                $heartbeat,
            );

            return new IdempotencyResult([
                'message' => '批量建站已执行完成。',
                'data'    => $results,
            ], collect($results)->contains(static fn (array $result): bool => ! $result['ok']) ? 207 : 201);
        };

        try {
            $result   = $key === '' ? $operation() : $idempotency->execute('control.sites.batch', $key, $validated, $operation);
            $response = response()->json($result->body, $result->status);
            if ($key !== '') {
                $response->headers->set('Idempotency-Key', $key);
                $response->headers->set('Idempotency-Replayed', $result->replayed ? 'true' : 'false');
            }
            // Successful provisioning responses can contain the one-time
            // tenant administrator password and must never be proxy cached.
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');

            return $response;
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return response()->json(['message' => SensitiveValueRedactor::redact($exception->getMessage())], 422);
        }
    }

    public function show(Request $request, TenantManager $tenants, Site $site): JsonResponse
    {
        $this->authorizeControlPlane($request, $tenants);

        return response()->json($site->load(['domains', 'mailSenderDomains', 'events' => static fn ($query) => $query->latest('id')->limit(50)]));
    }

    public function updateStatus(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        Site $site,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'force'  => ['sometimes', 'boolean'],
        ]);

        return $this->operation(
            '站点状态已更新。',
            fn () => $lifecycle->setStatus($site, $validated['status'], (bool) ($validated['force'] ?? false)),
        );
    }

    public function destroy(
        Request $request,
        TenantManager $tenants,
        SiteDeletionService $deletion,
        Site $site,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        // A tenant may own hundreds of prefixed tables and media files. Keep
        // the confirmed lifecycle request alive until fail-closed cleanup has
        // either completed or left the site in its retryable deleting state.
        ignore_user_abort(true);
        @set_time_limit((int) config('saas.provisioning.max_execution_seconds', 0));

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:64'],
            'delete_data'  => ['required', 'boolean'],
        ]);
        if (! hash_equals((string) $site->code, (string) $validated['confirmation'])) {
            throw ValidationException::withMessages([
                'confirmation' => '请输入完整且区分大小写的站点标识以确认删除。',
            ]);
        }

        return $this->operation(
            '租户子站已删除。',
            fn (): array => $deletion->delete($site, (bool) $validated['delete_data']),
        );
    }

    public function addDomain(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        DomainVerificationService $verification,
        Site $site,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate([
            'domain'   => ['required', 'string', 'max:253'],
            'primary'  => ['sometimes', 'boolean'],
        ]);

        if ($verification->isPlatformManagedHost($validated['domain'])) {
            return $this->operation(
                '平台子域名已绑定。',
                fn () => $lifecycle->addDomain($site, $validated['domain'], (bool) ($validated['primary'] ?? false)),
                201,
            );
        }

        return $this->operation(
            (bool) ($validated['primary'] ?? false)
                ? '已创建自定义域名验证请求；验证成功后才能设为主域名。'
                : '已创建自定义域名验证请求。',
            function () use ($site, $validated, $verification): array {
                $domain = $verification->request($site, $validated['domain']);

                return [
                    'domain'       => $domain,
                    'verification' => $verification->instructions($domain),
                ];
            },
            202,
        );
    }

    public function verifyDomain(
        Request $request,
        TenantManager $tenants,
        DomainVerificationService $verification,
        SiteLifecycleService $lifecycle,
        Site $site,
        Domain $domain,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate(['primary' => ['sometimes', 'boolean']]);

        return $this->operation('自定义域名已验证并启用。', function () use ($site, $domain, $validated, $verification, $lifecycle): Domain {
            $verified = $verification->verify($site, $domain);
            if ((bool) ($validated['primary'] ?? false) && ! $verified->is_primary) {
                $verified = $lifecycle->makePrimary($site, $verified);
            }

            return $verified;
        });
    }

    public function removeDomain(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        Site $site,
        Domain $domain,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate(['force' => ['sometimes', 'boolean']]);

        return $this->operation('域名已解绑。', function () use ($lifecycle, $site, $domain, $validated): null {
            $lifecycle->removeDomain($site, $domain, (bool) ($validated['force'] ?? false));

            return null;
        });
    }

    public function makePrimaryDomain(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        Site $site,
        Domain $domain,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);

        return $this->operation('主域名已更新。', fn () => $lifecycle->makePrimary($site, $domain));
    }

    public function updateDomainRouting(
        Request $request,
        TenantManager $tenants,
        SiteLifecycleService $lifecycle,
        Site $site,
        Domain $domain,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate(['mode' => ['required', 'in:redirect,alias']]);

        return $this->operation(
            '域名路由模式已更新。',
            fn () => $lifecycle->setDomainRouting($site, $domain, $validated['mode']),
        );
    }

    public function assignSenderDomain(
        Request $request,
        TenantManager $tenants,
        MailSenderProfileService $senders,
        Site $site,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        $validated = $request->validate(['domain' => ['required', 'string', 'max:253']]);

        try {
            $domain = DomainName::normalize($validated['domain']);
        } catch (InvalidArgumentException $exception) {
            // DomainName performs the strict host/path/wildcard checks used
            // by the rest of the control plane. Convert its input error into
            // a normal validation response instead of letting malformed
            // operator input escape as an HTTP 500.
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }
        if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['domain' => '发件域必须是有效的公网域名。']);
        }
        if (! in_array($domain, $senders->configuredDomains(), true)) {
            throw ValidationException::withMessages([
                'domain' => '该域名尚未在邮件中继的 MAIL_RELAY_ALLOWED_SENDER_DOMAINS 中启用。',
            ]);
        }
        if ($domain === strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.'))) {
            throw ValidationException::withMessages(['domain' => '共享平台发件域会自动授权，无需分配。']);
        }
        if ((bool) data_get($site->meta, 'control_site', false)) {
            throw ValidationException::withMessages(['domain' => '独立发件域只能分配给租户子站，主站使用平台发件域。']);
        }
        if (! in_array($site->status, [Site::STATUS_ACTIVE, Site::STATUS_SUSPENDED], true)) {
            throw ValidationException::withMessages(['domain' => '只能向已上线或已暂停的租户分配发件域。']);
        }

        return $this->operation('发件域已分配给租户。', function () use ($site, $domain): MailSenderDomain {
            return DB::connection('saas')->transaction(function () use ($site, $domain): MailSenderDomain {
                $existing = MailSenderDomain::query()->where('domain', $domain)->lockForUpdate()->first();
                if ($existing && (string) $existing->site_id !== (string) $site->getKey()) {
                    throw new RuntimeException('该发件域已分配给其他租户。');
                }

                return $existing ?: MailSenderDomain::query()->create([
                    'site_id' => $site->getKey(),
                    'domain'  => $domain,
                ]);
            }, 3);
        }, 201);
    }

    public function removeSenderDomain(
        Request $request,
        TenantManager $tenants,
        Site $site,
        MailSenderDomain $mailSenderDomain,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);
        abort_unless((string) $mailSenderDomain->site_id === (string) $site->getKey(), 404);

        return $this->operation('发件域已从租户解除分配。', function () use ($mailSenderDomain): null {
            $mailSenderDomain->delete();

            return null;
        });
    }

    public function health(
        Request $request,
        TenantManager $tenants,
        SiteHealthChecker $health,
        Site $site,
    ): JsonResponse {
        $this->authorizeControlPlane($request, $tenants);

        try {
            return response()->json($health->check($site->load('domains'), $request->boolean('dns', true)));
        } catch (Throwable $exception) {
            return response()->json(['message' => SensitiveValueRedactor::redact($exception->getMessage())], 422);
        }
    }

    private function authorizeControlPlane(Request $request, TenantManager $tenants): void
    {
        abort_unless(config('saas.enabled') && config('saas.http_management'), 404);
        abort_unless(Auth::guard(\Beike\Models\AdminUser::AUTH_GUARD)->user()?->is_root, 403);
        abort_unless($tenants->isControlHost($request->getHost()), 403);
        abort_unless((bool) data_get($tenants->current()?->meta, 'control_site', false), 403);
    }

    private function operation(string $message, callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['message' => $message, 'data' => $callback()], $status);
        } catch (Throwable $exception) {
            return response()->json(['message' => SensitiveValueRedactor::redact($exception->getMessage())], 422);
        }
    }

    private function prepareProvisioningRuntime(): void
    {
        ignore_user_abort(true);
        @set_time_limit((int) config('saas.provisioning.max_execution_seconds', 0));

        $target       = (string) config('saas.provisioning.memory_limit', '512M');
        $minimumBytes = (int) config('saas.provisioning.minimum_memory_bytes', 268435456);
        $currentBytes = $this->memoryBytes((string) ini_get('memory_limit'));
        $targetBytes  = $this->memoryBytes($target);

        if ($currentBytes >= 0 && $targetBytes > $currentBytes) {
            @ini_set('memory_limit', $target);
            $currentBytes = $this->memoryBytes((string) ini_get('memory_limit'));
        }

        if ($currentBytes >= 0 && $currentBytes < $minimumBytes) {
            throw new RuntimeException(
                '当前 PHP memory_limit 不足以初始化租户站点，请将 Coolify 应用内存限制设置为至少 '
                . max(1, (int) ceil($minimumBytes / 1024 / 1024)) . 'M 并重新部署。'
            );
        }
    }

    private function memoryBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        if ($value === '' || ! preg_match('/^(\d+)([KMG])?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (int) $matches[1];

        return match (strtoupper((string) ($matches[2] ?? ''))) {
            'G'     => $bytes * 1024 * 1024 * 1024,
            'M'     => $bytes * 1024 * 1024,
            'K'     => $bytes * 1024,
            default => $bytes,
        };
    }
}
