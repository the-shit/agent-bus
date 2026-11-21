<?php

return [

    /*
    | The default connection is unused at runtime. Durable history goes
    | through the bus_history connection, and only when BUS_HISTORY_DB_HOST
    | is set. sqlite :memory: keeps boot from touching the filesystem.
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'bus_history' => [
            'driver' => 'pgsql',
            'host' => env('BUS_HISTORY_DB_HOST'),
            'port' => env('BUS_HISTORY_DB_PORT', '5432'),
            'database' => env('BUS_HISTORY_DB_DATABASE'),
            'username' => env('BUS_HISTORY_DB_USERNAME'),
            'password' => env('BUS_HISTORY_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('BUS_HISTORY_DB_SCHEMA', 'agent-bus'),
            'sslmode' => env('BUS_HISTORY_DB_SSLMODE', 'prefer'),
        ],

    ],

];
