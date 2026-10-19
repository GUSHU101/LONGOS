<?php

declare(strict_types=1);

namespace Plugin\BeikeShopTestSandbox\Support;

/**
 * Single source of truth for synthetic test-card boundaries.
 *
 * These are deliberately non-routable sandbox PANs. No caller should send a
 * value outside this list to a third-party BIN provider.
 */
final class TestCardCatalog
{
    public const MODE_WHITELIST = 'whitelist';

    public const MODE_OPEN = 'open';

    private const ALLOWED_PANS = [
        '4242424242424242',
        '4000056655665556',
        '5555555555554444',
        '5200828282828210',
        '5105105105105100',
        '378282246310005',
        '3566002020360505',
        '3530111333300000',
        '6200000000000005',
        '6200000000000047',
        '9900000000000000',
    ];

    private const LOCAL_ONLY_PANS = [
        '9900000000000000',
    ];

    public static function normalizePan(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    public static function inputMode(): string
    {
        // A saved plugin setting is the primary runtime control. The config
        // fallback keeps existing installations deterministic until the admin
        // opens and saves the plugin settings page for the first time.
        $mode = function_exists('plugin_setting')
            ? plugin_setting('beike_shop_test_sandbox.card_input_mode', '')
            : '';

        if ($mode === '' || $mode === null) {
            $mode = function_exists('config')
                ? config('beikeshop_test.card_input.mode', self::MODE_WHITELIST)
                : (getenv('BEIKESHOP_TEST_CARD_MODE') ?: self::MODE_WHITELIST);
        }
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, [self::MODE_WHITELIST, self::MODE_OPEN], true)
            ? $mode
            : self::MODE_WHITELIST;
    }

    public static function isOpenMode(): bool
    {
        return self::inputMode() === self::MODE_OPEN;
    }

    public static function allowsPan(string $value): bool
    {
        $pan = self::normalizePan($value);

        return preg_match('/^\d{12,19}$/', $pan) === 1
            && (self::isOpenMode() || self::isAllowedPan($pan));
    }

    public static function allowsProviderBin(string $value): bool
    {
        $bin = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{6,8}$/', $bin) === 1
            && (self::isOpenMode() || self::isAllowedProviderBin($bin));
    }

    public static function isAllowedPan(string $value): bool
    {
        return in_array(self::normalizePan($value), self::ALLOWED_PANS, true);
    }

    public static function isLocalOnlyPan(string $value): bool
    {
        return in_array(self::normalizePan($value), self::LOCAL_ONLY_PANS, true);
    }

    /**
     * Eight-digit prefixes that may be sent to the BIN provider.
     * Local-only gift-card identifiers are intentionally excluded.
     *
     * @return array<int, string>
     */
    public static function providerWarmBins(): array
    {
        $bins = [];

        foreach (self::ALLOWED_PANS as $pan) {
            if (in_array($pan, self::LOCAL_ONLY_PANS, true)) {
                continue;
            }

            $bins[] = substr($pan, 0, 8);
        }

        return array_values(array_unique($bins));
    }

    public static function isAllowedProviderBin(string $value): bool
    {
        $bin = (string) preg_replace('/\D+/', '', $value);

        if (strlen($bin) < 6 || strlen($bin) > 8) {
            return false;
        }

        foreach (self::providerWarmBins() as $allowedBin) {
            if (str_starts_with($allowedBin, $bin)) {
                return true;
            }
        }

        return false;
    }
}
