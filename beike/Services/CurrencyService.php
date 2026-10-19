<?php

namespace Beike\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Store prices are always authored in USD. Display currencies are resolved
 * from the visitor's IP and the single ExchangeRate-API rate table; they are
 * intentionally not managed as shop records.
 */
class CurrencyService
{
    /** Visible in diagnostics/response headers so a deployment can be verified. */
    public const POLICY_REVISION = '2026-10-14.1';

    private const BASE_CURRENCY = 'USD';

    /**
     * Long-running queue/scheduler processes can visit more than one tenant in
     * the same PHP process. Keep the in-memory rate library scoped to the
     * active database (and prefix) so one tenant can never reuse another
     * tenant's snapshot.
     *
     * @var array<string, self>
     */
    private static array $instances = [];

    private ?array $rateLibrary = null;

    private ?string $lastProviderFailureReason = null;

    /** @var array{attempted:bool,success:bool,reason:string,rates_count:int} */
    private array $lastRefreshOutcome = [
        'attempted'   => false,
        'success'     => false,
        'reason'      => 'not_run',
        'rates_count' => 0,
    ];

    public static function getInstance(): self
    {
        $scope = self::runtimeScope();

        return self::$instances[$scope] ??= new self;
    }

    public static function resetRuntimeCache(): void
    {
        self::$instances = [];
    }

    public function __construct(bool $loadCurrencies = true)
    {
        // Kept compatible with existing callers. Currency rows are legacy
        // order data only and must not control visitor display currencies.
    }

    public function format($amount, $currency, $value = '', bool $format = true)
    {
        $currency     = $this->normalizeCurrency($currency);
        $presentation = $this->presentationFor($currency);
        $amount       = $this->displayAmount($amount, $currency, $value);

        if (! $format) {
            return $amount;
        }

        $number = number_format(abs($amount), $presentation['decimal_places'], '.', ',');
        $symbol = trim((string) $presentation['symbol']);

        // Keep display identical across PHP/ICU versions and always expose the
        // ISO code so ambiguous symbols such as $, ¥ and kr cannot mislead a
        // visitor after automatic IP-based currency selection.
        return ($amount < 0 ? '-' : '')
            . ($symbol === $currency ? $currency . ' ' : $symbol)
            . $number
            . ($symbol === $currency ? '' : ' ' . $currency);
    }

    /**
     * Convert a USD base amount for display only. Business rules, cart totals,
     * discounts and persisted order amounts must continue to use USD; rounding
     * happens once here according to the target currency's minor units.
     */
    public function displayAmount($amount, string $currency, $value = ''): float
    {
        $currency     = $this->normalizeCurrency($currency);
        $presentation = $this->presentationFor($currency);
        $rate         = is_numeric($value) && is_finite((float) $value) && (float) $value > 0
            ? (float) $value
            : $this->rateFor($currency);
        $amount       = is_numeric($amount) && is_finite((float) $amount) ? (float) $amount : 0.0;

        return round($amount * $rate, $presentation['decimal_places']);
    }

    public function convert($amount, $from, $to): float
    {
        $fromRate = $this->rateFor($this->normalizeCurrency($from));
        $toRate   = $this->rateFor($this->normalizeCurrency($to));

        return (float) $amount * ($toRate / max($fromRate, PHP_FLOAT_MIN));
    }

    /** Fetch one complete USD table at most once per calendar day. */
    public function refreshRatesIfDue(bool $force = false): array
    {
        $apiKey = $this->rateApiKey();
        if (! $this->validApiKey($apiKey)) {
            $this->setRefreshOutcome(false, false, 'not_configured', 0);

            return [];
        }

        $library = $this->readRateLibrary();
        if (! $force && $this->wasUpdatedToday($library)) {
            $this->setRefreshOutcome(false, true, 'current', count($library['rates']));

            return $library['rates'];
        }

        $lock = null;

        try {
            $lock = Cache::lock($this->refreshLockKey(), 15);
            if (! $lock->get()) {
                $this->setRefreshOutcome(false, $library['rates'] !== [], 'locked', count($library['rates']));

                return $library['rates'];
            }
        } catch (\Throwable) {
            // File/database cache drivers without locks can still make one
            // bounded request; pricing always retains the prior snapshot.
        }

        try {
            $library = $this->readRateLibrary(true);
            if (! $force && $this->wasUpdatedToday($library)) {
                $this->setRefreshOutcome(false, true, 'current', count($library['rates']));

                return $library['rates'];
            }

            $table = $this->requestRates($apiKey);
            if ($table === null) {
                $this->setRefreshOutcome(true, false, $this->lastProviderFailureReason ?? 'provider_failed', count($library['rates']));

                return $library['rates'];
            }

            $this->writeRateLibrary($table);
            $this->setRefreshOutcome(true, true, 'refreshed', count($table['rates']));

            return $table['rates'];
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // The lock TTL is the final guard.
                }
            }
        }
    }

    /** @return array{attempted:bool,success:bool,reason:string,rates_count:int} */
    public function lastRefreshOutcome(): array
    {
        return $this->lastRefreshOutcome;
    }

    /**
     * Validate a credential against the provider and atomically replace the
     * local snapshot only after a complete response has been received. This is
     * used by the web settings flow so enabling automatic currency does not
     * require a follow-up Artisan command. A failed key/network request leaves
     * the last known-good snapshot untouched.
     */
    public function verifyAndRefreshRates(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if (! $this->validApiKey($apiKey)) {
            $this->setRefreshOutcome(false, false, 'invalid_key', 0);

            return [];
        }

        $table = $this->requestRates($apiKey);
        if ($table === null) {
            $this->setRefreshOutcome(true, false, $this->lastProviderFailureReason ?? 'provider_failed', count($this->readRateLibrary()['rates']));

            return [];
        }

        $this->writeRateLibrary($table);
        $this->setRefreshOutcome(true, true, 'refreshed', count($table['rates']));

        return $table['rates'];
    }

    /** Force a verified refresh with the encrypted credential in settings. */
    public function verifyAndRefreshConfiguredRates(): array
    {
        return $this->verifyAndRefreshRates($this->rateApiKey());
    }

    public function getRatesFromApi(string $date): array
    {
        // Historical callers use this accessor while rendering legacy currency
        // records. Reading it must never turn a web request into an API call.
        return $this->readRateLibrary()['rates'];
    }

    public static function forgetRateSnapshot(): void
    {
        try {
            DB::table('exchange_rates')->delete();
            $scope = self::runtimeScope();
            if (isset(self::$instances[$scope])) {
                self::$instances[$scope]->forgetRateLibrary();
            }
            Cache::forget('currency:rate-refresh:USD:' . $scope);
        } catch (\Throwable) {
            // Saving settings should not fail merely because stale cache
            // housekeeping is unavailable.
        }
    }

    /** Compatibility method for the retired currency-admin screen. */
    public function applyStoredRates(): bool
    {
        return count($this->readRateLibrary()['rates']) > 1;
    }

    public function isConfigured(): bool
    {
        return $this->validApiKey($this->rateApiKey());
    }

    public function rateFor(string $currency): float
    {
        $currency = $this->normalizeCurrency($currency);
        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        $rate = $this->readRateLibrary()['rates'][$currency] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : 1.0;
    }

    public function hasRate(string $currency): bool
    {
        $currency = $this->normalizeCurrency($currency);

        return $currency === self::BASE_CURRENCY || isset($this->readRateLibrary()['rates'][$currency]);
    }

    /** Backwards-compatible name used by the prior visitor resolver. */
    public function hasFreshRate(string $currency): bool
    {
        return $this->hasRate($currency);
    }

    public function rateStatus(): array
    {
        $library = $this->readRateLibrary();
        $rates   = $library['rates'];
        $storage = $this->rateStorageStatus();

        return [
            'policy_revision'     => self::POLICY_REVISION,
            'configured'          => $this->isConfigured(),
            'storage_ready'       => $storage['ready'],
            'storage_missing'     => $storage['missing'],
            'available'           => count($rates) > 1,
            'fresh'               => $this->wasUpdatedToday($library),
            'source'              => 'exchange_rate_api',
            'fetched_at'          => $library['fetched_at'],
            'provider_updated_at' => $library['provider_updated_at'],
            'covered'             => count($rates),
        ];
    }

    /** @return array{rates:array,next_update_at:?string,provider_updated_at:?string}|null */
    private function requestRates(string $apiKey): ?array
    {
        $this->lastProviderFailureReason = null;

        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(7)
                ->retry(1, 200, throw: false)
                ->get(sprintf('https://v6.exchangerate-api.com/v6/%s/latest/USD', rawurlencode($apiKey)));
            if (! $response->successful()) {
                $this->lastProviderFailureReason = $this->providerFailureReason(
                    $response->status(),
                    (string) $response->json('error-type'),
                );
                Log::warning('ExchangeRate-API currency refresh failed.', [
                    'status' => $response->status(),
                    'reason' => $this->lastProviderFailureReason,
                ]);

                return null;
            }

            if ($response->json('result')                            !== 'success'
                || strtoupper((string) $response->json('base_code')) !== self::BASE_CURRENCY) {
                $this->lastProviderFailureReason = $this->providerFailureReason(
                    $response->status(),
                    (string) $response->json('error-type'),
                );
                Log::warning('ExchangeRate-API returned an invalid currency payload.', [
                    'reason' => $this->lastProviderFailureReason,
                ]);

                return null;
            }

            $rates        = $this->normalizeRates($response->json('conversion_rates'));
            $minimumRates = max(2, (int) config('currency.minimum_rate_count', 100));
            $required     = array_values(array_unique(array_map(
                static fn (mixed $code): string => strtoupper(trim((string) $code)),
                (array) config('currency.required_rate_codes', ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CNY']),
            )));
            if (count($rates) < $minimumRates || array_diff($required, array_keys($rates)) !== []) {
                $this->lastProviderFailureReason = 'provider_payload_incomplete';

                return null;
            }

            return [
                'rates'               => $rates,
                'next_update_at'      => $this->providerTimestamp($response->json('time_next_update_unix')),
                'provider_updated_at' => $this->providerTimestamp($response->json('time_last_update_unix')),
            ];
        } catch (\Throwable $exception) {
            $this->lastProviderFailureReason = 'network_error';
            Log::warning('ExchangeRate-API currency request failed.', ['exception' => $exception::class]);

            return null;
        }
    }

    /** @return array{ready:bool,missing:array<int,string>} */
    private function rateStorageStatus(): array
    {
        $required = [
            'currency_code', 'base_currency', 'rate', 'currency_symbol',
            'decimal_places', 'provider_updated_at', 'fetched_at',
            'created_at', 'updated_at',
        ];

        try {
            $schema = DB::getSchemaBuilder();
            if (! $schema->hasTable('exchange_rates')) {
                return ['ready' => false, 'missing' => ['exchange_rates']];
            }

            $columns = array_map('strtolower', $schema->getColumnListing('exchange_rates'));
            $missing = array_values(array_diff($required, $columns));

            return ['ready' => $missing === [], 'missing' => $missing];
        } catch (\Throwable) {
            return ['ready' => false, 'missing' => ['database_access']];
        }
    }

    private function providerFailureReason(int $status, string $providerReason): string
    {
        $providerReason = strtolower(trim($providerReason));
        if (in_array($providerReason, ['invalid-key', 'inactive-account'], true) || in_array($status, [401, 403], true)) {
            return 'invalid_or_inactive_key';
        }
        if ($providerReason === 'quota-reached' || $status === 429) {
            return 'provider_quota_reached';
        }
        if ($status >= 500) {
            return 'provider_unavailable';
        }

        return $providerReason !== '' ? 'provider_rejected' : 'provider_payload_invalid';
    }

    private function normalizeRates(mixed $rates): array
    {
        $normalized = [self::BASE_CURRENCY => 1.0];
        if (! is_array($rates)) {
            return $normalized;
        }
        foreach ($rates as $code => $rate) {
            $code = strtoupper(trim((string) $code));
            if ($code !== self::BASE_CURRENCY
                && CurrencyCatalog::isSupported($code)
                && is_numeric($rate)
                && (float) $rate > 0
                && (float) $rate < 1000000000000) {
                $normalized[$code] = (float) $rate;
            }
        }

        return $normalized;
    }

    /** @return array{rates:array<string,float>,presentation:array<string,array{symbol:string,decimal_places:int}>,fetched_at:?string,provider_updated_at:?string} */
    private function readRateLibrary(bool $refresh = false): array
    {
        if (! $refresh && $this->rateLibrary !== null) {
            return $this->rateLibrary;
        }

        return $this->rateLibrary = $this->readRateLibraryFromDatabase();
    }

    /** @return array{rates:array<string,float>,presentation:array<string,array{symbol:string,decimal_places:int}>,fetched_at:?string,provider_updated_at:?string} */
    private function readRateLibraryFromDatabase(): array
    {
        try {
            $rows         = DB::table('exchange_rates')->where('base_currency', self::BASE_CURRENCY)->get();
            $rates        = [];
            $presentation = [];
            foreach ($rows as $row) {
                $currency = strtoupper((string) $row->currency_code);
                if (CurrencyCatalog::isSupported($currency) && is_numeric($row->rate) && (float) $row->rate > 0) {
                    $rates[$currency]        = (float) $row->rate;
                    $presentation[$currency] = [
                        // Code-level presentation rules take effect immediately
                        // even when an old snapshot contains an ICU-derived or
                        // otherwise stale symbol.
                        'symbol'         => CurrencyCatalog::symbol($currency),
                        'decimal_places' => CurrencyCatalog::decimalPlaces($currency),
                    ];
                }
            }

            return [
                'rates'               => $rates,
                'presentation'        => $presentation,
                'fetched_at'          => $rows->max('fetched_at'),
                'provider_updated_at' => $rows->max('provider_updated_at'),
            ];
        } catch (\Throwable) {
            return ['rates' => [], 'presentation' => [], 'fetched_at' => null, 'provider_updated_at' => null];
        }
    }

    /** @param array{rates:array,next_update_at:?string,provider_updated_at:?string} $table */
    private function writeRateLibrary(array $table): void
    {
        try {
            $fetchedAt = now();
            $rows      = [];
            foreach ($table['rates'] as $currency => $rate) {
                $rows[] = [
                    'currency_code'       => $currency,
                    'base_currency'       => self::BASE_CURRENCY,
                    'rate'                => $rate,
                    'currency_symbol'     => CurrencyCatalog::symbol($currency),
                    'decimal_places'      => CurrencyCatalog::decimalPlaces($currency),
                    'provider_updated_at' => $table['provider_updated_at'],
                    'fetched_at'          => $fetchedAt,
                    'created_at'          => $fetchedAt,
                    'updated_at'          => $fetchedAt,
                ];
            }

            DB::transaction(function () use ($rows, $table): void {
                DB::table('exchange_rates')->upsert($rows, ['currency_code'], [
                    'base_currency', 'rate', 'currency_symbol', 'decimal_places', 'provider_updated_at', 'fetched_at', 'updated_at',
                ]);
                DB::table('exchange_rates')->whereNotIn('currency_code', array_keys($table['rates']))->delete();
            });

            $this->rateLibrary = [
                'rates'               => $table['rates'],
                'presentation'        => array_combine(array_keys($table['rates']), array_map(
                    fn (string $currency) => ['symbol' => CurrencyCatalog::symbol($currency), 'decimal_places' => CurrencyCatalog::decimalPlaces($currency)],
                    array_keys($table['rates'])
                )),
                'fetched_at'          => $fetchedAt->toIso8601String(),
                'provider_updated_at' => $table['provider_updated_at'],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to persist ExchangeRate-API rate library.', ['exception' => $exception::class]);

            throw $exception;
        }
    }

    /** @param array{rates:array<string,float>,presentation:array<string,array{symbol:string,decimal_places:int}>,fetched_at:?string,provider_updated_at:?string} $library */
    private function wasUpdatedToday(array $library): bool
    {
        if ($library['rates'] === [] || empty($library['fetched_at'])) {
            return false;
        }

        try {
            return now()->isSameDay($library['fetched_at']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function forgetRateLibrary(): void
    {
        $this->rateLibrary = null;
    }

    /** @return array{symbol:string,decimal_places:int} */
    private function presentationFor(string $currency): array
    {
        $presentation = $this->readRateLibrary()['presentation'][$currency] ?? null;

        return is_array($presentation)
            ? $presentation
            : ['symbol' => CurrencyCatalog::symbol($currency), 'decimal_places' => CurrencyCatalog::decimalPlaces($currency)];
    }

    private function providerTimestamp(mixed $timestamp): ?string
    {
        try {
            return is_numeric($timestamp) && (int) $timestamp > 0
                ? Carbon::createFromTimestampUTC((int) $timestamp)->format('Y-m-d H:i:s')
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function setRefreshOutcome(bool $attempted, bool $success, string $reason, int $ratesCount): void
    {
        $this->lastRefreshOutcome = [
            'attempted'   => $attempted,
            'success'     => $success,
            'reason'      => $reason,
            'rates_count' => $ratesCount,
        ];
    }

    private function rateApiKey(): string
    {
        $value = trim((string) system_setting('base.rate_api_key', ''));
        if (! str_starts_with($value, 'encrypted:')) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, strlen('encrypted:'))));
        } catch (\Throwable) {
            Log::warning('Unable to decrypt the saved ExchangeRate-API key.');

            return '';
        }
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        return CurrencyCatalog::isSupported($currency) ? $currency : self::BASE_CURRENCY;
    }

    private function validApiKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key) === 1;
    }

    private function refreshLockKey(): string
    {
        return 'currency:rate-refresh:USD:' . self::runtimeScope();
    }

    private static function runtimeScope(): string
    {
        try {
            $connection = DB::getDefaultConnection();
            $database   = (string) config("database.connections.{$connection}.database", '');
            $prefix     = (string) config("database.connections.{$connection}.prefix", '');
            $tenant     = app()->bound('saas.current_site')
                ? (string) (app('saas.current_site')->getKey() ?? app('saas.current_site')->code ?? '')
                : '';

            return sha1(implode('|', [$connection, $database, $prefix, $tenant]));
        } catch (\Throwable) {
            return sha1('default');
        }
    }
}
