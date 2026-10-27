<?php

/**
 * ImageService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-12 17:30:20
 * @modified   2022-07-12 17:30:20
 */

namespace Beike\Services;

use App\Jobs\GenerateImageDerivative;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use RuntimeException;
use Throwable;

class ImageService
{
    private const DERIVATIVE_VERSION = 'r2';

    // Decoding expands compressed images to several bytes per pixel and the
    // resize path briefly keeps the source, resized image and canvas alive.
    // Reject pathological sources before GD can terminate the whole worker
    // with an uncatchable memory-limit fatal error.
    private const MAX_SOURCE_PIXELS = 40_000_000;

    private $image;

    private $imagePath;

    private $placeholderImage = 'image/placeholder.png';

    /**
     * @param             $image
     * @throws \Exception
     */
    public function __construct($image)
    {
        // A fresh store or a damaged setting may not yet have a placeholder
        // configured. Never pass null to asset()/the URL generator: empty
        // image fields are expected while building a new visual section.
        $configuredPlaceholder  = system_setting('base.placeholder');
        $this->placeholderImage = is_scalar($configuredPlaceholder) && trim((string) $configuredPlaceholder) !== ''
            ? trim((string) $configuredPlaceholder)
            : 'image/placeholder.png';
        $this->image            = is_scalar($image) && trim((string) $image) !== ''
            ? trim((string) $image)
            : $this->placeholderImage;
        $this->imagePath        = public_path($this->image);
    }

    /**
     * 设置插件目录名称
     * @param $dirName
     * @return $this
     */
    public function setPluginDirName($dirName): static
    {
        $originImage = $this->image;
        if ($this->image == $this->placeholderImage) {
            return $this;
        }

        $this->imagePath = plugin_path("{$dirName}/Static") . $originImage;
        if (file_exists($this->imagePath)) {
            $this->image = Str::snake('plugin/' . $dirName . $originImage);
        } else {
            $this->image     = $this->placeholderImage;
            $this->imagePath = public_path($this->image);
        }

        return $this;
    }

    /**
     * 生成并获取缩略图
     * @param int $width
     * @param int $height
     * @return string
     */
    public function resize(int $width = 100, int $height = 100, bool $throwOnFailure = false): string
    {
        $lock          = null;
        $temporaryPath = null;
        $decodedPath   = null;

        try {
            $newImage = $this->derivativePath($width, $height);
            if ($newImage === null) {
                return '';
            }

            $newImagePath = public_path($newImage);
            if (! is_file($newImagePath)) {
                create_directories(dirname($newImage));
                $lock = @fopen($newImagePath . '.lock', 'c');
                if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
                    return $this->originUrl();
                }

                // Another PHP worker may have completed the same derivative
                // while this request waited for the filesystem lock.
                if (! is_file($newImagePath)) {
                    $this->assertSourceDimensionsAreSafe();
                    $extension     = strtolower(pathinfo($this->imagePath, PATHINFO_EXTENSION));
                    $temporaryPath = $newImagePath . '.tmp-' . getmypid() . '-' . uniqid('', true) . '.' . $extension;

                    try {
                        $img = Image::make($this->imagePath);
                    } catch (Throwable $decodeException) {
                        // GD cannot decode animated WebP files and a few
                        // extended WebP profiles even though browsers can.
                        // Keep the uploaded original for the lightbox, but
                        // use Imagick to extract a clean first frame for the
                        // responsive storefront derivative.
                        $decodedPath = $newImagePath . '.source-' . getmypid() . '-' . uniqid('', true) . '.png';
                        $this->decodeFirstFrameWithImagick($decodedPath, $decodeException);
                        $img = Image::make($decodedPath);
                    }

                    $img->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                    });

                    $canvas = Image::canvas($width, $height);
                    $canvas->insert($img, 'center');
                    // 82 keeps storefront photography visually clean while
                    // avoiding the large default-quality WebP/JPEG output
                    // highlighted by Lighthouse image-delivery diagnostics.
                    $quality = in_array($extension, ['jpg', 'jpeg', 'webp'], true) ? 82 : null;
                    $canvas->save($temporaryPath, $quality);
                    if (! @rename($temporaryPath, $newImagePath)) {
                        return $this->originUrl();
                    }
                    $temporaryPath = null;
                }
            }

            $data = [
                'image'    => $this->image,
                'newImage' => $newImage,
            ];
            $data     = hook_filter('service.image.resize', $data);
            $newImage = $data['newImage'];

            return asset($newImage);
        } catch (Throwable $exception) {
            // Responsive derivatives are an optimization, never a reason to
            // fail the product page. Unsupported codecs, corrupt uploads,
            // memory pressure, or unwritable cache paths use the source URL.
            // Queue jobs opt into exceptions so operational failures are
            // retried and remain visible instead of looking successful.
            if ($throwOnFailure) {
                throw $exception;
            }

            return $this->originUrl();
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            if (is_string($decodedPath) && is_file($decodedPath)) {
                @unlink($decodedPath);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Decode the first complete frame of media formats unsupported by GD.
     */
    private function decodeFirstFrameWithImagick(string $targetPath, Throwable $previous): void
    {
        if (! class_exists(\Imagick::class)) {
            throw new RuntimeException('The fallback image decoder is unavailable.', previous: $previous);
        }

        $source = new \Imagick($this->imagePath);
        $frames = $source->coalesceImages();
        $frames->setIteratorIndex(0);
        $frame = $frames->getImage();

        try {
            $frame->setImagePage(0, 0, 0, 0);
            $frame->setImageFormat('png');
            if (! $frame->writeImage($targetPath)) {
                throw new RuntimeException('The fallback image decoder could not write its first frame.', previous: $previous);
            }
        } finally {
            $frame->clear();
            $frames->clear();
            $source->clear();
        }
    }

    /**
     * Bound the decoded source before allocating GD/Imagick pixel buffers.
     */
    private function assertSourceDimensionsAreSafe(): void
    {
        $dimensions = @getimagesize($this->imagePath);
        if (! is_array($dimensions)) {
            throw new RuntimeException('The source image dimensions could not be read.');
        }

        $width  = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > intdiv(self::MAX_SOURCE_PIXELS, $height)) {
            throw new RuntimeException('The source image is too large to generate a safe derivative.');
        }
    }

    /**
     * Return a prepared derivative without decoding the source in the web
     * request. Missing files are generated by the tenant-aware queue worker;
     * the original remains a safe first-visit fallback.
     */
    public function cachedResize(int $width = 100, int $height = 100, bool $schedule = true): string
    {
        try {
            $newImage = $this->derivativePath($width, $height);
            if ($newImage === null) {
                return '';
            }

            if (is_file(public_path($newImage))) {
                $data = hook_filter('service.image.resize', [
                    'image'    => $this->image,
                    'newImage' => $newImage,
                ]);

                return asset($data['newImage']);
            }

            // Do the cheap header-only dimension check before dispatching so
            // corrupt or decompression-bomb sources cannot poison the queue
            // with a derivative job that will fail after every storefront hit.
            $this->assertSourceDimensionsAreSafe();

            // A synchronous queue would put the expensive decode back into
            // the storefront request. Local installs simply use the original
            // until an explicit resize is run.
            if ($schedule && (string) config('queue.default', 'sync') !== 'sync') {
                GenerateImageDerivative::dispatch($this->image, $width, $height);
            }

            return $this->originUrl();
        } catch (Throwable) {
            // Queue/database outages and malformed media must never affect
            // storefront availability.
            return $this->originUrl();
        }
    }

    /**
     * Build the versioned cache path shared by the request and queue worker.
     */
    private function derivativePath(int $width, int $height): ?string
    {
        if ($width < 1 || $height < 1 || $width > 2400 || $height > 2400) {
            return null;
        }

        if (! file_exists($this->imagePath)) {
            $this->image     = $this->placeholderImage;
            $this->imagePath = public_path($this->image);
        }
        if (! is_file($this->imagePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($this->imagePath, PATHINFO_EXTENSION));
        $position  = mb_strrpos($this->image, '.');
        if ($extension === '' || $position === false) {
            return null;
        }

        $sourceVersion = sprintf(
            '%x-%x',
            (int) (filemtime($this->imagePath) ?: 0),
            (int) (filesize($this->imagePath) ?: 0),
        );

        return 'cache/' . mb_substr($this->image, 0, $position)
            . '-' . $width . 'x' . $height . '-' . self::DERIVATIVE_VERSION . '-v' . $sourceVersion . '.' . $extension;
    }

    /**
     * 获取原图地址
     */
    public function originUrl(): string
    {
        return asset($this->image);
    }
}
