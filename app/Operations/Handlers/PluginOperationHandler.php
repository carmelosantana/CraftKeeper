<?php

namespace App\Operations\Handlers;

use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\StaleFileHash;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\PluginArtifact;
use App\Models\PluginOperationPlan;
use App\Models\PluginRollbackArtifact;
use App\Operations\OperationActorType;
use App\Operations\OperationHandler;
use App\Operations\OperationResult;
use App\Operations\OperationType;
use App\Plugins\PluginRollbackStore;
use Throwable;

/**
 * The OperationHandler for every plugin.* type — registered on
 * App\Operations\OperationHandlerRegistry via the `operation.handler`
 * container tag, per Task 5's extension convention. Only ever invoked by
 * App\Operations\OperationService::execute()/rollback(), which
 * structurally guarantees execute() runs for an Approved -> Running
 * operation and no other state: there is no code path in this class that
 * touches `/minecraft/plugins` for an operation nobody approved.
 *
 * Integrity chain this class is the last link in: App\Plugins\
 * PluginDownloader already verified the artifact's checksum during
 * quarantine, BEFORE any Operation existed (see App\Plugins\
 * PluginLifecycleService). This class re-verifies that quarantined
 * artifact's checksum ONE MORE TIME immediately before installing it
 * (defense in depth against at-rest tampering between propose and
 * approve), and every install/update write goes through
 * App\Filesystem\MinecraftFilesystem::writeAtomically() with the
 * TARGET's sha256 AS IT WAS AT PROPOSE TIME (App\Models\
 * PluginOperationPlan::plan['base_sha256']) as the expected hash —
 * exactly App\Operations\Handlers\Concerns\AppliesConfigChanges' own
 * TOCTOU contract, applied to plugin JARs: if the installed file changed
 * on disk between propose() and this execute() call, the write is
 * refused (App\Filesystem\Exceptions\StaleFileHash) rather than
 * overwritten blind. Every install/update FIRST preserves whatever is
 * CURRENTLY on disk at the target path (App\Plugins\
 * PluginRollbackStore::preserve()) before ever calling
 * writeAtomically() — so together with AtomicFileWriter's own guarantee
 * (temp-file-then-rename; on ANY failure the target is never partially
 * written), an update failure ALWAYS leaves the previously-installed
 * artifact intact: either writeAtomically() never touched the target at
 * all (every failure mode except a post-rename verification mismatch),
 * or — for that one rare case — this class's compensating rollback
 * restores the just-preserved bytes (mirroring
 * Concerns\AppliesConfigChanges::attemptRollback() exactly).
 */
final class PluginOperationHandler implements OperationHandler
{
    private const REVERSIBLE_TYPES = [
        OperationType::PluginInstall,
        OperationType::PluginUpdate,
        OperationType::PluginDisable,
        OperationType::PluginRemove,
        OperationType::PluginRollback,
    ];

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly PluginRollbackStore $rollbacks,
    ) {}

    public function supports(OperationType $type): bool
    {
        return in_array($type, self::REVERSIBLE_TYPES, true);
    }

    /**
     * Every plugin.* operation, success or failure, means its staged
     * quarantine artifact (if any — disable/remove/rollback have none)
     * can never legitimately be needed again: it either already moved
     * into `/minecraft/plugins`, or the operation failed and a fresh
     * proposal would re-download/re-verify a new one anyway. Deleting it
     * here — regardless of which of execute()'s many return paths was
     * taken — is what keeps quarantine from accumulating (Task 8's
     * ConfigChangePayload cleanup pattern, mirrored for plugins; see
     * App\Models\PluginOperationPlan::cleanupQuarantineForOperation()).
     */
    public function execute(Operation $operation): OperationResult
    {
        try {
            return match ($operation->type) {
                OperationType::PluginInstall, OperationType::PluginUpdate => $this->executeInstallOrUpdate($operation),
                OperationType::PluginDisable => $this->executeDisable($operation),
                OperationType::PluginRemove => $this->executeRemove($operation),
                OperationType::PluginRollback => $this->executeRollback($operation),
                default => OperationResult::failure('plugin.unsupported_operation', 'This operation type is not supported.'),
            };
        } finally {
            PluginOperationPlan::cleanupQuarantineForOperation($operation->id);
        }
    }

    /**
     * The generic "undo the specific bytes-level change THIS operation
     * made" — a separate lifecycle action from OperationType::
     * PluginRollback (which is itself a fresh, user-proposed "restore an
     * older artifact" operation). Every execute() path above that changes
     * bytes on disk ALWAYS preserves whatever was replaced first (see
     * class docblock) and records it as `plugin_operation_plans.
     * rollback_artifact_id` — this reads that same value back and
     * restores it, so every lifecycle change is reversible, not only the
     * ones an operator explicitly re-proposes.
     */
    public function rollback(Operation $operation): OperationResult
    {
        return match ($operation->type) {
            OperationType::PluginDisable => $this->undoDisable($operation),
            OperationType::PluginInstall, OperationType::PluginUpdate, OperationType::PluginRemove, OperationType::PluginRollback => $this->undoFromPreservedArtifact($operation),
            default => OperationResult::failure('plugin.rollback_not_supported', 'This plugin operation cannot be rolled back automatically.'),
        };
    }

    // -----------------------------------------------------------------
    // execute()
    // -----------------------------------------------------------------

    private function executeInstallOrUpdate(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null || $plan->quarantine_path === null) {
            return OperationResult::failure('plugin.plan_missing', 'No install plan was found for this operation.');
        }

        $bytes = @file_get_contents($plan->quarantine_path);

        if ($bytes === false) {
            return OperationResult::failure('plugin.quarantine_missing', 'The verified artifact is no longer available; the proposal must be re-created.');
        }

        // Re-verification, defense in depth: the artifact was already
        // proven to match its expected checksum during download/upload
        // (App\Plugins\PluginDownloader/PluginUploadService); this
        // re-checks it against what THIS plan recorded, in case the
        // quarantined file was somehow altered at rest between propose
        // and this approve->execute call.
        if (! hash_equals((string) $plan->verified_sha256, hash('sha256', $bytes))) {
            return OperationResult::failure('plugin.quarantine_tampered', 'The staged artifact no longer matches its verified checksum; refusing to install it.');
        }

        try {
            $targetPath = MinecraftPath::fromUserInput($plan->target_relative_path);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        $rollbackArtifactId = null;

        if ($targetPath->exists) {
            try {
                $preserved = $this->rollbacks->preserve(
                    $targetPath,
                    $operation->type === OperationType::PluginUpdate ? 'pre-update' : 'pre-install-replace',
                    $operation->id,
                );
                $rollbackArtifactId = $preserved->id;
            } catch (Throwable $e) {
                return OperationResult::failure('plugin.preserve_failed', 'Could not preserve the currently-installed artifact before replacing it; refusing to proceed.', ['error' => $e->getMessage()]);
            }
        }

        $baseSha256 = (string) ($plan->plan['base_sha256'] ?? hash('sha256', ''));

        try {
            $this->filesystem->writeAtomically($targetPath, $bytes, $baseSha256);
        } catch (StaleFileHash) {
            return OperationResult::failure('plugin.hash_mismatch', 'The plugin file changed on disk since this change was proposed.');
        } catch (AtomicWriteFailed $e) {
            return $this->attemptCompensatingRollback($targetPath, $rollbackArtifactId, $e);
        }

        $plan->forceFill(['rollback_artifact_id' => $rollbackArtifactId])->save();

        $this->recordArtifactProvenance($plan, strlen($bytes));

        $verb = $operation->type === OperationType::PluginUpdate ? 'Updated' : 'Installed';

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => $operation->type === OperationType::PluginUpdate ? 'plugin.updated' : 'plugin.installed',
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => ['target' => $plan->target_relative_path, 'sha256' => $plan->verified_sha256],
        ]);

        return OperationResult::success(
            "{$verb} {$plan->target_relative_path}. A server restart is required to take effect.",
            ['restart_required' => true, 'target' => $plan->target_relative_path],
        );
    }

    /**
     * Record WHERE these exact bytes came from, keyed by their checksum.
     *
     * App\Plugins\PluginInventoryService::resolveProvenanceForNew() reads
     * `plugin_artifacts` by sha256 to decide whether a file on disk is
     * attributable to a known source or is an untraceable manual drop. Until
     * now nothing ever wrote to that table — it was read in two places and
     * written in none — so the lookup always missed and EVERY plugin was
     * labelled "Manual", including one CraftKeeper had itself just downloaded
     * from the catalog and checksum-verified. The Catalog/Hangar/Modrinth
     * states of resources/js/components/craftkeeper/ProvenanceBadge.tsx were
     * unreachable in practice.
     *
     * The source is not inferred here: the plan recorded it at propose time
     * from the resolved App\Catalog\Data\PluginRelease (or Manual for an
     * upload), and this persists that same value verbatim.
     *
     * Content-addressed, so `sha256` is unique and this is an upsert:
     * installing the same bytes twice, or rolling back onto a checksum
     * already seen, must not collide. Deliberately AFTER the atomic write —
     * an artifact row for a file that never landed would be a lie about disk
     * state, which is the class of bug this whole method exists to avoid.
     *
     * Best-effort: provenance is a labelling concern, and failing an install
     * that has already succeeded on disk — leaving the operation marked
     * failed while the plugin is in fact installed — would be a far worse
     * outcome than an unattributed jar.
     */
    private function recordArtifactProvenance(PluginOperationPlan $plan, int $sizeBytes): void
    {
        $sha256 = (string) $plan->verified_sha256;

        if ($sha256 === '') {
            return;
        }

        $source = $plan->plan['source'] ?? null;
        $version = $plan->plan['artifact']['version'] ?? null;

        try {
            PluginArtifact::query()->updateOrCreate(
                ['sha256' => $sha256],
                [
                    'size_bytes' => $sizeBytes,
                    'source' => is_string($source) && $source !== '' ? $source : null,
                    'version' => is_string($version) && $version !== '' ? $version : null,
                ],
            );
        } catch (Throwable) {
            // See docblock: never fail a completed install over a label.
        }
    }

    private function executeDisable(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null) {
            return OperationResult::failure('plugin.plan_missing', 'No plan was found for this operation.');
        }

        $mismatch = $this->guardAgainstDrift($plan);

        if ($mismatch !== null) {
            return $mismatch;
        }

        try {
            $source = MinecraftPath::fromUserInput($plan->target_relative_path);
            $destination = MinecraftPath::fromUserInput($plan->target_relative_path.'.disabled');
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        if (! $source->exists) {
            return OperationResult::failure('plugin.not_found', 'The plugin file was not found on disk.');
        }

        if ($destination->exists) {
            return OperationResult::failure('plugin.disable_conflict', 'A disabled copy already exists at that path; resolve the conflict manually.');
        }

        $source->reverifyContainment();

        if (! @rename($source->absolutePath, $destination->absolutePath)) {
            return OperationResult::failure('plugin.disable_failed', 'Could not rename the plugin file to disable it.');
        }

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => 'plugin.disabled',
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => ['target' => $plan->target_relative_path],
        ]);

        return OperationResult::success(
            "Disabled {$plan->target_relative_path}. A server restart is required to take effect.",
            ['restart_required' => true, 'target' => $plan->target_relative_path],
        );
    }

    private function executeRemove(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null) {
            return OperationResult::failure('plugin.plan_missing', 'No plan was found for this operation.');
        }

        $actualPath = $this->resolveActualOnDiskPath($plan->target_relative_path);

        if ($actualPath === null) {
            return OperationResult::failure('plugin.not_found', 'The plugin file was not found on disk (neither enabled nor disabled).');
        }

        try {
            $preserved = $this->rollbacks->preserve($actualPath, 'pre-remove', $operation->id);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.preserve_failed', 'Could not preserve the artifact before removing it; refusing to proceed.', ['error' => $e->getMessage()]);
        }

        $actualPath->reverifyContainment();

        if (! @unlink($actualPath->absolutePath)) {
            return OperationResult::failure('plugin.remove_failed', 'The artifact was preserved but the installed file could not be removed.');
        }

        $plan->forceFill(['rollback_artifact_id' => $preserved->id])->save();

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => 'plugin.removed',
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => ['target' => $plan->target_relative_path, 'rollback_artifact_id' => $preserved->id],
        ]);

        return OperationResult::success(
            "Removed {$plan->target_relative_path}; a rollback artifact was preserved. A server restart is required to take effect.",
            ['restart_required' => true, 'target' => $plan->target_relative_path],
        );
    }

    private function executeRollback(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null) {
            return OperationResult::failure('plugin.plan_missing', 'No plan was found for this operation.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $operation->redacted_input ?? [];
        $restoreFromId = $metadata['rollback_artifact_id'] ?? null;
        $restoreFromArtifact = is_numeric($restoreFromId) ? PluginRollbackArtifact::find((int) $restoreFromId) : null;

        if ($restoreFromArtifact === null) {
            return OperationResult::failure('plugin.rollback_artifact_missing', 'The requested rollback artifact could not be found.');
        }

        try {
            $targetPath = MinecraftPath::fromUserInput($plan->target_relative_path);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        $preservedId = null;

        if ($targetPath->exists) {
            try {
                $preserved = $this->rollbacks->preserve($targetPath, 'pre-rollback', $operation->id);
                $preservedId = $preserved->id;
            } catch (Throwable $e) {
                return OperationResult::failure('plugin.preserve_failed', 'Could not preserve the currently-installed artifact before restoring; refusing to proceed.', ['error' => $e->getMessage()]);
            }
        }

        $bytes = $this->rollbacks->readBytes($restoreFromArtifact);
        $baseSha256 = (string) ($plan->plan['base_sha256'] ?? hash('sha256', ''));

        try {
            $this->filesystem->writeAtomically($targetPath, $bytes, $baseSha256);
        } catch (StaleFileHash) {
            return OperationResult::failure('plugin.hash_mismatch', 'The plugin file changed on disk since this rollback was proposed.');
        } catch (AtomicWriteFailed $e) {
            return $this->attemptCompensatingRollback($targetPath, $preservedId, $e);
        }

        $plan->forceFill(['rollback_artifact_id' => $preservedId])->save();

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => 'plugin.rolled_back',
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => ['target' => $plan->target_relative_path, 'restored_from' => $restoreFromArtifact->id],
        ]);

        return OperationResult::success(
            "Restored {$plan->target_relative_path} to a previous artifact. A server restart is required to take effect.",
            ['restart_required' => true, 'target' => $plan->target_relative_path],
        );
    }

    // -----------------------------------------------------------------
    // rollback() — undo a previously executed operation
    // -----------------------------------------------------------------

    private function undoDisable(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null) {
            return OperationResult::failure('plugin.plan_missing', 'No plan was found for this operation.');
        }

        try {
            $disabled = MinecraftPath::fromUserInput($plan->target_relative_path.'.disabled');
            $enabled = MinecraftPath::fromUserInput($plan->target_relative_path);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        if (! $disabled->exists) {
            return OperationResult::failure('plugin.rollback_snapshot_missing', 'The disabled file was not found; it may already have been re-enabled or removed.');
        }

        if ($enabled->exists) {
            return OperationResult::failure('plugin.rollback_conflict', 'An enabled copy already exists at that path.');
        }

        $disabled->reverifyContainment();

        if (! @rename($disabled->absolutePath, $enabled->absolutePath)) {
            return OperationResult::failure('plugin.rollback_failed', 'Could not re-enable the plugin file.');
        }

        return OperationResult::success("Re-enabled {$plan->target_relative_path}.");
    }

    /**
     * Mirrors executeRollback()'s safety exactly (see that method and the
     * class docblock): whatever is CURRENTLY on disk is preserved to
     * rollback storage BEFORE it is ever overwritten, so undoing an
     * earlier operation can never lose a LATER state unrecoverably — even
     * install v1 -> update v1->v2 -> update v2->v3, then undoing the
     * v1->v2 change, leaves v3 recoverable rather than silently gone.
     *
     * The write's expected hash is this operation's OWN propose-time
     * `verified_sha256` — the checksum of whatever THIS operation itself
     * left on disk (the new bytes it installed for install/update/
     * rollback; absent — hash('') — for a remove, which left nothing)
     * fixed at propose time, exactly the same "a value captured before
     * the write, not a freshly re-read one" contract executeRollback()/
     * executeInstallOrUpdate() use via `plan->base_sha256` for THEIR OWN
     * writes. base_sha256 itself is not usable here: it records the state
     * from BEFORE this operation ran, which this operation's own
     * (already-succeeded) write necessarily moved away from — using it
     * would refuse every undo unconditionally, including one with no
     * intervening change at all. verified_sha256 records the state AFTER
     * this operation ran, which is exactly what should still be on disk
     * right now if nothing has intervened — so a later change (like a
     * second update) is refused (StaleFileHash) rather than clobbered,
     * while an immediate, undisturbed undo still succeeds.
     */
    private function undoFromPreservedArtifact(Operation $operation): OperationResult
    {
        $plan = PluginOperationPlan::forOperation($operation->id);

        if ($plan === null) {
            return OperationResult::failure('plugin.plan_missing', 'No plan was found for this operation.');
        }

        try {
            $targetPath = MinecraftPath::fromUserInput($plan->target_relative_path);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        if ($plan->rollback_artifact_id === null) {
            return $this->undoByRemoving($operation, $plan, $targetPath);
        }

        $artifact = PluginRollbackArtifact::find($plan->rollback_artifact_id);

        if ($artifact === null) {
            return OperationResult::failure('plugin.rollback_snapshot_missing', 'No preserved artifact was found for this operation.');
        }

        if ($targetPath->exists) {
            try {
                $this->rollbacks->preserve($targetPath, 'pre-undo', $operation->id);
            } catch (Throwable $e) {
                return OperationResult::failure('plugin.preserve_failed', 'Could not preserve the currently-installed artifact before undoing this change; refusing to proceed.', ['error' => $e->getMessage()]);
            }
        }

        $bytes = $this->rollbacks->readBytes($artifact);
        $expected = (string) ($plan->verified_sha256 ?? hash('sha256', ''));

        try {
            $this->filesystem->writeAtomically($targetPath, $bytes, $expected);
        } catch (StaleFileHash) {
            return OperationResult::failure('plugin.hash_mismatch', 'The plugin file changed on disk since this change was made; refusing to undo it.');
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.rollback_failed', $e->getMessage());
        }

        return OperationResult::success("Restored {$plan->target_relative_path} to its previous artifact.");
    }

    /**
     * Undoes an operation that had NOTHING preserved (a brand-new install
     * with no prior file at that path) — the honest undo is that the file
     * should not exist. Still preserves what is there NOW first, so this
     * undo is itself not a dead end.
     */
    private function undoByRemoving(Operation $operation, PluginOperationPlan $plan, MinecraftPath $targetPath): OperationResult
    {
        if (! $targetPath->exists) {
            return OperationResult::success('Nothing to undo — the plugin file is already absent.');
        }

        try {
            $this->rollbacks->preserve($targetPath, 'pre-remove', $operation->id);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.preserve_failed', 'Could not preserve the installed artifact before removing it; refusing to proceed.', ['error' => $e->getMessage()]);
        }

        $targetPath->reverifyContainment();

        if (! @unlink($targetPath->absolutePath)) {
            return OperationResult::failure('plugin.rollback_failed', 'Could not remove the installed file while rolling back.');
        }

        return OperationResult::success("Removed {$plan->target_relative_path}.");
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * A defensive drift check for disable/remove (which do not write new
     * content, so they never go through writeAtomically()'s own hash
     * check): refuses to act if the target's CURRENT sha256 no longer
     * matches what this plan recorded at propose time, rather than
     * silently acting on a file that isn't the one an operator reviewed.
     */
    private function guardAgainstDrift(PluginOperationPlan $plan): ?OperationResult
    {
        try {
            $path = MinecraftPath::fromUserInput($plan->target_relative_path);
        } catch (Throwable $e) {
            return OperationResult::failure('plugin.invalid_target', $e->getMessage());
        }

        $current = $path->exists ? (string) hash_file('sha256', $path->absolutePath) : hash('sha256', '');
        $expected = (string) ($plan->plan['base_sha256'] ?? $current);

        if (! hash_equals($expected, $current)) {
            return OperationResult::failure('plugin.hash_mismatch', 'The plugin file changed on disk since this change was proposed.');
        }

        return null;
    }

    private function resolveActualOnDiskPath(string $logicalRelativePath): ?MinecraftPath
    {
        try {
            $enabled = MinecraftPath::fromUserInput($logicalRelativePath);
        } catch (Throwable) {
            return null;
        }

        if ($enabled->exists) {
            return $enabled;
        }

        try {
            $disabled = MinecraftPath::fromUserInput($logicalRelativePath.'.disabled');
        } catch (Throwable) {
            return null;
        }

        return $disabled->exists ? $disabled : null;
    }

    /**
     * Mirrors App\Operations\Handlers\Concerns\AppliesConfigChanges::
     * attemptRollback() exactly, applied to a plugin JAR instead of a
     * config file: on ANY AtomicWriteFailed, attempt to restore the
     * just-preserved rollback artifact (if one exists) so the target
     * never ends up in an uncertain state, and report both failures
     * together if the compensating write ALSO fails.
     */
    private function attemptCompensatingRollback(MinecraftPath $targetPath, ?int $rollbackArtifactId, Throwable $original): OperationResult
    {
        if ($rollbackArtifactId === null) {
            // Nothing existed before (a fresh install) — AtomicFileWriter's
            // own guarantee already means there is nothing to compensate;
            // the target was never partially written.
            return OperationResult::failure('plugin.write_failed', $original->getMessage());
        }

        $artifact = PluginRollbackArtifact::find($rollbackArtifactId);

        if ($artifact === null) {
            return OperationResult::failure('plugin.write_failed_rollback_failed', 'The write failed and the preserved artifact could not be found; manual intervention is required.', [
                'primary_error' => $original->getMessage(),
            ]);
        }

        try {
            $bytes = $this->rollbacks->readBytes($artifact);
            $currentBroken = $this->filesystem->read($targetPath);
            $this->filesystem->writeAtomically($targetPath, $bytes, $currentBroken->sha256);
        } catch (Throwable $rollbackError) {
            return OperationResult::failure('plugin.write_failed_rollback_failed', 'The write failed post-write verification and the automatic rollback also failed; manual intervention is required.', [
                'primary_error' => $original->getMessage(),
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }

        return OperationResult::failure('plugin.write_failed_rolled_back', 'The write failed post-write verification and was automatically rolled back to the previously-installed artifact.', [
            'primary_error' => $original->getMessage(),
        ]);
    }
}
