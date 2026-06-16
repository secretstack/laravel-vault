<?php

namespace Vaultenv\Vault\Tests\Feature;

use Vaultenv\Vault\Contracts\SecretProvider;
use Vaultenv\Vault\Secrets\SecretStore;
use Vaultenv\Vault\Tests\TestCase;
use Illuminate\Support\ServiceProvider;
use Mockery;

/**
 * Proves the local override (ADR-0014) is honored by the key_map backstop, not
 * just the Loader. Without the centralized OverridePolicy, applyKeyMapOverrides
 * would push the Vault value back into config() at runtime and silently defeat
 * the override for exactly the keys that happen to be in key_map.
 */
class KeyMapOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        // Registered AFTER VaultServiceProvider so its SecretStore binding wins,
        // keeping the whole test offline (no AppRole login / KV read).
        return [...parent::getPackageProviders($app), FakeStoreProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.env', 'local');
        $app['config']->set('vault.enabled', true);
        $app['config']->set('vault.local_overrides', ['STOCKV2_SERVICE_URL']);
        $app['config']->set('vault.key_map', [
            'STOCKV2_SERVICE_URL' => 'services.stockv2.url',         // overridden -> must be skipped
            'DB_HOST'             => 'database.connections.mysql.host', // unlisted  -> must be applied
        ]);

        // The dev's local value, as config/services.php would resolve from .env.
        $app['config']->set('services.stockv2.url', 'http://localhost:8002');
    }

    public function test_key_map_does_not_clobber_a_locally_overridden_key(): void
    {
        $this->assertSame(
            'http://localhost:8002',
            config('services.stockv2.url'),
            'local override must survive the key_map backstop',
        );
    }

    public function test_key_map_still_applies_to_non_overridden_keys(): void
    {
        $this->assertSame(
            'mysql.dev.svc',
            config('database.connections.mysql.host'),
            'unlisted key_map entries still receive the Vault value',
        );
    }
}

/** @internal binds a fake SecretStore so the key_map path never touches Vault. */
final class FakeStoreProvider extends ServiceProvider
{
    public function register(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andReturn([
            'STOCKV2_SERVICE_URL' => 'http://stockv2.dev.svc', // Vault value; must lose locally
            'DB_HOST'             => 'mysql.dev.svc',          // unlisted; must win
        ]);

        $this->app->singleton(SecretStore::class, fn (): SecretStore => new SecretStore($provider));
    }
}
