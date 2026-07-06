<?php

namespace Vaultenv\Vault\Provider;

use Vaultenv\Vault\Contracts\AuthMethod;
use Vaultenv\Vault\Contracts\SecretCache;
use Vaultenv\Vault\Contracts\SecretProvider;
use Vaultenv\Vault\Contracts\VaultClient;
use Vaultenv\Vault\Exceptions\VaultException;
use Psr\Log\LoggerInterface;

/**
 * The Vault driver: authenticates, reads a KV-v2 path, and caches the result.
 *
 * Resolution order: fresh cache -> Vault. On a Vault failure with a usable
 * (even expired) cache, serves last-known-good (grace, ADR-0004); with nothing
 * cached, the failure propagates (cold start, fail-closed at the loader).
 *
 * When a defaults path is configured (ADR-0015), both paths are fetched and
 * merged atomically — service key shadows defaults key — and the cache holds
 * the single merged payload. Either fetch failing means the fetch failed; a
 * 404 on the configured defaults path is a hard error, never treated as empty.
 *
 * Returns a flat array<string,string>; the lease, KV version, and auth method
 * never leak through the SecretProvider contract (ADR-0006).
 */
final class VaultSecretProvider implements SecretProvider
{
    public function __construct(
        private readonly VaultClient $client,
        private readonly AuthMethod $auth,
        private readonly SecretCache $cache,
        private readonly string $secretPath,
        private readonly int $cacheTtl,
        private readonly int $cacheSkew,
        private readonly LoggerInterface $logger,
        private readonly string $defaultsPath = '',
    ) {
    }

    public function fetch(): array
    {
        $cached = $this->cache->get();
        if ($cached !== null) {
            $this->logger->debug('vault.cache.hit', ['keys' => array_keys($cached['secrets'])]);

            return $cached['secrets'];
        }

        try {
            $token = $this->auth->authenticate($this->client);

            $defaults = $this->defaultsPath !== ''
                ? $this->client->readKvV2($this->defaultsPath, $token->clientToken)->data
                : [];

            $secret = $this->client->readKvV2($this->secretPath, $token->clientToken);
            $merged = array_merge($defaults, $secret->data);

            $ttl = max(1, min($token->leaseDuration - $this->cacheSkew, $this->cacheTtl));
            $this->cache->put($merged, $ttl);

            $this->logger->info('vault.fetch.ok', [
                'path'          => $this->secretPath,
                'defaults_path' => $this->defaultsPath,
                'version'       => $secret->version,
                'keys'          => array_keys($merged),
                'shadowed_keys' => array_keys(array_intersect_key($defaults, $secret->data)),
            ]);

            return $merged;
        } catch (VaultException $e) {
            // Grace: serve last-known-good if we have any (ADR-0004).
            $stale = $this->cache->getStale();
            if ($stale !== null) {
                $this->logger->warning('vault.cache.stale_served', [
                    'path'  => $this->secretPath,
                    'error' => $e->getMessage(),
                    'keys'  => array_keys($stale['secrets']),
                ]);

                return $stale['secrets'];
            }

            // Cold start: nothing to fall back to — propagate.
            $this->logger->error('vault.fetch.fail', [
                'path'  => $this->secretPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
