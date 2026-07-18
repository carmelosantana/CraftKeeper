<?php

namespace App\Plugins;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;
use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\PluginOperationPlan;
use App\Models\PluginRollbackArtifact;
use App\Models\ServerSample;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationService;
use App\Server\ServerVersionDetector;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Turns a quarantined/verified artifact (or an already-installed plugin,
 * for disable/remove/rollback) into a reviewable, audited plugin.*
 * Operation — the "propose" half of Task 15's lifecycle, mirroring Task
 * 8's App\Config\ConfigChangeService: build a rich INSTALL PLAN, call
 * App\Operations\OperationService::propose() (which only ever persists
 * MINIMAL, pre-defined metadata per App\Operations\OperationRequest's
 * pluginInstall()/pluginUpdate()/pluginDisable()/pluginRemove()/
 * pluginRollback() factories — a `release_id`/`rollback_artifact_id`
 * string, never the plan itself), THEN attach the rich plan as a
 * separate App\Models\PluginOperationPlan row keyed by the now-real
 * Operation id — exactly the propose-then-attach-payload order
 * App\Config\ConfigChangeService::storeRawChanges() established, applied
 * here for richness rather than confidentiality (nothing in a plugin
 * install plan is secret-shaped).
 *
 * The download (App\Plugins\PluginDownloader — Task 15's Step-1
 * integrity gate) always happens BEFORE OperationService::propose() is
 * ever called: a checksum-mismatched or oversized artifact throws before
 * an Operation ever exists, so a bad artifact never produces even a
 * Proposed operation, let alone reaches execute(). Once the artifact IS
 * verified, its quarantine directory is relocated from its own
 * download-time token to {data_root}/quarantine/{operation-id} — the
 * convention Task 15's ambiguity resolution #2 names — purely so its
 * final resting place is addressable by the Operation it belongs to;
 * this never re-touches the bytes themselves (a same-filesystem
 * directory rename under {data_root}).
 */
final class PluginLifecycleService
{
    public function __construct(
        private readonly PluginDownloader $downloader,
        private readonly JarInspector $inspector,
        private readonly PluginInventoryService $inventory,
        private readonly PluginCompatibilityService $compatibility,
        private readonly OperationService $operations,
        private readonly ServerVersionDetector $versionDetector,
    ) {}

    public function proposeInstall(PluginRelease $release, OperationAuthor $author): Operation
    {
        $artifact = $this->downloader->download($release);

        return $this->proposeFromArtifact($artifact, $release, null, $author);
    }

    public function proposeUpdate(PluginInstallation $installation, PluginRelease $release, OperationAuthor $author): Operation
    {
        $artifact = $this->downloader->download($release);

        return $this->proposeFromArtifact($artifact, $release, $installation, $author);
    }

    /**
     * The manual-upload counterpart: $artifact was already quarantined and
     * its findings already shown to the operator by
     * App\Http\Controllers\PluginController's upload flow (Task 15's
     * ambiguity resolution #2 — "show findings BEFORE an install
     * proposal") BEFORE this is ever called; this only builds the plan
     * and creates the Operation. $existing, when given, means "install
     * this upload OVER an already-tracked plugin" (an update); null means
     * a brand-new manual install.
     */
    public function proposeUpload(QuarantinedArtifact $artifact, OperationAuthor $author, ?PluginInstallation $existing = null): Operation
    {
        return $this->proposeFromArtifact($artifact, null, $existing, $author);
    }

    public function proposeDisable(PluginInstallation $installation, OperationAuthor $author): Operation
    {
        $path = $this->resolveInstalledPath($installation);
        $baseSha256 = $this->currentSha256($path);

        $operation = $this->operations->propose(OperationRequest::pluginDisable($installation->relative_path), $author);

        PluginOperationPlan::query()->create([
            'operation_id' => $operation->id,
            'kind' => 'disable',
            'target_relative_path' => $installation->relative_path,
            'source' => $installation->provenance,
            'release_name' => $installation->name,
            'release_version' => $installation->version,
            'plan' => [
                'artifact' => ['name' => $installation->name, 'version' => $installation->version],
                'fileChanges' => ["Rename {$installation->relative_path} to {$installation->relative_path}.disabled"],
                'restartRequired' => true,
                'base_sha256' => $baseSha256,
            ],
        ]);

        return $operation;
    }

    public function proposeRemove(PluginInstallation $installation, OperationAuthor $author): Operation
    {
        $path = $this->resolveInstalledPath($installation);
        $baseSha256 = $this->currentSha256($path);

        $operation = $this->operations->propose(OperationRequest::pluginRemove($installation->relative_path), $author);

        PluginOperationPlan::query()->create([
            'operation_id' => $operation->id,
            'kind' => 'remove',
            'target_relative_path' => $installation->relative_path,
            'source' => $installation->provenance,
            'release_name' => $installation->name,
            'release_version' => $installation->version,
            'plan' => [
                'artifact' => ['name' => $installation->name, 'version' => $installation->version],
                'fileChanges' => ["Move {$installation->relative_path} to plugin-rollbacks storage"],
                'restartRequired' => true,
                'base_sha256' => $baseSha256,
            ],
        ]);

        return $operation;
    }

    public function proposeRollback(PluginInstallation $installation, PluginRollbackArtifact $artifact, OperationAuthor $author): Operation
    {
        $path = $this->resolveInstalledPath($installation);
        $baseSha256 = $this->currentSha256($path);

        $operation = $this->operations->propose(
            OperationRequest::pluginRollback($installation->relative_path, (string) $artifact->id),
            $author,
        );

        PluginOperationPlan::query()->create([
            'operation_id' => $operation->id,
            'kind' => 'rollback',
            'target_relative_path' => $installation->relative_path,
            'source' => $installation->provenance,
            'release_name' => $installation->name,
            'release_version' => null,
            'verified_sha256' => $artifact->sha256,
            'size_bytes' => $artifact->size_bytes,
            'plan' => [
                'artifact' => ['name' => $installation->name, 'version' => null],
                'restoringArtifactId' => $artifact->id,
                'restoringSha256' => $artifact->sha256,
                'restoringReason' => $artifact->reason,
                'fileChanges' => ["Restore {$installation->relative_path} to a previously preserved artifact ({$artifact->sha256})"],
                'restartRequired' => true,
                'base_sha256' => $baseSha256,
            ],
        ]);

        return $operation;
    }

    /**
     * Inspects an already-quarantined manual upload, WITHOUT creating any
     * Operation — the findings-before-proposal step. Uses a fixed,
     * non-meaningful placeholder identity (see JarInspector::
     * inspectQuarantined()'s docblock for why the identity is never used
     * to touch a file); the REAL prospective target is computed
     * separately, once the operator confirms an install/update, by
     * proposeFromArtifact().
     */
    public function inspectQuarantinedUpload(QuarantinedArtifact $artifact): InspectedPlugin
    {
        return $this->inspector->inspectQuarantined($artifact->absolutePath, $this->placeholderIdentity());
    }

    /**
     * Re-resolves a previously quarantined artifact by its token (a
     * server-generated UUID directory name — see App\Plugins\Concerns\
     * QuarantinesArtifacts::beginQuarantine()) for the SECOND request of
     * the upload flow (inspect-then-confirm). The SHA-256 is always
     * RE-COMPUTED from the bytes on disk here, never trusted from a
     * client-supplied value, so a tampered round-trip cannot smuggle a
     * mismatched identity through.
     */
    public function resolveQuarantinedArtifact(string $token): QuarantinedArtifact
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $token) !== 1) {
            throw new RuntimeException('Invalid quarantine token.');
        }

        $path = rtrim((string) config('craftkeeper.data_root'), '/').'/quarantine/'.$token.'/artifact.jar';

        if (! is_file($path)) {
            throw new RuntimeException('The quarantined artifact could not be found; it may have expired — please upload again.');
        }

        $sha256 = (string) hash_file('sha256', $path);
        $size = (int) (filesize($path) ?: 0);

        return new QuarantinedArtifact($token, $path, $sha256, $size);
    }

    /**
     * "Restart required" stays true until a subsequent server start is
     * OBSERVED (Task 15's ambiguity resolution #3), reusing Task 11's
     * server observation data (App\Models\ServerSample, sampled every 15s
     * while RCON is reachable — App\Console\Commands\SampleServerState)
     * rather than inventing a second polling mechanism. No log line or
     * RCON call unambiguously means "the JVM just restarted," so this
     * reads the one signal that DOES exist: RCON going unreachable and
     * then reachable again AFTER this operation finished — a genuine
     * down-then-up transition, not merely "RCON is currently up" (which
     * would be true even if the server never restarted at all, silently
     * fabricating a positive "restart observed" the same way Task
     * 11/12's "no fabricated zero" principle forbids fabricating a
     * positive player count). Honestly returns false (not "unknown") when
     * no such transition has been observed yet, or when the operation has
     * no finished_at at all.
     */
    public function isRestartObserved(Operation $operation): bool
    {
        if ($operation->finished_at === null) {
            return false;
        }

        $firstReachableAfter = ServerSample::query()
            ->where('sampled_at', '>', $operation->finished_at)
            ->where('rcon_reachable', true)
            ->oldest('sampled_at')
            ->first();

        if ($firstReachableAfter === null) {
            return false;
        }

        $precedingSample = ServerSample::query()
            ->where('sampled_at', '<', $firstReachableAfter->sampled_at)
            ->latest('sampled_at')
            ->first();

        return $precedingSample !== null && ! $precedingSample->rcon_reachable;
    }

    private function proposeFromArtifact(
        QuarantinedArtifact $artifact,
        ?PluginRelease $release,
        ?PluginInstallation $existing,
        OperationAuthor $author,
    ): Operation {
        $isUpdate = $existing !== null;

        // Inspected FIRST, against a placeholder identity — its fields
        // (name/version/dependencies/diagnostics) never depend on the
        // identity passed in (see JarInspector::inspectQuarantined()'s
        // docblock), so a brand-new install's target FILENAME can itself
        // be derived from what this inspection found (falling back to the
        // catalog release's own name/slug, then the archive's own name)
        // without needing a second inspection pass.
        $inspected = $this->inspector->inspectQuarantined($artifact->absolutePath, $this->placeholderIdentity());

        $targetRelativePath = $isUpdate
            ? $existing->relative_path
            : 'plugins/'.$this->deriveInstallFilename($release, $inspected, $artifact);

        $identity = $this->resolveTargetPath($targetRelativePath);

        $graph = PluginDependencyGraph::build($this->inventory->currentInspections());
        $assessment = $this->compatibility->evaluate($inspected, $graph, $this->serverApiVersion());

        $baseSha256 = $this->currentSha256($identity);
        $installedNames = array_keys($graph->nodes);
        $unmetHardDependencies = array_values(array_diff($inspected->hardDependencies, $installedNames));

        $sourceValue = $release?->source()->value ?? PluginProvenance::Manual->value;

        $planData = [
            'artifact' => [
                'name' => $inspected->name ?? ($release !== null ? $release->name : null) ?? basename($targetRelativePath),
                'version' => ($release !== null ? $release->version : null) ?? $inspected->version,
                'mainClass' => $inspected->mainClass,
                'apiVersion' => $inspected->apiVersion,
                'metadataSource' => $inspected->metadataSource,
            ],
            'source' => $sourceValue,
            'checksum' => $artifact->sha256,
            'sizeBytes' => $artifact->sizeBytes,
            'compatibility' => [
                'state' => $assessment->state->value,
                'evidence' => array_map(
                    fn (PluginCompatibilityEvidence $e): array => [
                        'source' => $e->source,
                        'summary' => $e->summary,
                        'supportsCompatibility' => $e->supportsCompatibility,
                    ],
                    $assessment->evidence,
                ),
            ],
            'dependencies' => [
                'hard' => $inspected->hardDependencies,
                'soft' => $inspected->softDependencies,
            ],
            'unmetHardDependencies' => $unmetHardDependencies,
            'inspectionDiagnostics' => array_map(
                fn (PluginInspectionDiagnostic $d): array => ['issue' => $d->issue->value, 'message' => $d->message],
                $inspected->diagnostics,
            ),
            'fileChanges' => [$identity->exists
                ? "Replace {$targetRelativePath}"
                : "Create {$targetRelativePath}"],
            'restartRequired' => true,
            'base_sha256' => $baseSha256,
        ];

        $releaseIdString = $release !== null
            ? $this->encodeReleaseId($release->id)
            : 'manual:upload:'.$artifact->sha256;

        $operation = $isUpdate
            ? $this->operations->propose(OperationRequest::pluginUpdate($targetRelativePath, $releaseIdString), $author)
            : $this->operations->propose(OperationRequest::pluginInstall($targetRelativePath, $releaseIdString), $author);

        $quarantinePath = $this->relocateQuarantine($artifact, $operation->id);

        PluginOperationPlan::query()->create([
            'operation_id' => $operation->id,
            'kind' => $isUpdate ? 'update' : 'install',
            'target_relative_path' => $targetRelativePath,
            'source' => $sourceValue,
            'release_name' => $release?->name,
            'release_version' => ($release !== null ? $release->version : null) ?? $inspected->version,
            'quarantine_path' => $quarantinePath,
            'verified_sha256' => $artifact->sha256,
            'size_bytes' => $artifact->sizeBytes,
            'plan' => $planData,
        ]);

        return $operation;
    }

    private function resolveInstalledPath(PluginInstallation $installation): MinecraftPath
    {
        return $this->resolveTargetPath($installation->relative_path);
    }

    private function resolveTargetPath(string $relativePath): MinecraftPath
    {
        return MinecraftPath::fromUserInput($relativePath);
    }

    private function currentSha256(MinecraftPath $path): string
    {
        return $path->exists ? (string) hash_file('sha256', $path->absolutePath) : hash('sha256', '');
    }

    /**
     * A fixed, non-existent, always-safe placeholder path — never opened,
     * never written; see JarInspector::inspectQuarantined()'s docblock.
     */
    private function placeholderIdentity(): MinecraftPath
    {
        try {
            return MinecraftPath::fromUserInput('plugins/.quarantine-pending-inspection.jar');
        } catch (UnsafeMinecraftPath $e) {
            throw new RuntimeException('Could not resolve the Minecraft root for a placeholder inspection path.', previous: $e);
        }
    }

    /**
     * The install filename convention this task establishes (undocumented
     * by the brief): prefers the CATALOG release's own name/slug (so it
     * is knowable before the archive is even opened, and stays stable
     * across an update — the same release identity always derives the
     * same filename); for a manual upload with no catalog release, falls
     * back to the archive's own declared name, then finally the
     * quarantine token if neither yields anything. Sanitized to a small
     * safe character set; MinecraftPath::fromUserInput() rejects anything
     * unsafe regardless, this just keeps the common case readable (e.g.
     * "EssentialsX.jar" rather than a token).
     */
    private function deriveInstallFilename(?PluginRelease $release, InspectedPlugin $inspected, QuarantinedArtifact $artifact): string
    {
        $label = ($release !== null ? ($release->name !== '' ? $release->name : $release->slug) : null) ?? $inspected->name ?? $artifact->token;
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $label) ?? 'plugin';
        $safe = trim($safe, '-');

        return ($safe === '' ? 'plugin-'.substr($artifact->token, 0, 8) : $safe).'.jar';
    }

    private function encodeReleaseId(PluginReleaseId $id): string
    {
        return $id->source->value.':'.$id->projectId.':'.($id->version ?? 'latest');
    }

    /**
     * Best-effort api-version signal for App\Plugins\
     * PluginCompatibilityService::evaluate() — neither Task 11 nor Task
     * 13 auto-detects the running server's api-version (see that
     * service's own docblock: "nothing in this task auto-detects
     * ... that's Task 11/15 territory"). App\Server\ServerVersionDetector
     * only exposes a free-text label (e.g. "Paper 1.21.4"); this extracts
     * the leading semantic-version-shaped token from it as a reasonable
     * proxy for Paper's own api-version convention (which tracks the
     * Minecraft version). Returns null — never a guess — when no version
     * was detected at all, matching PluginCompatibilityService's own
     * "Unknown is the honest default" rule.
     */
    private function serverApiVersion(): ?string
    {
        $detected = $this->versionDetector->detect();

        if (! $detected->known || $detected->label === null) {
            return null;
        }

        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $detected->label, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Relocates the quarantine directory from its download-time token to
     * {data_root}/quarantine/{operation-id} — see class docblock. Falls
     * back to leaving the artifact at its original (still valid, still
     * verified) location if the rename cannot be performed for any
     * reason, rather than failing the whole proposal over a purely
     * cosmetic relocation.
     */
    private function relocateQuarantine(QuarantinedArtifact $artifact, string $operationId): string
    {
        $oldDir = dirname($artifact->absolutePath);
        $newDir = rtrim((string) config('craftkeeper.data_root'), '/').'/quarantine/'.$operationId;

        if ($oldDir === $newDir) {
            return $artifact->absolutePath;
        }

        if (is_dir($newDir)) {
            File::deleteDirectory($newDir);
        }

        if (! @rename($oldDir, $newDir)) {
            return $artifact->absolutePath;
        }

        return $newDir.'/'.basename($artifact->absolutePath);
    }
}
