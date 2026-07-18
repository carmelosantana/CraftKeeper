<?php

namespace App\Catalog\Exceptions;

use Throwable;

/**
 * The connect/request timed out, or the connection otherwise failed
 * before any HTTP response was received (Laravel's
 * \Illuminate\Http\Client\ConnectionException) — after exhausting
 * App\Catalog\Transport\CatalogHttpClient's retry budget.
 */
final class PluginSourceTimeout extends PluginSourceException
{
    public static function forUrl(string $url, ?Throwable $previous = null): self
    {
        return new self("Request to {$url} timed out or the connection failed.", previous: $previous);
    }
}
