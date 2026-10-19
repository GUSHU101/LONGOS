<?php

namespace Beike\Installer\Controllers;

use App\Saas\Support\SensitiveValueRedactor;
use Beike\Installer\Helpers\FinalInstallManager;
use Beike\Installer\Helpers\InstallationStateManager;
use Beike\Installer\Helpers\InstalledFileManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class FinalController extends BaseController
{
    /**
     * Update installed file and display finished view.
     *
     * @param InstalledFileManager $fileManager
     * @param FinalInstallManager  $finalInstall
     * @return RedirectResponse|Response
     */
    public function index(
        InstalledFileManager $fileManager,
        FinalInstallManager $finalInstall,
        InstallationStateManager $installationState,
    ): RedirectResponse|Response {
        try {
            return $this->finalize($fileManager, $finalInstall, $installationState);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            $message = trim(SensitiveValueRedactor::redact($exception->getMessage()));
            if ($message === '') {
                $message = trans('installer::installer_messages.environment.finalization_failed');
            }

            try {
                Log::error('LONGOS web installation finalization failed.', [
                    'exception' => $exception::class,
                    'message'   => $message,
                ]);
            } catch (Throwable) {
                // Logging permissions must not hide the recoverable error.
            }

            return redirect()->route('installer.environment')->withErrors(['error' => $message]);
        }
    }

    private function finalize(
        InstalledFileManager $fileManager,
        FinalInstallManager $finalInstall,
        InstallationStateManager $installationState,
    ): RedirectResponse|Response {
        $this->checkInstalled();

        $completion = request()->session()->get('installer.completed');
        if (! is_array($completion) || empty($completion['admin_email'])) {
            $completion = $installationState->completion($installationState->currentDatabaseSignature());
        }
        if (! is_array($completion) || empty($completion['admin_email'])) {
            return redirect()->route('installer.environment')->withErrors([
                'error' => trans('installer::installer_messages.environment.installation_session_expired'),
            ]);
        }

        $finalInstall->runFinal();

        $steps = 5;

        $data                   = compact('steps');
        $data['is_nginx']       = str_contains(strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 'nginx');
        $data['admin_email']    = $completion['admin_email'];
        $data['admin_path']     = trim((string) admin_name(), '/');
        $data['saas_ready']     = (bool) ($completion['saas'] ?? false);
        $data['base_domain']    = (string) ($completion['base_domain'] ?? config('saas.base_domain', ''));
        $dnsTargets             = (array) ($completion['dns_targets'] ?? config('saas.dns_targets', []));
        $data['dns_target']     = implode(', ', array_filter($dnsTargets)) ?: trans('installer::installer_messages.final.server_public_ip');
        $connection             = (string) config('database.default');
        $databaseConfig         = (array) config("database.connections.{$connection}", []);
        // Show only non-secret connection metadata so the operator can verify
        // exactly which database received the installation.
        $data['database_binding'] = [
            'connection' => strtoupper($connection),
            'host'       => (string) ($databaseConfig['host'] ?? ''),
            'database'   => (string) ($databaseConfig['database'] ?? ''),
        ];

        // Render completely before creating storage/installed. If a broken
        // view or asset helper throws, the installer remains resumable.
        // Environment secrets are deliberately not passed into the view.
        $html = view('installer::finished', $data)->render();

        $fileManager->update();
        $installationState->clear();
        request()->session()->forget('installer.completed');

        return response($html);
    }
}
