<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The current health snapshot of one catalog source (CraftKeeper
 * Catalog, Hangar, or Modrinth), one row per source — updated on every
 * live fetch attempt by App\Catalog\CatalogSourceHealth. This is
 * separate from the transient, per-search `PluginSourceResult` that
 * rides along with a `PluginSearchPage` (see
 * App\Catalog\Data\PluginSourceResult): that DTO reports "how did THIS
 * search go," while this row reports "how has this source been doing
 * lately" (consecutive failures, last success) — the kind of thing a
 * future status dashboard would read without re-deriving it from logs.
 * Nothing in this task's search path branches on this table; it is
 * observability, not a cache.
 *
 * @property int $id
 * @property string $source
 * @property string $status
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_attempt_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'source', 'status', 'consecutive_failures', 'last_success_at',
    'last_attempt_at', 'last_error',
])]
class CatalogSourceState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }
}
