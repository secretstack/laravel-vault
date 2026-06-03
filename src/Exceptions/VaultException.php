<?php

namespace Ibid\Vault\Exceptions;

/**
 * A HashiCorp Vault-specific failure (auth, HTTP, or malformed response).
 *
 * Extends SecretProviderException so that consumers catching the contract-level
 * exception also catch Vault driver errors.
 */
class VaultException extends SecretProviderException
{
}
