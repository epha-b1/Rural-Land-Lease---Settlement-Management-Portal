<?php
// Database configuration - all values from environment variables (Docker inline)
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => env('DB_HOST', 'db'),
            'hostport' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'rural_lease'),
            'username' => env('DB_USERNAME', 'app'),
            'password' => env('DB_PASSWORD', 'app'),
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'   => '',
            'debug'    => env('APP_DEBUG', false),
        ],
    ],
];
