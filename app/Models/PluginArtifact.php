<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A specific JAR identified purely by its content hash — the record Task
 * 14/15 populate when a plugin is downloaded from a known source
 * (CraftKeeper Catalog, Hangar, Modrinth) so App\Plugins\
 * PluginInventoryService can later recognize a byte-for-byte match on
 * disk as coming from that source rather than a manual drop. Nothing in
 * Task 13 creates a row here; PluginInventoryService only ever reads
 * this table, by sha256, to decide whether a checksum-changed
 * installation gets attributed to a known source release — see
 * PluginInventoryService::resolveProvenanceForChange()/
 * resolveProvenanceForNew().
 *
 * @property int $id
 * @property string $sha256
 * @property int $size_bytes
 * @property string|null $source
 * @property string|null $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['sha256', 'size_bytes', 'source', 'version'])]
class PluginArtifact extends Model
{
    //
}
