<?php

namespace App\Operations\Handlers\Concerns;

use App\Config\ConfigChange;
use App\Config\ConfigFormatRegistry;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\StaleFileHash;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Filesystem\SnapshotReference;
use App\Models\AuditEvent;
use App\Models\ConfigChangePayload;
use App\Models\ConfigFile;
use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Operations\OperationActorType;
use App\Operations\OperationResult;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The shared execute()/rollback() body for App\Operations\Handlers\
 * ConfigApplyHandler and ConfigRestoreHandler — both operation types
 * write a config file the exact same way once approved (the only
 * difference is which OperationType each class's supports() answers for,
 * and the audit-event/success-message wording), so the logic lives once,
 * here, rather than being duplicated across two OperationHandler classes.
 *
 * Requires the using class to provide these three constructor-injected
 * properties (all three concrete handlers do):
 *
 *   private readonly MinecraftFilesystem $filesystem;
 *   private readonly ConfigFormatRegistry $formats;
 *   private readonly ConfigSchemaRegistry $schemas;
 *
 * execute()-time defense in depth, all re-checked against CURRENT disk
 * state (never trusting what propose() saw): the proposal's expiry, the
 * change set's continued applicability (InvalidConfigChange can't
 * escape), continued schema validity, and — via
 * App\Filesystem\MinecraftFilesystem::writeAtomically()'s own optimistic-
 * concurrency check — the base sha256, which is the TOCTOU re-check: if
 * the file changed on disk between propose() and this execute() call,
 * writeAtomically() throws App\Filesystem\Exceptions\StaleFileHash and
 * this NEVER overwrites it.
 *
 * Snapshot-then-write order, per Task 6/8's contract: a pre-write
 * snapshot is captured (keyed by the operation id) immediately before
 * writeAtomically() runs. If the write itself throws
 * App\Filesystem\Exceptions\AtomicWriteFailed (its own post-rename
 * verification failed), attemptRollback() tries to restore that snapshot;
 * if THAT also fails, both failures are recorded together on the
 * returned OperationResult (error code plus an `output` array carrying
 * both underlying messages) rather than either one being silently lost.
 */
trait AppliesConfigChanges
{
    /**
     * Wraps the actual apply logic in a try/finally so that, no matter
     * which of its many early-return failure paths (or its single success
     * path) is taken, App\Models\ConfigChangePayload::deleteForOperation()
     * always runs exactly once right before this method returns. Every
     * return from doApplyApprovedChange() corresponds 1:1 to the operation
     * going terminal (Succeeded or Failed — see
     * App\Operations\OperationService::execute()), so the payload is
     * genuinely dead by the time finally runs. Crucially, `finally` runs
     * AFTER the try block's return VALUE has already been computed, so on
     * the success path the real value has already been written to disk
     * (writeAtomically() inside doApplyApprovedChange() is synchronous)
     * before the row holding that same raw value is deleted — never before.
     */
    private function applyApprovedChange(Operation $operation, string $auditEventType, string $successVerb): OperationResult
    {
        try {
            return $this->doApplyApprovedChange($operation, $auditEventType, $successVerb);
        } finally {
            ConfigChangePayload::deleteForOperation($operation->id);
        }
    }

    private function doApplyApprovedChange(Operation $operation, string $auditEventType, string $successVerb): OperationResult
    {
        $payload = ConfigChangePayload::query()->where('operation_id', $operation->id)->first();

        if ($payload === null) {
            return OperationResult::failure('config.payload_missing', 'No stored change payload was found for this operation.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $operation->redacted_input ?? [];

        if (isset($metadata['expires_at']) && now()->greaterThan(Carbon::parse((string) $metadata['expires_at']))) {
            return OperationResult::failure('config.proposal_expired', 'This proposal has expired and must be re-created.');
        }

        $baseSha256 = (string) ($metadata['base_sha256'] ?? '');

        try {
            $path = MinecraftPath::fromUserInput((string) $operation->target);
        } catch (Throwable $e) {
            return OperationResult::failure('config.invalid_path', $e->getMessage());
        }

        try {
            $current = $this->filesystem->read($path);
        } catch (Throwable $e) {
            return OperationResult::failure('config.read_failed', $e->getMessage());
        }

        $adapter = $this->formats->for($current);
        $schema = $this->schemas->forPath($path);
        $changes = $this->hydrateChanges($payload->changes);

        try {
            $newContents = $adapter->applyChanges($current->contents, $changes, $schema);
        } catch (InvalidConfigChange $e) {
            return OperationResult::failure('config.invalid_change', $e->getMessage());
        }

        $validation = $adapter->validate($newContents, $schema);

        if (! $validation->valid) {
            return OperationResult::failure('config.validation_failed', 'This change is no longer valid against the file\'s current content.');
        }

        $snapshot = $this->filesystem->copyToSnapshot($path, $operation->id);

        try {
            $written = $this->filesystem->writeAtomically($path, $newContents, $baseSha256);
        } catch (StaleFileHash) {
            return OperationResult::failure('config.hash_mismatch', 'The file changed on disk since this change was proposed.');
        } catch (AtomicWriteFailed $e) {
            return $this->attemptRollback($path, $snapshot, $e);
        }

        // A second, distinctly-keyed snapshot of the file's new (post-
        // write) content — this is what ConfigRevisionService::restore()
        // reads back later; the FIRST snapshot above (keyed by the bare
        // operation id) is reserved for the compensating rollback path.
        $afterSnapshot = $this->filesystem->copyToSnapshot($path, $operation->id.'-after');

        $configFile = ConfigFile::forPath(
            $path->relativePath,
            strtolower(pathinfo($path->relativePath, PATHINFO_EXTENSION)),
            $schema?->id,
        );

        $revision = ConfigRevision::query()->create([
            'config_file_id' => $configFile->id,
            'operation_id' => $operation->id,
            'kind' => $operation->type->value === 'config.restore' ? 'restore' : 'apply',
            'sha256' => $written->sha256,
            'snapshot_path' => $afterSnapshot->snapshotPath,
            'summary' => sprintf('%s %d change(s) to %s.', $successVerb, count($changes), $path->relativePath),
            'redacted_diff' => is_string($metadata['diff'] ?? null) ? $metadata['diff'] : null,
            'restart_impact' => is_string($metadata['restart_impact'] ?? null) ? $metadata['restart_impact'] : null,
            'risk' => $operation->risk->value,
            'author_type' => $operation->author_type,
            'author_id' => $operation->author_id,
            'author_origin' => $operation->author_origin,
        ]);

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => $auditEventType,
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => [
                'revision_id' => $revision->id,
                'changed_fields' => $metadata['changed_fields'] ?? [],
                'restart_impact' => $metadata['restart_impact'] ?? null,
            ],
        ]);

        return OperationResult::success(
            sprintf('%s %d change(s) to %s.', $successVerb, count($changes), $path->relativePath),
            ['revision_id' => $revision->id],
        );
    }

    private function attemptRollback(MinecraftPath $path, SnapshotReference $snapshot, Throwable $original): OperationResult
    {
        $originalBytes = @file_get_contents($snapshot->snapshotPath);

        if ($originalBytes === false) {
            return OperationResult::failure('config.write_failed_rollback_failed', 'The write failed and the safety snapshot could not be read; manual intervention is required.', [
                'primary_error' => $original->getMessage(),
            ]);
        }

        try {
            $currentBroken = $this->filesystem->read($path);
            $this->filesystem->writeAtomically($path, $originalBytes, $currentBroken->sha256);
        } catch (Throwable $rollbackError) {
            return OperationResult::failure('config.write_failed_rollback_failed', 'The write failed post-write verification and the automatic rollback also failed; manual intervention is required.', [
                'primary_error' => $original->getMessage(),
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }

        return OperationResult::failure('config.write_failed_rolled_back', 'The write failed post-write verification and was automatically rolled back to the previous content.', [
            'primary_error' => $original->getMessage(),
        ]);
    }

    /**
     * Reverses a previously SUCCEEDED (or Failed) operation on demand —
     * App\Operations\OperationService::rollback(), a separate lifecycle
     * action from the automatic compensating rollback above. Restores the
     * PRE-write snapshot captured under this operation's own id during
     * execute() (never the "-after" one).
     */
    public function rollback(Operation $operation): OperationResult
    {
        try {
            $path = MinecraftPath::fromUserInput((string) $operation->target);
        } catch (Throwable $e) {
            return OperationResult::failure('config.invalid_path', $e->getMessage());
        }

        $snapshotPath = $this->preWriteSnapshotPath($operation->id, $path);
        $originalBytes = @file_get_contents($snapshotPath);

        if ($originalBytes === false) {
            return OperationResult::failure('config.rollback_snapshot_missing', 'No pre-write snapshot was captured for this operation, so it cannot be rolled back automatically.');
        }

        try {
            $current = $this->filesystem->read($path);
            $this->filesystem->writeAtomically($path, $originalBytes, $current->sha256);
        } catch (Throwable $e) {
            return OperationResult::failure('config.rollback_failed', $e->getMessage());
        }

        return OperationResult::success('Restored the file to its content before this operation.');
    }

    /**
     * Reconstructs the pre-write snapshot's absolute path from the same
     * documented convention App\Filesystem\SnapshotStore itself uses
     * ({DATA_ROOT}/snapshots/{operationId}/{relativePath} — see its class
     * docblock) rather than a new filesystem lookup method, since
     * MinecraftFilesystem's interface (Task 6's Stable Interface) has no
     * "read back a snapshot" operation and copyToSnapshot() always
     * captures the CURRENT file, not a past one.
     */
    private function preWriteSnapshotPath(string $operationId, MinecraftPath $path): string
    {
        $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');

        return $dataRoot.'/snapshots/'.$operationId.'/'.$path->relativePath;
    }

    /**
     * @param  list<array{kind: string, path: string, value: mixed}>  $rawChanges
     * @return list<ConfigChange>
     */
    private function hydrateChanges(array $rawChanges): array
    {
        return array_map(fn (array $c): ConfigChange => match ($c['kind']) {
            'replace' => ConfigChange::replace($c['path'], $c['value']),
            'add' => ConfigChange::add($c['path'], $c['value']),
            'remove' => ConfigChange::remove($c['path']),
            default => throw new \RuntimeException("Unknown stored config change kind [{$c['kind']}]."),
        }, $rawChanges);
    }
}
