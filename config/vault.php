<?php

return [

    'enabled' => env('VAULT_ENABLED', false),

    'address' => rtrim(env('VAULT_ADDR', 'http://127.0.0.1:8200'), '/'),

    'namespace' => env('VAULT_NAMESPACE', ''),

    'auth' => [
        'mount'     => env('VAULT_AUTH_MOUNT', 'approle'),
        'role_id'   => env('VAULT_ROLE_ID', ''),
        'secret_id' => env('VAULT_SECRET_ID', ''),
    ],

    'secret_path' => env('VAULT_SECRET_PATH', ''),

    // Fail-closed by default in production (ADR-0003). Set true only in local/dev.
    'fail_open' => env('VAULT_FAIL_OPEN', false),

    'cache' => [
        'enabled' => env('VAULT_CACHE_ENABLED', true),
        'path'    => storage_path('framework/vault'),
        'ttl'     => (int) env('VAULT_CACHE_TTL', 300),
        'skew'    => 30,
    ],

    'http' => [
        'timeout'     => (int) env('VAULT_HTTP_TIMEOUT', 5),
        'retries'     => (int) env('VAULT_HTTP_RETRIES', 3),
        'retry_delay' => 500,
        'max_delay'   => 5000,
        'verify'      => env('VAULT_TLS_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Map (config:cache backstop)
    |--------------------------------------------------------------------------
    | Optional. Maps Vault secret keys to Laravel config paths so values reach
    | their config even when config is cached. Prefer running config:cache at
    | container startup (after injection) over maintaining this — see ADR-0005.
    |   e.g. 'DB_PASSWORD' => 'database.connections.mysql.password',
    */
    'key_map' => [],

];
