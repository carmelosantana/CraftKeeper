<?php

namespace App\Ai;

use App\Config\ConfigFormatRegistry;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\AuditEvent;
use App\Server\ServerVersionDetector;
use Throwable;

/**
 * Assembles App\Ai\AiContext for one assistant turn: server version/
 * platform, the selected config's schema and a BOUNDED, redacted excerpt
 * of it, validation diagnostics, recent relevant audit events, and cached
 * documentation citations (App\Ai\DocumentationIndex) — the task brief's
 * Step 3.
 *
 * The redaction decision is made HERE, once, before AiContext is ever
 * constructed: the excerpt is redacted (App\Ai\SecretRedactor, against
 * BOTH configured Secret values and schema-discovered secret values —
 * ambiguity resolution #2) UNLESS `$request->allowUnredacted` is true —
 * which the caller (App\Ai\AssistantService) only ever sets when the
 * resolved provider is the local Ollama provider AND the operator has
 * explicitly opted in (`ai.ollama.allow_unredacted`). A hosted provider
 * NEVER reaches this class with allowUnredacted true — AssistantService
 * hard-codes that, not this class — but this class still defends its own
 * default (redacted) regardless of caller discipline elsewhere.
 *
 * The full file is read and redacted BEFORE truncation, never the other
 * way around: truncating first could split a secret value across the cut
 * point, leaving one half of it un-matchable by SecretRedactor and
 * therefore leaked into the excerpt. See the same reasoning applied in
 * App\Ai\Tools\ReadConfigTool.
 *
 * Every value assembled here — config excerpt, diagnostic messages, audit
 * event summaries — is inert DATA describing what CraftKeeper observed.
 * None of it is ever interpreted as an instruction to the AI agent; see
 * App\Ai\AssistantService's system prompt preamble, which states this
 * explicitly, and App\Ai\Tools\AllowedToolsPolicy, which makes it
 * structurally true regardless of what the prompt says.
 */
final class ContextBuilder
{
    private const EXCERPT_MAX_CHARS = 4000;

    private const RECENT_AUDIT_EVENTS_LIMIT = 5;

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
        private readonly SecretRedactor $redactor,
        private readonly DocumentationIndex $documentation,
        private readonly ServerVersionDetector $versionDetector,
    ) {}

    public function build(ContextRequest $request): AiContext
    {
        $version = $this->versionDetector->detect();
        $platform = $version->known ? strtok((string) $version->label, ' ') ?: 'Unknown' : 'Unknown';

        if ($request->configPath === null) {
            return new AiContext(
                serverVersion: $version->known ? $version->label : null,
                platform: (string) $platform,
                schemaId: null,
                schemaTitle: null,
                configPath: null,
                excerpt: '',
                truncated: false,
                diagnostics: [],
                recentAuditEvents: $this->recentAuditEvents(null),
                citations: $this->documentation->search([$platform]),
                disclosures: [],
                unredacted: false,
            );
        }

        return $this->buildForConfigPath($request, $version->known ? $version->label : null, (string) $platform);
    }

    private function buildForConfigPath(ContextRequest $request, ?string $serverVersion, string $platform): AiContext
    {
        try {
            $path = MinecraftPath::fromUserInput($request->configPath ?? '');
            $snapshot = $this->filesystem->read($path);
        } catch (UnsafeMinecraftPath|MinecraftRootUnavailable|MinecraftFileNotFound|NotARegularFile) {
            return new AiContext(
                serverVersion: $serverVersion,
                platform: $platform,
                schemaId: null,
                schemaTitle: null,
                configPath: $request->configPath,
                excerpt: '(unavailable — the file could not be read)',
                truncated: false,
                diagnostics: [],
                recentAuditEvents: $this->recentAuditEvents($request->configPath),
                citations: $this->documentation->search([$platform]),
                disclosures: [],
                unredacted: false,
            );
        }

        $schema = $this->schemas->forPath($path);
        $adapter = $this->formats->for($snapshot);

        $parsed = null;
        $diagnostics = [];

        try {
            $parsed = $adapter->parse($snapshot->contents);
            $validation = $adapter->validate($snapshot->contents, $schema);

            foreach ($validation->diagnostics as $diagnostic) {
                $diagnostics[] = [
                    'severity' => $diagnostic->severity->value,
                    'message' => $diagnostic->message,
                    'path' => $diagnostic->path,
                ];
            }
        } catch (Throwable) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'The file could not be fully parsed.', 'path' => null];
        }

        $unredacted = $request->allowUnredacted;

        if ($unredacted) {
            $safeText = $snapshot->contents;
            $disclosures = [];
        } else {
            $result = $this->redactor->redactKnownSecrets($snapshot->contents, $parsed, $schema);
            $safeText = $result->text;
            $disclosures = $result->disclosures;
        }

        $truncated = mb_strlen($safeText) > self::EXCERPT_MAX_CHARS;
        $excerpt = $truncated ? mb_substr($safeText, 0, self::EXCERPT_MAX_CHARS) : $safeText;

        $keywords = array_filter([$platform, $schema?->id, $schema?->title]);

        return new AiContext(
            serverVersion: $serverVersion,
            platform: $platform,
            schemaId: $schema?->id,
            schemaTitle: $schema?->title,
            configPath: $path->relativePath,
            excerpt: $excerpt,
            truncated: $truncated,
            diagnostics: $diagnostics,
            recentAuditEvents: $this->recentAuditEvents($path->relativePath),
            citations: $this->documentation->search(array_values($keywords)),
            disclosures: $disclosures,
            unredacted: $unredacted,
        );
    }

    /**
     * Every secret VALUE App\Ai\SecretRedactor would treat as "known" for
     * $configPath right now: every configured App\Models\Secret value,
     * plus (when $configPath resolves to a real, parseable file) every
     * schema `secret: true` field value actually present in it — the
     * EXACT set build()/buildForConfigPath() already redact the context
     * excerpt against.
     *
     * App\Ai\AssistantService additionally uses this, unchanged, to run
     * ONE redaction pass over the WHOLE outgoing request — system prompt,
     * full conversation HISTORY, and the current message — right before
     * transport (Task 16's fix: per-component redaction, i.e. redacting
     * only this turn's excerpt and this turn's own message, left a raw
     * secret sitting in an EARLIER stored turn free to be replayed
     * verbatim to a hosted provider the moment the operator switched
     * providers mid-conversation; see AssistantService's own docblock).
     *
     * Read-only introspection, not itself a redaction call — it mirrors
     * buildForConfigPath()'s own file read/parse and is equally
     * best-effort: any failure to read or parse the file simply falls
     * back to configured secrets alone, exactly like build() does.
     *
     * @return array<string, string> value => human label
     */
    public function knownSecretLabels(?string $configPath): array
    {
        $labels = $this->redactor->configuredSecretLabels();

        if ($configPath === null) {
            return $labels;
        }

        try {
            $path = MinecraftPath::fromUserInput($configPath);
            $snapshot = $this->filesystem->read($path);
        } catch (UnsafeMinecraftPath|MinecraftRootUnavailable|MinecraftFileNotFound|NotARegularFile) {
            return $labels;
        }

        $schema = $this->schemas->forPath($path);
        $adapter = $this->formats->for($snapshot);

        try {
            $parsed = $adapter->parse($snapshot->contents);
        } catch (Throwable) {
            return $labels;
        }

        return array_merge($labels, $this->redactor->discoverSchemaSecretLabels($parsed, $schema));
    }

    /**
     * @return list<array{type: string, target: string|null, occurredAt: string|null}>
     */
    private function recentAuditEvents(?string $target): array
    {
        $query = AuditEvent::query()->with('operation')->latest('id');

        if ($target !== null) {
            $query->whereHas('operation', fn ($q) => $q->where('target', $target));
        }

        return array_values($query->limit(self::RECENT_AUDIT_EVENTS_LIMIT)->get()
            ->map(fn (AuditEvent $event): array => [
                'type' => $event->event_type,
                'target' => $event->operation?->target,
                'occurredAt' => $event->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
