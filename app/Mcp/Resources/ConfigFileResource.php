<?php

namespace App\Mcp\Resources;

use App\Config\ConfigDiffBuilder;
use App\Config\ConfigFormatRegistry;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Support\ApiScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * A single config file's BOUNDED, REDACTED content, addressed by a
 * `rawurlencode()`d Minecraft-relative path (Laravel MCP's URI template
 * variables cannot contain a literal "/", which most nested plugin config
 * paths do — see Laravel\Mcp\Support\UriTemplate::compileRegex() — so the
 * path segment is percent-encoded both when this resource builds its own
 * `content_uri` links in App\Mcp\Resources\ConfigResource and when it
 * decodes an incoming request here).
 *
 * `contents` is ALWAYS the output of App\Config\ConfigDiffBuilder::
 * redactSecrets() — the exact same Task 8 redaction the web UI's
 * source-mode preview and the REST API's GET /api/v1/config/files/{path}
 * use — never the raw bytes App\Filesystem\MinecraftFilesystem::read()
 * returned. Output is truncated to a bounded character count; truncation
 * happens AFTER redaction, never before, so a secret value can never be
 * split across the cut and left unredactable. Requires the `config:read`
 * scope.
 */
#[Description('Bounded, REDACTED content of one CraftKeeper-managed config file, addressed by its URL-encoded Minecraft-relative path. Secret-flagged values are always redacted — never the raw bytes.')]
class ConfigFileResource extends Resource implements HasUriTemplate
{
    private const MAX_CHARS = 8000;

    protected string $mimeType = 'application/json';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('craftkeeper://config/files/{encoded_path}');
    }

    public function handle(Request $request, McpGuard $guard, MinecraftFilesystem $filesystem, ConfigFormatRegistry $formats, ConfigSchemaRegistry $schemas): Response
    {
        return $guard->run(
            'resource',
            (string) $this->uriTemplate(),
            ApiScope::ConfigRead->value,
            ['encoded_path' => $request->get('encoded_path')],
            function (McpGrant $grant) use ($request, $filesystem, $formats, $schemas) {
                $relativePath = rawurldecode((string) $request->get('encoded_path', ''));

                try {
                    $path = MinecraftPath::fromUserInput($relativePath);
                    $snapshot = $filesystem->read($path);
                } catch (UnsafeMinecraftPath|MinecraftRootUnavailable|MinecraftFileNotFound|NotARegularFile $e) {
                    return Response::error('Unable to read that config file: '.$e->getMessage());
                }

                $schema = $schemas->forPath($path);
                $adapter = $formats->for($snapshot);
                $redacted = ConfigDiffBuilder::redactSecrets($adapter, $schema, $snapshot->contents);

                $truncated = mb_strlen($redacted) > self::MAX_CHARS;
                $excerpt = $truncated ? mb_substr($redacted, 0, self::MAX_CHARS) : $redacted;

                return Response::json([
                    'path' => $path->relativePath,
                    'sha256' => $snapshot->sha256,
                    'schema_title' => $schema?->title,
                    'contents' => $excerpt,
                    'truncated' => $truncated,
                ]);
            }
        );
    }
}
