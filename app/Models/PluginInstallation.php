<?php

namespace App\Models;

use App\Plugins\PluginCompatibilityState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One plugin JAR App\Plugins\PluginInventoryService has ever seen on
 * disk, keyed by its LOGICAL relative path (always the enabled form —
 * see App\Plugins\DiscoveredPlugin's docblock). Intentionally thin: all
 * the reconciliation logic (additions/removals/changes/duplicates/
 * conflicts, provenance preservation, compatibility assessment) lives in
 * PluginInventoryService; this model only persists what that service
 * decided.
 *
 * @property int $id
 * @property string $relative_path
 * @property string|null $name
 * @property string|null $version
 * @property string|null $main_class
 * @property string|null $api_version
 * @property list<string> $hard_dependencies
 * @property list<string> $soft_dependencies
 * @property string|null $metadata_source
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property Carbon|null $file_modified_at
 * @property bool $enabled
 * @property string $provenance
 * @property bool $duplicate_name
 * @property list<array{issue: string, message: string}> $inspection_diagnostics
 * @property PluginCompatibilityState|null $compatibility_state
 * @property list<array{source: string, summary: string, supports_compatibility: bool|null}> $compatibility_evidence
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $missing_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'relative_path', 'name', 'version', 'main_class', 'api_version',
    'hard_dependencies', 'soft_dependencies', 'metadata_source',
    'sha256', 'size_bytes', 'file_modified_at', 'enabled', 'provenance',
    'duplicate_name', 'inspection_diagnostics', 'compatibility_state',
    'compatibility_evidence', 'last_seen_at', 'missing_since',
])]
class PluginInstallation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hard_dependencies' => 'array',
            'soft_dependencies' => 'array',
            'inspection_diagnostics' => 'array',
            'compatibility_evidence' => 'array',
            'compatibility_state' => PluginCompatibilityState::class,
            'enabled' => 'boolean',
            'duplicate_name' => 'boolean',
            'file_modified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }
}
