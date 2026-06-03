<?php

namespace Ibid\Vault\DTO;

/**
 * A Vault auth token as returned by an AppRole login.
 *
 * Immutable value object. Holds only what the read path needs; the raw
 * client token is never logged or serialized by the package.
 */
final class VaultToken
{
    public function __construct(
        public readonly string $clientToken,
        public readonly int $leaseDuration,
        public readonly bool $renewable,
        public readonly int $issuedAt,
    ) {
    }

    /**
     * Build from a Vault login response's `auth` block.
     *
     * @param array<string,mixed> $auth
     */
    public static function fromAuth(array $auth, ?int $now = null): self
    {
        return new self(
            clientToken: (string) ($auth['client_token'] ?? ''),
            leaseDuration: (int) ($auth['lease_duration'] ?? 0),
            renewable: (bool) ($auth['renewable'] ?? false),
            issuedAt: $now ?? time(),
        );
    }

    public function expiresAt(): int
    {
        return $this->issuedAt + $this->leaseDuration;
    }

    /**
     * Whether the token is at or past expiry, treating the final
     * $skewSeconds before expiry as already expired (safety margin).
     */
    public function isExpired(int $skewSeconds = 30, ?int $now = null): bool
    {
        return ($now ?? time()) >= ($this->expiresAt() - $skewSeconds);
    }
}
