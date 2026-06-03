<?php

namespace Ibid\Vault\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ibid\Vault\Http\GuzzleVaultClient;
use Ibid\Vault\Exceptions\VaultException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GuzzleVaultClientTest extends TestCase
{
    /** @var array<int,array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param array<int,Response|\Throwable> $responses
     * @param list<int>|null                 $sleeps captured backoff delays (microseconds)
     */
    private function makeClient(
        array $responses,
        string $namespace = '',
        int $maxRetries = 3,
        ?array &$sleeps = null,
        ?\Closure $clock = null,
    ): GuzzleVaultClient {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $sleeper = $sleeps === null
            ? static fn (int $us) => null
            : function (int $us) use (&$sleeps): void { $sleeps[] = $us; };

        return new GuzzleVaultClient(
            http:       $http,
            address:    'https://vault.test',
            namespace:  $namespace,
            maxRetries: $maxRetries,
            baseDelayMs: 10,
            maxDelayMs:  1000,
            deadlineMs:  10000,
            logger:     new NullLogger(),
            sleeper:    $sleeper,
            clock:      $clock,
        );
    }

    public function test_read_kv_v2_parses_nested_secret_and_sends_token_header(): void
    {
        $client = $this->makeClient([
            new Response(200, [], (string) json_encode([
                'data' => ['data' => ['FOO' => 'bar'], 'metadata' => ['version' => 3]],
            ])),
        ]);

        $secret = $client->readKvV2('ibid/data/ims/dev/stockv2', 's.token');

        $this->assertSame(['FOO' => 'bar'], $secret->data);
        $this->assertSame(3, $secret->version);
        $this->assertSame('s.token', $this->history[0]['request']->getHeaderLine('X-Vault-Token'));
        $this->assertStringEndsWith('/v1/ibid/data/ims/dev/stockv2', (string) $this->history[0]['request']->getUri());
    }

    public function test_retries_on_5xx_then_succeeds(): void
    {
        $sleeps = [];
        $client = $this->makeClient(
            responses: [
                new Response(500, [], (string) json_encode(['errors' => ['internal error']])),
                new Response(200, [], (string) json_encode([
                    'data' => ['data' => ['A' => '1'], 'metadata' => ['version' => 1]],
                ])),
            ],
            sleeps: $sleeps,
        );

        $secret = $client->readKvV2('p', 's.t');

        $this->assertSame(['A' => '1'], $secret->data);
        $this->assertCount(2, $this->history, 'should have retried once');
        $this->assertCount(1, $sleeps, 'should have slept once between attempts');
    }

    public function test_does_not_retry_on_403_or_404(): void
    {
        $client = $this->makeClient([
            new Response(403, [], (string) json_encode(['errors' => ['permission denied']])),
            new Response(200, [], '{}'),
        ]);

        try {
            $client->request('GET', 'p');
            $this->fail('expected VaultException on 403');
        } catch (VaultException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertCount(1, $this->history, '403 is permanent and must not be retried');
    }

    public function test_sends_namespace_header_when_configured(): void
    {
        $client = $this->makeClient(
            responses: [new Response(200, [], '{}')],
            namespace: 'ibid-team',
        );

        $client->request('GET', 'p');

        $this->assertSame('ibid-team', $this->history[0]['request']->getHeaderLine('X-Vault-Namespace'));
    }

    public function test_throws_after_exhausting_retries(): void
    {
        $sleeps = [];
        $client = $this->makeClient(
            responses: [
                new Response(503, [], (string) json_encode(['errors' => ['unavailable']])),
                new Response(503, [], (string) json_encode(['errors' => ['unavailable']])),
                new Response(503, [], (string) json_encode(['errors' => ['unavailable']])),
            ],
            maxRetries: 3,
            sleeps: $sleeps,
        );

        try {
            $client->request('GET', 'p');
            $this->fail('expected VaultException after exhausting retries');
        } catch (VaultException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $this->assertCount(3, $this->history, 'should attempt exactly maxRetries times');
        $this->assertCount(2, $sleeps, 'sleeps between 3 attempts = 2');
    }

    public function test_stops_retrying_when_deadline_would_be_breached(): void
    {
        $clockValues = [0.0, 100.0, 100.0]; // 100s elapsed after the first attempt
        $sleeps = [];
        $client = $this->makeClient(
            responses: [
                new Response(503, [], (string) json_encode(['errors' => ['unavailable']])),
                new Response(200, [], (string) json_encode(['data' => ['data' => [], 'metadata' => ['version' => 1]]])),
            ],
            maxRetries: 5,
            sleeps: $sleeps,
            clock: function () use (&$clockValues): float { return array_shift($clockValues) ?? 100.0; },
        );

        try {
            $client->request('GET', 'p');
            $this->fail('expected VaultException');
        } catch (VaultException) {
            // expected
        }

        $this->assertCount(1, $this->history, 'deadline must prevent the retry');
        $this->assertCount(0, $sleeps, 'no sleep when the deadline would be breached');
    }

    public function test_backoff_delay_is_within_equal_jitter_bounds(): void
    {
        $sleeps = [];
        $client = $this->makeClient(
            responses: [
                new Response(500, [], '{}'),
                new Response(200, [], (string) json_encode(['data' => ['data' => [], 'metadata' => ['version' => 1]]])),
            ],
            sleeps: $sleeps,
        );

        $client->request('GET', 'p');

        // baseDelayMs=10, attempt 1 => capped=min(10,1000)=10, half=5 => delay in [5,10] ms => [5000,10000] micros
        $this->assertCount(1, $sleeps);
        $this->assertGreaterThanOrEqual(5000, $sleeps[0]);
        $this->assertLessThanOrEqual(10000, $sleeps[0]);
    }
}
