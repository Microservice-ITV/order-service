<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
     * Same pattern as auth-service: comma-separated origins. Patterns avoid Fruitcake single-origin static CORP bug.
     */
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(
        ',',
        env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000')
    )))),

    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(
        ',',
        env('CORS_ALLOWED_ORIGINS_PATTERNS', in_array((string) env('APP_ENV', 'local'), ['local', 'testing'], true)
            ? 'http://localhost:*,http://127.0.0.1:*'
            : '')
    )))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];