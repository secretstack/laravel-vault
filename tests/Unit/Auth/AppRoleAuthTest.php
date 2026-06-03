<?php

namespace Ibid\Vault\Tests\Unit\Auth;

use Ibid\Vault\Auth\AppRoleAuth;
use Ibid\Vault\Contracts\VaultClient;
use Ibid\Vault\Exceptions\VaultException;
use Mockery;
use PHPUnit\Framework\TestCase;

class AppRoleAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_authenticate_posts_credentials_and_returns_token(): void
    {
        $client = Mockery::mock(VaultClient::class);
        $client->shouldReceive('request')
            ->once()
            ->with('POST', 'auth/approle/login', ['json' => ['role_id' => 'r-id', 'secret_id' => 's-id']])
            ->andReturn([
                'auth' => ['client_token' => 's.tok', 'lease_duration' => 600, 'renewable' => true],
            ]);

        $token = (new AppRoleAuth('r-id', 's-id', 'approle'))->authenticate($client);

        $this->assertSame('s.tok', $token->clientToken);
        $this->assertSame(600, $token->leaseDuration);
        $this->assertTrue($token->renewable);
    }

    public function test_authenticate_throws_when_client_token_missing(): void
    {
        $client = Mockery::mock(VaultClient::class);
        $client->shouldReceive('request')->once()->andReturn(['auth' => []]);

        $this->expectException(VaultException::class);

        (new AppRoleAuth('r-id', 's-id'))->authenticate($client);
    }
}
