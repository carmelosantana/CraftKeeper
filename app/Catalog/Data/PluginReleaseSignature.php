<?php

namespace App\Catalog\Data;

/**
 * Signature metadata a catalog release MAY carry (resources/catalog/
 * plugin-catalog.schema.json's `signature` object — present only for
 * releases the catalog repository actually signed). Nothing in this
 * task verifies a signature against an artifact; that is deliberately
 * out of scope the same way checksum verification is (see this task's
 * brief: "Downloaded-artifact checksum VERIFICATION is Task 15; here you
 * only carry the EXPECTED sha256"). This DTO exists so that expectation
 * — carry, don't verify — extends to signatures too.
 */
final readonly class PluginReleaseSignature
{
    public function __construct(
        public string $algorithm,
        public string $signature,
        public string $keyUrl,
    ) {}

    /**
     * @return array{algorithm: string, signature: string, keyUrl: string}
     */
    public function toArray(): array
    {
        return ['algorithm' => $this->algorithm, 'signature' => $this->signature, 'keyUrl' => $this->keyUrl];
    }

    /**
     * @param  array{algorithm: string, signature: string, keyUrl: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['algorithm'], $data['signature'], $data['keyUrl']);
    }
}
