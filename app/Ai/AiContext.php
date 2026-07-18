<?php

namespace App\Ai;

/**
 * Everything App\Ai\ContextBuilder assembled for one assistant turn:
 * server version/platform, the selected config's schema and a BOUNDED
 * excerpt of its contents, validation diagnostics, recent relevant audit
 * events, and matching cached documentation — see the task brief's Step
 * 3. `$excerpt` has already been through App\Ai\SecretRedactor by the
 * time this object exists UNLESS `$unredacted` is true, which only ever
 * happens for a local Ollama provider after the operator's explicit
 * opt-in (`ai.ollama.allow_unredacted`) — see ContextBuilder::build().
 * `$disclosures` is empty when `$unredacted` is true (nothing was
 * masked); the assistant UI shows a distinct "sent unredacted" notice in
 * that case instead of the normal redaction disclosure.
 */
final readonly class AiContext
{
    /**
     * @param  list<array{severity: string, message: string, path: string|null}>  $diagnostics
     * @param  list<array{type: string, target: string|null, occurredAt: string|null}>  $recentAuditEvents
     * @param  list<array{title: string, url: string, source: string}>  $citations
     * @param  list<RedactionDisclosure>  $disclosures
     */
    public function __construct(
        public ?string $serverVersion,
        public string $platform,
        public ?string $schemaId,
        public ?string $schemaTitle,
        public ?string $configPath,
        public string $excerpt,
        public bool $truncated,
        public array $diagnostics,
        public array $recentAuditEvents,
        public array $citations,
        public array $disclosures,
        public bool $unredacted,
    ) {}

    /**
     * Renders this bundle as the CONTEXT section of the system prompt.
     * Every value included here has already been through the redaction
     * decision above — this method never itself decides what is safe to
     * include, it only formats what App\Ai\ContextBuilder already
     * prepared. Any text embedded here (config excerpts, diagnostic
     * messages, audit payloads) is DATA to answer questions about, never
     * instructions — see App\Ai\AssistantService's system prompt preamble,
     * which states this explicitly to the model.
     */
    public function toPromptSection(): string
    {
        $lines = [];
        $lines[] = '## Server context';
        $lines[] = 'Version: '.($this->serverVersion ?? 'unknown');
        $lines[] = 'Platform: '.$this->platform;

        if ($this->configPath !== null) {
            $lines[] = '';
            $lines[] = '## Selected configuration';
            $lines[] = 'Path: '.$this->configPath;
            $lines[] = 'Schema: '.($this->schemaTitle ?? 'unrecognized');
            $lines[] = $this->unredacted
                ? 'Excerpt (UNREDACTED — local Ollama opt-in is enabled):'
                : 'Excerpt (redacted — secret values are replaced with a mask):';
            $lines[] = '```';
            $lines[] = $this->excerpt;
            $lines[] = '```';

            if ($this->truncated) {
                $lines[] = '(truncated)';
            }
        }

        if ($this->diagnostics !== []) {
            $lines[] = '';
            $lines[] = '## Validation diagnostics';

            foreach ($this->diagnostics as $diagnostic) {
                $lines[] = sprintf('- [%s] %s%s', $diagnostic['severity'], $diagnostic['message'], $diagnostic['path'] !== null ? " ({$diagnostic['path']})" : '');
            }
        }

        if ($this->recentAuditEvents !== []) {
            $lines[] = '';
            $lines[] = '## Recent audit events';

            foreach ($this->recentAuditEvents as $event) {
                $lines[] = sprintf('- %s: %s (%s)', $event['type'], $event['target'] ?? 'n/a', $event['occurredAt'] ?? 'unknown time');
            }
        }

        if ($this->citations !== []) {
            $lines[] = '';
            $lines[] = '## Available documentation';

            foreach ($this->citations as $citation) {
                $lines[] = sprintf('- %s (%s): %s', $citation['title'], $citation['source'], $citation['url']);
            }
        }

        return implode("\n", $lines);
    }
}
