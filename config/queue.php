<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default'     => env('QUEUE_CONNECTION', 'database'),

    // Keeps Docker's worker health check accurate while a job is legitimately
    // busy. Job-specific timeouts override this lease; every completion or
    // exception releases it immediately.
    'worker_heartbeat_lease' => (int) env('QUEUE_WORKER_HEARTBEAT_LEASE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync'       => [
            'driver' => 'sync',
        ],

        'database'   => [
            'driver'       => 'database',
            // Queue transport belongs to the control plane. Leaving this
            // null would make jobs dispatched during a tenant request land
            // in that tenant database, where the central worker cannot see
            // them after the tenant context has been released.
            'connection'   => env('QUEUE_DB_CONNECTION', env('SAAS_ENABLED', false) ? 'saas' : null),
            'table'        => 'jobs',
            'queue'        => 'default',
            // Long-running Shopify image imports declare a 55-minute job
            // timeout. Keep reservations longer than that so a second worker
            // cannot execute the same tenant import concurrently.
            'retry_after'  => (int) env('QUEUE_RETRY_AFTER', 3900),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver'       => 'beanstalkd',
            'host'         => 'localhost',
            'queue'        => 'default',
            'retry_after'  => (int) env('QUEUE_RETRY_AFTER', 3900),
            'block_for'    => 0,
            'after_commit' => false,
        ],

        'sqs'        => [
            'driver'       => 'sqs',
            'key'          => env('AWS_ACCESS_KEY_ID'),
            'secret'       => env('AWS_SECRET_ACCESS_KEY'),
            'prefix'       => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'        => env('SQS_QUEUE', 'default'),
            'suffix'       => env('SQS_SUFFIX'),
            'region'       => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis'      => [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => env('REDIS_QUEUE', 'default'),
            'retry_after'  => (int) env('QUEUE_RETRY_AFTER', 3900),
            'block_for'    => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed'      => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('QUEUE_FAILED_DB_CONNECTION', env('SAAS_ENABLED', false) ? 'saas' : env('DB_CONNECTION', 'mysql')),
        'table'    => 'failed_jobs',
    ],

];
