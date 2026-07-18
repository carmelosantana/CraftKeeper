<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PluginInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One installed plugin — GET /api/v1/plugins and GET
 * /api/v1/plugins/{filename}. Nothing on App\Models\PluginInstallation is
 * secret-shaped (name/version/dependency lists/compatibility evidence are
 * all plugin metadata, not credentials), so no redaction pass is needed
 * here; this exists mainly to give the API a stable, explicit shape
 * independent of the model's own column set.
 *
 * @mixin PluginInstallation
 */
class PluginResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PluginInstallation $plugin */
        $plugin = $this->resource;

        return [
            'relative_path' => $plugin->relative_path,
            'filename' => basename($plugin->relative_path),
            'name' => $plugin->name,
            'version' => $plugin->version,
            'api_version' => $plugin->api_version,
            'enabled' => $plugin->enabled,
            'provenance' => $plugin->provenance,
            'duplicate_name' => $plugin->duplicate_name,
            'hard_dependencies' => $plugin->hard_dependencies,
            'soft_dependencies' => $plugin->soft_dependencies,
            'compatibility_state' => $plugin->compatibility_state?->value,
            'sha256' => $plugin->sha256,
            'size_bytes' => $plugin->size_bytes,
            'last_seen_at' => $plugin->last_seen_at?->toIso8601String(),
            'missing_since' => $plugin->missing_since?->toIso8601String(),
        ];
    }
}
