<?php

namespace Vaultenv\Vault\Secrets;

use Psr\Log\LoggerInterface;

/**
 * Injects resolved secrets into the process environment so existing
 * env()/config() consumers pick them up transparently.
 *
 * Enforces a hard deny-list (ADR-0005): APP_KEY, APP_ENV, and any VAULT_* key
 * are NEVER injected from the secret payload — they are bootstrap-tier config
 * and letting Vault override them would create a boot loop. Values are never
 * logged; only key names and counts.
 *
 * Honors the local-override allow-list (ADR-0014): when the OverridePolicy is
 * active, a listed key keeps its local .env value instead of being overwritten
 * by Vault. The deny-list is checked first and always wins, so an override can
 * never resurrect a bootstrap-tier key.
 */
final class EnvInjector
{
    private const DENY_EXACT  = ['APP_KEY', 'APP_ENV'];
    private const DENY_PREFIX = ['VAULT_'];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?OverridePolicy $overrides = null,
    ) {
    }

    /**
     * @param array<string,string> $secrets
     *
     * @return list<string> the keys actually injected
     */
    public function inject(array $secrets): array
    {
        $this->warnOnNonLocalLeftover();

        $injected = [];

        foreach ($secrets as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            if ($this->isDenied($key)) {
                $this->logger->warning('vault.denylist.hit', ['key' => $key]);

                continue;
            }

            if ($this->overrides?->shouldKeepLocal($key)) {
                // Local dev wins (ADR-0014): leave the dev's .env value in place.
                $this->logger->info('vault.override.hit', ['key' => $key]);

                continue;
            }

            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
            $injected[] = $key;
        }

        $this->logger->info('vault.inject.count', ['count' => count($injected)]);

        return $injected;
    }

    /**
     * Surface — never silently obey — a VAULT_LOCAL_OVERRIDES that leaked into a
     * non-local environment (e.g. a stray value baked into a deployed pod). The
     * override is ignored (Vault-wins still holds); we only log it (ADR-0014).
     */
    private function warnOnNonLocalLeftover(): void
    {
        if (! $this->overrides?->hasNonLocalLeftover()) {
            return;
        }

        foreach ($this->overrides->keys() as $key) {
            $this->logger->warning('vault.override.ignored_nonlocal', ['key' => $key]);
        }
    }

    private function isDenied(string $key): bool
    {
        if (in_array($key, self::DENY_EXACT, true)) {
            return true;
        }

        foreach (self::DENY_PREFIX as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
