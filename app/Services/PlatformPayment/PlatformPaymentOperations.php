<?php

namespace App\Services\PlatformPayment;

use App\Saas\Models\PlatformPaymentSession;
use App\Saas\Models\Site;
use Beike\Repositories\SettingRepo;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PlatformPaymentOperations
{
    public function __construct(private readonly PlatformPaymentSettings $settings) {}

    /**
     * Return a failure-safe cohort snapshot for the administration homepage.
     * Child sites can only see their own sessions; the control site receives
     * the platform-wide operational view.
     *
     * @param array{0: \DateTimeInterface, 1: \DateTimeInterface} $dateRange
     * @return array<string, mixed>
     */
    public function snapshot(array $dateRange): array
    {
        $site      = current_site();
        $isControl = $site && (bool) data_get($site->meta, 'control_site', false);
        $result    = $this->emptySnapshot($isControl ? 'all_sites' : 'current_site');

        if (! $site instanceof Site || ! config('saas.enabled')) {
            return $result;
        }

        try {
            if (! Schema::connection('saas')->hasTable('saas_platform_payment_sessions')) {
                return $result;
            }

            $query = PlatformPaymentSession::query()->whereBetween('created_at', $dateRange);
            if (! $isControl) {
                $query->where('site_id', $site->id);
            }

            $aggregate = (clone $query)->selectRaw(
                'COUNT(*) AS started'
                . ', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed'
                . ', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS processing'
                . ', SUM(CASE WHEN status IN (?, ?) AND active_key IS NULL THEN 1 ELSE 0 END) AS abandoned'
                . ', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed'
                . ', SUM(CASE WHEN status = ? AND active_key IS NOT NULL THEN 1 ELSE 0 END) AS expired_locked'
                . ', SUM(CASE WHEN status = ? AND provider_order_id IS NULL AND updated_at <= ? THEN 1 ELSE 0 END) AS stale_claims'
                . ', SUM(CASE WHEN status = ? AND provider = ? THEN 1 ELSE 0 END) AS paypal_completed'
                . ', SUM(CASE WHEN status = ? AND provider = ? THEN 1 ELSE 0 END) AS stripe_completed',
                [
                    PlatformPaymentSession::STATUS_COMPLETED,
                    PlatformPaymentSession::STATUS_PROCESSING,
                    PlatformPaymentSession::STATUS_CANCELLED,
                    PlatformPaymentSession::STATUS_EXPIRED,
                    PlatformPaymentSession::STATUS_FAILED,
                    PlatformPaymentSession::STATUS_EXPIRED,
                    PlatformPaymentSession::STATUS_PROCESSING,
                    now()->subMinutes(5),
                    PlatformPaymentSession::STATUS_COMPLETED,
                    'paypal',
                    PlatformPaymentSession::STATUS_COMPLETED,
                    'stripe',
                ],
            )->first();

            $started       = (int) ($aggregate->started ?? 0);
            $completed     = (int) ($aggregate->completed ?? 0);
            $failed        = (int) ($aggregate->failed ?? 0);
            $expiredLocked = (int) ($aggregate->expired_locked ?? 0);
            $staleClaims   = (int) ($aggregate->stale_claims ?? 0);
            $providers     = array_filter([
                'paypal' => (int) ($aggregate->paypal_completed ?? 0),
                'stripe' => (int) ($aggregate->stripe_completed ?? 0),
            ]);

            $result = array_merge($result, [
                'available'       => true,
                'started'         => $started,
                'completed'       => $completed,
                'processing'      => (int) ($aggregate->processing ?? 0),
                'abandoned'       => (int) ($aggregate->abandoned ?? 0),
                'needs_attention' => $failed + $expiredLocked + $staleClaims,
                'completion_rate' => $started > 0 ? round(($completed / $started) * 100, 1) : 0.0,
                'by_provider'     => $providers,
            ]);
        } catch (Throwable) {
            return $result;
        }

        try {
            $snapshot            = $this->settings->snapshot();
            $providers           = $this->settings->availableProvidersForSnapshot($snapshot);
            $locallyActive       = $isControl || SettingRepo::getPluginStatus(PlatformPaymentSessionService::PAYMENT_CODE);
            $result['state']     = $locallyActive && $providers !== [] ? 'ready' : 'disabled';
            $result['providers'] = $providers;
        } catch (Throwable) {
            $result['state'] = 'unavailable';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(string $scope): array
    {
        return [
            'available'       => false,
            'state'           => 'unavailable',
            'scope'           => $scope,
            'providers'       => [],
            'started'         => 0,
            'completed'       => 0,
            'processing'      => 0,
            'abandoned'       => 0,
            'needs_attention' => 0,
            'completion_rate' => 0.0,
            'by_provider'     => [],
        ];
    }
}
