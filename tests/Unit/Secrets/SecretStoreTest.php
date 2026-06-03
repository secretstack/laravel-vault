<?php

namespace Ibid\Vault\Tests\Unit\Secrets;

use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Secrets\SecretStore;
use Mockery;
use PHPUnit\Framework\TestCase;

class SecretStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_loads_from_provider_once_and_memoizes(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn(['A' => '1']);

        $store = new SecretStore($provider);

        $first  = $store->all();
        $second = $store->all(); // must NOT call the provider again

        $this->assertSame(['A' => '1'], $first);
        $this->assertSame($first, $second);
    }

    public function test_get_returns_value_or_default(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn(['DB_PASSWORD' => 'p@ss']);

        $store = new SecretStore($provider);

        $this->assertSame('p@ss', $store->get('DB_PASSWORD'));
        $this->assertSame('fallback', $store->get('MISSING', 'fallback'));
        $this->assertNull($store->get('MISSING'));
    }

    public function test_refresh_reloads_from_provider(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->twice()->andReturn(['A' => '1'], ['A' => '2']);

        $store = new SecretStore($provider);

        $this->assertSame(['A' => '1'], $store->all());
        $this->assertSame(['A' => '2'], $store->refresh(), 'refresh must re-call the provider');
    }
}
