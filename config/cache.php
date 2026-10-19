<?php

use Illuminate\Support\Str;

$configuredCacheDriver = (string) env('CACHE_DRIVER', 'file');

// A fresh or resumed web installation boots the framework before migrations
// have created the database cache tables. Keep bootstrap cache operations on
// the filesystem until the atomic installed marker exists; the installer
// promotes CACHE_DRIVER back to database only after migrations succeed.
$defaultCacheDriver = $configuredCacheDriver === 'database' && ! is_file(storage_path('installed'))
    ? 'file'
    : $configuredCacheDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */

    'default' => $defaultCacheDriver,

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "apc", "array", "database", "file",
    |         "memcached", "redis", "dynamodb", "octane", "null"
    |
    */

    'stores'  => [

        'apc'       => [
            'driver' => 'apc',
        ],

        'array'     => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'database'  => [
            'driver'          => 'database',
            'table'           => 'cache',
            'connection'      => env('CACHE_DB_CONNECTION'),
            'lock_connection' => null,
            'lock_table'      => 'cache_locks',
        ],

        'file'      => [
            'driver' => 'file',
            'path'   => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver'        => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl'          => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options'       => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers'       => [
                [
                    'host'   => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port'   => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis'     => [
            'driver'          => 'redis',
            'connection'      => 'cache',
            'lock_connection' => 'default',
        ],

        'dynamodb'  => [
            'driver'   => 'dynamodb',
            'key'      => env('AWS_ACCESS_KEY_ID'),
            'secret'   => env('AWS_SECRET_ACCESS_KEY'),
            'region'   => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table'    => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane'    => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as APC or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | value to get prefixed to all our keys so we can avoid collisions.
    |
    */

    'prefix'  => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_cache'),

];
