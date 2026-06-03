<?php

namespace Ibid\Vault\Bootstrap;

use GuzzleHttp\Client as GuzzleClient;
use Ibid\Vault\Auth\AppRoleAuth;
use Ibid\Vault\Cache\EncryptedFileCache;
use Ibid\Vault\Exceptions\VaultException;
use Ibid\Vault\Http\GuzzleVaultClient;
use Ibid\Vault\Provider\VaultSecretProvider;
use Ibid\Vault\Secrets\EnvInjector;
use Ibid\Vault\Secrets\SecretStore;
use Ibid\Vault\Support\FileLogger;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;
use Psr\Log\LoggerInterface;

/**
 * Facade-free early bootstrap. Hooked from a consumer's bootstrap/app.php via
 * `afterBootstrapping(LoadEnvironmentVariables, fn ($app) => VaultBootstrap::inject($app))`.
 *
 * At this point .env is loaded but config()/facades are NOT available, so this
 * builds its dependency graph with raw `new` and a FileLogger (no container,
 * no facades). Secrets are injected into $_ENV before LoadConfiguration runs.
 *
 * Failure posture (ADR-0003): fail-closed by default — a cold start with no
 * usable secrets throws and the process exits. fail-open (dev) swallows and
 * relies on the provider's stale-grace; it never falls back to a phantom .env.
 */
final class VaultBootstrap
{
    public static function inject(Application $app): void
    {
        if (! filter_var(Env::get('VAULT_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $failOpen = filter_var(Env::get('VAULT_FAIL_OPEN', 'false'), FILTER_VALIDATE_BOOLEAN);
        $logFile  = $app->storagePath('logs/vault.log');

        try {
            $logger  = new FileLogger($logFile);
            $secrets = self::buildStore($app, $logger)->all();
            (new EnvInjector($logger))->inject($secrets);
        } catch (\Throwable $e) {
            self::emergencyLog($logFile, $e);

            if ($failOpen) {
                return;
            }

            throw new VaultException('Vault bootstrap failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function buildStore(Application $app, LoggerInterface $logger): SecretStore
    {
        $address      = rtrim((string) Env::get('VAULT_ADDR', 'http://127.0.0.1:8200'), '/');
        $mount        = (string) Env::get('VAULT_AUTH_MOUNT', 'approle');
        $roleId       = (string) Env::get('VAULT_ROLE_ID', '');
        $secretId     = (string) Env::get('VAULT_SECRET_ID', '');
        $secretPath   = (string) Env::get('VAULT_SECRET_PATH', '');
        $namespace    = (string) Env::get('VAULT_NAMESPACE', '');
        $timeout      = (int) Env::get('VAULT_HTTP_TIMEOUT', 5);
        $retries      = max(1, (int) Env::get('VAULT_HTTP_RETRIES', 3));
        $tlsVerify    = filter_var(Env::get('VAULT_TLS_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
        $cacheTtl     = (int) Env::get('VAULT_CACHE_TTL', 300);
        $cacheEnabled = filter_var(Env::get('VAULT_CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        $appKey       = (string) Env::get('APP_KEY', '');

        if ($roleId === '' || $secretId === '' || $secretPath === '') {
            throw new VaultException(
                'VAULT_ROLE_ID, VAULT_SECRET_ID, and VAULT_SECRET_PATH must all be set when VAULT_ENABLED=true'
            );
        }

        $client = new GuzzleVaultClient(
            http:        new GuzzleClient(['timeout' => $timeout, 'verify' => $tlsVerify, 'http_errors' => false]),
            address:     $address,
            namespace:   $namespace,
            maxRetries:  $retries,
            baseDelayMs: 500,
            maxDelayMs:  5000,
            deadlineMs:  $timeout * $retries * 1000,
            logger:      $logger,
        );

        $cache = new EncryptedFileCache(
            self::buildEncrypter($appKey),
            $app->storagePath('framework/vault'),
            $logger,
        );

        $provider = new VaultSecretProvider(
            client:       $client,
            auth:         new AppRoleAuth($roleId, $secretId, $mount),
            cache:        $cache,
            secretPath:   $secretPath,
            cacheTtl:     $cacheTtl,
            cacheSkew:    30,
            cacheEnabled: $cacheEnabled,
            logger:       $logger,
        );

        return new SecretStore($provider);
    }

    private static function buildEncrypter(string $appKey): Encrypter
    {
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new VaultException('APP_KEY is not set; cannot encrypt the Vault secret cache');
        }

        return new Encrypter($appKey, 'AES-256-CBC');
    }

    private static function emergencyLog(string $logFile, \Throwable $e): void
    {
        try {
            $dir = dirname($logFile);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $line = sprintf(
                "[%s] VAULT_BOOTSTRAP_FAILED: %s — %s in %s:%d\n",
                date('Y-m-d H:i:s'),
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            );

            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Never mask the original error.
        }
    }
}
