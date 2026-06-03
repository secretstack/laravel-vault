<?php

namespace Ibid\Vault\Tests;

use Ibid\Vault\VaultServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VaultServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // A valid 32-byte APP_KEY so the encrypted cache can be built.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }
}
