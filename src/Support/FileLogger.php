<?php

namespace Vaultenv\Vault\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 file logger for the facade-free boot path, where Laravel's
 * Log facade is not yet available. Deliberately avoids any Monolog API so the
 * package isn't coupled to Monolog 2 vs 3 differences across Laravel versions.
 *
 * Logging must never break boot, so all I/O errors are swallowed.
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        try {
            $dir = dirname($this->file);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $suffix = $context === [] ? '' : ' ' . (string) json_encode($context, JSON_UNESCAPED_SLASHES);
            $line   = sprintf("[%s] vault.%s: %s%s\n", date('c'), (string) $level, (string) $message, $suffix);

            @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Never let logging failure obscure the real boot path.
        }
    }
}
