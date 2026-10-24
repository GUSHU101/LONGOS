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

use Illuminate\Support\Str;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Facades\Image;

class ImageService
{
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
    public function resize(int $width = 100, int $height = 100): string
    {
        try {
            if (! file_exists($this->imagePath)) {
                $this->image     = $this->placeholderImage;
                $this->imagePath = public_path($this->image);
            }
            if (! file_exists($this->imagePath)) {
                return '';
            }

            $extension     = strtolower(pathinfo($this->imagePath, PATHINFO_EXTENSION));
            $sourceVersion = sprintf(
                '%x-%x',
                (int) (filemtime($this->imagePath) ?: 0),
                (int) (filesize($this->imagePath) ?: 0),
            );
            $newImage      = 'cache/' . mb_substr($this->image, 0, mb_strrpos($this->image, '.'))
                . '-' . $width . 'x' . $height . '-v' . $sourceVersion . '.' . $extension;

            $newImagePath = public_path($newImage);
            if (! is_file($newImagePath)) {
                ini_set('memory_limit', '-1');
                create_directories(dirname($newImage));
                $img = Image::make($this->imagePath);

                $img->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });

                $canvas = Image::canvas($width, $height);
                $canvas->insert($img, 'center');
                $canvas->save($newImagePath);
            }

            $data = [
                'image'    => $this->image,
                'newImage' => $newImage,
            ];
            $data     = hook_filter('service.image.resize', $data);
            $newImage = $data['newImage'];

            return asset($newImage);
        } catch (NotReadableException $e) {
            return $this->originUrl();
        }
    }

    /**
     * 获取原图地址
     */
    public function originUrl(): string
    {
        return asset($this->image);
    }
}
