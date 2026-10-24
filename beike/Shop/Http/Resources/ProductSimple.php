<?php

/**
 * ProductSimple.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-23 11:33:06
 * @modified   2022-06-23 11:33:06
 */

namespace Beike\Shop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSimple extends JsonResource
{
    /**
     * 图片列表页Item
     *
     * @param Request $request
     * @return array
     * @throws \Exception
     */
    public function toArray($request): array
    {
        $masterSku = $this->masterSku;
        if (empty($masterSku)) {
            throw new \Exception("invalid master sku for product {$this->id}");
        }

        $name       = $this->description->name ?? '';
        $images     = array_values(array_filter($this->images ?? [], function ($image) {
            $isYouTube = str_contains($image, 'youtube.com/watch') || str_contains($image, 'youtu.be/');

            return ! str_ends_with($image, '.mp4') && ! $isYouTube;
        }));
        $firstImage   = ! empty($images) ? reset($images) : '';
        $cardImages   = [];
        $imageSrcsets = [];

        foreach ($images as $index => $image) {
            // Product cards render the first image and optional hover image.
            // Resolve cached responsive derivatives for only those two.
            $sources        = $index < 2 ? $this->cardImageSources($image) : [];
            $cardImages[]   = $this->cardImageFallback($image, $sources);
            $imageSrcsets[] = $sources;
        }

        $data = [
            'id'                     => $this->id,
            'sku_id'                 => $masterSku->id,
            'name'                   => $name,
            'name_format'            => $name,
            'url'                    => $this->url,
            'price'                  => $masterSku->price,
            'price_converted'        => (float) currency_format($masterSku->price, '', '', false),
            'origin_price'           => $masterSku->origin_price,
            'origin_price_converted' => (float) currency_format($masterSku->origin_price, '', '', false),
            'currency_code'          => current_currency_code(),
            'price_format'           => currency_format($masterSku->price),
            'origin_price_format'    => currency_format($masterSku->origin_price),
            'category_id'            => $this->category_id           ?? null,
            'in_wishlist'            => $this->inCurrentWishlist->id ?? 0,
            'status'                 => $this->active,
            'image'                  => $firstImage,
            // Missing derivatives are queued, never decoded in this request.
            'image_format'           => $firstImage ? image_origin($firstImage) : '',

            'images'               => $cardImages,
            'image_srcsets'        => $imageSrcsets,
        ];

        return hook_filter('resource.product.simple', $data);
    }

    /**
     * Describe cached card sources without transforming them in the request.
     *
     * @return array<int, array{url: string, width: int, height: int}>
     */
    private function cardImageSources(string $image): array
    {
        if (preg_match('#^https?://#i', $image)) {
            return [];
        }

        $urlPath      = parse_url($image, PHP_URL_PATH);
        $relativePath = ltrim(str_replace('\\', '/', is_string($urlPath) ? $urlPath : ''), '/');
        $publicRoot   = realpath(public_path());
        $absolutePath = $relativePath !== '' ? realpath(public_path($relativePath)) : false;

        if (! $publicRoot || ! $absolutePath || ! str_starts_with($absolutePath, $publicRoot . DIRECTORY_SEPARATOR)) {
            return [];
        }

        $dimensions = @getimagesize($absolutePath);
        if (! is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
            return [];
        }

        $originWidth  = (int) $dimensions[0];
        $originHeight = (int) $dimensions[1];

        $sources = [];
        foreach ([360, 560, 800] as $width) {
            if ($width >= $originWidth) {
                break;
            }

            $height    = calculate_height_by_ratio($width);
            $sources[] = [
                'url'    => image_cached_resize($image, $width, $height),
                'width'  => $width,
                'height' => $height,
            ];
        }

        if ($sources === [] || $originWidth <= 800) {
            $sources[] = [
                'url'    => image_origin($image),
                'width'  => $originWidth,
                'height' => $originHeight,
            ];
        }

        return $sources;
    }

    /**
     * Prefer an approximately 800px card source while keeping remote and
     * unsupported formats at their untouched original URL.
     *
     * @param array<int, array{url: string, width: int, height: int}> $sources
     */
    private function cardImageFallback(string $image, array $sources): string
    {
        if (empty($sources)) {
            return image_origin($image);
        }

        foreach ($sources as $source) {
            if ($source['width'] >= 800) {
                return $source['url'];
            }
        }

        return $sources[array_key_last($sources)]['url'];
    }
}
