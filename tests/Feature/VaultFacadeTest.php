<?php

namespace Ibid\Vault\Tests\Feature;

use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Facades\Vault;
use Ibid\Vault\Secrets\SecretStore;
use Ibid\Vault\Tests\TestCase;
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
