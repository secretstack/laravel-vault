<?php

namespace Ibid\Vault\Tests\Unit\Cache;

use Ibid\Vault\Cache\EncryptedFileCache;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EncryptedFileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/vault-cache-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $file = $this->dir . '/secrets.cache';
        if (is_file($file)) {
            @unlink($file);
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    private function makeCache(?string $key = null): EncryptedFileCache
    {
        $key ??= str_repeat('a', 32); // 32 bytes for AES-256-CBC
        return new EncryptedFileCache(new Encrypter($key, 'AES-256-CBC'), $this->dir, new NullLogger());
    }

    public function test_put_then_get_round_trips_secrets(): void
    {
        $cache = $this->makeCache();
        $cache->put(['DB_PASSWORD' => 'p@ss'], 300);

        $payload = $cache->get();

        $this->assertNotNull($payload);
        $this->assertSame(['DB_PASSWORD' => 'p@ss'], $payload['secrets']);
        $this->assertGreaterThan(time(), $payload['expires_at']);
    }

    public function test_get_returns_null_when_expired(): void
    {
        $cache = $this->makeCache();
        $cache->put(['A' => '1'], -10); // already expired

        $this->assertNull($cache->get());
    }

    public function test_get_stale_returns_payload_even_when_expired(): void
    {
        $cache = $this->makeCache();
        $cache->put(['A' => '1'], -10); // expired

        $stale = $cache->getStale();

        $this->assertNotNull($stale);
        $this->assertSame(['A' => '1'], $stale['secrets']);
    }

    public function test_get_returns_null_and_discards_on_tampered_file(): void
    {
        $cache = $this->makeCache();
        $cache->put(['A' => '1'], 300);

        file_put_contents($this->dir . '/secrets.cache', 'tampered-not-encrypted');

        $this->assertNull($cache->get());
        $this->assertFileDoesNotExist($this->dir . '/secrets.cache', 'tampered cache should be discarded');
    }

    public function test_get_returns_null_when_decrypted_with_wrong_key(): void
    {
        $this->makeCache(str_repeat('a', 32))->put(['A' => '1'], 300);

        $other = $this->makeCache(str_repeat('b', 32));

        $this->assertNull($other->get(), 'a different APP_KEY must not decrypt the cache');
    }

    public function test_put_writes_file_0600_and_dir_0700(): void
    {
        $cache = $this->makeCache();
        $cache->put(['A' => '1'], 300);

        $this->assertSame(0600, fileperms($this->dir . '/secrets.cache') & 0777);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function test_forget_deletes_the_cache_file(): void
    {
        $cache = $this->makeCache();
        $cache->put(['A' => '1'], 300);

        $cache->forget();

        $this->assertNull($cache->get());
        $this->assertFileDoesNotExist($this->dir . '/secrets.cache');
    }
}
