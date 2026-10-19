<?php

namespace Beike\Services;

class CurrencyCatalog
{
    /** ExchangeRate-API's published ISO 4217 code list; KPW is intentionally excluded. */
    private const SUPPORTED_CODES = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLF CLP CNH CNY COP CRC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP FOK GBP GEL GGP GHS GIP GMD GNF GTQ GYD HKD HNL HRK HTG HUF IDR ILS IMP INR IQD IRR ISK JEP JMD JOD JPY KES KGS KHR KID KMF KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SYP SZL THB TJS TMT TND TOP TRY TTD TVD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST XAF XCD XDR XOF XPF YER ZAR ZMW ZWL';

    private const FALLBACK_SYMBOLS = [
        'AED' => 'د.إ', 'AFN' => '؋', 'ALL' => 'L', 'AMD' => '֏', 'ANG' => 'NAƒ', 'AOA' => 'Kz', 'ARS' => 'AR$',
        'AUD' => 'A$', 'AWG' => 'Afl.', 'AZN' => '₼', 'BAM' => 'KM', 'BDT' => '৳', 'BGN' => 'лв', 'BHD' => 'د.ب',
        'BND' => 'B$', 'BOB' => 'Bs.', 'BRL' => 'R$', 'BTN' => 'Nu.', 'BYN' => 'Br', 'CAD' => 'CA$', 'CDF' => 'FC',
        'CHF' => 'CHF', 'CLF' => 'UF', 'CLP' => 'CLP$', 'CNH' => 'CN¥', 'CNY' => '¥', 'COP' => 'COL$', 'CRC' => '₡',
        'CUP' => 'CUP$', 'CVE' => 'CVE', 'CZK' => 'Kč', 'DJF' => 'Fdj', 'DOP' => 'RD$', 'DZD' => 'د.ج', 'EGP' => 'E£',
        'ETB' => 'Br', 'EUR' => '€', 'FOK' => 'kr', 'GEL' => '₾', 'GGP' => '£', 'GNF' => 'FG', 'GBP' => '£', 'GTQ' => 'Q',
        'HKD' => 'HK$', 'HNL' => 'L', 'HRK' => 'kn', 'HTG' => 'G', 'HUF' => 'Ft', 'IDR' => 'Rp', 'IMP' => '£', 'INR' => '₹',
        'IQD' => 'ع.د', 'IRR' => '﷼', 'ISK' => 'kr', 'JEP' => '£', 'JOD' => 'د.ا', 'JPY' => '¥', 'KGS' => 'сом', 'KHR' => '៛', 'KID' => '$',
        'KMF' => 'CF', 'KRW' => '₩', 'KWD' => 'د.ك', 'KZT' => '₸', 'LAK' => '₭', 'LBP' => 'ل.ل', 'LKR' => 'Rs', 'LSL' => 'L',
        'LYD' => 'ل.د', 'MAD' => 'د.م.', 'MDL' => 'L', 'MKD' => 'ден', 'MMK' => 'Ks', 'MNT' => '₮', 'MRU' => 'UM', 'MXN' => 'MX$',
        'MYR' => 'RM', 'MZN' => 'MT', 'NIO' => 'C$', 'NPR' => 'रू', 'NZD' => 'NZ$', 'OMR' => 'ر.ع.', 'PAB' => 'B/.', 'PEN' => 'S/',
        'PHP' => '₱', 'PLN' => 'zł', 'PYG' => '₲', 'QAR' => 'ر.ق', 'RON' => 'lei', 'RSD' => 'дин.', 'RUB' => '₽', 'SAR' => 'ر.س',
        'SBD' => 'SI$', 'SDG' => 'ج.س.', 'SGD' => 'S$', 'SOS' => 'Sh.So.', 'SRD' => 'Sr$', 'SSP' => '£', 'STN' => 'Db', 'SYP' => '£S',
        'THB' => '฿', 'TJS' => 'ЅМ', 'TMT' => 'm', 'TND' => 'د.ت', 'TRY' => '₺', 'TVD' => 'TV$', 'TWD' => 'NT$', 'UAH' => '₴',
        'UYU' => '$U', 'USD' => '$', 'UZS' => "so'm", 'VES' => 'Bs.S', 'VND' => '₫', 'XDR' => 'SDR', 'YER' => 'ر.ي', 'ZAR' => 'R',
        'ZWL' => 'Z$',
        // Less common ISO currencies deliberately remain explicit too. This
        // prevents a server's ICU package from changing storefront output.
        'BBD' => 'Bds$', 'BIF' => 'FBu', 'BMD' => 'BD$', 'BSD' => 'B$', 'BWP' => 'P', 'BZD' => 'BZ$', 'DKK' => 'kr',
        'ERN' => 'Nfk', 'FJD' => 'FJ$', 'FKP' => '£', 'GHS' => 'GH₵', 'GIP' => '£', 'GMD' => 'D', 'GYD' => 'G$', 'ILS' => '₪',
        'JMD' => 'J$', 'KES' => 'KSh', 'KYD' => 'CI$', 'LRD' => 'L$', 'MGA' => 'Ar', 'MOP' => 'MOP$', 'MUR' => '₨',
        'MVR' => 'Rf', 'MWK' => 'MK', 'NAD' => 'N$', 'NGN' => '₦', 'NOK' => 'kr', 'PGK' => 'K', 'PKR' => '₨', 'RWF' => 'FRw',
        'SCR' => '₨', 'SEK' => 'kr', 'SHP' => '£', 'SLE' => 'Le', 'SZL' => 'L', 'TOP' => 'T$', 'TTD' => 'TT$', 'TZS' => 'TSh',
        'UGX' => 'USh', 'VUV' => 'VT', 'WST' => 'WS$', 'XAF' => 'FCFA', 'XCD' => 'EC$', 'XOF' => 'CFA', 'XPF' => 'CFPF', 'ZMW' => 'ZK',
    ];

    public static function isSupported(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::codes(), true);
    }

    /** @return string[] */
    public static function codes(): array
    {
        return explode(' ', self::SUPPORTED_CODES);
    }

    public static function decimalPlaces(string $code): int
    {
        $code = strtoupper(trim($code));
        if (in_array($code, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true)) {
            return 3;
        }

        return in_array($code, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 0 : 2;
    }

    public static function symbol(string $code): string
    {
        $code = strtoupper(trim($code));

        // Prefer LONGOS' reviewed symbols. ICU chooses symbols from a locale,
        // and one currency can belong to many countries, so the same store
        // previously rendered values such as $, US$ or USD on different
        // servers. The catalog makes storefront output deterministic.
        return self::FALLBACK_SYMBOLS[$code] ?? $code;
    }

    public static function locale(string $code): string
    {
        foreach (config('currency.country_currency', []) as $country => $currency) {
            if ($currency === $code) {
                return $country === 'CN' ? 'zh_CN' : 'en_' . $country;
            }
        }

        return 'en_US';
    }
}
