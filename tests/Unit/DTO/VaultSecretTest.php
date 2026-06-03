<?php

namespace Ibid\Vault\Tests\Unit\DTO;

use Ibid\Vault\DTO\VaultSecret;
use PHPUnit\Framework\TestCase;

class VaultSecretTest extends TestCase
{
    public function test_from_kv_v2_response_parses_nested_data_and_version(): void
    {
        // KV-v2 nests the secret under data.data and the version under data.metadata.version
        $secret = VaultSecret::fromKvV2Response([
            'data' => [
                'data'     => ['DB_PASSWORD' => 'p@ss', 'JWT_SECRET' => 'jwt'],
                'metadata' => ['version' => 2],
            ],
        ], now: 1_000_000);

        $this->assertSame(['DB_PASSWORD' => 'p@ss', 'JWT_SECRET' => 'jwt'], $secret->data);
        $this->assertSame(2, $secret->version);
        $this->assertSame(1_000_000, $secret->fetchedAt);
    }

    public function test_from_kv_v2_response_defaults_to_empty_data_when_missing(): void
    {
        $secret = VaultSecret::fromKvV2Response([], now: 1_000_000);

        $this->assertSame([], $secret->data);
        $this->assertSame(0, $secret->version);
    }
}
