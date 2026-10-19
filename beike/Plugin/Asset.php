<?php

/**
 * Asset.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-03-03 14:50:31
 * @modified   2023-03-03 14:50:31
 */

namespace Beike\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Asset
{
    private string $pluginPath;

    public const CONTENT_TYPES = [
        'js'   => 'application/javascript',
        'css'  => 'text/css',
        'jpg'  => 'image/jpeg',
        'apng' => 'image/apng',
        'avif' => 'image/avif',
        'gif'  => 'image/gif',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'webm' => 'video/webm',
        'ogg'  => 'video/ogg',
    ];

    public function __construct($pluginCode)
    {
        $pluginCode = trim((string) $pluginCode);
        if ($pluginCode === '' || preg_match('/^[a-zA-Z0-9_]+$/', $pluginCode) !== 1) {
            throw new InvalidArgumentException('Invalid plugin code.');
        }

        $pluginsRoot = realpath(base_path('plugins'));
        $pluginPath  = realpath(base_path('plugins/' . Str::studly($pluginCode)));
        if ($pluginsRoot === false || $pluginPath === false || ! is_dir($pluginPath)
                                   || ! $this->isInside($pluginPath, $pluginsRoot)) {
            throw new InvalidArgumentException('Unknown plugin code.');
        }

        $this->pluginPath = $pluginPath;
    }

    public static function getInstance($pluginCode)
    {
        return new self($pluginCode);
    }

    /**
     * Get content and type
     *
     * @param $file
     * @return array|string
     */
    public function getContent($file)
    {
        $staticRoot = realpath($this->pluginPath . DIRECTORY_SEPARATOR . 'Static');
        $file       = str_replace('\\', '/', trim((string) $file));
        if ($staticRoot === false || $file === '' || str_contains($file, "\0")
                                  || str_starts_with($file, '/')
                                  || in_array('..', explode('/', $file), true)) {
            return [];
        }

        $filePath = realpath($staticRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
        if ($filePath === false || ! is_file($filePath) || ! $this->isInside($filePath, $staticRoot)) {
            return [];
        }

        $extension = strtolower(File::extension($filePath));
        $type      = self::CONTENT_TYPES[$extension] ?? null;
        if ($type === null) {
            return [];
        }

        $content = file_get_contents($filePath);

        return is_string($content) ? ['type' => $type, 'content' => $content] : [];
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return str_starts_with($path, $root . '/');
    }
}
