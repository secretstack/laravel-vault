<?php

namespace Vaultenv\Vault;

use Vaultenv\Vault\Config\VaultConfig;
use Vaultenv\Vault\Contracts\AuthMethod;
use Vaultenv\Vault\Contracts\SecretCache;
use Vaultenv\Vault\Contracts\SecretProvider;
use Vaultenv\Vault\Contracts\VaultClient;
use Vaultenv\Vault\Factory\VaultFactory;
use Vaultenv\Vault\Secrets\OverridePolicy;
use Vaultenv\Vault\Secrets\SecretStore;
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

        // Resolved config + the assembler are bound once; every collaborator
        // below is wired through the Factory so the wiring lives in one place
        // (shared with the facade-free Loader). APP_KEY lives under app.*, not
        // vault.*, so it is threaded in separately.
        $this->app->singleton(VaultConfig::class, fn ($app): VaultConfig => VaultConfig::fromArray(
            $app['config']->get('vault'),
            (string) $app['config']->get('app.key'),
            (string) $app['config']->get('app.env'),
        ));

        $this->app->singleton(VaultFactory::class, fn (): VaultFactory => new VaultFactory());

        $this->app->singleton(VaultClient::class, fn ($app): VaultClient => $app->make(VaultFactory::class)
            ->makeClient($app->make(VaultConfig::class), Log::channel('vault')));

        $this->app->singleton(AuthMethod::class, fn ($app): AuthMethod => $app->make(VaultFactory::class)
            ->makeAuth($app->make(VaultConfig::class)));

        $this->app->singleton(SecretCache::class, fn ($app): SecretCache => $app->make(VaultFactory::class)
            ->makeCache($app->make(VaultConfig::class), Log::channel('vault')));

        $this->app->singleton(SecretProvider::class, fn ($app): SecretProvider => $app->make(VaultFactory::class)
            ->makeProvider(
                $app->make(VaultClient::class),
                $app->make(AuthMethod::class),
                $app->make(SecretCache::class),
                $app->make(VaultConfig::class),
                Log::channel('vault'),
            ));

        $this->app->singleton(SecretStore::class, fn ($app): SecretStore => new SecretStore($app->make(SecretProvider::class)));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/vault.php' => $this->app->configPath('vault.php')], 'vault-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Vaultenv\Vault\Console\CheckCommand::class,
                \Vaultenv\Vault\Console\RefreshCommand::class,
                \Vaultenv\Vault\Console\InstallCommand::class,
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
            $policy  = OverridePolicy::fromConfig($this->app->make(VaultConfig::class));

            foreach ($keyMap as $secretKey => $configPath) {
                // Honor the local override (ADR-0014): if the dev is keeping this
                // key's .env value, don't push the Vault value back into config()
                // here — otherwise the override would work everywhere except for
                // keys that happen to be in key_map.
                if ($policy->shouldKeepLocal($secretKey)) {
                    continue;
                }

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
}
