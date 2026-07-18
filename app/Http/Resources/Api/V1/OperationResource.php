<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ChangeProposal;
use App\Models\Operation;
use App\Operations\OperationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape every /api/v1 Operation-backed endpoint returns — config
 * proposals, plugin proposals, rcon command proposals, and the generic
 * activity feed all serialize through this single resource, so a token
 * can never see a different (accidentally richer or leakier) shape of the
 * same underlying Operation depending on which route it came through.
 *
 * Security-critical: this NEVER loads or exposes App\Models\
 * ConfigChangePayload (the encrypted table holding a config proposal's
 * REAL field values) or App\Models\RconCommandPayload (the encrypted
 * table holding a secret-shaped rcon command's real text) — both are
 * intentionally absent from every query this class runs.
 * `change_summary` is built ONLY from `Operation::redacted_input` (already
 * redacted at propose() time by App\Config\ConfigChangeService — see its
 * own class docblock) and App\Models\ChangeProposal rows (also already
 * redacted `before`/`after` display text, never raw values) — mirroring
 * App\Http\Controllers\ConfigController::presentOperation()'s identical
 * approach for the existing web UI, reused rather than re-derived.
 *
 * @mixin Operation
 */
class OperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Operation $operation */
        $operation = $this->resource;
        $meta = $operation->redacted_input ?? [];

        return [
            'id' => $operation->id,
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'target' => $operation->target,
            'risk' => $operation->risk->value,
            'actor' => [
                'type' => $operation->author_type->value,
                'id' => $operation->author_id,
                'origin' => $operation->author_origin,
            ],
            'correlation_id' => $operation->correlation_id,
            'created_at' => $operation->created_at?->toIso8601String(),
            'approved_at' => $operation->approved_at?->toIso8601String(),
            'rejected_at' => $operation->rejected_at?->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
            'outcome' => $operation->outcome,
            'error_code' => $operation->error_code,
            'change_summary' => $this->configChangeSummary($operation, $meta),
        ];
    }

    /**
     * Only present for config.apply/config.restore operations — null for
     * every other operation type (plugin.*, rcon.command, server.stop).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function configChangeSummary(Operation $operation, array $meta): ?array
    {
        if (! in_array($operation->type, [OperationType::ConfigApply, OperationType::ConfigRestore], true)) {
            return null;
        }

        $realPaths = is_array($meta['changed_fields'] ?? null) ? $meta['changed_fields'] : [];

        $fields = $operation->changeProposals()
            ->whereIn('field', $realPaths)
            ->get()
            ->map(fn (ChangeProposal $p) => [
                'path' => $p->field,
                'summary' => $p->summary,
                'before' => $p->before,
                'after' => $p->after,
            ])
            ->values()
            ->all();

        return [
            'kind' => $meta['kind'] ?? 'apply',
            'diff' => $meta['diff'] ?? '',
            'valid' => (bool) ($meta['valid'] ?? false),
            'diagnostics' => is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : [],
            'restart_impact' => $meta['restart_impact'] ?? 'none',
            'documentation' => is_array($meta['documentation'] ?? null) ? $meta['documentation'] : [],
            'fields' => $fields,
            'expires_at' => $meta['expires_at'] ?? null,
        ];
    }
}
