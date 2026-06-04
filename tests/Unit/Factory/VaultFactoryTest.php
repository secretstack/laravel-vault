<?php

namespace Ibid\Vault\Tests\Unit\Factory;

use Ibid\Vault\Config\VaultConfig;
use Ibid\Vault\Exceptions\VaultException;
use Ibid\Vault\Factory\VaultFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VaultFactoryTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/vault-factory-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $file = $this->cacheDir . '/secrets.cache';
        if (is_file($file)) {
            @unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            @rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = [], ?string $appKey = null): VaultConfig
    {
        $base = [
            'enabled'     => true,
            'address'     => 'http://127.0.0.1:1', // unroutable: any real fetch attempt fails fast
            'namespace'   => '',
            'auth'        => ['mount' => 'approle', 'role_id' => 'rid', 'secret_id' => 'sid'],
            'secret_path' => 'ibid/data/ims/dev/svc',
            'fail_open'   => false,
            'cache'       => ['enabled' => true, 'path' => $this->cacheDir, 'ttl' => 600, 'skew' => 30],
            'http'        => ['timeout' => 1, 'retries' => 1, 'retry_delay' => 1, 'max_delay' => 2, 'verify' => true],
        ];

        return VaultConfig::fromArray(
            array_replace_recursive($base, $overrides),
            appKey: $appKey ?? 'base64:' . base64_encode(str_repeat('a', 32)),
        );
    }

    public function test_make_store_serves_cached_secrets_without_contacting_vault(): void
    {
        $factory = new VaultFactory();
        $logger  = new NullLogger();
        $cfg     = $this->config();

        // Seed through the factory's OWN cache wiring: if makeCache and makeStore
        // disagreed on path or encryption key, makeStore could not read this back.
        $factory->makeCache($cfg, $logger)->put(['DB_PASSWORD' => 's3cret'], ttl: 600);

        $secrets = $factory->makeStore($cfg, $logger)->all();

        $this->assertSame(['DB_PASSWORD' => 's3cret'], $secrets);
    }

    public function test_make_cache_throws_a_clear_exception_when_app_key_is_empty(): void
    {
        $cfg = $this->config(appKey: '');

        $this->expectException(VaultException::class);
        $this->expectExceptionMessage('APP_KEY');

        (new VaultFactory())->makeCache($cfg, new NullLogger());
    }

    public function test_make_store_refuses_to_assemble_an_unusable_config(): void
    {
        $cfg = $this->config(['auth' => ['role_id' => '']]); // incomplete: missing role id

        // The guard fires at wire time (inside makeProvider), before any fetch —
        // makeStore() itself throws rather than returning a store that fails later.
        $this->expectException(VaultException::class);

        (new VaultFactory())->makeStore($cfg, new NullLogger());
    }
}
