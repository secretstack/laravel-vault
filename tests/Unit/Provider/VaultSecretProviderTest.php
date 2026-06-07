<?php

namespace Vaultenv\Vault\Tests\Unit\Provider;

use Vaultenv\Vault\Cache\NullCache;
use Vaultenv\Vault\Contracts\AuthMethod;
use Vaultenv\Vault\Contracts\SecretCache;
use Vaultenv\Vault\Contracts\VaultClient;
use Vaultenv\Vault\DTO\VaultSecret;
use Vaultenv\Vault\DTO\VaultToken;
use Vaultenv\Vault\Exceptions\VaultException;
use Vaultenv\Vault\Provider\VaultSecretProvider;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VaultSecretProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function makeProvider(
        VaultClient $client,
        AuthMethod $auth,
        SecretCache $cache,
        int $cacheTtl = 300,
        int $cacheSkew = 30,
    ): VaultSecretProvider {
        return new VaultSecretProvider(
            client:     $client,
            auth:       $auth,
            cache:      $cache,
            secretPath: 'ibid/data/ims/dev/stockv2',
            cacheTtl:   $cacheTtl,
            cacheSkew:  $cacheSkew,
            logger:     new NullLogger(),
        );
    }

    private function token(int $leaseDuration = 3600): VaultToken
    {
        return new VaultToken('s.tok', $leaseDuration, true, time());
    }

    private function secret(array $data = ['DB_PASSWORD' => 'vault-value']): VaultSecret
    {
        return new VaultSecret($data, 1, time());
    }

    public function test_returns_cached_secrets_without_contacting_vault(): void
    {
        /** @var VaultClient&MockInterface $client */
        $client = Mockery::mock(VaultClient::class);
        $client->shouldNotReceive('readKvV2');

        /** @var AuthMethod&MockInterface $auth */
        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldNotReceive('authenticate');

        /** @var SecretCache&MockInterface $cache */
        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('get')->once()->andReturn([
            'secrets'    => ['DB_PASSWORD' => 'cached-value'],
            'fetched_at' => time(),
            'expires_at' => time() + 300,
        ]);

        $provider = $this->makeProvider($client, $auth, $cache);

        $this->assertSame(['DB_PASSWORD' => 'cached-value'], $provider->fetch());
    }

    public function test_fetches_from_vault_on_cache_miss_and_caches(): void
    {
        $client = Mockery::mock(VaultClient::class);
        $client->shouldReceive('readKvV2')
            ->once()
            ->with('ibid/data/ims/dev/stockv2', 's.tok')
            ->andReturn($this->secret());

        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldReceive('authenticate')->once()->andReturn($this->token());

        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('put')->once();

        $provider = $this->makeProvider($client, $auth, $cache);

        $this->assertSame(['DB_PASSWORD' => 'vault-value'], $provider->fetch());
    }

    public function test_cache_ttl_is_min_of_lease_minus_skew_and_config_ttl(): void
    {
        $client = Mockery::mock(VaultClient::class);
        $client->shouldReceive('readKvV2')->once()->andReturn($this->secret());

        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldReceive('authenticate')->once()->andReturn($this->token(leaseDuration: 60));

        $captured = null;
        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('put')->once()->withArgs(function (array $secrets, int $ttl) use (&$captured) {
            $captured = $ttl;

            return true;
        });

        $this->makeProvider($client, $auth, $cache, cacheTtl: 300, cacheSkew: 30)->fetch();

        $this->assertSame(30, $captured, 'ttl should be min(lease(60) - skew(30), config(300)) = 30');
    }

    public function test_serves_stale_cache_when_vault_unreachable(): void
    {
        $client = Mockery::mock(VaultClient::class);

        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldReceive('authenticate')->once()->andThrow(new VaultException('connection refused'));

        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('getStale')->once()->andReturn([
            'secrets'    => ['DB_PASSWORD' => 'stale-but-usable'],
            'fetched_at' => time() - 99999,
            'expires_at' => time() - 100,
        ]);

        $provider = $this->makeProvider($client, $auth, $cache);

        $this->assertSame(['DB_PASSWORD' => 'stale-but-usable'], $provider->fetch());
    }

    public function test_propagates_when_vault_unreachable_and_nothing_cached(): void
    {
        $client = Mockery::mock(VaultClient::class);

        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldReceive('authenticate')->once()->andThrow(new VaultException('connection refused'));

        $cache = Mockery::mock(SecretCache::class);
        $cache->shouldReceive('get')->once()->andReturn(null);
        $cache->shouldReceive('getStale')->once()->andReturn(null);

        $this->expectException(VaultException::class);

        $this->makeProvider($client, $auth, $cache)->fetch();
    }

    public function test_null_cache_adapter_bypasses_persistence_transparently(): void
    {
        // When cache is disabled, the NullCache adapter absorbs get/put/getStale
        // without branching in the Provider. Vault is always contacted; no stale
        // grace is available (NullCache.getStale() → null → cold fail path).
        $client = Mockery::mock(VaultClient::class);
        $client->shouldReceive('readKvV2')->once()->andReturn(
            new VaultSecret(['API_KEY' => 'live-value'], 1, time())
        );

        $auth = Mockery::mock(AuthMethod::class);
        $auth->shouldReceive('authenticate')->once()->andReturn($this->token());

        $result = $this->makeProvider($client, $auth, new NullCache(new NullLogger()))->fetch();

        $this->assertSame(['API_KEY' => 'live-value'], $result);
    }
}
