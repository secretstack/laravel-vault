<?php

namespace Vaultenv\Vault\Console;

use Illuminate\Console\Command;

/**
 * Wires the facade-free bootstrap hook into a consumer's bootstrap/app.php
 * (the one mandatory code change, ADR-0002). Idempotent; prints the diff.
 *
 * Handles the Laravel 9/10 `return $app;` skeleton; for the Laravel 11+ slim
 * skeleton it prints manual instructions rather than risk an unsafe edit.
 */
final class InstallCommand extends Command
{
    protected $signature = 'vault:install {--path= : Path to bootstrap/app.php (defaults to the app)}';

    protected $description = 'Wire the Vault bootstrap hook into bootstrap/app.php';

    public function handle(): int
    {
        $path = $this->option('path') ?: $this->laravel->basePath('bootstrap/app.php');

        if (! is_file($path)) {
            $this->error("bootstrap file not found: {$path}");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, 'VaultBootstrap::inject')) {
            $this->info('Already installed — VaultBootstrap::inject is present.');

            return self::SUCCESS;
        }

        if (! str_contains($contents, 'return $app;')) {
            $this->warn('Could not find "return $app;" (Laravel 11+ slim skeleton). Add this manually to bootstrap/app.php:');
            $this->line($this->hookSnippet());

            return self::FAILURE;
        }

        $patched = str_replace('return $app;', rtrim($this->hookSnippet()) . "\n\nreturn \$app;", $contents);
        file_put_contents($path, $patched);

        $this->info('Patched ' . $path);
        $this->line('--- added ---');
        $this->line($this->hookSnippet());

        return self::SUCCESS;
    }

    private function hookSnippet(): string
    {
        return <<<'PHP'
$app->afterBootstrapping(
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    fn ($app) => \Vaultenv\Vault\Bootstrap\VaultBootstrap::inject($app)
);
PHP;
    }
}
