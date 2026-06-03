<?php

namespace Ibid\Vault\Tests\Unit\Secrets;

use Ibid\Vault\Secrets\EnvInjector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EnvInjectorTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['IBID_TEST_FOO', 'DB_PASSWORD', 'VAULT_FOO_TEST', 'APP_KEY', 'APP_ENV'] as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
    }

    public function test_injects_into_env_server_and_putenv(): void
    {
        $injected = (new EnvInjector(new NullLogger()))->inject(['IBID_TEST_FOO' => 'bar']);

        $this->assertSame(['IBID_TEST_FOO'], $injected);
        $this->assertSame('bar', $_ENV['IBID_TEST_FOO']);
        $this->assertSame('bar', $_SERVER['IBID_TEST_FOO']);
        $this->assertSame('bar', getenv('IBID_TEST_FOO'));
    }

    public function test_never_injects_denied_bootstrap_keys(): void
    {
        $injected = (new EnvInjector(new NullLogger()))->inject([
            'APP_KEY'        => 'should-not',
            'APP_ENV'        => 'should-not',
            'VAULT_FOO_TEST' => 'should-not',
            'DB_PASSWORD'    => 'ok',
        ]);

        $this->assertSame(['DB_PASSWORD'], $injected, 'only non-bootstrap keys are injected');
        $this->assertFalse(getenv('VAULT_FOO_TEST'), 'VAULT_* must never be injected');
    }
}
