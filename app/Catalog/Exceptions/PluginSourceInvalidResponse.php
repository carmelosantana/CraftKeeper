<?php

namespace App\Catalog\Exceptions;

/**
 * The response came back within size/status limits but its body was not
 * usable — invalid JSON, or valid JSON that does not have the shape the
 * adapter expects (e.g. missing an expected top-level key).
 */
final class PluginSourceInvalidResponse extends PluginSourceException
{
    public static function forUrl(string $url, string $reason): self
    {
        return new self("Response from {$url} was not usable: {$reason}");
    }
}
