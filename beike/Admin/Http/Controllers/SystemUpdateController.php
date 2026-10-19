<?php

namespace Beike\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Saas\Support\SensitiveValueRedactor;
use App\Saas\TenantManager;
use App\Updates\UpdateLauncher;
use App\Updates\UpdateManager;
use App\Updates\UpdateSettingsStore;
use App\Updates\UpdateStateStore;
use Beike\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SystemUpdateController extends Controller
{
    public function index(UpdateSettingsStore $settings, UpdateStateStore $states): View
    {
        $this->authorizeControlRoot();
        $configuration = $settings->get();
        unset($configuration['token']);

        return view('admin::pages.system_updates.index', [
            'configuration'  => $configuration,
            'state'          => $this->runtimeState($states),
            'currentVersion' => (string) config('beike.version', ''),
            'enabled'        => (bool) config('longos.updater.enabled', false),
        ]);
    }

    public function store(Request $request, UpdateSettingsStore $settings, UpdateStateStore $states): JsonResponse
    {
        $this->authorizeControlRoot();
        $validated = $request->validate([
            'repository'  => ['required', 'string', 'max:140'],
            'channel'     => ['required', 'in:stable,prerelease'],
            'token'       => ['nullable', 'string', 'max:512'],
            'clear_token' => ['nullable', 'boolean'],
        ]);

        $lock = null;

        try {
            // Serialize settings with queue/run so a release cannot be checked
            // from one repository and downloaded with another repository's
            // token or endpoint.
            $lock  = $states->acquireLock();
            $state = $states->get();
            if (in_array($state['status'] ?? null, ['pending', 'running'], true)) {
                throw new \RuntimeException('更新进行中不能修改 GitHub 更新设置。');
            }
            $saved = $settings->save(
                (string) $validated['repository'],
                (string) $validated['channel'],
                array_key_exists('token', $validated) ? (string) $validated['token'] : null,
                (bool) ($validated['clear_token'] ?? false),
            );
            unset($saved['token']);

            return json_success('GitHub 更新设置已保存。', $saved);
        } catch (Throwable $exception) {
            return json_fail(SensitiveValueRedactor::redact($exception->getMessage()));
        } finally {
            if (is_resource($lock)) {
                $states->releaseLock($lock);
            }
        }
    }

    public function check(UpdateManager $updates): JsonResponse
    {
        $this->authorizeControlRoot();

        try {
            return json_success('版本检查完成。', $updates->check());
        } catch (Throwable $exception) {
            return json_fail(SensitiveValueRedactor::redact($exception->getMessage()));
        }
    }

    public function start(Request $request, UpdateManager $updates, UpdateLauncher $launcher): JsonResponse
    {
        $this->authorizeControlRoot();

        try {
            $validated = $request->validate([
                'version'  => ['required', 'string', 'max:64', 'regex:/^\d+(?:\.\d+){2,3}(?:[-+][0-9A-Za-z.-]+)?$/'],
                'asset_id' => ['required', 'integer', 'min:1'],
            ]);
            $state = $updates->queue((string) $validated['version'], (int) $validated['asset_id']);
            $jobId = (string) $state['id'];
            if ($launcher->launch($jobId)) {
                $message = '更新已由独立后台进程开始；可以离开本页。';
            } else {
                // Some shared hosting environments disable proc_open. The
                // response-termination fallback still needs no queue worker
                // or manual command, but occupies this PHP-FPM worker longer.
                app()->terminating(static function () use ($jobId): void {
                    app(UpdateManager::class)->run($jobId);
                });
                $message = '更新已开始；可以离开本页。';
            }

            return json_success($message, $state);
        } catch (Throwable $exception) {
            return json_fail(SensitiveValueRedactor::redact($exception->getMessage()));
        }
    }

    public function status(UpdateStateStore $states): JsonResponse
    {
        $this->authorizeControlRoot();

        $state = $this->runtimeState($states);

        return json_success('更新状态已刷新。', $state);
    }

    /** @return array<string, mixed> */
    private function runtimeState(UpdateStateStore $states): array
    {
        $state                  = $states->get();
        $workerActive           = $states->workerActive();
        $updated                = strtotime((string) ($state['updated_at'] ?? ''));
        $state['worker_active'] = $workerActive;
        $state['recoverable']   = ! $workerActive && (
            (($state['status'] ?? null) === 'failed' && ($state['recovery_required'] ?? false) === true)
            || (in_array($state['status'] ?? null, ['pending', 'running'], true)
                && $updated !== false
                && $updated < time() - 30)
        );

        return $state;
    }

    public function recover(UpdateManager $updates, UpdateStateStore $states): JsonResponse
    {
        $this->authorizeControlRoot();
        $state = $states->get();

        try {
            return json_success('中断更新已恢复。', $updates->recover((string) ($state['id'] ?? '')));
        } catch (Throwable $exception) {
            return json_fail(SensitiveValueRedactor::redact($exception->getMessage()));
        }
    }

    private function authorizeControlRoot(): void
    {
        $user = auth(AdminUser::AUTH_GUARD)->user();
        abort_unless($user?->is_root, 403);

        if (! config('saas.enabled')) {
            return;
        }

        $site = current_site();
        abort_unless(
            $site
            && (bool) data_get($site->meta, 'control_site', false)
            && app(TenantManager::class)->isControlHost(request()->getHost()),
            403,
        );
    }
}
