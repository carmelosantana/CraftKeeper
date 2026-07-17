<?php

namespace App\Config\Formats\Support;

/**
 * Dot-notation get/set/unset over a nested associative array — the
 * structural-edit primitive used by every adapter's "full re-serialize"
 * fallback path (YAML/TOML/JSON, whenever a change targets something a
 * byte-precise scalar patch can't reach: a new nested key, an array
 * element, or a whole object/array value).
 */
final class DotPath
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function has(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $data;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function set(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $key = array_pop($segments);
        $cursor = &$data;

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$key] = $value;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function unset(array $data, string $path): array
    {
        $segments = explode('.', $path);
        $key = array_pop($segments);
        $cursor = &$data;

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                return $data;
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor[$key]);

        return $data;
    }
}
