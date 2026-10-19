<?php

namespace Beike\Plugin;

use Throwable;

/**
 * Makes plugin registrations safe for SaaS and long-lived workers.
 *
 * Plugin bootstrap files are registered once when the application boots, but
 * their callbacks must be evaluated against the tenant that is current when
 * the callback actually runs. Otherwise the first tenant seen by an Octane or
 * queue worker would decide plugin behaviour for every later tenant.
 */
class RuntimeGuard
{
    private static ?string $registeringPlugin = null;

    public static function whileRegistering(string $pluginCode, callable $callback): mixed
    {
        $previous                  = self::$registeringPlugin;
        self::$registeringPlugin   = $pluginCode;

        try {
            return $callback();
        } finally {
            self::$registeringPlugin = $previous;
        }
    }

    public static function wrapFilter(callable $callback): callable
    {
        $pluginCode = self::$registeringPlugin;
        if ($pluginCode === null) {
            return $callback;
        }

        return static function (...$arguments) use ($callback, $pluginCode): mixed {
            return self::isActive($pluginCode)
                ? $callback(...$arguments)
                : ($arguments[0] ?? null);
        };
    }

    public static function wrapAction(callable $callback): callable
    {
        $pluginCode = self::$registeringPlugin;
        if ($pluginCode === null) {
            return $callback;
        }

        return static function (...$arguments) use ($callback, $pluginCode): mixed {
            return self::isActive($pluginCode) ? $callback(...$arguments) : null;
        };
    }

    public static function wrapBlade(callable $callback): callable
    {
        $pluginCode = self::$registeringPlugin;
        if ($pluginCode === null) {
            return $callback;
        }

        return static function (...$arguments) use ($callback, $pluginCode): mixed {
            return self::isActive($pluginCode)
                ? $callback(...$arguments)
                : ($arguments[1] ?? '');
        };
    }

    public static function isActive(string $pluginCode): bool
    {
        if (! installed() || ! app()->bound('plugin')) {
            return false;
        }

        try {
            $plugin = app('plugin')->getPlugin($pluginCode);
            if (! $plugin || ! $plugin->getInstalled() || ! $plugin->getEnabled()) {
                return false;
            }

            if ($plugin->getType() === 'theme') {
                return (string) system_setting('base.theme') === (string) $plugin->code;
            }

            return true;
        } catch (Throwable) {
            // A missing tenant database or a partially-installed plugin must
            // disable that extension, never make the entire storefront fail.
            return false;
        }
    }
}
