<?php

namespace App\Catalog\Transport;

/**
 * The result of one App\Catalog\Transport\CatalogHttpClient::get() call
 * that made it past size/status checks — either a fresh body to
 * normalize, or a 304 confirming a previously cached body is still
 * current.
 */
final readonly class CatalogHttpResponse
{
    private function __construct(
        public bool $notModified,
        public string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {}

    public static function ok(string $body, ?string $etag, ?string $lastModified): self
    {
        return new self(notModified: false, body: $body, etag: $etag, lastModified: $lastModified);
    }

    public static function notModified(?string $etag, ?string $lastModified): self
    {
        return new self(notModified: true, body: '', etag: $etag, lastModified: $lastModified);
    }
}
