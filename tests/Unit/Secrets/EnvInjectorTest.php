<?php

namespace Vaultenv\Vault\Tests\Unit\Secrets;

use Vaultenv\Vault\Secrets\EnvInjector;
use Vaultenv\Vault\Secrets\OverridePolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

class EnvInjectorTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['IBID_TEST_FOO', 'DB_PASSWORD', 'VAULT_FOO_TEST', 'APP_KEY', 'APP_ENV', 'STOCKV2_SERVICE_URL'] as $k) {
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

    public function test_skips_non_string_values(): void
    {
        /** @phpstan-ignore-next-line intentional bad type to exercise the guard */
        $injected = (new EnvInjector(new NullLogger()))->inject(['IBID_TEST_FOO' => 'ok', 'IBID_BAD' => 123]);

        $this->assertSame(['IBID_TEST_FOO'], $injected, 'non-string values are skipped');
    }

    public function test_keeps_local_value_for_overridden_key_when_active(): void
    {
        $_ENV['STOCKV2_SERVICE_URL'] = 'http://localhost:8002'; // dev's .env value, already loaded at boot
        $logger = new SpyLoggerStub();
        $policy = new OverridePolicy(isLocal: true, keys: ['STOCKV2_SERVICE_URL']);

        $injected = (new EnvInjector($logger, $policy))->inject([
            'STOCKV2_SERVICE_URL' => 'http://stockv2.dev.svc', // Vault's value, must NOT win
            'DB_PASSWORD'         => 'from-vault',
        ]);

        $this->assertSame(['DB_PASSWORD'], $injected, 'overridden key is not injected from Vault');
        $this->assertSame('http://localhost:8002', $_ENV['STOCKV2_SERVICE_URL'], 'local .env value is preserved');
        $this->assertSame('from-vault', $_ENV['DB_PASSWORD'], 'unlisted keys still come from Vault');
        $this->assertContains('vault.override.hit', $logger->messages);
    }

    public function test_vault_wins_for_overridden_key_when_not_local(): void
    {
        $_ENV['STOCKV2_SERVICE_URL'] = 'http://localhost:8002';
        $logger = new SpyLoggerStub();
        $policy = new OverridePolicy(isLocal: false, keys: ['STOCKV2_SERVICE_URL']);

        $injected = (new EnvInjector($logger, $policy))->inject(['STOCKV2_SERVICE_URL' => 'http://stockv2.dev.svc']);

        $this->assertSame(['STOCKV2_SERVICE_URL'], $injected, 'non-local: Vault wins as usual (ADR-0005)');
        $this->assertSame('http://stockv2.dev.svc', $_ENV['STOCKV2_SERVICE_URL']);
        $this->assertContains('vault.override.ignored_nonlocal', $logger->messages, 'leftover override is surfaced, not silent');
    }

    public function test_no_policy_behaves_exactly_as_before(): void
    {
        $_ENV['STOCKV2_SERVICE_URL'] = 'http://localhost:8002';

        $injected = (new EnvInjector(new NullLogger()))->inject(['STOCKV2_SERVICE_URL' => 'http://stockv2.dev.svc']);

        $this->assertSame(['STOCKV2_SERVICE_URL'], $injected);
        $this->assertSame('http://stockv2.dev.svc', $_ENV['STOCKV2_SERVICE_URL'], 'no policy -> Vault always wins');
    }
}

/** Minimal PSR-3 spy capturing event names only (never values, per ADR-0007). @internal */
final class SpyLoggerStub extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
