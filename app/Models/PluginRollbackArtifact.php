<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One JAR App\Operations\Handlers\PluginOperationHandler preserved under
 * {data_root}/plugin-rollbacks BEFORE overwriting or removing it from
 * `/minecraft/plugins` — never unlinked immediately (Task 15's ambiguity
 * resolution #3). Keyed by `relative_path` (the plugin's LOGICAL,
 * always-enabled-form path, matching App\Models\PluginInstallation's own
 * convention) so a plugin.rollback Operation can find "the artifact this
 * plugin looked like before" and App\Console\Commands\
 * PrunePluginRollbackArtifacts can enforce "keep 3 per plugin for 30
 * days" per plugin independently of every other plugin's own history.
 *
 * @property int $id
 * @property string $relative_path
 * @property string $storage_path
 * @property string $sha256
 * @property int $size_bytes
 * @property string|null $source_operation_id
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['relative_path', 'storage_path', 'sha256', 'size_bytes', 'source_operation_id', 'reason'])]
class PluginRollbackArtifact extends Model
{
    //
}
