<?php

namespace Ibid\Vault\Console;

use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Secrets\SecretStore;
use Illuminate\Console\Command;

/**
 * Vault health check.
 *
 * Plain mode: human diagnostic (config, secret availability with masked values,
 * cache status). `--gate` mode: terse, exit code mirrors the bootstrap loader's
 * success — secrets obtainable by any path (fresh / cache / stale grace) => 0,
 * cold (nothing) => non-zero. Used in run.sh as the deploy gate (ADR-0010).
 */
final class CheckCommand extends Command
{
    protected $signature = 'vault:check {--gate : Exit non-zero if secrets cannot be obtained (boot-equivalent; for run.sh)}';

    protected $description = 'Check Vault connectivity and secret availability';

    public function handle(SecretStore $store, SecretCache $cache): int
    {
        if ($this->option('gate')) {
            try {
                $store->all();

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('vault:check --gate: secrets unobtainable — ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $cfg = config('vault');
        $this->info('─── HashiCorp Vault Health Check ───');
        $this->line('Address  : ' . $cfg['address']);
        $this->line('Path     : ' . $cfg['secret_path']);
        $this->line('Mount    : ' . $cfg['auth']['mount']);
        $this->line('Fail-open: ' . ($cfg['fail_open'] ? 'YES (dev)' : 'NO (fail-closed)'));
        $this->newLine();

        try {
            $secrets = $store->all();
        } catch (\Throwable $e) {
            $this->error('✗ Could not obtain secrets: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Secrets available — ' . count($secrets) . ' keys (values masked):');
        foreach ($secrets as $key => $value) {
            $this->line('   ' . $key . ' = ' . $this->mask((string) $value));
        }

        $this->line('Cache: ' . ($cache->get() !== null ? 'valid' : 'cold/expired'));

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        $len = strlen($value);

        return $len <= 3 ? str_repeat('*', $len) : str_repeat('*', $len - 3) . substr($value, -3);
    }
}
