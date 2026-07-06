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

    /*
    |--------------------------------------------------------------------------
    | Environment Defaults Path (ADR-0015)
    |--------------------------------------------------------------------------
    | Optional per-environment shared path merged beneath the service path —
    | a key in secret_path shadows the same key here. Empty = feature off.
    | A configured-but-missing path (404) is a hard error, never empty.
    |   e.g. VAULT_DEFAULTS_PATH="ibid/data/ims/dev/_global"
    */
    'defaults_path' => env('VAULT_DEFAULTS_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Local Override Allow-list (ADR-0014)
    |--------------------------------------------------------------------------
    | Comma-separated keys whose value in the local .env wins over Vault — for
    | repointing a service URL at localhost during cross-service development.
    | Active ONLY when APP_ENV=local; ignored (and logged) anywhere else, so it
    | can never weaken Vault-wins precedence (ADR-0005) on a deployed pod.
    |   e.g. VAULT_LOCAL_OVERRIDES="STOCKV2_SERVICE_URL,PAYMENT_URL"
    */
    'local_overrides' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VAULT_LOCAL_OVERRIDES', '')),
    ))),

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
