<?php

namespace Vaultenv\Vault\Facades;

use Vaultenv\Vault\Secrets\SecretStore;
use Illuminate\Support\Facades\Facade;

/**
 * On-demand accessor for Vault-managed secrets.
 *
 * @method static array<string,string> all()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static array<string,string> refresh()
 *
 * @see SecretStore
 */
final class Vault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SecretStore::class;
    }
}
