<?php

/**
 * ShippingMethodService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-01 10:54:46
 * @modified   2022-12-01 10:54:46
 */

namespace Beike\Services;

use Beike\Admin\Http\Resources\PluginResource;
use Beike\Repositories\CartRepo;
use Beike\Repositories\PluginRepo;
use Beike\Shop\Services\CheckoutService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ShippingMethodService
{
    /**
     * 获取配送方式, 二维数组, 一个配送插件对应多个配送方式
     *
     * @param CheckoutService $checkout
     * @return array
     * @throws \Exception
     */
    public static function getShippingMethods(CheckoutService $checkout): array
    {
        $shippingPlugins = PluginRepo::getShippingMethods();

        $shippingMethods = [];
        foreach ($shippingPlugins as $shippingPlugin) {
            $pluginCode = (string) $shippingPlugin->code;

            try {
                $plugin     = $shippingPlugin->plugin;
                $pluginName = Str::studly($pluginCode);
                $className  = "Plugin\\{$pluginName}\\Bootstrap";

                if (! class_exists($className) || ! method_exists($className, 'getQuotes')) {
                    Log::warning('Shipping plugin skipped because its quote provider is missing.', [
                        'plugin' => $pluginCode,
                        'class'  => $className,
                    ]);

                    continue;
                }

                $pluginResource = (new PluginResource($plugin))->jsonSerialize();
                $quotes         = (new $className)->getQuotes($checkout, $plugin);
                if (! is_array($quotes)) {
                    continue;
                }
                $quotes    = array_slice($quotes, 0, 100);
                $usedCodes = [];
                $quotes    = array_values(array_filter(array_map(function ($quote) use ($pluginResource, $pluginCode, &$usedCodes) {
                    if (! is_array($quote) || empty($quote['code'])) {
                        return null;
                    }

                    $rawCode = strtolower(trim((string) $quote['code']));
                    if (str_starts_with($rawCode, $pluginCode . '.')) {
                        $rawCode = substr($rawCode, strlen($pluginCode) + 1);
                    }
                    $suffix = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', $rawCode), '_');
                    if ($suffix === '') {
                        return null;
                    }
                    $quote['code'] = $pluginCode . '.' . $suffix;
                    if (isset($usedCodes[$quote['code']])) {
                        Log::warning('Duplicate shipping quote skipped.', [
                            'plugin' => $pluginCode,
                            'quote'  => $quote['code'],
                        ]);

                        return null;
                    }
                    $usedCodes[$quote['code']] = true;

                    $quote['name']        = trim((string) ($quote['name'] ?? '')) ?: ($pluginResource['name'] ?? $quote['code']);
                    $quote['icon']        = (string) ($quote['icon'] ?? ($pluginResource['icon'] ?? ''));
                    $quote['html']        = (string) ($quote['html'] ?? '');
                    $quote['safe_html']   = preg_match('/<[a-z][^>]*>/i', $quote['html']) === 1
                        ? app(ShippingQuoteHtmlSanitizer::class)->sanitize($quote['html'], $quote['code'])
                        : '';
                    $cost                 = (float) ($quote['cost'] ?? 0);
                    $quote['cost']        = is_finite($cost) ? min(1_000_000_000_000, max(0, $cost)) : 0;
                    $quote['cost_format'] = currency_format($quote['cost']);
                    $description          = trim((string) ($quote['description'] ?? ''));
                    if ($description === '') {
                        $description = trim(strip_tags($quote['html']));
                    }
                    if ($description === trim(strip_tags($quote['cost_format']))) {
                        $description = '';
                    }
                    $quote['description'] = $description;

                    return $quote;
                }, $quotes)));
                if ($quotes) {
                    $shippingMethods[] = [
                        'code'   => $pluginCode,
                        'name'   => $pluginResource['name'],
                        'quotes' => $quotes,
                    ];
                }
            } catch (Throwable $exception) {
                // A misconfigured optional shipping extension must not turn
                // the entire checkout into a 500. The merchant still gets a
                // diagnostic entry while healthy providers remain available.
                Log::warning('Shipping plugin quote provider failed.', [
                    'plugin'    => $pluginCode,
                    'exception' => $exception::class,
                ]);

                continue;
            }
        }

        $data = hook_filter('service.shipping_method.data', ['shipping_methods' => $shippingMethods, 'checkout' => $checkout]);

        // Hooks execute after providers and can replace the whole payload.
        // Normalize the final boundary again instead of trusting the earlier
        // provider pass. Only the fields used by checkout leave this service.
        $extra     = is_array($checkout->cart->extra) ? $checkout->cart->extra : [];
        $quoteData = is_array($extra['shipping_quote_data'] ?? null) ? $extra['shipping_quote_data'] : [];

        return self::normalizeForCheckout($data['shipping_methods'] ?? [], $quoteData);
    }

    /**
     * 获取配送方式, 二维数组, 一个配送插件对应多个配送方式
     *
     * @param CheckoutService $checkout
     * @return array
     * @throws \Exception
     */
    public static function getShippingMethodsForCurrentCart(CheckoutService $checkout): array
    {
        $customerId = current_customer()->id ?? 0;
        if (! CartRepo::shippingRequired($customerId)) {
            return [];
        }

        return self::getShippingMethods($checkout);
    }

    /**
     * 获取当前使用的配送方式
     *
     * @param $shipments
     * @param $methodCode
     * @return void
     */
    public static function getCurrentQuote($shipments, $methodCode)
    {
        if (empty($shipments) || empty($methodCode)) {
            return null;
        }
        foreach ($shipments as $method) {
            $quotes = is_array($method) && is_array($method['quotes'] ?? null) ? $method['quotes'] : [];
            foreach ($quotes as $quote) {
                if (is_array($quote) && ($quote['code'] ?? null) === $methodCode) {
                    return $quote;
                }
            }
        }

        return null;
    }

    /**
     * Final, hook-safe shipping contract consumed by the checkout UI and
     * totals service.
     *
     * @return array<int, array{code: string, name: string, quotes: array<int, array<string, mixed>>}>
     */
    public static function normalizeForCheckout(mixed $methods, array $quoteData = []): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $sanitizer = app(ShippingQuoteHtmlSanitizer::class);
        $result    = [];
        $usedCodes = [];

        foreach (array_slice($methods, 0, 50) as $method) {
            if (! is_array($method) || ! is_array($method['quotes'] ?? null)) {
                continue;
            }

            $methodCode = self::safeCode($method['code'] ?? '');
            if ($methodCode === '') {
                continue;
            }

            $methodName = self::safeText($method['name'] ?? '', 160) ?: $methodCode;
            $quotes     = [];
            foreach (array_slice($method['quotes'], 0, 100) as $quote) {
                if (! is_array($quote)) {
                    continue;
                }

                $rawCode = strtolower(self::scalarString($quote['code'] ?? ''));
                if (str_starts_with($rawCode, $methodCode . '.')) {
                    $rawCode = substr($rawCode, strlen($methodCode) + 1);
                }
                $suffix = self::safeCode($rawCode, 100);
                if ($suffix === '') {
                    continue;
                }

                $code = $methodCode . '.' . $suffix;
                if (isset($usedCodes[$code])) {
                    Log::warning('Duplicate shipping quote skipped after checkout hook normalization.', [
                        'quote' => $code,
                    ]);

                    continue;
                }
                $usedCodes[$code] = true;

                $rawCost  = $quote['cost'] ?? 0;
                $cost     = is_numeric($rawCost) ? (float) $rawCost : 0.0;
                $cost     = is_finite($cost) ? round(min(1_000_000_000_000, max(0, $cost)), 4) : 0.0;
                $html     = self::scalarString($quote['html'] ?? $quote['safe_html'] ?? '');
                $safeHtml = preg_match('/<[a-z][^>]*>/i', $html) === 1
                    ? $sanitizer->sanitize($html, $code)
                    : '';
                if ($safeHtml !== '' && hash_equals($code, (string) ($quoteData['code'] ?? ''))) {
                    $safeHtml = $sanitizer->applyFieldValues($safeHtml, (array) ($quoteData['fields'] ?? []));
                }

                $description = self::safeText($quote['description'] ?? '', 1000);
                if ($description === '') {
                    $description = self::safeText(strip_tags($html), 1000);
                }

                $costFormat = currency_format($cost);
                if ($description === self::safeText(strip_tags($costFormat), 1000)) {
                    $description = '';
                }

                $quotes[] = [
                    'type'            => self::safeText($quote['type'] ?? 'shipping', 40) ?: 'shipping',
                    'code'            => $code,
                    'name'            => self::safeText($quote['name'] ?? '', 160) ?: $methodName,
                    'description'     => $description,
                    'icon'            => self::safeIcon($quote['icon'] ?? ''),
                    'cost'            => $cost,
                    'cost_format'     => $costFormat,
                    'safe_html'       => $safeHtml,
                    'field_names'     => $sanitizer->fieldNames($safeHtml),
                    'required_fields' => $sanitizer->requiredFieldNames($safeHtml),
                ];
            }

            if ($quotes) {
                $result[] = [
                    'code'   => $methodCode,
                    'name'   => $methodName,
                    'quotes' => $quotes,
                ];
            }
        }

        return $result;
    }

    public static function quoteDataIsComplete(array $quote, array $quoteData): bool
    {
        $required = array_values(array_filter(
            (array) ($quote['required_fields'] ?? []),
            fn ($name) => is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) === 1,
        ));
        if (! $required) {
            return true;
        }

        if (! hash_equals((string) ($quote['code'] ?? ''), (string) ($quoteData['code'] ?? ''))
            || ! is_array($quoteData['fields'] ?? null)) {
            return false;
        }

        foreach ($required as $name) {
            $value = $quoteData['fields'][$name] ?? null;
            if ((! is_scalar($value) && $value !== null) || trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Retain only controls that were actually declared by the selected quote.
     */
    public static function filterQuoteData(array $quote, array $quoteData): array
    {
        $allowed = array_fill_keys(array_values(array_filter(
            (array) ($quote['field_names'] ?? []),
            fn ($name) => is_string($name) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $name) === 1,
        )), true);
        $fields = [];
        foreach (array_slice((array) ($quoteData['fields'] ?? []), 0, 50, true) as $name => $value) {
            if (isset($allowed[$name]) && (is_scalar($value) || $value === null)) {
                $fields[$name] = mb_substr(trim((string) $value), 0, 1000);
            }
        }

        return [
            'code'   => (string) ($quote['code'] ?? ''),
            'fields' => $fields,
        ];
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) || $value === null ? trim((string) $value) : '';
    }

    private static function safeText(mixed $value, int $length): string
    {
        $text = html_entity_decode(strip_tags(self::scalarString($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text) ?? '';

        return mb_substr(trim($text), 0, $length);
    }

    private static function safeCode(mixed $value, int $length = 80): string
    {
        $code = strtolower(self::scalarString($value));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? '';

        return mb_substr(trim($code, '_'), 0, $length);
    }

    private static function safeIcon(mixed $value): string
    {
        $icon = mb_substr(self::scalarString($value), 0, 2048);
        if ($icon === '' || preg_match('/[\x00-\x1F\x7F]/', $icon) === 1) {
            return '';
        }
        if (str_starts_with($icon, '/') && ! str_starts_with($icon, '//')) {
            return $icon;
        }

        $scheme = strtolower((string) parse_url($icon, PHP_URL_SCHEME));
        if ($scheme === '') {
            return str_starts_with($icon, '//') ? '' : $icon;
        }

        return in_array($scheme, ['http', 'https'], true) ? $icon : '';
    }
}
