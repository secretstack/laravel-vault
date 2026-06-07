<?php

namespace Vaultenv\Vault\Cache;

use Vaultenv\Vault\Contracts\SecretCache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

/**
 * APP_KEY-encrypted file cache for fetched secrets.
 *
 * Defense-in-depth, not a pod-compromise control (ADR-0007). Directory 0700,
 * file 0600. `get()` returns only fresh (unexpired) payloads; `getStale()`
 * returns the last-known-good payload even past TTL, for the grace path
 * (ADR-0004). Both return null on a missing, malformed, or tampered file.
 */
final class EncryptedFileCache implements SecretCache
{
    private const CACHE_FILE = 'secrets.cache';

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly string $cachePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{secrets: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    public function get(): ?array
    {
        $payload = $this->read();

        if ($payload === null) {
            return null;
        }

        if (time() >= $payload['expires_at']) {
            $this->logger->debug('vault.cache.expired', ['expires_at' => $payload['expires_at']]);

            return null;
        }

        return $payload;
    }

    /**
     * Last-known-good payload, even if past its TTL (grace path, ADR-0004).
     * Null only when the file is missing, malformed, or tampered.
     *
     * @return array{secrets: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    public function getStale(): ?array
    {
        return $this->read();
    }

    /**
     * @param array<string,string> $secrets
     */
    public function put(array $secrets, int $ttl): void
    {
        $this->ensureDirectory();

        $payload = [
            'secrets'    => $secrets,
            'fetched_at' => time(),
            'expires_at' => time() + $ttl,
        ];

        $file = $this->filePath();
        file_put_contents($file, $this->encrypter->encrypt($payload), LOCK_EX);
        chmod($file, 0600);

        $this->logger->info('vault.cache.put', [
            'keys'       => array_keys($secrets),
            'ttl'        => $ttl,
            'expires_at' => $payload['expires_at'],
        ]);
    }

    public function forget(): void
    {
        $this->silentDelete($this->filePath());
        $this->logger->info('vault.cache.forget');
    }

    /**
     * Decrypt and validate the cache file, ignoring TTL.
     *
     * @return array{secrets: array<string,string>, fetched_at: int, expires_at: int}|null
     */
    private function read(): ?array
    {
        $file = $this->filePath();

        if (! file_exists($file)) {
            return null;
        }

        try {
            $payload = $this->encrypter->decrypt((string) file_get_contents($file));

            if (! is_array($payload) || ! isset($payload['expires_at'], $payload['secrets'])) {
                return null;
            }

            return $payload;
        } catch (DecryptException $e) {
            // Tampered file or rotated APP_KEY — discard safely.
            $this->logger->warning('vault.cache.decrypt_failed', ['error' => $e->getMessage()]);
            $this->silentDelete($file);

            return null;
        }
    }

    private function filePath(): string
    {
        return rtrim($this->cachePath, '/') . '/' . self::CACHE_FILE;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0700, true);
        }
    }

    private function silentDelete(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
