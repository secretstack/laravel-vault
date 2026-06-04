<?php

namespace Ibid\Vault\Tests\Feature;

use Ibid\Vault\Auth\AppRoleAuth;
use Ibid\Vault\Cache\EncryptedFileCache;
use Ibid\Vault\Contracts\AuthMethod;
use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Contracts\VaultClient;
use Ibid\Vault\Http\GuzzleVaultClient;
use Ibid\Vault\Provider\VaultSecretProvider;
use Ibid\Vault\Secrets\SecretStore;
use Ibid\Vault\Tests\TestCase;

class VaultServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Resolving the real graph runs the Factory's assertUsable guard, so the
        // binding test needs a complete config (the realistic precondition for
        // anything resolving the provider).
        $app['config']->set('vault.auth.role_id', 'rid');
        $app['config']->set('vault.auth.secret_id', 'sid');
        $app['config']->set('vault.secret_path', 'ibid/data/ims/dev/svc');
    }

    public function test_merges_vault_config(): void
    {
        $config = config('vault');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('address', $config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertFalse($config['fail_open'], 'package default fail_open must be false (ADR-0003)');
    }

    public function test_binds_contracts_to_concrete_implementations(): void
    {
        $this->assertInstanceOf(GuzzleVaultClient::class, $this->app->make(VaultClient::class));
        $this->assertInstanceOf(AppRoleAuth::class, $this->app->make(AuthMethod::class));
        $this->assertInstanceOf(EncryptedFileCache::class, $this->app->make(SecretCache::class));
        $this->assertInstanceOf(VaultSecretProvider::class, $this->app->make(SecretProvider::class));
        $this->assertInstanceOf(SecretStore::class, $this->app->make(SecretStore::class));
    }

    public function test_secret_store_is_a_singleton(): void
    {
        $this->assertSame(
            $this->app->make(SecretStore::class),
            $this->app->make(SecretStore::class),
        );
    }
}
