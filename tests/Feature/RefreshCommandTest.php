<?php

namespace Vaultenv\Vault\Tests\Feature;

use Vaultenv\Vault\Config\VaultConfig;
use Vaultenv\Vault\Contracts\SecretCache;
use Vaultenv\Vault\Contracts\SecretProvider;
use Vaultenv\Vault\Secrets\SecretStore;
use Vaultenv\Vault\Tests\TestCase;
use Mockery;

class RefreshCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function vaultConfig(bool $cacheEnabled = true): VaultConfig
    {
        return VaultConfig::fromArray(
            ['enabled' => true, 'cache' => ['enabled' => $cacheEnabled, 'path' => sys_get_temp_dir()]],
            'base64:' . base64_encode(str_repeat('a', 32)),
        );
    }

    public function test_refresh_forgets_cache_and_refetches(): void
    {
        $this->app->instance(VaultConfig::class, $this->vaultConfig(cacheEnabled: true));

        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('forget')->once();
        $this->app->instance(SecretCache::class, $cache);

        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn(['A' => '1', 'B' => '2']);
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:refresh')->assertExitCode(0);
    }

    public function test_refresh_warns_when_cache_is_disabled(): void
    {
        $this->app->instance(VaultConfig::class, $this->vaultConfig(cacheEnabled: false));

        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('forget')->once();
        $this->app->instance(SecretCache::class, $cache);

        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn(['A' => '1']);
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:refresh')
            ->expectsOutput('Cache is disabled; secrets will be re-fetched from Vault but not persisted.')
            ->assertExitCode(0);
    }
}
