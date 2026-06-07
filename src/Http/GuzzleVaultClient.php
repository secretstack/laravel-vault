<?php

namespace Vaultenv\Vault\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Vaultenv\Vault\Contracts\VaultClient;
use Vaultenv\Vault\DTO\VaultSecret;
use Vaultenv\Vault\Exceptions\VaultException;
use Psr\Log\LoggerInterface;

/**
 * Guzzle-based Vault HTTP transport.
 *
 * Resilience (ADR-0007): bounded retries + exponential backoff with jitter +
 * a total deadline. 403/404 are permanent and never retried. The Guzzle client
 * MUST be built with `http_errors=false` so this class controls status handling.
 *
 * `sleeper` and `clock` are injectable for deterministic, fast tests.
 */
final class GuzzleVaultClient implements VaultClient
{
    private Closure $sleeper;

    private Closure $clock;

    public function __construct(
        private readonly Client $http,
        private readonly string $address,
        private readonly string $namespace,
        private readonly int $maxRetries,
        private readonly int $baseDelayMs,
        private readonly int $maxDelayMs,
        private readonly int $deadlineMs,
        private readonly LoggerInterface $logger,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $micros) => usleep($micros);
        $this->clock   = $clock ?? static fn (): float => microtime(true);
    }

    public function request(string $method, string $path, array $options = []): array
    {
        $url = "{$this->address}/v1/{$path}";

        if ($this->namespace !== '') {
            $options['headers']['X-Vault-Namespace'] = $this->namespace;
        }

        $start         = ($this->clock)();
        $attempts      = 0;
        $lastException = null;

        while ($attempts < $this->maxRetries) {
            try {
                $response   = $this->http->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                $body       = json_decode((string) $response->getBody(), true) ?? [];

                if ($statusCode < 200 || $statusCode >= 300) {
                    $errors = implode(', ', $body['errors'] ?? []);
                    throw new VaultException("Vault HTTP {$statusCode} on {$method} {$path}: {$errors}", $statusCode);
                }

                return $body;
            } catch (VaultException $e) {
                // 403/404 are permanent (bad creds / wrong path) — never retried.
                if (in_array($e->getCode(), [403, 404], true)) {
                    throw $e;
                }
                $lastException = $e;
            } catch (GuzzleException $e) {
                $lastException = new VaultException(
                    "Vault HTTP error on {$method} {$path}: {$e->getMessage()}",
                    0,
                    $e
                );
            }

            $attempts++;
            if ($attempts >= $this->maxRetries) {
                break;
            }

            $delayMs   = $this->backoffWithJitter($attempts);
            $elapsedMs = (int) ((($this->clock)() - $start) * 1000);

            // Stop if the next sleep would breach the total deadline (ADR-0007).
            if (($elapsedMs + $delayMs) > $this->deadlineMs) {
                break;
            }

            $this->logger->warning('vault.request.retry', [
                'method'   => $method,
                'path'     => $path,
                'attempt'  => $attempts,
                'max'      => $this->maxRetries,
                'delay_ms' => $delayMs,
            ]);

            ($this->sleeper)($delayMs * 1000);
        }

        throw $lastException ?? new VaultException(
            "Vault request failed after {$this->maxRetries} attempts: {$method} {$path}"
        );
    }

    /**
     * Exponential backoff with equal jitter, capped at maxDelayMs.
     * Returns a delay in [capped/2, capped] where capped = min(base*2^(n-1), maxDelayMs).
     */
    private function backoffWithJitter(int $attempt): int
    {
        $base   = $this->baseDelayMs * (2 ** ($attempt - 1));
        $capped = (int) min($base, $this->maxDelayMs);
        $half   = intdiv($capped, 2);

        return $half + random_int(0, $capped - $half);
    }

    public function readKvV2(string $path, string $clientToken): VaultSecret
    {
        $response = $this->request('GET', $path, ['headers' => ['X-Vault-Token' => $clientToken]]);

        $secret = VaultSecret::fromKvV2Response($response);

        $this->logger->info('vault.read.ok', [
            'path'    => $path,
            'version' => $secret->version,
            'keys'    => array_keys($secret->data),
        ]);

        return $secret;
    }
}
