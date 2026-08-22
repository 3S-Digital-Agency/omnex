<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Laravel 12 merges this file with the framework's default connections
    | (pgsql, mysql, sqlite, sqlsrv), so only the additional connection is
    | declared here.
    |
    | `pgsql` (the default) is the RUNTIME connection: a least-privilege
    | `omnex_app` role in production, subject to Row-Level Security.
    | `pgsql_migrate` is the OWNER connection used exclusively by
    | `php artisan migrate --database=pgsql_migrate`, so schema and role
    | provisioning never run under the least-privilege runtime account.
    |
    */

    'connections' => [

        'pgsql_migrate' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'omnex'),
            'username' => env('DB_MIGRATE_USERNAME', env('DB_USERNAME', 'omnex')),
            'password' => env('DB_MIGRATE_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

    ],

];
