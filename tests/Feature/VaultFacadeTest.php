<?php

namespace Vaultenv\Vault\Tests\Feature;

use Vaultenv\Vault\Contracts\SecretProvider;
use Vaultenv\Vault\Facades\Vault;
use Vaultenv\Vault\Secrets\SecretStore;
use Vaultenv\Vault\Tests\TestCase;
use Mockery;

class VaultFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_facade_resolves_values_from_the_secret_store(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andReturn(['DB_PASSWORD' => 'via-facade']);

        // Bind a stubbed store so the facade never contacts Vault.
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->assertSame('via-facade', Vault::get('DB_PASSWORD'));
        $this->assertSame('fallback', Vault::get('MISSING', 'fallback'));
    }
}
