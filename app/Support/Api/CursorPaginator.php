<?php

namespace App\Support\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A small, backend-agnostic cursor paginator for /api/v1 collection
 * endpoints (Task 17's ambiguity resolution #4: "CURSOR pagination for
 * collections"). Deliberately works over an already-materialized,
 * already-ordered Collection rather than an Eloquent query builder — some
 * /api/v1 list endpoints are backed by a real query (operations, plugin
 * installations) and some by an in-memory scan (config files, via
 * App\Filesystem\MinecraftFilesystem::discover(), exactly like
 * App\Http\Controllers\ConfigController::index() already does) — so one
 * paginator covers both without forcing a fake query object onto the
 * latter.
 *
 * The cursor itself is an opaque, base64-encoded copy of whatever stable,
 * unique identifier the caller's $identifier callback returns for the
 * last item on the page (e.g. a config file's relative path, an
 * Operation's ordered UUID, a plugin's relative path) — callers must
 * supply a Collection already sorted by that same identifier, ascending
 * or descending consistently, so "the next item after this cursor" is
 * well-defined.
 */
final class CursorPaginator
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  Collection<array-key, mixed>  $items  Already sorted by the identifier below.
     * @param  callable(mixed): string  $identifier
     * @return array{items: Collection<array-key, mixed>, nextCursor: ?string, hasMore: bool}
     */
    public static function paginate(Collection $items, Request $request, callable $identifier): array
    {
        $perPage = self::perPage($request);
        $cursor = self::decode($request->query('cursor'));
        $ordered = $items->values();

        $start = 0;

        if ($cursor !== null) {
            $index = $ordered->search(fn ($item) => $identifier($item) === $cursor);
            $start = $index === false ? 0 : $index + 1;
        }

        $window = $ordered->slice($start, $perPage + 1)->values();
        $hasMore = $window->count() > $perPage;
        $page = $window->take($perPage);

        $next = $hasMore && $page->isNotEmpty() ? self::encode($identifier($page->last())) : null;

        return ['items' => $page, 'nextCursor' => $next, 'hasMore' => $hasMore];
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(bool $hasMore, ?string $nextCursor): array
    {
        return [
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    private static function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $requested ?: self::DEFAULT_PER_PAGE));
    }

    private static function encode(string $key): string
    {
        return base64_encode($key);
    }

    private static function decode(mixed $cursor): ?string
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        return $decoded === false ? null : $decoded;
    }
}
