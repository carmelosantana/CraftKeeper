<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A known, editable config file CraftKeeper has proposed/applied a change
 * to at least once — the registry ConfigRevision rows hang off of.
 * Deliberately thin: this task only needs enough of a "file identity" to
 * anchor a revision history (Task 9's Index/History pages are expected to
 * read this alongside App\Config\ConfigDiscoveryService's live inventory,
 * not replace it — a ConfigFile row is created lazily, the first time an
 * operation against that path actually succeeds, not at discovery time).
 *
 * @property int $id
 * @property string $path
 * @property string $format
 * @property string|null $schema_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'format', 'schema_id'])]
class ConfigFile extends Model
{
    /**
     * @return HasMany<ConfigRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ConfigRevision::class);
    }

    /**
     * Find-or-create the ConfigFile row for a path, updating its format/
     * schema id if either has changed since it was first recorded (e.g. a
     * generic file later gains a recognized schema).
     */
    public static function forPath(string $path, string $format, ?string $schemaId): self
    {
        $file = static::query()->firstOrCreate(['path' => $path], [
            'format' => $format,
            'schema_id' => $schemaId,
        ]);

        if ($file->format !== $format || $file->schema_id !== $schemaId) {
            $file->forceFill(['format' => $format, 'schema_id' => $schemaId])->save();
        }

        return $file;
    }
}
