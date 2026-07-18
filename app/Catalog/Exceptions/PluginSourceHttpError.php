<?php

namespace App\Catalog\Exceptions;

/**
 * The source responded, but with a non-2xx status that
 * App\Catalog\Transport\CatalogHttpClient is not going to retry further
 * (a 4xx — never retried at all — or a 5xx whose retry budget is
 * exhausted).
 */
final class PluginSourceHttpError extends PluginSourceException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function client(string $url, int $status): self
    {
        return new self("Request to {$url} failed with client error {$status}.", $status);
    }

    public static function server(string $url, int $status): self
    {
        return new self("Request to {$url} failed with server error {$status} after retries.", $status);
    }
}
