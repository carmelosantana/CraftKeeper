<?php

namespace App\Http\Controllers;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginSourceException;
use App\Catalog\PluginSource;
use App\Catalog\UnifiedCatalogService;
use App\Http\Controllers\Concerns\PresentsOperations;
use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\PluginOperationPlan;
use App\Models\PluginRollbackArtifact;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\Exceptions\PluginChecksumMismatch;
use App\Plugins\Exceptions\PluginDownloadFailed;
use App\Plugins\Exceptions\PluginReleaseMissingArtifact;
use App\Plugins\PluginCompatibilityEvidence;
use App\Plugins\PluginInspectionDiagnostic;
use App\Plugins\PluginInventoryService;
use App\Plugins\PluginLifecycleService;
use App\Plugins\PluginProvenance;
use App\Plugins\PluginUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Discovery (Task 14's catalog search/filter/results), the installed
 * inventory, plugin detail, manual upload, and every plugin.* operation's
 * propose/approve/reject/rollback lifecycle — Task 15's UI surface.
 *
 * Security-critical invariant this controller upholds: `install`/`update`
 * NEVER accept a download URL or expected checksum from the browser.
 * Only `source`/`projectId`/`version` (an identity) are ever read from the
 * request; the actual, trustworthy App\Catalog\Data\PluginRelease —
 * including its `downloadUrl`/`sha256` — is always re-resolved
 * server-side via `resolveSource($provenance)->release($id)` (App\Catalog\
 * PluginSource::release(), Task 14's "Task 15 calls this to resolve a
 * concrete download" contract). A tampered or replayed client request can
 * therefore never smuggle an attacker-chosen URL/hash into
 * App\Plugins\PluginDownloader.
 */
class PluginController extends Controller
{
    use PresentsOperations;

    private const PLUGIN_OPERATION_TYPES = [
        OperationType::PluginInstall,
        OperationType::PluginUpdate,
        OperationType::PluginDisable,
        OperationType::PluginRemove,
        OperationType::PluginRollback,
    ];

    /**
     * @param  iterable<PluginSource>  $sources
     */
    public function __construct(
        private readonly UnifiedCatalogService $catalog,
        private readonly PluginInventoryService $inventory,
        private readonly PluginLifecycleService $lifecycle,
        private readonly PluginUploadService $uploads,
        private readonly OperationService $operations,
        private readonly iterable $sources,
    ) {}

    // -----------------------------------------------------------------
    // Installed inventory + detail
    // -----------------------------------------------------------------

    public function index(Request $request): Response
    {
        // Task 13 built reconcile() (disk-vs-database sync) but left
        // "when it runs" to whichever task builds the first real reader —
        // this task. Re-running it before every read (mirroring App\Http\
        // Controllers\ConfigController::index()'s own "always discover
        // fresh, never trust a stale row" philosophy) is what makes a
        // just-completed install/update/disable/remove/rollback (or any
        // OUT-OF-BAND change to plugins/ made outside CraftKeeper)
        // immediately visible here, rather than only after some later,
        // unrelated write happens to trigger it.
        $this->inventory->reconcile();

        $installations = PluginInstallation::query()->orderBy('name')->get();
        $pending = $this->pendingOperationsByTarget();

        return Inertia::render('plugins/Index', [
            'plugins' => $installations->map(fn (PluginInstallation $i) => $this->presentInstallation($i, $pending))->values(),
        ]);
    }

    public function show(Request $request, string $filename): Response
    {
        $this->inventory->reconcile();

        $relativePath = 'plugins/'.$filename;
        $installation = PluginInstallation::query()->where('relative_path', $relativePath)->first();

        abort_if($installation === null, 404);

        $pending = $this->pendingOperationsByTarget();
        $history = Operation::query()
            ->where('target', $relativePath)
            ->whereIn('type', self::PLUGIN_OPERATION_TYPES)
            ->latest()
            ->limit(20)
            ->get();

        $rollbackArtifacts = PluginRollbackArtifact::query()
            ->where('relative_path', $relativePath)
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('plugins/Show', [
            'plugin' => $this->presentInstallation($installation, $pending),
            'history' => $history->map(fn (Operation $o) => $this->presentOperationSummary($o))->values(),
            'rollbackArtifacts' => $rollbackArtifacts->map(fn (PluginRollbackArtifact $a) => [
                'id' => $a->id,
                'sha256' => $a->sha256,
                'sizeBytes' => $a->size_bytes,
                'reason' => $a->reason,
                'createdAt' => $a->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    // -----------------------------------------------------------------
    // Discovery (Task 14)
    // -----------------------------------------------------------------

    public function discover(Request $request): Response
    {
        $this->inventory->reconcile();

        $query = trim((string) $request->query('q', ''));
        $minecraftVersion = $request->query('mc');
        $platform = $request->query('platform');

        $page = $this->catalog->search(new PluginSearchQuery(
            query: $query !== '' ? $query : null,
            minecraftVersion: is_string($minecraftVersion) && $minecraftVersion !== '' ? $minecraftVersion : null,
            platform: is_string($platform) && $platform !== '' ? $platform : null,
        ));

        $installedNames = PluginInstallation::query()->whereNotNull('name')->pluck('name')
            ->map(fn (string $n) => strtolower($n))->all();

        return Inertia::render('plugins/Discover', [
            'query' => $query,
            'items' => array_map(fn (PluginRelease $r) => $this->presentRelease($r, in_array(strtolower($r->name), $installedNames, true)), $page->items),
            'sourceResults' => array_map(fn ($sr) => [
                'source' => $this->provenanceKey($sr->source),
                'degraded' => $sr->degraded,
                'message' => $sr->message,
                'servedFromCache' => $sr->servedFromCache,
                'stale' => $sr->stale,
            ], $page->sourceResults),
        ]);
    }

    // -----------------------------------------------------------------
    // Install / Update proposals — identity in, resolved release out
    // -----------------------------------------------------------------

    /**
     * "Install" from Discover degrades gracefully into an UPDATE when a
     * plugin with the same declared name is already tracked — the same
     * name-based correlation App\Catalog\InstalledPluginIndex already
     * uses for sort ranking (Task 13 stores no catalog project id
     * alongside an installation, only its provenance/name — see that
     * class's docblock for why name is the only signal available). This
     * means Discover's "Install" button never creates a stray, second
     * install of something already tracked; it targets the SAME on-disk
     * file the operator already has.
     */
    public function proposeInstall(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'projectId' => ['required', 'string'],
            'version' => ['nullable', 'string'],
        ]);

        $release = $this->resolveRelease($data['source'], $data['projectId'], $data['version'] ?? null);

        if ($release === null) {
            return back();
        }

        $existing = PluginInstallation::query()->where('name', $release->name)->first();

        return $existing !== null
            ? $this->tryPropose(fn () => $this->lifecycle->proposeUpdate($existing, $release, $this->author($request)))
            : $this->tryPropose(fn () => $this->lifecycle->proposeInstall($release, $this->author($request)));
    }

    public function proposeUpdate(Request $request, string $filename): RedirectResponse
    {
        $installation = $this->installationOrAbort($filename);

        $data = $request->validate([
            'source' => ['required', 'string'],
            'projectId' => ['required', 'string'],
            'version' => ['nullable', 'string'],
        ]);

        $release = $this->resolveRelease($data['source'], $data['projectId'], $data['version'] ?? null);

        if ($release === null) {
            return back();
        }

        return $this->tryPropose(fn () => $this->lifecycle->proposeUpdate($installation, $release, $this->author($request)));
    }

    public function proposeDisable(Request $request, string $filename): RedirectResponse
    {
        $installation = $this->installationOrAbort($filename);

        return $this->tryPropose(fn () => $this->lifecycle->proposeDisable($installation, $this->author($request)));
    }

    public function proposeRemove(Request $request, string $filename): RedirectResponse
    {
        $installation = $this->installationOrAbort($filename);

        return $this->tryPropose(fn () => $this->lifecycle->proposeRemove($installation, $this->author($request)));
    }

    public function proposeRollback(Request $request, string $filename): RedirectResponse
    {
        $installation = $this->installationOrAbort($filename);

        $data = $request->validate(['rollback_artifact_id' => ['required', 'integer']]);
        $artifact = PluginRollbackArtifact::query()->where('id', $data['rollback_artifact_id'])->first();

        // Never restore a rollback artifact belonging to a DIFFERENT
        // plugin's path onto this one, even if the id itself is valid.
        if ($artifact === null || $artifact->relative_path !== $installation->relative_path) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'That rollback artifact does not belong to this plugin.']);

            return back();
        }

        return $this->tryPropose(fn () => $this->lifecycle->proposeRollback($installation, $artifact, $this->author($request)));
    }

    // -----------------------------------------------------------------
    // Manual upload — findings shown BEFORE any proposal exists
    // -----------------------------------------------------------------

    public function uploadForm(): Response
    {
        return Inertia::render('plugins/Upload', ['findings' => null, 'error' => null]);
    }

    public function uploadStore(Request $request): Response
    {
        $request->validate(['file' => ['required', 'file']]);

        try {
            $artifact = $this->uploads->store($request->file('file'));
        } catch (PluginArtifactTooLarge|PluginDownloadFailed $e) {
            return Inertia::render('plugins/Upload', ['findings' => null, 'error' => $e->getMessage()]);
        }

        $inspected = $this->lifecycle->inspectQuarantinedUpload($artifact);
        $existing = $inspected->name !== null
            ? PluginInstallation::query()->where('name', $inspected->name)->first()
            : null;

        return Inertia::render('plugins/Upload', [
            'error' => null,
            'findings' => [
                'token' => $artifact->token,
                'sha256' => $artifact->sha256,
                'sizeBytes' => $artifact->sizeBytes,
                'name' => $inspected->name,
                'version' => $inspected->version,
                'mainClass' => $inspected->mainClass,
                'apiVersion' => $inspected->apiVersion,
                'hardDependencies' => $inspected->hardDependencies,
                'softDependencies' => $inspected->softDependencies,
                'metadataSource' => $inspected->metadataSource,
                'diagnostics' => array_map(
                    fn (PluginInspectionDiagnostic $d) => ['issue' => $d->issue->value, 'message' => $d->message],
                    $inspected->diagnostics,
                ),
                'existingInstallationPath' => $existing?->relative_path,
            ],
        ]);
    }

    public function uploadPropose(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['existing_path' => ['nullable', 'string']]);

        try {
            $artifact = $this->lifecycle->resolveQuarantinedArtifact($token);
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect('/plugins/upload');
        }

        $existing = ! empty($data['existing_path'])
            ? PluginInstallation::query()->where('relative_path', $data['existing_path'])->first()
            : null;

        return $this->tryPropose(fn () => $this->lifecycle->proposeUpload($artifact, $this->author($request), $existing));
    }

    // -----------------------------------------------------------------
    // Operation lifecycle: approve / reject / rollback / progress page
    // -----------------------------------------------------------------

    public function operation(Operation $operation): Response
    {
        $this->guardPluginOperation($operation);

        $plan = PluginOperationPlan::forOperation($operation->id);

        return Inertia::render('plugins/Operation', [
            'operation' => $this->presentOperationSummary($operation),
            'plan' => $plan?->plan,
            'targetRelativePath' => $plan?->target_relative_path,
            'canRollback' => in_array($operation->status, [OperationStatus::Succeeded, OperationStatus::Failed], true),
            'restartObserved' => $this->lifecycle->isRestartObserved($operation),
        ]);
    }

    public function approve(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPluginOperation($operation, requireStatus: OperationStatus::Proposed);

        $this->operations->approve($operation->id, $request->user());
        $executed = $this->operations->execute($operation->id);

        Inertia::flash('toast', $executed->status === OperationStatus::Succeeded
            ? ['type' => 'success', 'message' => $executed->outcome ?? 'Plugin change applied.']
            : ['type' => 'error', 'message' => $executed->outcome ?? 'The plugin change could not be applied.']);

        return redirect('/plugins/operations/'.$operation->id);
    }

    public function reject(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPluginOperation($operation, requireStatus: OperationStatus::Proposed);

        $reason = (string) $request->input('reason', 'Discarded by operator.');
        $this->operations->reject($operation->id, $request->user(), $reason);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Plugin change discarded.']);

        return redirect('/plugins');
    }

    public function rollbackOperation(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPluginOperation($operation);

        if (! in_array($operation->status, [OperationStatus::Succeeded, OperationStatus::Failed], true)) {
            abort(404);
        }

        $result = $this->operations->rollback($operation->id, $this->author($request));

        Inertia::flash('toast', $result->status === OperationStatus::RolledBack
            ? ['type' => 'success', 'message' => 'Change rolled back.']
            : ['type' => 'error', 'message' => $result->outcome ?? 'The rollback could not be completed.']);

        return redirect('/plugins/operations/'.$operation->id);
    }

    // -----------------------------------------------------------------
    // Shared prop building
    // -----------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $pending
     * @return array<string, mixed>
     */
    private function presentInstallation(PluginInstallation $installation, array $pending): array
    {
        return [
            'relativePath' => $installation->relative_path,
            'filename' => basename($installation->relative_path),
            'name' => $installation->name,
            'version' => $installation->version,
            'mainClass' => $installation->main_class,
            'apiVersion' => $installation->api_version,
            'hardDependencies' => $installation->hard_dependencies,
            'softDependencies' => $installation->soft_dependencies,
            'sha256' => $installation->sha256,
            'sizeBytes' => $installation->size_bytes,
            'enabled' => $installation->enabled,
            'provenance' => $this->provenanceKeyFromString($installation->provenance),
            'duplicateName' => $installation->duplicate_name,
            'compatibilityState' => $installation->compatibility_state?->value,
            'compatibilityEvidence' => $installation->compatibility_evidence,
            'missingSince' => $installation->missing_since?->toIso8601String(),
            'lastSeenAt' => $installation->last_seen_at?->toIso8601String(),
            'pendingOperation' => $pending[$installation->relative_path] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRelease(PluginRelease $release, bool $installed): array
    {
        return [
            'source' => $this->provenanceKey($release->source()),
            'projectId' => $release->id->projectId,
            'version' => $release->version,
            'name' => $release->name,
            'slug' => $release->slug,
            'description' => $release->description,
            'license' => $release->license,
            'projectUrl' => $release->projectUrl,
            'minecraftVersions' => $release->minecraftVersions,
            'platforms' => $release->platforms,
            'withdrawn' => $release->withdrawn,
            'installed' => $installed,
            'compatibilityEvidence' => array_map(
                fn (PluginCompatibilityEvidence $e) => ['source' => $e->source, 'summary' => $e->summary, 'supportsCompatibility' => $e->supportsCompatibility],
                $release->compatibilityEvidence,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pendingOperationsByTarget(): array
    {
        $operations = Operation::query()
            ->whereIn('type', self::PLUGIN_OPERATION_TYPES)
            ->whereIn('status', [OperationStatus::Proposed, OperationStatus::Approved, OperationStatus::Running])
            ->latest()
            ->get();

        $byTarget = [];

        foreach ($operations as $operation) {
            $byTarget[(string) $operation->target] ??= $this->presentOperationSummary($operation);
        }

        return $byTarget;
    }

    // -----------------------------------------------------------------
    // Guards / helpers
    // -----------------------------------------------------------------

    private function tryPropose(callable $propose): RedirectResponse
    {
        try {
            $operation = $propose();
        } catch (PluginChecksumMismatch $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Refused: the downloaded artifact does not match its published checksum. Nothing was installed.']);

            return back();
        } catch (PluginArtifactTooLarge $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        } catch (PluginDownloadFailed|PluginReleaseMissingArtifact $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not prepare that change: '.$e->getMessage()]);

            return back();
        }

        return redirect('/plugins/operations/'.$operation->id);
    }

    private function resolveRelease(string $sourceValue, string $projectId, ?string $version): ?PluginRelease
    {
        $provenance = PluginProvenance::tryFrom($sourceValue);

        if ($provenance === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Unknown catalog source.']);

            return null;
        }

        try {
            $source = $this->resolveSource($provenance);

            return $source->release(new PluginReleaseId($provenance, $projectId, $version));
        } catch (PluginSourceException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Could not resolve that release: '.$e->getMessage()]);

            return null;
        }
    }

    private function resolveSource(PluginProvenance $provenance): PluginSource
    {
        foreach ($this->sources as $source) {
            if ($source->key() === $provenance) {
                return $source;
            }
        }

        throw new RuntimeException("No catalog source is registered for [{$provenance->value}].");
    }

    private function installationOrAbort(string $filename): PluginInstallation
    {
        $installation = PluginInstallation::query()->where('relative_path', 'plugins/'.$filename)->first();
        abort_if($installation === null, 404);

        return $installation;
    }

    private function guardPluginOperation(Operation $operation, ?OperationStatus $requireStatus = null): void
    {
        if (! in_array($operation->type, self::PLUGIN_OPERATION_TYPES, true)) {
            abort(404);
        }

        if ($requireStatus !== null && $operation->status !== $requireStatus) {
            abort(404);
        }
    }

    private function author(Request $request): OperationAuthor
    {
        return OperationAuthor::user($request->user()->getKey());
    }

    private function provenanceKey(PluginProvenance $provenance): string
    {
        return strtolower($provenance->value);
    }

    private function provenanceKeyFromString(string $provenance): string
    {
        return strtolower($provenance);
    }
}
