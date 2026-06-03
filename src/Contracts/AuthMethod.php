<?php

namespace Ibid\Vault\Contracts;

use Ibid\Vault\DTO\VaultToken;
use Ibid\Vault\Exceptions\VaultException;

/**
 * A Vault authentication strategy. AppRole is the only implementation in v1;
 * this seam lets Azure / JWT / Workload Identity slot in later (ADR-0006)
 * without touching the rest of the package.
 */
interface AuthMethod
{
    /**
     * Authenticate against Vault and return a token.
     *
     * @throws VaultException
     */
    public function authenticate(VaultClient $client): VaultToken;
}
