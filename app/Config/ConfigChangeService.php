<?php

namespace App\Config;

use App\Config\Exceptions\ConfigConflict;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Formats\JsonAdapter;
use App\Config\Formats\PropertiesAdapter;
use App\Config\Formats\TomlAdapter;
use App\Config\Formats\YamlAdapter;
use App\Config\Schemas\ConfigFieldRisk;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Config\Schemas\RestartImpact;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\ChangeProposal;
use App\Models\ConfigChangePayload;
use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Operations\InputRedactor;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationRisk;
use App\Operations\OperationService;
use App\Operations\OperationType;
use Illuminate\Support\Facades\DB;

/**
 * Turns a caller's ConfigChangeRequest into a reviewable, audited
 * Operation — the "propose" half of CraftKeeper's config change pipeline
 * (App\Operations\Handlers\ConfigApplyHandler / ConfigRestoreHandler own
 * the "apply" half, at execute() time).
 *
 * Three responsibilities, in order:
 *
 * 1. Conflict detection: reads the file's CURRENT content and refuses
 *    (ConfigConflict, never a silent overwrite) if the request's base
 *    sha256 no longer matches — see the class docblock on
 *    App\Config\Exceptions\ConfigConflict for how this differs from the
 *    later, execute()-time TOCTOU re-check.
 * 2. Building a rich, secret-safe proposal: computes redacted before/
 *    after values and a redacted unified diff (App\Config\
 *    ConfigDiffBuilder), runs validation, classifies risk/restart impact
 *    from the schema, and collects documentation citations — all of it
 *    safe to display, audit, and broadcast as-is. A change that
 *    literally cannot be represented in the target format
 *    (ConfigFormatAdapter::applyChanges() throwing InvalidConfigChange)
 *    is caught here and surfaces as a validation failure on the created
 *    Operation, never as an uncaught exception.
 * 3. Persisting the REAL change values separately, encrypted: the one
 *    place a proposed secret value exists in the database is
 *    App\Models\ConfigChangePayload, never anything derived from through
 *    App\Operations\OperationService::propose()'s generic metadata path
 *    (see storeRawChanges()'s docblock for why that distinction is load-
 *    bearing, not stylistic).
 */
class ConfigChangeService
{
    /**
     * How long an approved-but-not-yet-executed proposal remains valid.
     * Enforced defensively by the handler (App\Operations\Handlers\
     * Concerns\AppliesConfigChanges) immediately before it writes, so a
     * proposal reviewed long ago can never silently execute against
     * whatever the file looks like much later.
     */
    public const PROPOSAL_TTL_HOURS = 24;

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
        private readonly OperationService $operations,
    ) {}

    public function propose(ConfigChangeRequest $request, OperationAuthor $author): Operation
    {
        return $this->build($request, $author, OperationType::ConfigApply, null);
    }

    /**
     * Internal seam for App\Config\ConfigRevisionService::restore(): builds
     * a config.restore Operation through this exact same pipeline (base-
     * hash conflict check, redaction, diff, validation) rather than
     * duplicating any of it — restore is a fresh reviewable proposal, not
     * a blind file copy.
     */
    public function proposeRestore(ConfigChangeRequest $request, OperationAuthor $author, ConfigRevision $revision): Operation
    {
        return $this->build($request, $author, OperationType::ConfigRestore, $revision);
    }

    private function build(ConfigChangeRequest $request, OperationAuthor $author, OperationType $type, ?ConfigRevision $restoring): Operation
    {
        $path = MinecraftPath::fromUserInput($request->path);
        $current = $this->filesystem->read($path);

        if (! hash_equals($request->baseSha256, $current->sha256)) {
            throw new ConfigConflict($path, $request->baseSha256, $current->sha256);
        }

        $adapter = $this->formats->for($current);
        $schema = $this->schemas->forPath($path);
        $currentParsed = $adapter->parse($current->contents);

        $diagnostics = [];
        $newContents = $current->contents;
        $valid = true;
        $applied = false;

        try {
            if ($this->willNormalize($adapter, $current->contents, $request->changes, $schema)) {
                $diagnostics[] = [
                    'severity' => 'warning',
                    'message' => 'Applying this change will reformat the file and may drop comments or reorder keys.',
                    'path' => null,
                ];
            }

            $newContents = $adapter->applyChanges($current->contents, $request->changes, $schema);
            $applied = true;

            $result = $adapter->validate($newContents, $schema);
            $valid = $result->valid;

            foreach ($result->diagnostics as $diagnostic) {
                $diagnostics[] = [
                    'severity' => $diagnostic->severity->value,
                    'message' => $this->safeDiagnosticMessage($diagnostic->path, $diagnostic->message, $schema),
                    'path' => $diagnostic->path,
                ];
            }
        } catch (InvalidConfigChange $e) {
            $valid = false;
            $diagnostics[] = ['severity' => 'error', 'message' => $e->getMessage(), 'path' => null];
        }

        $summary = $this->summarizeChanges($request->changes, $currentParsed, $schema);

        $changingSecretPaths = array_values(array_map(
            fn (array $field) => $field['path'],
            array_filter($summary['fields'], fn (array $field) => $field['secret']),
        ));

        $diff = $applied
            ? ConfigDiffBuilder::build($adapter, $schema, $request->path, $current->contents, $newContents, $changingSecretPaths)
            : '';

        $expiresAt = now()->addHours(self::PROPOSAL_TTL_HOURS);

        $metadata = [
            'kind' => $type === OperationType::ConfigRestore ? 'restore' : 'apply',
            'base_sha256' => $current->sha256,
            'changed_fields' => array_map(fn (array $c) => $c['path'], $summary['fields']),
            'diff' => $diff,
            'valid' => $valid,
            'diagnostics' => $diagnostics,
            'restart_impact' => $summary['restartImpact']->value,
            'documentation' => $summary['documentation'],
            'expires_at' => $expiresAt->toIso8601String(),
            'restoring_revision_id' => $restoring?->id,
        ];

        $risk = $summary['risk'] === ConfigFieldRisk::High ? OperationRisk::Elevated : OperationRisk::Standard;

        return DB::transaction(function () use ($request, $author, $type, $metadata, $risk, $summary): Operation {
            $operation = $this->operations->propose(
                OperationRequest::make($type, $request->path, $metadata, $risk),
                $author,
            );

            $this->recordFieldProposals($operation, $summary['fields']);
            $this->storeRawChanges($operation, $request->changes);

            return $operation;
        });
    }

    /**
     * willNormalize() is deliberately NOT part of the fixed
     * ConfigFormatAdapter interface (see its docblock and Task 7's entry
     * in docs/architecture/decisions.md) — every concrete adapter
     * declares it with the same signature, so this narrows $adapter to
     * each of the four known concrete classes in turn (a closed set:
     * App\Config\ConfigFormatRegistry only ever constructs and returns
     * one of these four) rather than calling through the interface type,
     * which has no such method.
     *
     * @param  list<ConfigChange>  $changes
     */
    private function willNormalize(ConfigFormatAdapter $adapter, string $contents, array $changes, ?ConfigSchema $schema): bool
    {
        return match (true) {
            $adapter instanceof PropertiesAdapter => $adapter->willNormalize($contents, $changes, $schema),
            $adapter instanceof YamlAdapter => $adapter->willNormalize($contents, $changes, $schema),
            $adapter instanceof JsonAdapter => $adapter->willNormalize($contents, $changes, $schema),
            $adapter instanceof TomlAdapter => $adapter->willNormalize($contents, $changes, $schema),
            default => false,
        };
    }

    /**
     * @param  list<ConfigChange>  $changes
     * @return array{fields: list<array{path: string, kind: string, before: string, after: string, summary: string, secret: bool}>, risk: ConfigFieldRisk, restartImpact: RestartImpact, documentation: list<array{path: string, url: string}>}
     */
    private function summarizeChanges(array $changes, ParsedConfig $currentParsed, ?ConfigSchema $schema): array
    {
        $fields = [];
        $risk = ConfigFieldRisk::Low;
        $restartImpact = RestartImpact::None;
        $documentation = [];

        foreach ($changes as $change) {
            $field = $schema?->field($change->path);
            $secret = $field?->secret === true;

            $beforeValue = $currentParsed->node($change->path)?->value;
            $afterValue = $change->kind === ConfigChangeKind::Remove ? null : $change->value;

            $before = $secret ? InputRedactor::MASK : $this->displayValue($beforeValue);
            $after = match (true) {
                $change->kind === ConfigChangeKind::Remove => '(removed)',
                $secret => InputRedactor::MASK,
                default => $this->displayValue($afterValue),
            };

            $fields[] = [
                'path' => $change->path,
                'kind' => $change->kind->value,
                'before' => $before,
                'after' => $after,
                'summary' => sprintf('%s %s', ucfirst($change->kind->value), $change->path),
                'secret' => $secret,
            ];

            if ($field !== null) {
                $risk = $this->higherRisk($risk, $field->risk);
                $restartImpact = $this->higherRestartImpact($restartImpact, $field->restartImpact);
                $documentation[] = ['path' => $change->path, 'url' => $field->documentationUrl];
            }
        }

        return ['fields' => $fields, 'risk' => $risk, 'restartImpact' => $restartImpact, 'documentation' => $documentation];
    }

    private function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: '(complex value)',
        };
    }

    private function higherRisk(ConfigFieldRisk $a, ConfigFieldRisk $b): ConfigFieldRisk
    {
        $order = [ConfigFieldRisk::Low->value => 0, ConfigFieldRisk::Medium->value => 1, ConfigFieldRisk::High->value => 2];

        return $order[$b->value] > $order[$a->value] ? $b : $a;
    }

    private function higherRestartImpact(RestartImpact $a, RestartImpact $b): RestartImpact
    {
        $order = [RestartImpact::None->value => 0, RestartImpact::Reload->value => 1, RestartImpact::Restart->value => 2];

        return $order[$b->value] > $order[$a->value] ? $b : $a;
    }

    /**
     * Defense in depth: App\Config\Schemas\SchemaValidator's own
     * diagnostics for an "allowed values"/"range" mismatch embed the
     * actual out-of-range VALUE in their message text (see its docblock —
     * this is fine for every field today, since no secret-flagged field
     * currently declares allowedValues/range). Rather than rely on that
     * staying true forever, any diagnostic pinned to a secret field's path
     * gets its message replaced before it is ever persisted, audited, or
     * broadcast.
     */
    private function safeDiagnosticMessage(?string $diagnosticPath, string $message, ?ConfigSchema $schema): string
    {
        if ($diagnosticPath === null || $schema?->field($diagnosticPath)?->secret !== true) {
            return $message;
        }

        return sprintf('[%s] failed schema validation (details withheld: secret field).', $diagnosticPath);
    }

    /**
     * @param  list<array{path: string, kind: string, before: string, after: string, summary: string}>  $fields
     */
    private function recordFieldProposals(Operation $operation, array $fields): void
    {
        foreach ($fields as $field) {
            ChangeProposal::query()->create([
                'operation_id' => $operation->id,
                'field' => $field['path'],
                'summary' => $field['summary'],
                'before' => $field['before'],
                'after' => $field['after'],
            ]);
        }
    }

    /**
     * The one place the REAL (unredacted) proposed values are persisted —
     * deliberately NEVER folded into OperationRequest's $metadata array.
     * App\Operations\OperationService::propose() feeds that metadata
     * (after only a coarse, key-name-based pass — App\Operations\
     * InputRedactor) straight into Operation::redacted_input and, one
     * level deep, into generic App\Models\ChangeProposal rows; a raw
     * secret value sitting under an innocuously-named key (e.g. this
     * class's own 'changed_fields'/'value' shape) would sail straight
     * through that net. Routing the real values through
     * App\Models\ConfigChangePayload instead — encrypted, `#[Hidden]`,
     * outside every audit/broadcast path — is what makes "secrets never
     * appear anywhere except the file itself" actually true rather than
     * incidentally true today.
     *
     * @param  list<ConfigChange>  $changes
     */
    private function storeRawChanges(Operation $operation, array $changes): void
    {
        ConfigChangePayload::query()->create([
            'operation_id' => $operation->id,
            'changes' => array_map(fn (ConfigChange $change): array => [
                'kind' => $change->kind->value,
                'path' => $change->path,
                'value' => $change->value,
            ], $changes),
        ]);
    }
}
