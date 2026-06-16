<?php

namespace Vaultenv\Vault\Secrets;

use Vaultenv\Vault\Config\VaultConfig;

/**
 * The single source of truth for the local-override decision (ADR-0014).
 *
 * Production precedence is "Vault wins" (ADR-0005): a forgotten stale .env value
 * must never shadow the real secret. For local cross-service development that
 * rule is inverted — but ONLY, explicitly, and observably:
 *   - active iff APP_ENV === 'local' AND the key is named in VAULT_LOCAL_OVERRIDES,
 *   - a non-empty override list outside local is a misconfig to surface, not obey.
 *
 * Both the Loader's EnvInjector and the ServiceProvider's key_map backstop
 * consult this one object, so the precedence rule cannot drift between the two
 * boot phases (the bug that would otherwise let an override work everywhere
 * except for keys listed in key_map).
 */
final class OverridePolicy
{
    /**
     * @param list<string> $keys keys whose local .env value should survive
     */
    public function __construct(
        private readonly bool $isLocal,
        private readonly array $keys,
    ) {
    }

    public static function fromConfig(VaultConfig $config): self
    {
        return new self(
            isLocal: $config->appEnv === 'local',
            keys: $config->localOverrides,
        );
    }

    /** Override is in effect: we are local and at least one key is listed. */
    public function isActive(): bool
    {
        return $this->isLocal && $this->keys !== [];
    }

    /** Override keys exist but we are NOT local — surface it, never apply it. */
    public function hasNonLocalLeftover(): bool
    {
        return ! $this->isLocal && $this->keys !== [];
    }

    /** True when the key's local .env value must be kept instead of Vault's. */
    public function shouldKeepLocal(string $key): bool
    {
        return $this->isActive() && in_array($key, $this->keys, true);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }
}
