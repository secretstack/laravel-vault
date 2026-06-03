<?php

namespace Ibid\Vault\Secrets;

use Ibid\Vault\Contracts\SecretProvider;

/**
 * Per-worker, write-once in-memory holder of resolved secrets.
 *
 * Octane-safe: secrets are resolved once at first access and memoized for the
 * worker's lifetime; never mutated mid-request. Holds NO static state (a static
 * would leak/share across workers). Rotation is via rolling restart, not a live
 * refresh (Q5) — `refresh()` exists for the dev/cache-warm path only.
 */
final class SecretStore
{
    /** @var array<string,string>|null */
    private ?array $secrets = null;

    public function __construct(
        private readonly SecretProvider $provider,
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->secrets ??= $this->provider->fetch();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Drop the in-memory copy and reload. The provider's own cache is busted
     * separately (RefreshCommand) before this is called.
     *
     * @return array<string,string>
     */
    public function refresh(): array
    {
        $this->secrets = null;

        return $this->all();
    }
}
