<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/config/files/{path} — a single file's metadata AND content.
 * `contents` is ALWAYS the output of App\Config\ConfigDiffBuilder::
 * redactSecrets() (Task 8's redaction, the exact same call
 * App\Http\Controllers\ConfigController uses for its own source-mode
 * preview) — never the raw bytes App\Filesystem\MinecraftFilesystem::read()
 * returned. $this->resource is a plain array built by
 * App\Http\Controllers\Api\V1\ConfigController::show(); no raw
 * FileSnapshot ever reaches this class.
 */
class ConfigFileDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'path' => $this->resource['path'],
            'filename' => $this->resource['filename'],
            'format' => $this->resource['format'],
            'category' => $this->resource['category'],
            'provenance' => $this->resource['provenance'],
            'recognized' => $this->resource['recognized'],
            'schema_title' => $this->resource['schema_title'],
            'size_bytes' => $this->resource['size_bytes'],
            'modified_at' => $this->resource['modified_at'],
            'base_sha256' => $this->resource['base_sha256'],
            'contents' => $this->resource['contents'],
            'validation' => $this->resource['validation'],
        ];
    }
}
