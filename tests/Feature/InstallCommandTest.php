<?php

namespace Vaultenv\Vault\Tests\Feature;

use Vaultenv\Vault\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir() . '/bootstrap-app-' . uniqid('', true) . '.php';
        file_put_contents($this->fixture, "<?php\n\n\$app = new Application();\n\nreturn \$app;\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixture)) {
            @unlink($this->fixture);
        }
        parent::tearDown();
    }

    public function test_patches_bootstrap_app_with_the_hook(): void
    {
        $this->artisan('vault:install', ['--path' => $this->fixture])->assertExitCode(0);

        $this->assertStringContainsString('VaultBootstrap::inject', (string) file_get_contents($this->fixture));
        $this->assertStringContainsString('return $app;', (string) file_get_contents($this->fixture));
    }

    public function test_is_idempotent(): void
    {
        $this->artisan('vault:install', ['--path' => $this->fixture])->assertExitCode(0);
        $this->artisan('vault:install', ['--path' => $this->fixture])->assertExitCode(0);

        $this->assertSame(
            1,
            substr_count((string) file_get_contents($this->fixture), 'VaultBootstrap::inject'),
            'hook must be inserted exactly once',
        );
    }

    public function test_fails_when_bootstrap_file_is_missing(): void
    {
        $this->artisan('vault:install', ['--path' => '/nonexistent/bootstrap-app.php'])
            ->assertExitCode(1);
    }

    public function test_prints_manual_instructions_for_laravel_11_plus_slim_skeleton(): void
    {
        // L11+ slim skeleton has no `return $app;` to anchor on.
        file_put_contents($this->fixture, "<?php\n\nreturn Application::configure(basePath: dirname(__DIR__))->create();\n");

        $this->artisan('vault:install', ['--path' => $this->fixture])->assertExitCode(1);

        $this->assertStringNotContainsString('VaultBootstrap::inject', (string) file_get_contents($this->fixture));
    }
}
