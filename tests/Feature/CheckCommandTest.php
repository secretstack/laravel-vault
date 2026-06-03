<?php

namespace Ibid\Vault\Tests\Feature;

use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Exceptions\VaultException;
use Ibid\Vault\Secrets\SecretStore;
use Ibid\Vault\Tests\TestCase;
use Mockery;

class CheckCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_gate_passes_when_secrets_are_obtainable(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andReturn(['A' => '1']);
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:check --gate')->assertExitCode(0);
    }

    public function test_gate_fails_on_cold_start_with_nothing_obtainable(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andThrow(new VaultException('cold: vault unreachable, no cache'));
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:check --gate')->assertExitCode(1);
    }

    public function test_plain_mode_reports_available_secrets(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andReturn(['DB_PASSWORD' => 'supersecret']);
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:check')
            ->expectsOutputToContain('Secrets available')
            ->assertExitCode(0);
    }

    public function test_plain_mode_fails_when_secrets_unobtainable(): void
    {
        $provider = Mockery::mock(SecretProvider::class);
        $provider->shouldReceive('fetch')->andThrow(new VaultException('cold'));
        $this->app->instance(SecretStore::class, new SecretStore($provider));

        $this->artisan('vault:check')->assertExitCode(1);
    }
}
