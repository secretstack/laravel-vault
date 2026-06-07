<?php

namespace Vaultenv\Vault\Cache;

use Vaultenv\Vault\Contracts\SecretCache;
use Psr\Log\LoggerInterface;

/**
 * No-op cache adapter for when caching is disabled. Every read is a miss;
 * writes and forgets are silent no-ops. Emits vault.cache.disabled on get()
 * so operators can confirm cache state in the vault log.
 */
final class NullCache implements SecretCache
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(): ?array
    {
        $this->logger->debug('vault.cache.disabled');

        return null;
    }

    public function getStale(): ?array
    {
        return null;
    }

    public function put(array $secrets, int $ttl): void
    {
    }

    public function forget(): void
    {
    }
}
