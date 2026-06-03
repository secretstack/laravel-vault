<?php

namespace Ibid\Vault\Tests\Unit\Bootstrap;

use Ibid\Vault\Bootstrap\VaultBootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class EmergencyLogTest extends TestCase
{
    public function test_emergency_log_writes_failure_marker_and_message(): void
    {
        $file = sys_get_temp_dir() . '/vault-emerg-' . uniqid('', true) . '.log';

        $method = new ReflectionMethod(VaultBootstrap::class, 'emergencyLog');
        $method->setAccessible(true);
        $method->invoke(null, $file, new RuntimeException('connection refused'));

        $contents = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('VAULT_BOOTSTRAP_FAILED', $contents);
        $this->assertStringContainsString('connection refused', $contents);
    }
}
