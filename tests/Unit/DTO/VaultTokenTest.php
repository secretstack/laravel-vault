<?php

namespace Ibid\Vault\Tests\Unit\DTO;

use Ibid\Vault\DTO\VaultToken;
use PHPUnit\Framework\TestCase;

class VaultTokenTest extends TestCase
{
    public function test_from_auth_parses_the_vault_auth_block(): void
    {
        $token = VaultToken::fromAuth([
            'client_token'   => 's.abc123',
            'lease_duration' => 3600,
            'renewable'      => true,
        ], now: 1_000_000);

        $this->assertSame('s.abc123', $token->clientToken);
        $this->assertSame(3600, $token->leaseDuration);
        $this->assertTrue($token->renewable);
        $this->assertSame(1_000_000, $token->issuedAt);
        $this->assertSame(1_003_600, $token->expiresAt());
    }

    public function test_is_expired_accounts_for_skew(): void
    {
        // issued at 1000, lease 3600 => expires at 4600
        $token = new VaultToken('s.x', 3600, true, 1000);

        $this->assertFalse($token->isExpired(skewSeconds: 30, now: 2000));  // well before expiry
        $this->assertTrue($token->isExpired(skewSeconds: 30, now: 4580));   // inside the 30s skew window
        $this->assertTrue($token->isExpired(skewSeconds: 30, now: 5000));   // past expiry
    }
}
