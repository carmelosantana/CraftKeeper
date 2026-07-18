<?php

namespace App\Mcp\Resources;

use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\MinecraftFilesystem;
use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Support\ApiScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Resource;

/**
 * A BOUNDED inventory of discovered CraftKeeper-managed config files —
 * metadata only (path, format, category, size), never file content. Each
 * item's `content_uri` points at the App\Mcp\Resources\ConfigFileResource
 * URI template for that file's bounded, REDACTED content. Reuses the
 * exact same App\Filesystem\MinecraftFilesystem::discover() +
 * App\Config\Schemas\ConfigSchemaRegistry pipeline
 * App\Http\Controllers\Api\V1\ConfigController::index() already uses.
 * Requires the `config:read` scope.
 */
#[Description('Bounded inventory of discovered CraftKeeper-managed config files: path, format, category, and size only — never file content. Use each item\'s content_uri (or the config file resource template) for redacted content.')]
class ConfigResource extends Resource
{
    private const MAX_ITEMS = 100;

    protected string $uri = 'craftkeeper://config/files';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, McpGuard $guard, MinecraftFilesystem $filesystem, ConfigSchemaRegistry $schemas): Response
    {
        return $guard->run('resource', $this->uri(), ApiScope::ConfigRead->value, [], function (McpGrant $grant) use ($filesystem, $schemas) {
            $discovered = collect($filesystem->discover())->sortBy(fn ($file) => $file->path->relativePath)->values();

            $items = $discovered->take(self::MAX_ITEMS)->map(fn ($file) => [
                'path' => $file->path->relativePath,
                'content_uri' => 'craftkeeper://config/files/'.rawurlencode($file->path->relativePath),
                'format' => $file->format,
                'category' => $file->category->value,
                'recognized' => $file->recognized,
                'schema_title' => $schemas->forPath($file->path)?->title,
                'size_bytes' => $file->sizeBytes,
            ])->values();

            return Response::json([
                'files' => $items->all(),
                'truncated' => $discovered->count() > self::MAX_ITEMS,
            ]);
        });
    }
}
