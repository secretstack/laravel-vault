<?php

namespace Ibid\Vault\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Package providers under test.
     * VaultServiceProvider is wired here once it exists (Cycle 9).
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }
}
