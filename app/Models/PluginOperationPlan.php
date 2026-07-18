<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The rich, reviewable plan behind one plugin.install/update/disable/
 * remove/rollback Operation — Task 15's analog to App\Models\
 * ConfigChangePayload (Task 8), created right after App\Operations\
 * OperationService::propose() returns a real Operation id (see
 * App\Plugins\PluginLifecycleService). Unlike ConfigChangePayload this
 * carries no secret value, so it is plain JSON, not encrypted, and is
 * kept for history/audit display after the owning Operation goes
 * terminal — only the on-disk `quarantine_path` FILE is deleted at that
 * point (`quarantine_cleaned_at` records when), never this row.
 *
 * @property int $id
 * @property string $operation_id
 * @property string $kind
 * @property string $target_relative_path
 * @property string|null $source
 * @property string|null $release_name
 * @property string|null $release_version
 * @property string|null $quarantine_path
 * @property string|null $verified_sha256
 * @property int|null $size_bytes
 * @property int|null $rollback_artifact_id
 * @property array<string, mixed> $plan
 * @property Carbon|null $quarantine_cleaned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'operation_id', 'kind', 'target_relative_path', 'source',
    'release_name', 'release_version', 'quarantine_path',
    'verified_sha256', 'size_bytes', 'rollback_artifact_id', 'plan',
    'quarantine_cleaned_at',
])]
class PluginOperationPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'quarantine_cleaned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * @return BelongsTo<PluginRollbackArtifact, $this>
     */
    public function rollbackArtifact(): BelongsTo
    {
        return $this->belongsTo(PluginRollbackArtifact::class, 'rollback_artifact_id');
    }

    public static function forOperation(string $operationId): ?self
    {
        return static::query()->where('operation_id', $operationId)->first();
    }

    /**
     * Deletes the ON-DISK quarantine artifact (never this row — see class
     * docblock) once it can never legitimately be needed again: the
     * owning Operation has reached a terminal state (Succeeded, Failed,
     * or Rejected — App\Operations\OperationService's own vocabulary).
     * A no-op when there is no plan for this operation, or its quarantine
     * file was already cleaned up — safe to call from multiple terminal
     * paths (see App\Plugins\PluginLifecycleService::cleanupQuarantineFor()
     * and App\Operations\OperationService::reject()) without double-work
     * or an error on the second call.
     */
    public static function cleanupQuarantineForOperation(string $operationId): void
    {
        $plan = static::forOperation($operationId);

        if ($plan === null || $plan->quarantine_path === null) {
            return;
        }

        $quarantineDir = dirname($plan->quarantine_path);

        if (is_file($plan->quarantine_path)) {
            @unlink($plan->quarantine_path);
        }

        // Only ever removes an EMPTY directory (see
        // App\Plugins\Concerns\QuarantinesArtifacts::abortQuarantine()'s
        // identical reasoning) — safe even if this races another cleanup
        // call.
        @rmdir($quarantineDir);

        $plan->forceFill([
            'quarantine_path' => null,
            'quarantine_cleaned_at' => now(),
        ])->save();
    }
}
