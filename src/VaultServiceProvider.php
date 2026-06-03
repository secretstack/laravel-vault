<?php

namespace Ibid\Vault;

use GuzzleHttp\Client as GuzzleClient;
use Ibid\Vault\Auth\AppRoleAuth;
use Ibid\Vault\Cache\EncryptedFileCache;
use Ibid\Vault\Contracts\AuthMethod;
use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Contracts\VaultClient;
use Ibid\Vault\Http\GuzzleVaultClient;
use Ibid\Vault\Provider\VaultSecretProvider;
use Ibid\Vault\Secrets\SecretStore;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered provider for the runtime path (facades available here).
 *
 * The primary secret injection already happened in VaultBootstrap at boot.
 * This wires the runtime accessor (SecretStore / Vault facade), the singleton
 * graph, the commands, and a defensive config:cache backstop (key_map).
 *
 * Bindings hold NO static state (Octane-safe); SecretStore memoizes per worker.
 */
final class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vault.php', 'vault');

        // Provide a default 'vault' log channel so consumers need not add one.
        if ($this->app['config']->get('logging.channels.vault') === null) {
            $this->app['config']->set('logging.channels.vault', [
                'driver' => 'single',
                'path'   => storage_path('logs/vault.log'),
                'level'  => 'debug',
            ]);
        }

        $this->app->singleton(VaultClient::class, function ($app): VaultClient {
            $cfg     = $app['config']->get('vault');
            $retries = max(1, (int) $cfg['http']['retries']);

            return new GuzzleVaultClient(
                http: new GuzzleClient([
                    'timeout'     => $cfg['http']['timeout'],
                    'verify'      => $cfg['http']['verify'],
                    'http_errors' => false,
                ]),
                address:     $cfg['address'],
                namespace:   $cfg['namespace'],
                maxRetries:  $retries,
                baseDelayMs: (int) $cfg['http']['retry_delay'],
                maxDelayMs:  5000,
                deadlineMs:  (int) $cfg['http']['timeout'] * $retries * 1000,
                logger:      Log::channel('vault'),
            );
        });

        $this->app->singleton(AuthMethod::class, function ($app): AuthMethod {
            $cfg = $app['config']->get('vault');

            return new AppRoleAuth($cfg['auth']['role_id'], $cfg['auth']['secret_id'], $cfg['auth']['mount']);
        });

        $this->app->singleton(SecretCache::class, function ($app): SecretCache {
            return new EncryptedFileCache(
                $this->buildEncrypter((string) $app['config']->get('app.key')),
                $app['config']->get('vault.cache.path'),
                Log::channel('vault'),
            );
        });

        $this->app->singleton(SecretProvider::class, function ($app): SecretProvider {
            $cfg = $app['config']->get('vault');

            return new VaultSecretProvider(
                client:       $app->make(VaultClient::class),
                auth:         $app->make(AuthMethod::class),
                cache:        $app->make(SecretCache::class),
                secretPath:   $cfg['secret_path'],
                cacheTtl:     (int) $cfg['cache']['ttl'],
                cacheSkew:    (int) $cfg['cache']['skew'],
                cacheEnabled: (bool) $cfg['cache']['enabled'],
                logger:       Log::channel('vault'),
            );
        });

        $this->app->singleton(SecretStore::class, fn ($app): SecretStore => new SecretStore($app->make(SecretProvider::class)));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/vault.php' => $this->app->configPath('vault.php')], 'vault-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Ibid\Vault\Console\CheckCommand::class,
                \Ibid\Vault\Console\RefreshCommand::class,
                \Ibid\Vault\Console\InstallCommand::class,
            ]);
        }

        $this->applyKeyMapOverrides();
    }

    /**
     * Apply Vault values to specific config paths (config:cache backstop, ADR-0005).
     * No-op unless enabled and a key_map is configured.
     */
    private function applyKeyMapOverrides(): void
    {
        $cfg    = $this->app['config']->get('vault');
        $keyMap = $cfg['key_map'] ?? [];

        if ($keyMap === [] || ! ($cfg['enabled'] ?? false)) {
            return;
        }

        try {
            $secrets = $this->app->make(SecretStore::class)->all();

            foreach ($keyMap as $secretKey => $configPath) {
                if (array_key_exists($secretKey, $secrets)) {
                    $this->app['config']->set($configPath, $secrets[$secretKey]);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('vault')->error('vault.key_map.failed', ['error' => $e->getMessage()]);

            if (! ($cfg['fail_open'] ?? false)) {
                throw $e;
            }
        }
    }

    private function buildEncrypter(string $appKey): Encrypter
    {
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return new Encrypter($appKey, 'AES-256-CBC');
    }
}
