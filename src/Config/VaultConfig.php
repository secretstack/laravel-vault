<?php

namespace Vaultenv\Vault\Config;

use Vaultenv\Vault\Exceptions\VaultException;
use Illuminate\Support\Env;

/**
 * The typed, validated projection of config/vault.php (CONTEXT.md: "Resolved
 * config"). Both the Loader (fromEnv, raw env at boot) and the
 * VaultServiceProvider (fromArray, config('vault') at runtime) build one of
 * these, so casts, defaults, and the completeness check live in one place
 * instead of being smeared across the two boot phases.
 *
 * Immutable; carries no behaviour beyond parsing and the assertUsable() guard.
 */
final class VaultConfig
{
    /**
     * @param list<string> $localOverrides keys whose local .env value wins over
     *                                      Vault when APP_ENV === 'local' (ADR-0014)
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $address,
        public readonly string $namespace,
        public readonly string $authMount,
        public readonly string $roleId,
        public readonly string $secretId,
        public readonly string $secretPath,
        public readonly bool $failOpen,
        public readonly bool $cacheEnabled,
        public readonly string $cachePath,
        public readonly int $cacheTtl,
        public readonly int $cacheSkew,
        public readonly int $httpTimeout,
        public readonly int $httpRetries,
        public readonly int $httpRetryDelayMs,
        public readonly int $httpMaxDelayMs,
        public readonly bool $tlsVerify,
        public readonly string $appKey,
        public readonly string $appEnv = 'production',
        public readonly array $localOverrides = [],
        public readonly string $defaultsPath = '',
    ) {
    }

    /**
     * Build from raw environment at boot, before config()/facades exist
     * (CONTEXT.md: "The Loader"). Booleans and integers arrive as strings here,
     * so they are cast explicitly. cachePath is injected because it derives from
     * the framework's storage path, not an env var.
     */
    public static function fromEnv(string $cachePath): self
    {
        return new self(
            enabled:          filter_var(Env::get('VAULT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            address:          rtrim((string) Env::get('VAULT_ADDR', 'http://127.0.0.1:8200'), '/'),
            namespace:        (string) Env::get('VAULT_NAMESPACE', ''),
            authMount:        (string) Env::get('VAULT_AUTH_MOUNT', 'approle'),
            roleId:           (string) Env::get('VAULT_ROLE_ID', ''),
            secretId:         (string) Env::get('VAULT_SECRET_ID', ''),
            secretPath:       (string) Env::get('VAULT_SECRET_PATH', ''),
            failOpen:         filter_var(Env::get('VAULT_FAIL_OPEN', 'false'), FILTER_VALIDATE_BOOLEAN),
            cacheEnabled:     filter_var(Env::get('VAULT_CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
            cachePath:        $cachePath,
            cacheTtl:         (int) Env::get('VAULT_CACHE_TTL', 300),
            cacheSkew:        30,
            httpTimeout:      (int) Env::get('VAULT_HTTP_TIMEOUT', 5),
            httpRetries:      max(1, (int) Env::get('VAULT_HTTP_RETRIES', 3)),
            httpRetryDelayMs: 500,
            httpMaxDelayMs:   5000,
            tlsVerify:        filter_var(Env::get('VAULT_TLS_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
            appKey:           (string) Env::get('APP_KEY', ''),
            appEnv:           (string) Env::get('APP_ENV', 'production'),
            localOverrides:   self::normalizeOverrides(Env::get('VAULT_LOCAL_OVERRIDES', '')),
            defaultsPath:     (string) Env::get('VAULT_DEFAULTS_PATH', ''),
        );
    }

    /**
     * Build from a config('vault') array plus the separately-sourced APP_KEY
     * (which lives under the framework's app.* config root, not vault.*).
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config, string $appKey, string $appEnv = 'production'): self
    {
        $auth  = $config['auth'] ?? [];
        $cache = $config['cache'] ?? [];
        $http  = $config['http'] ?? [];

        return new self(
            enabled:          (bool) ($config['enabled'] ?? false),
            address:          rtrim((string) ($config['address'] ?? ''), '/'),
            namespace:        (string) ($config['namespace'] ?? ''),
            authMount:        (string) ($auth['mount'] ?? 'approle'),
            roleId:           (string) ($auth['role_id'] ?? ''),
            secretId:         (string) ($auth['secret_id'] ?? ''),
            secretPath:       (string) ($config['secret_path'] ?? ''),
            failOpen:         (bool) ($config['fail_open'] ?? false),
            cacheEnabled:     (bool) ($cache['enabled'] ?? true),
            cachePath:        (string) ($cache['path'] ?? ''),
            cacheTtl:         (int) ($cache['ttl'] ?? 300),
            cacheSkew:        (int) ($cache['skew'] ?? 30),
            httpTimeout:      (int) ($http['timeout'] ?? 5),
            httpRetries:      max(1, (int) ($http['retries'] ?? 3)),
            httpRetryDelayMs: (int) ($http['retry_delay'] ?? 500),
            httpMaxDelayMs:   (int) ($http['max_delay'] ?? 5000),
            tlsVerify:        (bool) ($http['verify'] ?? true),
            appKey:           $appKey,
            appEnv:           $appEnv,
            localOverrides:   self::normalizeOverrides($config['local_overrides'] ?? []),
            defaultsPath:     (string) ($config['defaults_path'] ?? ''),
        );
    }

    /**
     * Normalize VAULT_LOCAL_OVERRIDES into a clean list. Accepts a CSV string
     * (the boot/env path) or an already-split array (the config('vault') path);
     * trims each entry and drops empties so a trailing comma or stray space
     * never produces a phantom override key.
     *
     * @param string|array<int|string,mixed> $raw
     *
     * @return list<string>
     */
    private static function normalizeOverrides(string|array $raw): array
    {
        $items = is_string($raw) ? explode(',', $raw) : $raw;

        return array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            $items,
        ), static fn (string $k): bool => $k !== ''));
    }

    /**
     * Guard that this config is complete enough to authenticate and read a
     * secret. Called by the Factory before it wires the Provider, so a disabled
     * service can still build a VaultConfig from config/vault.php without
     * tripping this (the constructor never throws).
     *
     * @throws VaultException when role id, secret-zero, or secret path is missing
     */
    public function assertUsable(): void
    {
        if ($this->roleId === '' || $this->secretId === '' || $this->secretPath === '') {
            throw new VaultException(
                'VAULT_ROLE_ID, VAULT_SECRET_ID, and VAULT_SECRET_PATH must all be set when VAULT_ENABLED=true'
            );
        }
    }
}
