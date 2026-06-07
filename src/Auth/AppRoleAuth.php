<?php

namespace Vaultenv\Vault\Auth;

use Vaultenv\Vault\Contracts\AuthMethod;
use Vaultenv\Vault\Contracts\VaultClient;
use Vaultenv\Vault\DTO\VaultToken;
use Vaultenv\Vault\Exceptions\VaultException;

/**
 * AppRole authentication: exchanges a role_id + secret_id for a client token.
 *
 * The only AuthMethod in v1. The secret_id is held in memory only and never
 * logged. Azure / JWT / Workload Identity would be sibling implementations.
 */
final class AppRoleAuth implements AuthMethod
{
    public function __construct(
        private readonly string $roleId,
        private readonly string $secretId,
        private readonly string $mount = 'approle',
    ) {
    }

    public function authenticate(VaultClient $client): VaultToken
    {
        $response = $client->request('POST', "auth/{$this->mount}/login", [
            'json' => ['role_id' => $this->roleId, 'secret_id' => $this->secretId],
        ]);

        if (! isset($response['auth']['client_token'])) {
            throw new VaultException('Vault AppRole login response missing auth.client_token');
        }

        return VaultToken::fromAuth($response['auth']);
    }
}
