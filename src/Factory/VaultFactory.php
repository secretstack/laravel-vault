<?php

namespace Ibid\Vault\Factory;

use GuzzleHttp\Client as GuzzleClient;
use Ibid\Vault\Auth\AppRoleAuth;
use Ibid\Vault\Cache\EncryptedFileCache;
use Ibid\Vault\Config\VaultConfig;
use Ibid\Vault\Contracts\AuthMethod;
use Ibid\Vault\Contracts\SecretCache;
use Ibid\Vault\Contracts\SecretProvider;
use Ibid\Vault\Contracts\VaultClient;
use Ibid\Vault\Exceptions\VaultException;
use Ibid\Vault\Http\GuzzleVaultClient;
use Ibid\Vault\Provider\VaultSecretProvider;
use Ibid\Vault\Secrets\SecretStore;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

/**
 * The in-process assembler of the secret-fetching graph (CONTEXT.md: "The
 * Factory"). Given a Resolved config and a logger, it wires
 * client -> auth -> cache -> provider -> store.
 *
 * This is the single home for the wiring constants (max backoff, AES cipher,
 * the retry deadline formula, the base64: strip and empty-APP_KEY guard) so the
 * two boot phases (the facade-free Loader and the container-bound
 * VaultServiceProvider) cannot drift. Stateless and Octane-safe: pure factory
 * methods, no mutable state.
 */
final class VaultFactory
{
    public function makeClient(VaultConfig $c, LoggerInterface $logger): VaultClient
    {
        return new GuzzleVaultClient(
            http: new GuzzleClient([
                'timeout'     => $c->httpTimeout,
                'verify'      => $c->tlsVerify,
                'http_errors' => false,
            ]),
            address:     $c->address,
            namespace:   $c->namespace,
            maxRetries:  $c->httpRetries,
            baseDelayMs: $c->httpRetryDelayMs,
            maxDelayMs:  $c->httpMaxDelayMs,
            deadlineMs:  $c->httpTimeout * $c->httpRetries * 1000,
            logger:      $logger,
        );
    }

    public function makeAuth(VaultConfig $c): AuthMethod
    {
        return new AppRoleAuth($c->roleId, $c->secretId, $c->authMount);
    }

    public function makeCache(VaultConfig $c, LoggerInterface $logger): SecretCache
    {
        return new EncryptedFileCache($this->buildEncrypter($c), $c->cachePath, $logger);
    }

    public function makeProvider(
        VaultClient $client,
        AuthMethod $auth,
        SecretCache $cache,
        VaultConfig $c,
        LoggerInterface $logger,
    ): SecretProvider {
        $c->assertUsable();

        return new VaultSecretProvider(
            client:       $client,
            auth:         $auth,
            cache:        $cache,
            secretPath:   $c->secretPath,
            cacheTtl:     $c->cacheTtl,
            cacheSkew:    $c->cacheSkew,
            cacheEnabled: $c->cacheEnabled,
            logger:       $logger,
        );
    }

    /**
     * Boot convenience: compose the full graph into a SecretStore. The
     * VaultServiceProvider does not use this — it binds each collaborator as a
     * container singleton via the make* methods above.
     */
    public function makeStore(VaultConfig $c, LoggerInterface $logger): SecretStore
    {
        $provider = $this->makeProvider(
            $this->makeClient($c, $logger),
            $this->makeAuth($c),
            $this->makeCache($c, $logger),
            $c,
            $logger,
        );

        return new SecretStore($provider);
    }

    private function buildEncrypter(VaultConfig $c): Encrypter
    {
        $key = $c->appKey;

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new VaultException('APP_KEY is not set; cannot encrypt the Vault secret cache');
        }

        return new Encrypter($key, 'AES-256-CBC');
    }
}
