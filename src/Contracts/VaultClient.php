<?php

namespace Vaultenv\Vault\Contracts;

use Vaultenv\Vault\DTO\VaultSecret;
use Vaultenv\Vault\Exceptions\VaultException;

/**
 * Low-level Vault HTTP transport. Engine-agnostic except for the KV-v2
 * read convenience. Implementations own retry/backoff/jitter/deadline.
 */
interface VaultClient
{
    /**
     * Perform a Vault API request against `/v1/{path}` and return the decoded body.
     *
     * @param array<string,mixed> $options Guzzle-style options (json, headers)
     * @return array<string,mixed>
     *
     * @throws VaultException
     */
    public function request(string $method, string $path, array $options = []): array;

    /**
     * Read a KV-v2 secret at $path using the given client token.
     *
     * @throws VaultException
     */
    public function readKvV2(string $path, string $clientToken): VaultSecret;
}
