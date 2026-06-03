<?php

namespace Ibid\Vault\Console;

use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Secrets\SecretStore;
use Illuminate\Console\Command;

/**
 * Busts the cache and re-fetches from Vault. Dev / cache-warm tool only —
 * NOT a production hot-reload path (rotation is via rolling restart, Q5).
 */
final class RefreshCommand extends Command
{
    protected $signature = 'vault:refresh';

    protected $description = 'Clear the Vault secret cache and re-fetch from Vault';

    public function handle(SecretCache $cache, SecretStore $store): int
    {
        $this->info('Clearing Vault secret cache…');
        $cache->forget();

        $this->info('Re-fetching secrets from Vault…');
        $secrets = $store->refresh();

        $this->info('Done — refreshed ' . count($secrets) . ' keys.');

        return self::SUCCESS;
    }
}
