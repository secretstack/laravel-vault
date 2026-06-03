<?php

namespace Ibid\Vault\DTO;

/**
 * A secret read from a KV-v2 path.
 *
 * Immutable value object. `data` is a flat key=>value map; the package
 * never logs the values, only their keys.
 */
final class VaultSecret
{
    /**
     * @param array<string,string> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $version,
        public readonly int $fetchedAt,
    ) {
    }

    /**
     * Build from a KV-v2 read response.
     *
     * KV-v2 nests the secret payload under `data.data` and its version
     * under `data.metadata.version`.
     *
     * @param array<string,mixed> $response
     */
    public static function fromKvV2Response(array $response, ?int $now = null): self
    {
        /** @var array<string,string> $data */
        $data = $response['data']['data'] ?? [];

        return new self(
            data: $data,
            version: (int) ($response['data']['metadata']['version'] ?? 0),
            fetchedAt: $now ?? time(),
        );
    }
}
