<?php

namespace Beike\Services;

/**
 * Creates HTMLPurifier configurations that respect LONGOS' immutable image.
 *
 * HTMLPurifier otherwise stores serialized definitions below vendor/, which
 * is deliberately root-owned in production. Runtime cache data belongs in
 * Laravel's writable storage volume instead.
 */
final class HtmlPurifierConfig
{
    public static function create(): \HTMLPurifier_Config
    {
        $cachePath = self::cachePath();
        if (! is_dir($cachePath)
            && ! @mkdir($cachePath, 0770, true)
            && ! is_dir($cachePath)
        ) {
            throw new \RuntimeException("Unable to create HTMLPurifier cache directory: {$cachePath}");
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);

        return $config;
    }

    public static function cachePath(): string
    {
        $application = app();
        if (method_exists($application, 'storagePath')) {
            return $application->storagePath('framework/cache/htmlpurifier');
        }

        // Isolated unit tests intentionally boot only Laravel's base
        // container. Keep this low-level service testable without changing
        // its production path.
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'htmlpurifier';
    }
}
