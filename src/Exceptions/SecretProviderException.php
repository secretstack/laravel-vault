<?php

namespace Vaultenv\Vault\Exceptions;

use RuntimeException;

/**
 * Thrown by a SecretProvider when secrets cannot be fetched from its source.
 *
 * This is the contract-level exception (see Contracts\SecretProvider). Driver
 * implementations throw more specific subclasses (e.g. VaultException).
 */
class SecretProviderException extends RuntimeException
{
}
