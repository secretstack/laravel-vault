<?php

namespace Vaultenv\Vault\Tests\Unit\Support;

use Vaultenv\Vault\Support\FileLogger;
use PHPUnit\Framework\TestCase;

class FileLoggerTest extends TestCase
{
    public function test_writes_level_message_and_context(): void
    {
        $file = sys_get_temp_dir() . '/vault-flog-' . uniqid('', true) . '.log';

        (new FileLogger($file))->info('fetch.ok', ['keys' => ['A', 'B']]);

        $contents = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('vault.info', $contents);
        $this->assertStringContainsString('fetch.ok', $contents);
        $this->assertStringContainsString('keys', $contents);
    }

    public function test_writes_message_without_context(): void
    {
        $file = sys_get_temp_dir() . '/vault-flog-' . uniqid('', true) . '.log';

        (new FileLogger($file))->warning('cache.stale_served');

        $contents = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('vault.warning', $contents);
        $this->assertStringContainsString('cache.stale_served', $contents);
    }
}
