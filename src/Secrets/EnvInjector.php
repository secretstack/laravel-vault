<?php

namespace Ibid\Vault\Secrets;

use Psr\Log\LoggerInterface;

/**
 * Injects resolved secrets into the process environment so existing
 * env()/config() consumers pick them up transparently.
 *
 * Enforces a hard deny-list (ADR-0005): APP_KEY, APP_ENV, and any VAULT_* key
 * are NEVER injected from the secret payload — they are bootstrap-tier config
 * and letting Vault override them would create a boot loop. Values are never
 * logged; only key names and counts.
 */
final class EnvInjector
{
    private const DENY_EXACT  = ['APP_KEY', 'APP_ENV'];
    private const DENY_PREFIX = ['VAULT_'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $secrets
     *
     * @return list<string> the keys actually injected
     */
    public function inject(array $secrets): array
    {
        $injected = [];

        foreach ($secrets as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            if ($this->isDenied($key)) {
                $this->logger->warning('vault.denylist.hit', ['key' => $key]);

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
