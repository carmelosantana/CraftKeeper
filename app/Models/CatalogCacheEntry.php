<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One cached, normalized catalog result — either a query-specific
 * `PluginSearchPage` ('page' kind) or CraftKeeperCatalogSource's whole
 * last-known-good catalog document ('catalog-snapshot' kind). See
 * App\Catalog\CatalogCache for the freshness/retention rules built on
 * top of this model: `fresh_until` gates whether a cached row can be
 * served WITHOUT attempting a live fetch (the 15-minute page cache);
 * `expires_at` gates whether a row can still be served as a labeled,
 * stale fallback AFTER a live fetch fails (the 7-day
 * stale-while-error retention). A row past `expires_at` is treated as
 * absent by App\Catalog\CatalogCache even if the row itself hasn't
 * been deleted yet.
 *
 * @property int $id
 * @property string $cache_key
 * @property string $source
 * @property string $kind
 * @property array<mixed> $payload
 * @property string|null $etag
 * @property string|null $last_modified
 * @property Carbon $fresh_until
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'cache_key', 'source', 'kind', 'payload', 'etag', 'last_modified',
    'fresh_until', 'expires_at',
])]
class CatalogCacheEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fresh_until' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
