<?php

namespace Vaultenv\Vault\Contracts;

/**
 * Persistence for fetched secrets across pod/worker boots.
 *
 * `get()` returns only fresh (unexpired) entries; `getStale()` returns the
 * last-known-good entry even past TTL, for the grace path (ADR-0004).
 */
interface SecretCache
{
    /**
     * @return array{secrets: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    public function get(): ?array;

    /**
     * @return array{secrets: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    public function getStale(): ?array;

    /**
     * @param array<string,string> $secrets
     */
    public function put(array $secrets, int $ttl): void;

    public function forget(): void;
}
