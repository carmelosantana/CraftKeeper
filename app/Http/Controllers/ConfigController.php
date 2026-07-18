<?php

namespace App\Http\Controllers;

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\ConfigDiagnostic;
use App\Config\ConfigDiffBuilder;
use App\Config\ConfigFormatAdapter;
use App\Config\ConfigFormatRegistry;
use App\Config\ConfigRevisionService;
use App\Config\DiscoveredFile;
use App\Config\Exceptions\ConfigConflict;
use App\Config\Exceptions\ConfigParseException;
use App\Config\Formats\PropertiesAdapter;
use App\Config\Formats\Support\DotPath;
use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigFieldType;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Config\ValidationResult;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\FileSnapshot;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\ChangeProposal;
use App\Models\ConfigFile;
use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Operations\InputRedactor;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Configuration inventory, preview, editing (guided/structured/source),
 * review, conflict, history, and restore — Task 9. This is the first UI
 * to consume the Task 6-8 config services; the load-bearing invariant
 * every method here upholds is the plan's "never sends raw secret values
 * to the browser" test: any config content that reaches an Inertia prop
 * — the inventory preview, or ANY of the three edit-mode representations
 * — has every schema `secret: true` field's value redacted to
 * App\Operations\InputRedactor::MASK ('••••••') before it is ever built
 * into a response. That redaction is delegated entirely to
 * App\Config\ConfigDiffBuilder::redactSecrets() (Task 8), which already
 * proves this property for the diff/proposal pipeline — reusing it here
 * (rather than re-implementing redaction) is what keeps the guarantee
 * true in exactly one place.
 *
 * The three edit modes (guided/structured/source) never send their edits
 * as a raw "replace the whole file" request. Each mode reconciles what the
 * operator changed into the SAME App\Config\ConfigChangeRequest shape Task
 * 8's ConfigChangeService::propose() already accepts — see
 * reconcileGuided()/reconcileStructured()/reconcileSource() — so the three
 * modes are guaranteed to produce identical domain changes for the same
 * edit, and none of them can ever write the literal '••••••' sentinel
 * back to a file: a secret field whose submitted value still equals the
 * sentinel is treated as "unchanged" and dropped from the change set
 * entirely (see each reconcile*() method's docblock for how).
 */
class ConfigController extends Controller
{
    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
        private readonly ConfigChangeService $changes,
        private readonly ConfigRevisionService $revisions,
        private readonly OperationService $operations,
    ) {}

    /**
     * GET /configurations — the grouped inventory. Search operates only on
     * names/paths/schema titles/plugin names, per the plan's own "Search
     * operates on names, paths, schema labels, and plugin names, never
     * secret values" — the query never touches file content at all, so
     * there is no code path by which a secret value could influence (or
     * leak through) a search match.
     */
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $discovered = $this->filesystem->discover();

        $items = [];

        foreach ($discovered as $file) {
            $schema = $this->schemas->forPath($file->path);
            $pluginName = $this->pluginName($file->path);

            if ($query !== '' && ! $this->matchesSearch($query, $file, $schema, $pluginName)) {
                continue;
            }

            $items[] = $this->buildInventoryItem($file, $schema, $pluginName);
        }

        $groups = collect($items)
            ->groupBy('category')
            ->map(fn ($group) => $group->values()->all())
            ->all();

        return Inertia::render('config/Index', [
            'query' => $query,
            'groups' => $groups,
            'total' => count($items),
        ]);
    }

    /**
     * GET /configurations/{path} — loads a single file for guided,
     * structured, and source editing simultaneously (the three modes are
     * tabs over the same page, not separate routes — see class docblock).
     * An optional `?operation=` query parameter re-opens a just-created
     * proposal's review panel (used by propose()'s redirect-free success
     * path and by restore()'s redirect).
     */
    public function edit(Request $request, string $path): Response
    {
        $resolved = $this->resolvePath($path);
        $current = $this->readOrAbort($resolved);
        $adapter = $this->formats->for($current);
        $schema = $this->schemas->forPath($resolved);
        $currentParsed = $adapter->parse($current->contents);

        $operation = $this->pendingOperationFromRequest($request, $resolved);

        return Inertia::render('config/Edit', $this->editProps($resolved, $current, $adapter, $schema, $currentParsed, $operation));
    }

    /**
     * POST /configurations/{path} — the single write endpoint every edit
     * mode submits to. `mode` selects which reconciler turns the
     * request's raw edit representation into a list<ConfigChange>; from
     * there on, guided/structured/source are indistinguishable — the same
     * ConfigChangeRequest goes through the same
     * App\Config\ConfigChangeService::propose() call.
     */
    public function propose(Request $request, string $path): HttpResponse
    {
        $resolved = $this->resolvePath($path);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['guided', 'structured', 'source', 'fields'])],
            'base_sha256' => ['required', 'string'],
            'base_source' => ['nullable', 'string'],
            'values' => ['nullable'],
            'source' => ['nullable', 'string'],
            'changes' => ['nullable', 'array'],
        ]);

        $current = $this->readOrAbort($resolved);
        $adapter = $this->formats->for($current);
        $schema = $this->schemas->forPath($resolved);
        $currentParsed = $adapter->parse($current->contents);

        $mode = (string) $data['mode'];

        try {
            $changeList = match ($mode) {
                'guided' => $this->reconcileGuided($schema, $currentParsed, $this->arrayInput($data['values'] ?? null)),
                'structured' => $this->reconcileStructured($adapter, $schema, $currentParsed->data, $this->arrayInput($data['values'] ?? null)),
                'source' => $this->reconcileSource($adapter, $schema, $current->contents, (string) ($data['source'] ?? '')),
                // 'fields' is NOT a fourth general editing mode — it is the
                // narrow escape hatch ambiguity resolution #3 requires: the
                // Conflict page's "create a fresh proposal from manually
                // selected values" action already knows the exact final
                // value for each field it lets the operator pick (no
                // baseline diffing needed, since there is no single
                // baseline tree once base/disk/proposed have diverged), so
                // it applies each selection directly as a Replace.
                'fields' => $this->reconcileFields($schema, array_values($this->arrayInput($data['changes'] ?? null))),
                default => throw new InvalidArgumentException("Unknown config edit mode [{$mode}]."),
            };
        } catch (ConfigParseException $e) {
            $props = $this->editProps($resolved, $current, $adapter, $schema, $currentParsed, null);
            $props['sourceError'] = $e->getMessage();

            return Inertia::render('config/Edit', $props)->toResponse($request);
        }

        if ($changeList === []) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'No changes to save.']);

            return Inertia::render('config/Edit', $this->editProps($resolved, $current, $adapter, $schema, $currentParsed, null))
                ->toResponse($request);
        }

        $changeRequest = new ConfigChangeRequest($resolved->relativePath, (string) $data['base_sha256'], $changeList);

        try {
            $operation = $this->changes->propose($changeRequest, OperationAuthor::user($request->user()->getKey()));
        } catch (ConfigConflict $e) {
            return $this->conflictResponse($request, $resolved, $current, $adapter, $schema, $changeList, $e);
        }

        return Inertia::render('config/Edit', $this->editProps($resolved, $current, $adapter, $schema, $currentParsed, $operation))
            ->toResponse($request);
    }

    /**
     * POST /configurations/operations/{operation}/approve — approve, then
     * immediately execute (config writes are fast, local, and
     * synchronous — see docs/architecture/decisions.md Task 5's note that
     * "whichever task builds the first real handler decides" how
     * execute() is triggered after approval; there is no queue involved
     * for config.apply/config.restore).
     */
    public function approve(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPendingConfigOperation($operation);

        $this->operations->approve($operation->id, $request->user());
        $executed = $this->operations->execute($operation->id);

        Inertia::flash('toast', $executed->status === OperationStatus::Succeeded
            ? ['type' => 'success', 'message' => $executed->outcome ?? 'Change applied.']
            : ['type' => 'error', 'message' => $executed->outcome ?? 'The change could not be applied.']);

        return redirect('/configurations/'.$operation->target);
    }

    /**
     * POST /configurations/operations/{operation}/reject — discard a
     * proposal without ever writing it. Also the "Discard changes" action
     * in the review panel.
     */
    public function reject(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPendingConfigOperation($operation);

        $reason = (string) $request->input('reason', 'Discarded by operator.');
        $this->operations->reject($operation->id, $request->user(), $reason);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Change discarded.']);

        return redirect('/configurations/'.$operation->target);
    }

    /**
     * GET /configurations/history/{path} — a file's revision history.
     * `redacted_diff`/`summary` on App\Models\ConfigRevision are already
     * secret-redacted at the moment they were written (they are copied
     * verbatim from the same proposal metadata Task 8's ConfigChangeService
     * builds), so no additional redaction is needed here.
     */
    public function history(Request $request, string $path): Response
    {
        $resolved = $this->resolvePath($path);
        $configFile = ConfigFile::query()->where('path', $resolved->relativePath)->first();

        $revisions = $configFile !== null
            ? ConfigRevision::query()->where('config_file_id', $configFile->id)->latest()->get()
            : collect();

        return Inertia::render('config/History', [
            'path' => $resolved->relativePath,
            'revisions' => $revisions->map(fn (ConfigRevision $revision) => [
                'id' => $revision->id,
                'kind' => $revision->kind,
                'summary' => $revision->summary,
                'diff' => $revision->redacted_diff,
                'restartImpact' => $revision->restart_impact,
                'risk' => $revision->risk,
                'authorType' => $revision->author_type?->value,
                'createdAt' => $revision->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * POST /configurations/revisions/{revision}/restore — App\Config\
     * ConfigRevisionService::restore() builds a FRESH, reviewable
     * config.restore proposal (never a blind file copy); this redirects
     * straight into the same review panel edit() renders for a normal
     * proposal, so restore never applies without the same approve/cancel
     * step every other change goes through.
     */
    public function restore(Request $request, ConfigRevision $revision): RedirectResponse
    {
        try {
            $operation = $this->revisions->restore($revision, $request->user());
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Unable to prepare that restore: '.$e->getMessage()]);

            return redirect('/configurations/history/'.$revision->configFile->path);
        }

        return redirect('/configurations/'.$operation->target.'?operation='.$operation->id);
    }

    // -----------------------------------------------------------------
    // Shared prop building
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function editProps(MinecraftPath $path, FileSnapshot $current, ConfigFormatAdapter $adapter, ?ConfigSchema $schema, ParsedConfig $currentParsed, ?Operation $operation): array
    {
        $validation = $adapter->validate($current->contents, $schema);
        $discovered = $this->findDiscovered($path);

        return [
            'file' => $this->buildFileMeta($path, $current, $schema, $discovered, $validation),
            'guided' => $this->buildGuided($schema, $currentParsed),
            'structured' => ['data' => $this->buildStructuredData($adapter, $schema, $currentParsed->data)],
            'source' => ['contents' => $this->redactedSource($adapter, $schema, $current->contents)],
            'proposal' => $operation !== null ? $this->presentOperation($operation) : null,
            'historyUrl' => '/configurations/history/'.$path->relativePath,
        ];
    }

    private function pendingOperationFromRequest(Request $request, MinecraftPath $path): ?Operation
    {
        $operationId = $request->query('operation');

        if (! is_string($operationId) || $operationId === '') {
            return null;
        }

        $operation = Operation::query()->find($operationId);

        if ($operation === null
            || $operation->target !== $path->relativePath
            || ! in_array($operation->type, [OperationType::ConfigApply, OperationType::ConfigRestore], true)
        ) {
            return null;
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileMeta(MinecraftPath $path, FileSnapshot $current, ?ConfigSchema $schema, ?DiscoveredFile $discovered, ValidationResult $validation): array
    {
        return [
            'path' => $path->relativePath,
            'filename' => basename($path->relativePath),
            'format' => $schema->format ?? strtolower(pathinfo($path->relativePath, PATHINFO_EXTENSION)),
            'category' => $discovered !== null ? $discovered->category->value : 'other',
            'provenance' => $this->provenanceKey($discovered !== null ? $discovered->provenance : 'Discovered'),
            'recognized' => $discovered !== null ? $discovered->recognized : ($schema !== null),
            'schemaId' => $schema?->id,
            'schemaTitle' => $schema?->title,
            'modifiedAt' => date(DATE_ATOM, $current->mtime),
            'sizeBytes' => strlen($current->contents),
            'baseSha256' => $current->sha256,
            'validation' => [
                'valid' => $validation->valid,
                'diagnostics' => $this->presentDiagnostics($validation->diagnostics),
            ],
        ];
    }

    /**
     * @param  list<ConfigDiagnostic>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function presentDiagnostics(array $diagnostics): array
    {
        return array_map(fn (ConfigDiagnostic $d) => [
            'severity' => $d->severity->value,
            'message' => $d->message,
            'path' => $d->path,
            'line' => $d->line,
            'column' => $d->column,
        ], $diagnostics);
    }

    /**
     * Guided-mode field metadata. Every field's `currentValue` is the
     * value CraftKeeper is about to show the operator in a form control —
     * this is the one line that decides whether a secret leaks into the
     * browser in guided mode: a schema-secret field ALWAYS gets
     * InputRedactor::MASK here, never `$node->value`, regardless of what
     * the file actually contains.
     *
     * @return array<string, mixed>|null
     */
    private function buildGuided(?ConfigSchema $schema, ParsedConfig $currentParsed): ?array
    {
        if ($schema === null) {
            return null;
        }

        $byGroup = [];

        foreach ($schema->fields as $field) {
            $node = $currentParsed->node($field->path);
            $currentValue = $field->secret ? InputRedactor::MASK : ($node !== null ? $node->value : $field->default);

            $byGroup[$this->groupTitle($field->path)][] = [
                'path' => $field->path,
                'type' => $field->type->value,
                'title' => $field->title,
                'description' => $field->description,
                'default' => $field->default,
                'restartImpact' => $field->restartImpact->value,
                'risk' => $field->risk->value,
                'allowedValues' => $field->allowedValues,
                'range' => $field->range !== null ? ['min' => $field->range->min, 'max' => $field->range->max] : null,
                'secret' => $field->secret,
                'documentationUrl' => $field->documentationUrl,
                'currentValue' => $currentValue,
                // Task 7's schema carries no curated "commonly edited vs
                // rarely touched" signal — an earlier version of this
                // derived `advanced` from risk===low && restartImpact===
                // none, which backfired badly: MOTD (server-properties'
                // single most commonly edited field) is risk:low,
                // restartImpact:none, so it collapsed under "advanced"
                // right alongside genuinely obscure settings, while a
                // handful of medium/high-risk fields stayed "essential"
                // purely by that same accident. Rather than hide fields
                // an operator would reasonably expect to see immediately,
                // every field is shown flat; GuidedEditor.tsx still
                // supports per-field `advanced` collapsing structurally
                // for whenever the schema gains a real curated signal.
                'advanced' => false,
            ];
        }

        $groups = [];

        foreach ($byGroup as $title => $fields) {
            $groups[] = ['title' => $title, 'fields' => $fields];
        }

        return ['groups' => $groups];
    }

    private function groupTitle(string $path): string
    {
        $segment = str_contains($path, '.') ? strstr($path, '.', true) : 'General';

        return ucwords(str_replace(['-', '_'], ' ', (string) $segment));
    }

    /**
     * Structured-mode tree. Every schema-secret dotted (or, for the flat
     * Properties format, literal) path present in the decoded structure
     * has its value replaced with InputRedactor::MASK before the tree
     * ever becomes an Inertia prop — this is what makes structured mode's
     * generic object/array editor safe to use on a file with a secret
     * field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildStructuredData(ConfigFormatAdapter $adapter, ?ConfigSchema $schema, array $data): array
    {
        if ($schema === null) {
            return $data;
        }

        $flat = $adapter instanceof PropertiesAdapter;

        foreach ($schema->fields as $field) {
            if (! $field->secret) {
                continue;
            }

            if ($flat) {
                if (array_key_exists($field->path, $data)) {
                    $data[$field->path] = InputRedactor::MASK;
                }
            } elseif (DotPath::has($data, $field->path)) {
                $data = DotPath::set($data, $field->path, InputRedactor::MASK);
            }
        }

        return $data;
    }

    /**
     * The Conflict page's field-picker resolution path (mode: 'fields' —
     * see propose()'s docblock note on why this is not a fourth general
     * editing mode). Every entry is applied as a direct Replace with no
     * diffing against any baseline — there is deliberately no single
     * "current" tree to diff against once base/disk/proposed have already
     * diverged. The same secret round-trip rule applies: a secret field
     * whose selected value is still the sentinel is skipped, never
     * written, exactly like every other reconciliation path.
     *
     * @param  list<mixed>  $rawChanges
     * @return list<ConfigChange>
     */
    private function reconcileFields(?ConfigSchema $schema, array $rawChanges): array
    {
        $changes = [];

        foreach ($rawChanges as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = $entry['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            $value = $entry['value'] ?? null;
            $field = $schema?->field($path);

            if ($field?->secret === true && $value === InputRedactor::MASK) {
                continue;
            }

            $changes[] = ConfigChange::replace($path, $value);
        }

        return $changes;
    }

    /**
     * Source-mode redacted text — every schema-secret field's value is
     * masked directly in the raw source bytes via the exact same
     * byte-offset span mechanism App\Config\ConfigDiffBuilder already uses
     * for the diff/proposal pipeline (Task 8), reused here rather than
     * re-implemented so this guarantee can never drift from that one.
     */
    private function redactedSource(ConfigFormatAdapter $adapter, ?ConfigSchema $schema, string $contents): string
    {
        return ConfigDiffBuilder::redactSecrets($adapter, $schema, $contents);
    }

    /**
     * Filters an Operation's ChangeProposal rows down to only REAL
     * field-path changes — ambiguity resolution #5. An Operation carries
     * both rich per-field rows (App\Config\ConfigChangeService::
     * recordFieldProposals()) AND generic, one-level-deep metadata rows
     * App\Operations\OperationService::propose() derives from every OTHER
     * key in redacted_input (field="diff", "base_sha256",
     * "changed_fields.0", "diagnostics.0", ...). redacted_input's own
     * `changed_fields` array is EXACTLY the list of real field dotted
     * paths this proposal touches, so filtering ChangeProposal rows to
     * `field IN (changed_fields)` shows only real changes without ever
     * needing to enumerate the generic metadata keys to exclude.
     *
     * @return array<string, mixed>
     */
    private function presentOperation(Operation $operation): array
    {
        $meta = $operation->redacted_input ?? [];
        $realPaths = is_array($meta['changed_fields'] ?? null) ? $meta['changed_fields'] : [];

        $fields = $operation->changeProposals()
            ->whereIn('field', $realPaths)
            ->get()
            ->map(fn (ChangeProposal $p) => [
                'path' => $p->field,
                'summary' => $p->summary,
                'before' => $p->before,
                'after' => $p->after,
            ])
            ->values();

        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : [];

        return [
            'operationId' => $operation->id,
            'status' => $operation->status->value,
            'kind' => $meta['kind'] ?? 'apply',
            'diff' => $meta['diff'] ?? '',
            'valid' => (bool) ($meta['valid'] ?? false),
            'diagnostics' => $diagnostics,
            'restartImpact' => $meta['restart_impact'] ?? 'none',
            'risk' => $operation->risk->value,
            'documentation' => is_array($meta['documentation'] ?? null) ? $meta['documentation'] : [],
            'fields' => $fields,
            'normalizationWarning' => collect($diagnostics)->contains(
                fn ($d) => ($d['severity'] ?? null) === 'warning' && str_contains((string) ($d['message'] ?? ''), 'reformat'),
            ),
            'expiresAt' => $meta['expires_at'] ?? null,
            'outcome' => $operation->outcome,
            'errorCode' => $operation->error_code,
        ];
    }

    // -----------------------------------------------------------------
    // Reconciliation: mode-specific edits -> list<ConfigChange>
    // -----------------------------------------------------------------

    /**
     * Guided mode. For every schema field the client submitted a value
     * for: a secret field whose submitted value is STILL the sentinel
     * ('••••••', i.e. the operator never touched it) is skipped entirely
     * — no ConfigChange is ever created for it, so the real value is left
     * exactly as it is on disk and the literal sentinel text is never
     * written anywhere. Any other field is only included when its
     * (type-coerced) submitted value actually differs from the file's
     * current value, so re-submitting an unedited form is always a no-op.
     *
     * @param  array<array-key, mixed>  $submitted
     * @return list<ConfigChange>
     */
    private function reconcileGuided(?ConfigSchema $schema, ParsedConfig $currentParsed, array $submitted): array
    {
        if ($schema === null) {
            return [];
        }

        $changes = [];

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->path, $submitted)) {
                continue;
            }

            $raw = $submitted[$field->path];

            if ($field->secret && $raw === InputRedactor::MASK) {
                continue;
            }

            $coerced = $this->coerceGuidedValue($field->type, $raw);

            // The SAME baseline buildGuided() showed the browser: the
            // field's real current value if the file has it, otherwise
            // the schema default it was displayed with. Comparing against
            // the raw (possibly-null) current node value alone would
            // treat "this field is absent from the file, and the operator
            // never touched its default-filled control" as a real edit —
            // silently adding every untouched schema field's default to
            // the file on the very first guided save.
            $node = $currentParsed->node($field->path);
            $baseline = $node !== null ? $node->value : $field->default;

            // Laravel's global ConvertEmptyStringsToNull middleware turns
            // every submitted '' into null BEFORE this method ever sees
            // it — and GuidedEditor's text input already renders both a
            // null and an empty-string current value identically as an
            // empty box (see GuidedEditor.tsx's FieldControl), so an
            // untouched string field whose real baseline is '' (a common
            // schema default, e.g. level-seed/resource-pack) would
            // otherwise be misread as "changed to null" purely because it
            // round-tripped through an empty HTML input. Scoped to string
            // fields only — booleans/integers/numbers never hit this
            // ambiguity, since their schema defaults are never
            // empty-string-shaped. This normalization also decides what a
            // GENUINE clear-the-field edit writes ('' rather than null);
            // for App\Config\Formats\PropertiesAdapter (every currently
            // schema-secret field's format) both render identically as an
            // empty value, so this is lossless there. It does not weaken
            // the secret round-trip check above, which already returned
            // before this point for any secret field still showing the
            // mask.
            if ($field->type === ConfigFieldType::String) {
                $coerced ??= '';
                $baseline ??= '';
            }

            if ($this->looseEquals($coerced, $baseline)) {
                continue;
            }

            $changes[] = ConfigChange::replace($field->path, $coerced);
        }

        return $changes;
    }

    private function coerceGuidedValue(ConfigFieldType $type, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return $type === ConfigFieldType::String ? $raw : null;
        }

        return match ($type) {
            ConfigFieldType::Boolean => is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            ConfigFieldType::Integer => is_numeric($raw) ? (int) $raw : $raw,
            ConfigFieldType::Number => is_numeric($raw) ? (float) $raw : $raw,
            ConfigFieldType::String, ConfigFieldType::Array => $raw,
        };
    }

    /**
     * Structured mode. Builds a redacted BASELINE tree (the file's real
     * current structure with every schema-secret path masked — identical
     * to what buildStructuredData() sent to the browser) and diffs the
     * submitted tree against THAT, not the real unredacted structure. For
     * every non-secret path this is exactly as accurate as diffing
     * against the real structure (only secret leaves ever differ between
     * the two). For a secret path, an operator who never touched the
     * field re-submits the same sentinel the baseline already has at that
     * path — the diff sees no difference and skips it, so (as in guided
     * mode) the real value is never touched and the sentinel is never
     * written. An operator who types a real new value produces a genuine
     * difference from the masked baseline and IS included, carrying the
     * real value forward through the normal encrypted
     * App\Models\ConfigChangePayload channel — never through this masked
     * comparison.
     *
     * @param  array<string, mixed>  $currentData
     * @param  array<array-key, mixed>  $submittedData
     * @return list<ConfigChange>
     */
    private function reconcileStructured(ConfigFormatAdapter $adapter, ?ConfigSchema $schema, array $currentData, array $submittedData): array
    {
        $baseline = $this->buildStructuredData($adapter, $schema, $currentData);
        $flat = $adapter instanceof PropertiesAdapter;

        $changes = [];
        $this->diffTree($baseline, $submittedData, '', $flat, $changes);

        return $changes;
    }

    /**
     * @param  list<ConfigChange>  $changes
     */
    private function diffTree(mixed $before, mixed $after, string $prefix, bool $flat, array &$changes): void
    {
        $beforeMap = is_array($before) ? $before : [];
        $afterMap = is_array($after) ? $after : [];

        foreach ($afterMap as $key => $value) {
            $path = $flat || $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (! array_key_exists($key, $beforeMap)) {
                $changes[] = ConfigChange::add($path, $value);

                continue;
            }

            $beforeValue = $beforeMap[$key];

            if (! $flat && $this->isMap($beforeValue) && $this->isMap($value)) {
                $this->diffTree($beforeValue, $value, $path, false, $changes);

                continue;
            }

            if (! $this->deepEquals($beforeValue, $value)) {
                $changes[] = ConfigChange::replace($path, $value);
            }
        }

        foreach (array_keys($beforeMap) as $key) {
            if (! array_key_exists($key, $afterMap)) {
                $path = $flat || $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $changes[] = ConfigChange::remove($path);
            }
        }
    }

    private function isMap(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    private function deepEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b || json_encode($a) === json_encode($b);
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        return $this->deepEquals($a, $b);
    }

    /**
     * Source mode. This is the subtle one — see docs/architecture/
     * decisions.md's Task 9 entry for the full round-trip design. In
     * short: rather than trying to track byte offsets through arbitrary
     * free-form text edits (unreliable — an inserted line shifts every
     * later offset), this reconciles by PARSING both the redacted
     * baseline text (exactly what redactedSource() sent to the browser)
     * and the operator's submitted text, then diffing their located
     * scalar leaves by dotted path — the same "diff two parsed
     * documents' scalar leaves" primitive App\Config\ConfigRevisionService
     * already uses for restore(). Because the baseline is REDACTED, a
     * secret leaf the operator left untouched parses to the identical
     * sentinel value on both sides and is skipped (never written); a
     * secret leaf the operator retyped parses to a different value and
     * is included, carrying the real typed value forward. Every
     * NON-secret leaf's baseline text is byte-identical to the real file
     * (redaction only ever touches secret spans), so this is exactly as
     * accurate as diffing against the unredacted original for anything
     * that isn't secret.
     *
     * Disclosed scope limit (shared with ConfigRevisionService::restore(),
     * not new here): only LOCATABLE SCALAR LEAVES are represented as
     * ConfigChanges (per Task 7's scalar-leaf model). A source-mode edit
     * that only changes comments, formatting, or key order — with no
     * scalar VALUE actually different — produces zero ConfigChanges and
     * is correctly treated as a no-op; ambiguity resolution #2 requires
     * all three modes to converge on the identical ConfigChangeRequest
     * shape, which rules out a parallel "replace the whole file's bytes"
     * code path that could diverge from what guided/structured produce
     * for the same edit.
     *
     * @return list<ConfigChange>
     *
     * @throws ConfigParseException if the submitted text cannot be parsed — the caller
     *                              re-renders the editor with a validation error instead of proposing anything.
     */
    private function reconcileSource(ConfigFormatAdapter $adapter, ?ConfigSchema $schema, string $currentContents, string $submittedSource): array
    {
        $baselineSource = $this->redactedSource($adapter, $schema, $currentContents);

        if ($submittedSource === $baselineSource) {
            return [];
        }

        $baselineNodes = [];
        foreach ($adapter->parse($baselineSource)->nodes as $node) {
            $baselineNodes[$node->path] = $node->value;
        }

        $submittedNodes = [];
        foreach ($adapter->parse($submittedSource)->nodes as $node) {
            $submittedNodes[$node->path] = $node->value;
        }

        $changes = [];

        foreach ($submittedNodes as $path => $value) {
            if (! array_key_exists($path, $baselineNodes)) {
                $changes[] = ConfigChange::add($path, $value);

                continue;
            }

            if (! $this->looseEquals($baselineNodes[$path], $value)) {
                $changes[] = ConfigChange::replace($path, $value);
            }
        }

        foreach (array_keys($baselineNodes) as $path) {
            if (! array_key_exists($path, $submittedNodes)) {
                $changes[] = ConfigChange::remove($path);
            }
        }

        return $changes;
    }

    // -----------------------------------------------------------------
    // Conflict
    // -----------------------------------------------------------------

    /**
     * @param  list<ConfigChange>  $changeList
     */
    private function conflictResponse(Request $request, MinecraftPath $path, FileSnapshot $observedCurrent, ConfigFormatAdapter $adapter, ?ConfigSchema $schema, array $changeList, ConfigConflict $e): HttpResponse
    {
        // Read once more: propose() itself proved $observedCurrent is
        // already stale, so the freshest content we can show as "on disk"
        // is a brand-new read, not the snapshot we diffed against above.
        $disk = $this->filesystem->read($path);
        $diskParsed = $adapter->parse($disk->contents);

        $proposedRows = [];

        foreach ($changeList as $change) {
            $field = $schema?->field($change->path);
            $secret = $field?->secret === true;
            $before = $diskParsed->node($change->path)?->value;

            $proposedRows[] = [
                'path' => $change->path,
                'before' => $secret ? InputRedactor::MASK : $this->displayValue($before),
                'after' => $change->kind === ConfigChangeKind::Remove
                    ? '(removed)'
                    : ($secret ? InputRedactor::MASK : $this->displayValue($change->value)),
            ];
        }

        $baseSource = $request->string('base_source')->toString();

        return Inertia::render('config/Conflict', [
            'path' => $path->relativePath,
            'expectedSha256' => $e->expectedSha256,
            'actualSha256' => $e->actualSha256,
            'base' => $baseSource !== '' ? $baseSource : $this->redactedSource($adapter, $schema, $observedCurrent->contents),
            'disk' => $this->redactedSource($adapter, $schema, $disk->contents),
            'diskSha256' => $disk->sha256,
            'proposed' => $proposedRows,
            'mode' => (string) $request->string('mode'),
        ])->toResponse($request)->setStatusCode(409);
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

    // -----------------------------------------------------------------
    // Inventory helpers
    // -----------------------------------------------------------------

    private function pluginName(MinecraftPath $path): ?string
    {
        $segments = explode('/', $path->relativePath);

        return $segments[0] === 'plugins' && isset($segments[1]) ? $segments[1] : null;
    }

    private function matchesSearch(string $query, DiscoveredFile $file, ?ConfigSchema $schema, ?string $pluginName): bool
    {
        $needle = mb_strtolower($query);

        foreach ([$file->path->relativePath, basename($file->path->relativePath), $schema?->title, $pluginName] as $haystack) {
            if ($haystack !== null && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInventoryItem(DiscoveredFile $file, ?ConfigSchema $schema, ?string $pluginName): array
    {
        $base = [
            'path' => $file->path->relativePath,
            'filename' => basename($file->path->relativePath),
            'format' => $file->format,
            'category' => $file->category->value,
            'provenance' => $this->provenanceKey($file->provenance),
            'recognized' => $file->recognized,
            'schemaTitle' => $schema?->title,
            'pluginName' => $pluginName,
            'sizeBytes' => $file->sizeBytes,
        ];

        try {
            $snapshot = $this->filesystem->read($file->path);
        } catch (Throwable) {
            return $base + [
                'modifiedAt' => null,
                'valid' => null,
                'preview' => null,
                'restartImpact' => null,
                'readable' => false,
            ];
        }

        $adapter = $this->formats->for($snapshot);
        $validation = $adapter->validate($snapshot->contents, $schema);

        return $base + [
            'modifiedAt' => date(DATE_ATOM, $snapshot->mtime),
            'valid' => $validation->valid,
            'preview' => $this->boundedPreview($adapter, $schema, $snapshot->contents),
            'restartImpact' => $this->maxRestartImpact($schema),
            'readable' => true,
        ];
    }

    private function maxRestartImpact(?ConfigSchema $schema): ?string
    {
        if ($schema === null) {
            return null;
        }

        $order = ['none' => 0, 'reload' => 1, 'restart' => 2];
        $max = 'none';

        foreach ($schema->fields as $field) {
            if ($order[$field->restartImpact->value] > $order[$max]) {
                $max = $field->restartImpact->value;
            }
        }

        return $max;
    }

    private function boundedPreview(ConfigFormatAdapter $adapter, ?ConfigSchema $schema, string $contents, int $maxChars = 280): string
    {
        return mb_strimwidth($this->redactedSource($adapter, $schema, $contents), 0, $maxChars, '…');
    }

    private function provenanceKey(string $provenance): string
    {
        return match ($provenance) {
            'Built in' => 'built-in',
            'Plugin' => 'plugin',
            default => 'discovered',
        };
    }

    private function findDiscovered(MinecraftPath $path): ?DiscoveredFile
    {
        foreach ($this->filesystem->discover() as $file) {
            if ($file->path->relativePath === $path->relativePath) {
                return $file;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Path / operation guards
    // -----------------------------------------------------------------

    /**
     * @return array<array-key, mixed>
     */
    private function arrayInput(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function resolvePath(string $rawPath): MinecraftPath
    {
        try {
            return MinecraftPath::fromUserInput($rawPath);
        } catch (UnsafeMinecraftPath) {
            abort(404);
        }
    }

    private function readOrAbort(MinecraftPath $path): FileSnapshot
    {
        try {
            return $this->filesystem->read($path);
        } catch (MinecraftFileNotFound|NotARegularFile) {
            abort(404);
        }
    }

    private function guardPendingConfigOperation(Operation $operation): void
    {
        if (! in_array($operation->type, [OperationType::ConfigApply, OperationType::ConfigRestore], true)
            || $operation->status !== OperationStatus::Proposed
        ) {
            abort(404);
        }
    }
}
