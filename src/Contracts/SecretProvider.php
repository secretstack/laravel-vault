<?php

namespace Ibid\Vault\Contracts;

use Ibid\Vault\Exceptions\SecretProviderException;

/**
 * The source of a service's secrets. The only implementation in v1 is the
 * Vault driver; this is the public extensibility seam (ADR-0006).
 *
 * Implementations return a flat key=>value map and MUST NOT leak source-specific
 * concepts (leases, KV versions, auth methods) through this contract.
 */
interface SecretProvider
{
    /**
     * @return array<string,string>
     *
     * @throws SecretProviderException when no secrets can be obtained by any means
     */
    public function fetch(): array;
}
