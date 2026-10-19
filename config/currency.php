<?php

/*
 * Currency localisation is deliberately configured in the application, not
 * through a browser-side exchange-rate widget.  Prices, carts and orders all
 * use the same server-side rate snapshot.
 */

return [
    /*
     * Cloudflare can provide a country code derived from the visitor IP via
     * CF-IPCountry. This defaults to enabled, but the application accepts the
     * header only when the direct peer is a known Cloudflare IP range. The
     * origin firewall must likewise admit Cloudflare ranges only.
     */
    'trust_cdn_country_headers' => filter_var(env('CURRENCY_TRUST_CDN_COUNTRY_HEADERS', true), FILTER_VALIDATE_BOOL),

    // Optional offline fallback when storefront traffic does not pass through
    // Cloudflare. The web application never downloads this database itself.
    'geolite2_path' => env('CURRENCY_GEOLITE2_PATH', storage_path('app/GeoLite2-Country.mmdb')),

    // A successful provider response must be a complete market snapshot. A
    // tiny but syntactically valid response must never replace a healthy table
    // and silently force most countries back to USD.
    'minimum_rate_count'  => max(20, (int) env('CURRENCY_MINIMUM_RATE_COUNT', 100)),
    'required_rate_codes' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CNY'],

    'country_currency' => [
        // ExchangeRate-API does not yet publish XCG. Curaçao/Sint Maarten use
        // the widely accepted USD fallback instead of the retired ANG code.
        'AD' => 'EUR', 'AE' => 'AED', 'AF' => 'AFN', 'AG' => 'XCD', 'AI' => 'XCD', 'AL' => 'ALL', 'AM' => 'AMD', 'AO' => 'AOA', 'AR' => 'ARS', 'AT' => 'EUR', 'AU' => 'AUD', 'AW' => 'AWG', 'AZ' => 'AZN',
        'BA' => 'BAM', 'BB' => 'BBD', 'BD' => 'BDT', 'BE' => 'EUR', 'BF' => 'XOF', 'BG' => 'EUR', 'BH' => 'BHD', 'BI' => 'BIF', 'BJ' => 'XOF', 'BM' => 'BMD', 'BN' => 'BND', 'BO' => 'BOB', 'BR' => 'BRL', 'BS' => 'BSD', 'BT' => 'BTN', 'BW' => 'BWP', 'BY' => 'BYN', 'BZ' => 'BZD',
        'CA' => 'CAD', 'CD' => 'CDF', 'CF' => 'XAF', 'CG' => 'XAF', 'CH' => 'CHF', 'CI' => 'XOF', 'CL' => 'CLP', 'CM' => 'XAF', 'CN' => 'CNY', 'CO' => 'COP', 'CR' => 'CRC', 'CV' => 'CVE', 'CW' => 'USD', 'CY' => 'EUR', 'CZ' => 'CZK',
        'DE' => 'EUR', 'DJ' => 'DJF', 'DK' => 'DKK', 'DM' => 'XCD', 'DO' => 'DOP', 'DZ' => 'DZD',
        'EC' => 'USD', 'EE' => 'EUR', 'EG' => 'EGP', 'ER' => 'ERN', 'ES' => 'EUR', 'ET' => 'ETB',
        'FI' => 'EUR', 'FJ' => 'FJD', 'FK' => 'FKP', 'FO' => 'DKK', 'FR' => 'EUR',
        'GA' => 'XAF', 'GB' => 'GBP', 'GD' => 'XCD', 'GE' => 'GEL', 'GG' => 'GBP', 'GH' => 'GHS', 'GI' => 'GIP', 'GM' => 'GMD', 'GN' => 'GNF', 'GQ' => 'XAF', 'GR' => 'EUR', 'GT' => 'GTQ', 'GY' => 'GYD',
        'HK' => 'HKD', 'HN' => 'HNL', 'HR' => 'EUR', 'HT' => 'HTG', 'HU' => 'HUF',
        'ID' => 'IDR', 'IE' => 'EUR', 'IL' => 'ILS', 'IM' => 'GBP', 'IN' => 'INR', 'IQ' => 'IQD', 'IS' => 'ISK', 'IT' => 'EUR',
        'JE' => 'GBP', 'JM' => 'JMD', 'JO' => 'JOD', 'JP' => 'JPY',
        'KE' => 'KES', 'KG' => 'KGS', 'KH' => 'KHR', 'KM' => 'KMF', 'KN' => 'XCD', 'KR' => 'KRW', 'KW' => 'KWD', 'KY' => 'KYD', 'KZ' => 'KZT',
        'LA' => 'LAK', 'LB' => 'LBP', 'LC' => 'XCD', 'LI' => 'CHF', 'LK' => 'LKR', 'LR' => 'LRD', 'LS' => 'LSL', 'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'LY' => 'LYD',
        'MA' => 'MAD', 'MC' => 'EUR', 'MD' => 'MDL', 'ME' => 'EUR', 'MG' => 'MGA', 'MK' => 'MKD', 'ML' => 'XOF', 'MM' => 'MMK', 'MN' => 'MNT', 'MO' => 'MOP', 'MR' => 'MRU', 'MT' => 'EUR', 'MU' => 'MUR', 'MV' => 'MVR', 'MW' => 'MWK', 'MX' => 'MXN', 'MY' => 'MYR', 'MZ' => 'MZN',
        'NA' => 'NAD', 'NE' => 'XOF', 'NG' => 'NGN', 'NI' => 'NIO', 'NL' => 'EUR', 'NO' => 'NOK', 'NP' => 'NPR', 'NZ' => 'NZD',
        'OM' => 'OMR',
        'PA' => 'PAB', 'PE' => 'PEN', 'PG' => 'PGK', 'PH' => 'PHP', 'PK' => 'PKR', 'PL' => 'PLN', 'PR' => 'USD', 'PT' => 'EUR', 'PY' => 'PYG',
        'QA' => 'QAR',
        'RO' => 'RON', 'RS' => 'RSD', 'RU' => 'RUB', 'RW' => 'RWF',
        'SA' => 'SAR', 'SC' => 'SCR', 'SD' => 'SDG', 'SE' => 'SEK', 'SG' => 'SGD', 'SH' => 'SHP', 'SI' => 'EUR', 'SK' => 'EUR', 'SL' => 'SLE', 'SM' => 'EUR', 'SN' => 'XOF', 'SO' => 'SOS', 'SR' => 'SRD', 'ST' => 'STN', 'SV' => 'USD', 'SZ' => 'SZL',
        'TC' => 'USD', 'TD' => 'XAF', 'TG' => 'XOF', 'TH' => 'THB', 'TJ' => 'TJS', 'TL' => 'USD', 'TN' => 'TND', 'TO' => 'TOP', 'TR' => 'TRY', 'TT' => 'TTD', 'TW' => 'TWD', 'TZ' => 'TZS',
        'UA' => 'UAH', 'UG' => 'UGX', 'US' => 'USD', 'UY' => 'UYU', 'UZ' => 'UZS',
        'VC' => 'XCD', 'VE' => 'VES', 'VG' => 'USD', 'VN' => 'VND', 'VU' => 'VUV',
        'WS' => 'WST',
        'XK' => 'EUR',
        'ZA' => 'ZAR', 'ZM' => 'ZMW', 'ZW' => 'ZWL',

        // Territories and legacy ISO codes still returned by Cloudflare or
        // present in LONGOS's country catalogue. Keeping these mappings here
        // prevents a valid visitor country from silently falling back to USD.
        'AC' => 'SHP', 'AN' => 'USD', 'AQ' => 'USD', 'AS' => 'USD', 'AX' => 'EUR',
        'BL' => 'EUR', 'BQ' => 'USD', 'BV' => 'NOK',
        'CC' => 'AUD', 'CK' => 'NZD', 'CU' => 'CUP', 'CX' => 'AUD',
        'EH' => 'MAD',
        'FM' => 'USD',
        'GF' => 'EUR', 'GL' => 'DKK', 'GP' => 'EUR', 'GS' => 'GBP', 'GU' => 'USD', 'GW' => 'XOF',
        'HM' => 'AUD',
        'IC' => 'EUR', 'IO' => 'USD', 'IR' => 'IRR',
        'KI' => 'AUD', 'KP' => 'USD',
        'MF' => 'EUR', 'MH' => 'USD', 'MP' => 'USD', 'MQ' => 'EUR', 'MS' => 'XCD',
        'NC' => 'XPF', 'NF' => 'AUD', 'NR' => 'AUD', 'NU' => 'NZD',
        'PF' => 'XPF', 'PM' => 'EUR', 'PN' => 'NZD', 'PS' => 'ILS', 'PW' => 'USD',
        'RE' => 'EUR',
        'SB' => 'SBD', 'SJ' => 'NOK', 'SS' => 'SSP', 'SX' => 'USD', 'SY' => 'SYP',
        'TA' => 'GBP', 'TF' => 'EUR', 'TK' => 'NZD', 'TM' => 'TMT', 'TV' => 'AUD',
        'UM' => 'USD',
        'VA' => 'EUR', 'VI' => 'USD',
        'WF' => 'XPF',
        'YE' => 'YER', 'YT' => 'EUR',
    ],
];
