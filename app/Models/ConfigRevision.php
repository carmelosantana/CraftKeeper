<?php

namespace App\Models;

use App\Operations\OperationActorType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One point in a config file's reversible history — created by
 * App\Operations\Handlers\ConfigApplyHandler / ConfigRestoreHandler
 * immediately after a successful, verified write (never at proposal
 * time), one row per successfully executed operation.
 *
 * `snapshot_path` points at a captured copy of the file's bytes AS OF
 * this revision under {DATA_ROOT}/snapshots/ (see App\Filesystem\
 * SnapshotStore) — the actual content, at the same trust/exposure level
 * as the live Minecraft config file it was copied from (which must
 * itself hold any secret value in plaintext for the server to read it;
 * this is not a NEW place a secret is exposed, merely another on-disk
 * copy at the same boundary Task 6 already established). This is what
 * App\Config\ConfigRevisionService::restore() reads to compute the
 * changes needed to return the live file toward this revision — never
 * the database. `redacted_diff` and `summary`, by contrast, are always
 * pre-redacted display text, safe to show directly (Task 9's History
 * page).
 *
 * @property int $id
 * @property int $config_file_id
 * @property string|null $operation_id
 * @property string $kind
 * @property string $sha256
 * @property string $snapshot_path
 * @property string|null $summary
 * @property string|null $redacted_diff
 * @property string|null $restart_impact
 * @property string|null $risk
 * @property OperationActorType|null $author_type
 * @property string|null $author_id
 * @property string|null $author_origin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'config_file_id', 'operation_id', 'kind', 'sha256', 'snapshot_path',
    'summary', 'redacted_diff', 'restart_impact', 'risk',
    'author_type', 'author_id', 'author_origin',
])]
class ConfigRevision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_type' => OperationActorType::class,
        ];
    }

    /**
     * @return BelongsTo<ConfigFile, $this>
     */
    public function configFile(): BelongsTo
    {
        return $this->belongsTo(ConfigFile::class);
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
