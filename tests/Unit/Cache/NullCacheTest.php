<?php

namespace Vaultenv\Vault\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Vaultenv\Vault\Cache\NullCache;

class NullCacheTest extends TestCase
{
    public function test_get_returns_null_and_logs_cache_disabled(): void
    {
        $logged = [];
        $logger = new class ($logged) extends AbstractLogger {
            public function __construct(private array &$records) {}
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message];
            }
        };
        $cache  = new NullCache($logger);

        $this->assertNull($cache->get());
        $this->assertSame([['level' => 'debug', 'message' => 'vault.cache.disabled']], $logged);
    }

    public function test_get_stale_returns_null(): void
    {
        $this->assertNull((new NullCache(new NullLogger()))->getStale());
    }

    public function test_put_and_forget_are_no_ops(): void
    {
        $cache = new NullCache(new NullLogger());
        $cache->put(['DB_PASSWORD' => 'secret'], 300);
        $cache->forget();
        // reaching here means no exception was thrown
        $this->assertTrue(true);
    }

    public function test_get_after_put_still_returns_null(): void
    {
        $cache = new NullCache(new NullLogger());
        $cache->put(['DB_PASSWORD' => 'secret'], 300);

        $this->assertNull($cache->get());
    }
}
