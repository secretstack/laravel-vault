<?php

namespace Ibid\Vault\Tests\Feature;

use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Secrets\SecretStore;
use Ibid\Vault\Tests\TestCase;
use Mockery;

class RefreshCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refresh_forgets_cache_and_refetches(): void
    {
        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('forget')->once();
        $this->app->instance(SecretCache::class, $cache);

        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn(['A' => '1', 'B' => '2']);
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:refresh')->assertExitCode(0);
    }
}
