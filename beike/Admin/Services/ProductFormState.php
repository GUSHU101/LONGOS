<?php

namespace Beike\Admin\Services;

use Illuminate\Contracts\Support\Arrayable;
use Traversable;

/**
 * Convert current, legacy and validation-error product data into the stable
 * arrays expected by the admin product editor.
 */
final class ProductFormState
{
    public static function make(mixed $images, mixed $variables, mixed $skus): array
    {
        return [
            'images'    => self::normalizeImages($images),
            'variables' => self::normalizeVariables($variables),
            'skus'      => self::normalizeSkus($skus),
        ];
    }

    private static function normalizeVariables(mixed $value): array
    {
        return array_values(array_map(static function (mixed $variable): array {
            $variable = self::record($variable);
            $values   = array_values(array_map(static function (mixed $item): array {
                $item = self::record($item);

                return array_merge($item, [
                    'name'  => self::localizedName($item['name'] ?? ''),
                    'image' => is_scalar($item['image'] ?? null) ? (string) $item['image'] : '',
                ]);
            }, self::list($variable['values'] ?? [])));

            return array_merge($variable, [
                'name'    => self::localizedName($variable['name'] ?? ''),
                'values'  => $values,
                'isImage' => filter_var($variable['isImage'] ?? $variable['is_image'] ?? false, FILTER_VALIDATE_BOOL),
            ]);
        }, self::list($value)));
    }

    private static function normalizeSkus(mixed $value): array
    {
        return array_values(array_map(static function (mixed $sku): array {
            $sku      = self::record($sku);
            $id       = $sku['id'] ?? $sku['product_sku_id'] ?? null;
            $variants = array_map(static fn (mixed $item): string => (string) $item, self::list($sku['variants'] ?? []));

            return array_merge($sku, [
                'id'           => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
                'variants'     => array_values($variants),
                'images'       => self::normalizeImages($sku['images'] ?? []),
                'model'        => is_scalar($sku['model'] ?? null) ? (string) $sku['model'] : '',
                'sku'          => is_scalar($sku['sku'] ?? null) ? (string) $sku['sku'] : '',
                'price'        => self::scalarOrEmpty($sku['price'] ?? ''),
                'origin_price' => self::scalarOrEmpty($sku['origin_price'] ?? ''),
                'cost_price'   => self::scalarOrEmpty($sku['cost_price'] ?? ''),
                'weight'       => self::scalarOrEmpty($sku['weight'] ?? ''),
                'quantity'     => self::scalarOrEmpty($sku['quantity'] ?? ''),
                'is_default'   => ! empty($sku['is_default']),
                'active'       => array_key_exists('active', $sku) ? (int) (bool) $sku['active'] : 1,
            ]);
        }, self::list($value)));
    }

    private static function normalizeImages(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                return [trim($value)];
            }
            $value = $decoded;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $image): string => is_scalar($image) ? trim((string) $image) : '',
            self::list($value),
        ), static fn (string $image): bool => $image !== ''));
    }

    private static function localizedName(mixed $value): array
    {
        if (is_scalar($value)) {
            return ['en' => (string) $value];
        }

        $names = [];
        foreach (self::record($value) as $locale => $name) {
            if (is_scalar($name)) {
                $names[(string) $locale] = (string) $name;
            }
        }

        return $names ?: ['en' => ''];
    }

    private static function list(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    private static function record(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    private static function scalarOrEmpty(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return (int) $value;
        }

        return is_string($value) || is_int($value) || is_float($value) ? $value : '';
    }
}
