<?php

namespace App\Saas\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Verifies that installation/provisioning commands actually produced a
 * usable commerce database before the site is marked ready.
 */
final class DatabaseReadiness
{
    /** @var array<int, string> */
    private const REQUIRED_TABLES = [
        'migrations',
        'settings',
        'admin_users',
        'permissions',
        'roles',
        'plugins',
        'categories',
        'customers',
        'carts',
        'products',
        'orders',
        'abandoned_order_recoveries',
        'mail_logs',
        'cache',
        'cache_locks',
    ];

    /** @var array<string, array<int, string>> */
    private const REQUIRED_COLUMNS = [
        'mail_logs' => ['delivery_id', 'attempts', 'last_attempt_at'],
    ];

    /** @var array<int, string> */
    private const SEEDED_TABLES = [
        'settings',
        'countries',
        'currencies',
        'plugins',
        'products',
    ];

    /** @var array<int, string> */
    private const STOREFRONT_SETTINGS = [
        'theme',
        'menu_setting',
        'design_setting',
        'footer_setting',
    ];

    public static function assertMigrated(?string $connection = null): void
    {
        $database = DB::connection($connection);
        $schema   = $database->getSchemaBuilder();
        $missing  = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => ! $schema->hasTable($table),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'Database migration completed without required tables: ' . implode(', ', $missing),
            );
        }

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $missingColumns = array_values(array_filter(
                $columns,
                static fn (string $column): bool => ! $schema->hasColumn($table, $column),
            ));
            if ($missingColumns !== []) {
                throw new RuntimeException(
                    "Database migration completed without required {$table} columns: " . implode(', ', $missingColumns),
                );
            }
        }
    }

    public static function assertSeeded(?string $connection = null, ?string $adminEmail = null): void
    {
        $database = DB::connection($connection);
        self::assertMigrated($connection);

        foreach (self::SEEDED_TABLES as $table) {
            if (! $database->table($table)->exists()) {
                throw new RuntimeException("Database seeding produced no rows in {$table}.");
            }
        }

        $missingSettings = array_values(array_filter(
            self::STOREFRONT_SETTINGS,
            static fn (string $name): bool => ! $database->table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', $name)
                ->exists(),
        ));
        if ($missingSettings !== []) {
            throw new RuntimeException(
                'Database seeding produced no storefront configuration for: ' . implode(', ', $missingSettings),
            );
        }

        if ($adminEmail !== null) {
            self::assertAdmin($database, $adminEmail);
        }
    }

    /**
     * Determine whether the database already contains a complete base store.
     *
     * Provisioning retries must not blindly execute DatabaseSeeder again: a
     * tenant may already have plugin foreign keys and merchant-owned data.
     * This non-throwing probe deliberately excludes the administrator because
     * SiteProvisioner repairs that account after deciding whether to seed.
     */
    public static function isSeeded(?string $connection = null): bool
    {
        try {
            $database = DB::connection($connection);
            $schema   = $database->getSchemaBuilder();

            foreach (array_unique([...self::REQUIRED_TABLES, ...self::SEEDED_TABLES]) as $table) {
                if (! $schema->hasTable($table)) {
                    return false;
                }
            }

            foreach (self::REQUIRED_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        return false;
                    }
                }
            }

            foreach (self::SEEDED_TABLES as $table) {
                if (! $database->table($table)->exists()) {
                    return false;
                }
            }

            foreach (self::STOREFRONT_SETTINGS as $name) {
                if (! $database->table('settings')
                    ->where('type', 'system')
                    ->where('space', 'base')
                    ->where('name', $name)
                    ->exists()) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            // A missing table, broken connection or partially applied
            // migration means provisioning still has work to do.
            return false;
        }
    }

    public static function assertAdministrator(string $email, ?string $connection = null): void
    {
        self::assertAdmin(DB::connection($connection), $email);
    }

    private static function assertAdmin(ConnectionInterface $database, string $email): void
    {
        if (! $database->table('admin_users')
            ->where('id', 1)
            ->where('email', $email)
            ->where('active', true)
            ->exists()) {
            throw new RuntimeException("Active root administrator {$email} was not created in the target database.");
        }
    }
}
