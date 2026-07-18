<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /api/v1/config/files — metadata only, never file
 * contents (see ConfigFileDetailResource for the single-file show
 * endpoint, which DOES include contents, always through the same
 * secret-redaction pass Task 8 already proved). $this->resource is a
 * plain array built by App\Http\Controllers\Api\V1\ConfigController —
 * there is no raw file content anywhere in this shape for a secret value
 * to hide in.
 */
class ConfigFileResource extends JsonResource
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
        ];
    }
}
