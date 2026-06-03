<?php

namespace Ibid\Vault\Tests\Feature;

use Ibid\Vault\Bootstrap\VaultBootstrap;
use Ibid\Vault\Exceptions\VaultException;
use Ibid\Vault\Tests\TestCase;

class VaultBootstrapTest extends TestCase
{
    /** @var list<string> */
    private array $touched = [
        'VAULT_ENABLED', 'VAULT_FAIL_OPEN', 'VAULT_ROLE_ID',
        'VAULT_SECRET_ID', 'VAULT_SECRET_PATH', 'APP_KEY',
    ];

    protected function tearDown(): void
    {
        foreach ($this->touched as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    public function test_no_op_when_disabled_even_with_bad_config(): void
    {
        $this->setEnv('VAULT_ENABLED', 'false');
        $this->setEnv('VAULT_FAIL_OPEN', 'false');
        $this->setEnv('VAULT_ROLE_ID', ''); // would throw if the loader proceeded

        VaultBootstrap::inject($this->app);

        $this->assertTrue(true, 'disabled loader must return without touching Vault');
    }

    public function test_fail_closed_throws_on_cold_start(): void
    {
        $this->setEnv('VAULT_ENABLED', 'true');
        $this->setEnv('VAULT_FAIL_OPEN', 'false');
        $this->setEnv('VAULT_ROLE_ID', ''); // missing credential => cold/unbootable

        $this->expectException(VaultException::class);

        VaultBootstrap::inject($this->app);
    }

    public function test_fail_open_swallows_and_returns(): void
    {
        $this->setEnv('VAULT_ENABLED', 'true');
        $this->setEnv('VAULT_FAIL_OPEN', 'true');
        $this->setEnv('VAULT_ROLE_ID', ''); // same failure, but fail-open must not throw

        VaultBootstrap::inject($this->app);

        $this->assertTrue(true, 'fail-open must swallow the bootstrap failure');
    }
}
