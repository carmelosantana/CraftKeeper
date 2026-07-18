<?php

namespace App\Catalog\Exceptions;

/**
 * The response body exceeded App\Catalog\Transport\CatalogHttpClient's
 * response-size limit. Raised from TWO independent checks — mirroring
 * App\Plugins\JarInspector's declared-size-then-actual-bytes defense
 * from Task 13: `declared()` fires before any body is read at all (a
 * Content-Length header that alone exceeds the cap), and `actual()`
 * fires after the body has been received in case Content-Length was
 * absent, wrong, or understated. Either way, the body is never decoded
 * or normalized.
 */
final class PluginSourceResponseTooLarge extends PluginSourceException
{
    public static function declared(string $url, int $declaredBytes, int $maxBytes): self
    {
        return new self("Response from {$url} declares {$declaredBytes} bytes, exceeding the {$maxBytes}-byte limit.");
    }

    public static function actual(string $url, int $actualBytes, int $maxBytes): self
    {
        return new self("Response from {$url} was {$actualBytes} bytes, exceeding the {$maxBytes}-byte limit.");
    }
}
