<?php

namespace Plugin\FlatShipping;

use Beike\Admin\Http\Resources\PluginResource;
use Beike\Models\Country;
use Beike\Models\Zone;
use Beike\Plugin\Plugin;
use Beike\Shop\Services\CheckoutService;

class Bootstrap
{
    public function getQuotes(CheckoutService $checkout, Plugin $plugin): array
    {
        $resource = (new PluginResource($plugin))->jsonSerialize();
        $quotes   = [];
        foreach ($this->resolvedRules($checkout) as $item) {
            $rule     = $item['rule'];
            $suffix   = $item['code'];
            $cost     = $this->calculateFee($checkout, $rule);
            $quotes[] = [
                'type' => 'shipping',
                'code' => $plugin->code . '.' . $suffix,
                // Older saved rules and the built-in default may not contain
                // optional display fields. Always fall back to plugin labels.
                'name'        => ($rule['name'] ?? '') ?: ($resource['name'] ?? $plugin->code),
                'description' => ($rule['description'] ?? '') ?: ($resource['description'] ?? ''),
                'icon'        => $resource['icon'],
                'cost'        => $cost,
                'html'        => ($rule['description'] ?? '') ?: ($resource['description'] ?? ''),
            ];
        }

        return $quotes;
    }

    public function getShippingFee(CheckoutService $checkout): float
    {
        $selected = $checkout->cart->shipping_method_code;
        foreach ($this->resolvedRules($checkout) as $item) {
            $rule   = $item['rule'];
            $suffix = $item['code'];
            if ($selected === 'flat_shipping.' . $suffix) {
                return $this->calculateFee($checkout, $rule);
            }
        }

        return 0.0;
    }

    private function allRules(): array
    {
        $default = [
            'code' => '0',
            // A fresh installation must always expose one selectable quote.
            // Merchants can replace this safe fallback with detailed rules in
            // the visual editor, but an empty editor must never block checkout.
            'name'                    => 'Standard Shipping',
            'description'             => 'Delivery rate calculated at checkout',
            'type'                    => plugin_setting('flat_shipping.type', 'fixed'),
            'value'                   => plugin_setting('flat_shipping.value', 0),
            'handling_fee'            => plugin_setting('flat_shipping.handling_fee', 0),
            'min_order'               => plugin_setting('flat_shipping.min_order', 0),
            'max_order'               => plugin_setting('flat_shipping.max_order', 0),
            'free_shipping_threshold' => plugin_setting('flat_shipping.free_shipping_threshold', 0),
            'weight_initial'          => plugin_setting('flat_shipping.weight_initial', 0),
            'weight_step'             => plugin_setting('flat_shipping.weight_step', 1),
            'weight_rate'             => plugin_setting('flat_shipping.weight_rate', 0),
            'countries'               => plugin_setting('flat_shipping.country_codes', ''),
        ];
        if ($this->hasStructuredRules()) {
            $rules = $this->configuredRules();
        } else {
            // Legacy installations always exposed the default method plus
            // every configured regional method. Preserve that exact behavior
            // until the merchant saves the new visual editor for the first time.
            $rules = array_merge($this->normalizeRules([$default]), $this->legacyRules());
        }

        return $rules;
    }

    /**
     * The visual editor stores all shipping methods in shipping_rules. Keep
     * zone_rules as a migration-free fallback for stores configured before
     * this upgrade, so existing checkout rates are never silently removed.
     */
    private function hasStructuredRules(): bool
    {
        $raw   = plugin_setting('flat_shipping.shipping_rules', '');
        $rules = is_array($raw) ? $raw : json_decode((string) $raw, true);

        // The visual editor initializes this setting as "[]". That means no
        // custom methods have been created yet, not that all delivery options
        // should disappear. Keep the legacy/default quote available.
        return is_array($rules) && count($rules) > 0;
    }

    private function configuredRules(): array
    {
        $raw   = plugin_setting('flat_shipping.shipping_rules', '[]');
        $rules = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeRules($rules);
    }

    private function legacyRules(): array
    {
        $legacy = plugin_setting('flat_shipping.zone_rules', '[]');
        $rules  = is_array($legacy) ? $legacy : json_decode((string) $legacy, true);

        return $this->normalizeRules($rules, 'region_');
    }

    private function normalizeRules(mixed $rules, string $prefix = 'method_'): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];
        foreach ($rules as $position => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $item = array_merge([
                'code'             => $prefix . ($position + 1),
                'name'             => '',
                'description'      => '',
                'enabled'          => true,
                'type'             => 'fixed',
                'value'            => 0,
                'handling_fee'     => 0,
                'quantity_initial' => 0,
                'quantity_step'    => 1,
                'quantity_rate'    => 0,
                '_position'        => (int) $position,
            ], $rule);
            $fallbackCode        = $prefix . ($position + 1);
            $item['code']        = mb_substr($this->scalar($item['code']), 0, 80) ?: $fallbackCode;
            $item['name']        = mb_substr($this->scalar($item['name']), 0, 160);
            $item['description'] = mb_substr($this->scalar($item['description']), 0, 1000);
            $item['enabled']     = filter_var($item['enabled'], FILTER_VALIDATE_BOOLEAN);
            $item['type']        = in_array($item['type'] ?? '', ['fixed', 'percent', 'weight', 'quantity', 'free'], true)
                ? $item['type']
                : 'fixed';
            foreach ([
                'value', 'handling_fee', 'min_order', 'max_order',
                'free_shipping_threshold', 'weight_initial', 'weight_step',
                'weight_rate', 'min_weight', 'max_weight', 'quantity_initial',
                'quantity_step', 'quantity_rate', 'min_quantity', 'max_quantity',
            ] as $number) {
                $item[$number] = $this->number($item[$number] ?? 0);
            }
            $item['_position'] = (int) $position;
            $normalized[]      = $item;
        }

        usort($normalized, function (array $left, array $right) {
            $order = (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);

            return $order !== 0 ? $order : (int) ($left['_position'] ?? 0) <=> (int) ($right['_position'] ?? 0);
        });
        foreach ($normalized as &$rule) {
            unset($rule['_position']);
        }
        unset($rule);

        return $normalized;
    }

    /**
     * Shipping quote codes are persisted in the cart. A duplicate merchant
     * rule code would otherwise show one quote but calculate another rule's
     * fee during order confirmation. Give each eligible rule a stable unique
     * suffix for both display and fee lookup. Codes are assigned before
     * eligibility filtering so changing address or cart totals cannot make a
     * surviving duplicate silently inherit another rule's persisted code.
     */
    private function resolvedRules(CheckoutService $checkout): array
    {
        $resolved = [];
        $country  = $this->countryCode($checkout);
        $zone     = $this->zoneCode($checkout);
        $postcode = $this->postcode($checkout);
        $subtotal = (float) $checkout->totalService->getSubTotal();
        $weight   = $checkout->getCartWeight();
        $quantity = $checkout->totalService->countProducts();

        foreach ($this->assignRuleCodes($this->allRules()) as $item) {
            $rule     = $item['rule'];
            $eligible = (! array_key_exists('enabled', $rule) || filter_var($rule['enabled'], FILTER_VALIDATE_BOOLEAN))
                && $this->matchesLocation($rule, $country, $zone, $postcode)
                && $this->inRange($subtotal, $rule['min_order'] ?? 0, $rule['max_order'] ?? 0)
                && $this->inRange($weight, $rule['min_weight'] ?? 0, $rule['max_weight'] ?? 0)
                && $this->inRange($quantity, $rule['min_quantity'] ?? 0, $rule['max_quantity'] ?? 0);
            if (! $eligible) {
                continue;
            }

            $resolved[] = $item;
        }

        return $resolved;
    }

    /**
     * @return array<int, array{code: string, rule: array<string, mixed>}>
     */
    private function assignRuleCodes(array $rules): array
    {
        $resolved = [];
        $used     = [];
        foreach (array_values($rules) as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $base = $this->ruleCode($rule, $index);
            $code = $base;
            $copy = 2;
            while (isset($used[$code])) {
                $code = $base . '_' . $copy++;
            }
            $used[$code] = true;
            $resolved[]  = ['code' => $code, 'rule' => $rule];
        }

        return $resolved;
    }

    private function calculateFee(CheckoutService $checkout, array $rule): float
    {
        $subtotal = (float) $checkout->totalService->getSubTotal();
        $freeAt   = $this->number($rule['free_shipping_threshold'] ?? 0);
        if ($freeAt > 0 && $subtotal >= $freeAt) {
            return 0.0;
        }

        $value = $this->number($rule['value'] ?? 0);
        $fee   = match ($rule['type'] ?? 'fixed') {
            'percent'  => $subtotal * $value / 100,
            'weight'   => $this->weightFee($checkout->getCartWeight(), $value, $rule),
            'quantity' => $this->quantityFee($checkout->totalService->countProducts(), $value, $rule),
            'free'     => 0.0,
            default    => $value,
        };

        $fee = $fee + $this->number($rule['handling_fee'] ?? 0);

        return round(is_finite($fee) ? min(1_000_000_000_000, max(0, $fee)) : 0, 2);
    }

    private function weightFee(float $weight, float $base, array $rule): float
    {
        $initial = $this->number($rule['weight_initial'] ?? 0);
        $step    = $this->number($rule['weight_step'] ?? 0);
        $rate    = $this->number($rule['weight_rate'] ?? 0);
        if ($weight <= $initial || $step <= 0 || $rate <= 0) {
            return $base;
        }

        return $base + ceil(($weight - $initial) / $step) * $rate;
    }

    private function quantityFee(float $quantity, float $base, array $rule): float
    {
        $initial = $this->number($rule['quantity_initial'] ?? 0);
        $step    = $this->number($rule['quantity_step'] ?? 0);
        $rate    = $this->number($rule['quantity_rate'] ?? 0);
        if ($quantity <= $initial || $step <= 0 || $rate <= 0) {
            return $base;
        }

        return $base + ceil(($quantity - $initial) / $step) * $rate;
    }

    private function matchesLocation(array $rule, string $country, string $zone, string $postcode): bool
    {
        $countries = $this->codes($rule['countries'] ?? $rule['country_codes'] ?? []);
        $zones     = $this->codes($rule['zones'] ?? $rule['zone_codes'] ?? []);
        $postcodes = $this->codes($rule['postcodes'] ?? []);

        return (! $countries || in_array($country, $countries, true))
            && (! $zones || in_array($zone, $zones, true))
            && (! $postcodes || in_array($postcode, $postcodes, true));
    }

    /**
     * A rule code becomes part of the persisted shipping-method code. Normalize
     * merchant-entered JSON once so it is stable, URL-safe and cannot collide
     * with the plugin namespace.
     */
    private function ruleCode(array $rule, int $index): string
    {
        $fallback = $index ? 'region_' . $index : '0';
        $code     = strtolower(trim((string) ($rule['code'] ?? $fallback)));
        $code     = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?: $fallback;

        return trim($code, '_') ?: $fallback;
    }

    private function inRange(float $value, mixed $min, mixed $max): bool
    {
        $minimum = $this->number($min);
        $maximum = $this->number($max);

        return $value >= $minimum && ($maximum === 0.0 || $value <= $maximum);
    }

    private function codes(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            // Settings are persisted as JSON by some installation paths. The
            // old implementation treated the empty JSON list "[]" as a real
            // country code, which filtered out every shipping quote.
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        $values = is_array($value) ? $value : preg_split('/[,\s]+/', $this->scalar($value));
        $values = array_map(fn ($code) => strtoupper($this->scalar($code)), $values ?: []);

        return array_values(array_filter($values, fn (string $code) => $code !== '' && ! in_array($code, ['[]', 'NULL', 'ALL', '*'], true)));
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) || $value === null ? trim((string) $value) : '';
    }

    private function number(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $number = (float) $value;

        return is_finite($number) ? min(1_000_000_000_000, max(0, $number)) : 0.0;
    }

    private function countryCode(CheckoutService $checkout): string
    {
        $address   = $checkout->cart->shippingAddress ?: $checkout->cart->guest_shipping_address;
        $countryId = is_array($address) ? ($address['country_id'] ?? 0) : ($address->country_id ?? 0);

        return strtoupper((string) Country::query()->whereKey($countryId)->value('code'));
    }

    private function zoneCode(CheckoutService $checkout): string
    {
        $address = $checkout->cart->shippingAddress ?: $checkout->cart->guest_shipping_address;
        $zoneId  = is_array($address) ? ($address['zone_id'] ?? 0) : ($address->zone_id ?? 0);

        return strtoupper((string) Zone::query()->whereKey($zoneId)->value('code'));
    }

    private function postcode(CheckoutService $checkout): string
    {
        $address  = $checkout->cart->shippingAddress ?: $checkout->cart->guest_shipping_address;
        $postcode = is_array($address) ? ($address['zipcode'] ?? '') : ($address->zipcode ?? '');

        return strtoupper(trim((string) $postcode));
    }
}
