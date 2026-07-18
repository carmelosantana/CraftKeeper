<?php

namespace App\Catalog;

use App\Models\CatalogSourceState;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Carbon;

/**
 * Persists the per-source health snapshot in App\Models\
 * CatalogSourceState — see that model's docblock for how this differs
 * from the transient, per-search App\Catalog\Data\PluginSourceResult.
 * App\Catalog\Sources\AbstractPluginSource::search() calls
 * recordSuccess()/recordFailure() exactly once per live fetch attempt
 * (never on a cache-hit that skipped the live fetch entirely — a cache
 * hit says nothing new about the source's current health).
 */
final class CatalogSourceHealth
{
    public function recordSuccess(PluginProvenance $source): void
    {
        CatalogSourceState::query()->updateOrCreate(
            ['source' => $source->value],
            [
                'status' => 'ok',
                'consecutive_failures' => 0,
                'last_success_at' => Carbon::now(),
                'last_attempt_at' => Carbon::now(),
                'last_error' => null,
            ],
        );
    }

    public function recordFailure(PluginProvenance $source, string $message): void
    {
        $state = CatalogSourceState::query()->firstOrNew(['source' => $source->value]);
        $consecutiveFailures = ($state->consecutive_failures ?? 0) + 1;

        $state->fill([
            'status' => $consecutiveFailures >= 3 ? 'unavailable' : 'degraded',
            'consecutive_failures' => $consecutiveFailures,
            'last_attempt_at' => Carbon::now(),
            'last_error' => $message,
        ])->save();
    }

    public function snapshot(PluginProvenance $source): ?CatalogSourceState
    {
        return CatalogSourceState::query()->where('source', $source->value)->first();
    }
}
