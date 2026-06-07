<?php

namespace Vaultenv\Vault\Bootstrap;

use Vaultenv\Vault\Config\VaultConfig;
use Vaultenv\Vault\Exceptions\VaultException;
use Vaultenv\Vault\Factory\VaultFactory;
use Vaultenv\Vault\Secrets\EnvInjector;
use Vaultenv\Vault\Support\FileLogger;
use Illuminate\Contracts\Foundation\Application;

/**
 * Facade-free early bootstrap (CONTEXT.md: "The Loader"). Hooked from a
 * consumer's bootstrap/app.php via
 * `afterBootstrapping(LoadEnvironmentVariables, fn ($app) => VaultBootstrap::inject($app))`.
 *
 * At this point .env is loaded but config()/facades are NOT available, so the
 * config is read straight from the environment (VaultConfig::fromEnv) and the
 * graph is wired by VaultFactory with a FileLogger (no container, no facades).
 * Secrets are injected into $_ENV before LoadConfiguration runs.
 *
 * Failure posture (ADR-0003): fail-closed by default — a cold start with no
 * usable secrets throws and the process exits. fail-open (dev) swallows and
 * relies on the provider's stale-grace; it never falls back to a phantom .env.
 */
final class VaultBootstrap
{
    public static function inject(Application $app): void
    {
        $logFile = $app->storagePath('logs/vault.log');
        $config  = VaultConfig::fromEnv($app->storagePath('framework/vault'));

        if (! $config->enabled) {
            return;
        }

        try {
            $logger  = new FileLogger($logFile);
            $secrets = (new VaultFactory())->makeStore($config, $logger)->all();
            (new EnvInjector($logger))->inject($secrets);
        } catch (\Throwable $e) {
            self::emergencyLog($logFile, $e);

            if ($config->failOpen) {
                return;
            }

            throw new VaultException('Vault bootstrap failed: ' . $e->getMessage(), 0, $e);
        }
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
