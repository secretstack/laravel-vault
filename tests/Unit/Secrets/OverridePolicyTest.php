<?php

namespace Vaultenv\Vault\Tests\Unit\Secrets;

use Vaultenv\Vault\Config\VaultConfig;
use Vaultenv\Vault\Secrets\OverridePolicy;
use PHPUnit\Framework\TestCase;

class OverridePolicyTest extends TestCase
{
    public function test_active_only_when_local_and_keys_present(): void
    {
        $this->assertTrue((new OverridePolicy(isLocal: true, keys: ['A']))->isActive());
        $this->assertFalse((new OverridePolicy(isLocal: true, keys: []))->isActive(), 'no keys -> inactive');
        $this->assertFalse((new OverridePolicy(isLocal: false, keys: ['A']))->isActive(), 'non-local -> inactive');
    }

    public function test_keep_local_only_for_listed_keys_when_active(): void
    {
        $policy = new OverridePolicy(isLocal: true, keys: ['STOCKV2_SERVICE_URL']);

        $this->assertTrue($policy->shouldKeepLocal('STOCKV2_SERVICE_URL'));
        $this->assertFalse($policy->shouldKeepLocal('DB_PASSWORD'), 'unlisted key is never kept local');
    }

    public function test_never_keeps_local_when_non_local_even_if_listed(): void
    {
        $policy = new OverridePolicy(isLocal: false, keys: ['STOCKV2_SERVICE_URL']);

        $this->assertFalse($policy->shouldKeepLocal('STOCKV2_SERVICE_URL'));
        $this->assertTrue($policy->hasNonLocalLeftover(), 'listed keys in a non-local env are a misconfig to surface');
    }

    public function test_no_leftover_warning_when_local_or_empty(): void
    {
        $this->assertFalse((new OverridePolicy(isLocal: true, keys: ['A']))->hasNonLocalLeftover());
        $this->assertFalse((new OverridePolicy(isLocal: false, keys: []))->hasNonLocalLeftover());
    }

    public function test_built_from_vault_config(): void
    {
        $cfg = VaultConfig::fromArray(
            ['enabled' => true, 'local_overrides' => ['A', 'B']],
            appKey: 'base64:AAAA',
            appEnv: 'local',
        );

        $policy = OverridePolicy::fromConfig($cfg);

        $this->assertTrue($policy->isActive());
        $this->assertTrue($policy->shouldKeepLocal('A'));
        $this->assertSame(['A', 'B'], $policy->keys());
    }
}
