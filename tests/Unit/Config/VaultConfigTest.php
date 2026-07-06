<?php

namespace Vaultenv\Vault\Tests\Unit\Config;

use Vaultenv\Vault\Config\VaultConfig;
use Vaultenv\Vault\Exceptions\VaultException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VaultConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'VAULT_ENABLED', 'VAULT_ADDR', 'VAULT_NAMESPACE', 'VAULT_AUTH_MOUNT',
        'VAULT_ROLE_ID', 'VAULT_SECRET_ID', 'VAULT_SECRET_PATH', 'VAULT_FAIL_OPEN',
        'VAULT_CACHE_ENABLED', 'VAULT_CACHE_TTL', 'VAULT_HTTP_TIMEOUT', 'VAULT_HTTP_RETRIES',
        'VAULT_TLS_VERIFY', 'APP_KEY', 'APP_ENV', 'VAULT_LOCAL_OVERRIDES',
        'VAULT_DEFAULTS_PATH',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function configArray(): array
    {
        return [
            'enabled'     => true,
            'address'     => 'https://vault.example',
            'namespace'   => 'ns',
            'auth'        => ['mount' => 'approle', 'role_id' => 'rid', 'secret_id' => 'sid'],
            'secret_path' => 'ibid/data/ims/dev/svc',
            'fail_open'   => false,
            'cache'       => ['enabled' => true, 'path' => '/tmp/vault', 'ttl' => 600, 'skew' => 30],
            'http'        => ['timeout' => 5, 'retries' => 3, 'retry_delay' => 500, 'max_delay' => 5000, 'verify' => true],
        ];
    }

    public function test_from_array_maps_the_config_vault_shape(): void
    {
        $cfg = VaultConfig::fromArray($this->configArray(), appKey: 'base64:AAAA');

        $this->assertTrue($cfg->enabled);
        $this->assertSame('https://vault.example', $cfg->address);
        $this->assertSame('ns', $cfg->namespace);
        $this->assertSame('approle', $cfg->authMount);
        $this->assertSame('rid', $cfg->roleId);
        $this->assertSame('sid', $cfg->secretId);
        $this->assertSame('ibid/data/ims/dev/svc', $cfg->secretPath);
        $this->assertFalse($cfg->failOpen);
        $this->assertTrue($cfg->cacheEnabled);
        $this->assertSame('/tmp/vault', $cfg->cachePath);
        $this->assertSame(600, $cfg->cacheTtl);
        $this->assertSame(30, $cfg->cacheSkew);
        $this->assertSame(5, $cfg->httpTimeout);
        $this->assertSame(3, $cfg->httpRetries);
        $this->assertSame(500, $cfg->httpRetryDelayMs);
        $this->assertSame(5000, $cfg->httpMaxDelayMs);
        $this->assertTrue($cfg->tlsVerify);
        $this->assertSame('base64:AAAA', $cfg->appKey);
    }

    public function test_from_env_reads_and_casts_string_env_values(): void
    {
        $_SERVER['VAULT_ENABLED']       = 'true';
        $_SERVER['VAULT_ADDR']          = 'https://vault.example/'; // trailing slash, must be trimmed
        $_SERVER['VAULT_NAMESPACE']     = 'ns';
        $_SERVER['VAULT_AUTH_MOUNT']    = 'approle';
        $_SERVER['VAULT_ROLE_ID']       = 'rid';
        $_SERVER['VAULT_SECRET_ID']     = 'sid';
        $_SERVER['VAULT_SECRET_PATH']   = 'ibid/data/ims/dev/svc';
        $_SERVER['VAULT_FAIL_OPEN']     = 'false';
        $_SERVER['VAULT_CACHE_ENABLED'] = 'true';
        $_SERVER['VAULT_CACHE_TTL']     = '600';
        $_SERVER['VAULT_HTTP_TIMEOUT']  = '7';
        $_SERVER['VAULT_HTTP_RETRIES']  = '4';
        $_SERVER['VAULT_TLS_VERIFY']    = 'false';
        $_SERVER['APP_KEY']             = 'base64:ZZZZ';

        $cfg = VaultConfig::fromEnv(cachePath: '/var/cache/vault');

        $this->assertTrue($cfg->enabled);
        $this->assertSame('https://vault.example', $cfg->address);
        $this->assertSame('ns', $cfg->namespace);
        $this->assertSame('approle', $cfg->authMount);
        $this->assertSame('rid', $cfg->roleId);
        $this->assertSame('sid', $cfg->secretId);
        $this->assertSame('ibid/data/ims/dev/svc', $cfg->secretPath);
        $this->assertFalse($cfg->failOpen);          // string 'false' -> bool false
        $this->assertTrue($cfg->cacheEnabled);
        $this->assertSame('/var/cache/vault', $cfg->cachePath); // injected, not from env
        $this->assertSame(600, $cfg->cacheTtl);      // string -> int
        $this->assertSame(7, $cfg->httpTimeout);
        $this->assertSame(4, $cfg->httpRetries);
        $this->assertFalse($cfg->tlsVerify);         // string 'false' -> bool false
        $this->assertSame(500, $cfg->httpRetryDelayMs);  // not env-driven; constant default
        $this->assertSame(5000, $cfg->httpMaxDelayMs);   // not env-driven; constant default
        $this->assertSame('base64:ZZZZ', $cfg->appKey);
    }

    public function test_from_env_reads_app_env_and_local_overrides(): void
    {
        $_SERVER['APP_ENV']               = 'local';
        $_SERVER['VAULT_LOCAL_OVERRIDES'] = 'STOCKV2_SERVICE_URL, PAYMENT_URL ,'; // spaces + trailing empty

        $cfg = VaultConfig::fromEnv(cachePath: '/var/cache/vault');

        $this->assertSame('local', $cfg->appEnv);
        $this->assertSame(['STOCKV2_SERVICE_URL', 'PAYMENT_URL'], $cfg->localOverrides, 'trimmed, empties dropped');
    }

    public function test_from_env_defaults_app_env_to_production_when_absent(): void
    {
        $cfg = VaultConfig::fromEnv(cachePath: '/var/cache/vault');

        $this->assertSame('production', $cfg->appEnv, 'fail-safe: unset APP_ENV is treated as non-local');
        $this->assertSame([], $cfg->localOverrides);
    }

    public function test_from_array_reads_app_env_and_local_overrides(): void
    {
        $config = array_replace($this->configArray(), ['local_overrides' => ['A', 'B']]);

        $cfg = VaultConfig::fromArray($config, appKey: 'base64:AAAA', appEnv: 'local');

        $this->assertSame('local', $cfg->appEnv);
        $this->assertSame(['A', 'B'], $cfg->localOverrides);
    }

    public function test_from_array_defaults_app_env_to_production(): void
    {
        $cfg = VaultConfig::fromArray($this->configArray(), appKey: 'base64:AAAA');

        $this->assertSame('production', $cfg->appEnv);
        $this->assertSame([], $cfg->localOverrides);
    }

    public function test_from_env_reads_defaults_path(): void
    {
        $_SERVER['VAULT_DEFAULTS_PATH'] = 'ibid/data/ims/dev/_global';

        $cfg = VaultConfig::fromEnv(cachePath: '/var/cache/vault');

        $this->assertSame('ibid/data/ims/dev/_global', $cfg->defaultsPath);
    }

    public function test_from_array_reads_defaults_path(): void
    {
        $config = array_replace($this->configArray(), ['defaults_path' => 'ibid/data/ims/dev/_global']);

        $cfg = VaultConfig::fromArray($config, appKey: 'base64:AAAA');

        $this->assertSame('ibid/data/ims/dev/_global', $cfg->defaultsPath);
    }

    public function test_defaults_path_defaults_to_empty_string(): void
    {
        $this->assertSame('', VaultConfig::fromEnv(cachePath: '/var/cache/vault')->defaultsPath);
        $this->assertSame('', VaultConfig::fromArray($this->configArray(), appKey: 'base64:AAAA')->defaultsPath);
    }

    public function test_assert_usable_passes_when_required_fields_present(): void
    {
        $cfg = VaultConfig::fromArray($this->configArray(), appKey: 'base64:AAAA');

        $this->expectNotToPerformAssertions();
        $cfg->assertUsable();
    }

    /**
     * @param array<string,mixed> $override
     */
    #[DataProvider('missingRequiredFieldProvider')]
    public function test_assert_usable_throws_when_a_required_field_is_missing(array $override): void
    {
        $config = array_replace_recursive($this->configArray(), $override);
        $cfg    = VaultConfig::fromArray($config, appKey: 'base64:AAAA');

        $this->expectException(VaultException::class);
        $cfg->assertUsable();
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function missingRequiredFieldProvider(): array
    {
        return [
            'missing role_id'     => [['auth' => ['role_id' => '']]],
            'missing secret_id'   => [['auth' => ['secret_id' => '']]],
            'missing secret_path' => [['secret_path' => '']],
        ];
    }
}
