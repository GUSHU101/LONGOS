<?php

namespace Beike\Admin\Http\Controllers;

use App\Saas\Models\PlatformPaymentAccount;
use App\Saas\TenantManager;
use App\Services\PlatformPayment\PlatformPaymentAccountService;
use App\Services\PlatformPayment\PlatformPaymentSettings;
use App\Services\PlatformPayment\PlatformPaypalGateway;
use Beike\Models\AdminUser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Control-plane-only management for receiver accounts. Child tenants never
 * receive these routes, credential values, or callback-domain controls.
 */
class PlatformPaymentAccountController extends Controller
{
    public function store(Request $request, PlatformPaymentAccountService $accounts): RedirectResponse
    {
        $this->assertControlSite();
        $data = $this->validateCreate($request);

        try {
            $account = $this->withConfigurationLock(fn (): PlatformPaymentAccount => $accounts->create($data));

            return $this->redirectToSettings()->with('success', "已添加收款账户“{$account->label}”，并发布专用回传域名。");
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['account' => $this->safeMessage($exception)])->withInput();
        }
    }

    public function update(Request $request, PlatformPaymentAccount $account, PlatformPaymentAccountService $accounts): RedirectResponse
    {
        $this->assertControlSite();
        $data = $this->validateUpdate($request, $account);

        try {
            $account = $this->withConfigurationLock(fn (): PlatformPaymentAccount => $accounts->update($account, $data));

            return $this->redirectToSettings()->with('success', "已更新收款账户“{$account->label}”。");
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['account' => $this->safeMessage($exception)])->withInput();
        }
    }

    public function health(PlatformPaymentAccount $account, PlatformPaymentAccountService $accounts, PlatformPaymentSettings $settings): RedirectResponse
    {
        $this->assertControlSite();

        try {
            $this->withConfigurationLock(function () use ($account, $accounts, $settings): void {
                $snapshot = $settings->snapshotForPaypalAccount($account);
                (new PlatformPaypalGateway($settings, $snapshot))->healthCheck();
                $accounts->markHealth($account, 'ready');
            });

            return $this->redirectToSettings()->with('success', "“{$account->label}”已通过 PayPal 身份验证。");
        } catch (Throwable $exception) {
            report($exception);

            try {
                // This probe authenticates before an order can be created.
                // Fail closed immediately so the known-bad account cannot be
                // selected for a new buyer session before the next scheduler
                // run.
                $accounts->recordUnavailable($account);
            } catch (Throwable) {
            }

            return $this->redirectToSettings()->withErrors(['account' => '该账户未通过 PayPal 身份验证。请核对环境、Client ID、Secret 与商户授权。']);
        }
    }

    public function destroy(PlatformPaymentAccount $account, PlatformPaymentAccountService $accounts): RedirectResponse
    {
        $this->assertControlSite();

        try {
            $label = (string) $account->label;
            $this->withConfigurationLock(function () use ($account, $accounts): void {
                $accounts->delete($account);
            });

            return $this->redirectToSettings()->with('success', "已安全删除收款账户“{$label}”及其专用回传域名。");
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToSettings()->withErrors(['account' => $this->safeMessage($exception)]);
        }
    }

    /** @return array<string, mixed> */
    private function validateCreate(Request $request): array
    {
        $data = $request->validate([
            'label'           => ['required', 'string', 'max:96'],
            'api_mode'        => ['required', Rule::in(['sandbox', 'live'])],
            'client_id'       => ['required', 'string', 'max:512', 'regex:/^[A-Za-z0-9_-]{10,512}$/'],
            'secret'          => ['required', 'string', 'min:8', 'max:4096'],
            'merchant_id'     => ['nullable', 'string', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
            'rotation_weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'enabled'         => ['nullable', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled', true);

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateUpdate(Request $request, PlatformPaymentAccount $account): array
    {
        $data = $request->validate([
            'label'           => ['required', 'string', 'max:96'],
            'api_mode'        => ['required', Rule::in(['sandbox', 'live'])],
            'client_id'       => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9_-]{10,512}$/'],
            'secret'          => ['nullable', 'string', 'max:4096'],
            'merchant_id'     => ['nullable', 'string', 'regex:/^[2-9A-HJ-NP-Z]{13}$/'],
            'rotation_weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'enabled'         => ['nullable', 'boolean'],
        ]);
        // Client IDs are not secret, but hiding them reduces accidental
        // copying. A blank edit field therefore means "keep the current
        // identifier", just like a blank secret field.
        $data['client_id'] = trim((string) ($data['client_id'] ?? '')) ?: (string) $account->client_id;
        $data['enabled']   = $request->boolean('enabled');

        return $data;
    }

    private function assertControlSite(): void
    {
        abort_unless((bool) data_get(current_site()?->meta, 'control_site', false), 404);

        // The route middleware is the primary protection. Keep this local
        // assertion as a defense in depth layer for queued/manual controller
        // invocations and for installations that have cached older routes.
        if (config('saas.enabled')) {
            abort_unless(Auth::guard(AdminUser::AUTH_GUARD)->user()?->is_root, 403);
            abort_unless(app(TenantManager::class)->isControlHost(request()->getHost()), 403);
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function withConfigurationLock(callable $callback): mixed
    {
        $lock = Cache::lock(
            PlatformPaymentSettings::CONFIGURATION_LOCK,
            PlatformPaymentSettings::CONFIGURATION_LOCK_SECONDS,
        );

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new RuntimeException('支付配置正在使用或更新，请稍后重试。');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function redirectToSettings(): RedirectResponse
    {
        return redirect()->to(admin_route('plugins.edit', ['code' => 'longos_platform_pay']));
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' && mb_strlen($message) <= 220
            ? $message
            : '收款账户暂时无法保存，请检查配置后重试。';
    }
}
