<?php

namespace App\Operations;

/**
 * A typed request to propose an operation: what kind of mutation, what it
 * targets, and its (raw, not-yet-redacted) metadata. OperationService
 * redacts the metadata before anything is persisted — see
 * App\Operations\InputRedactor.
 *
 * At this task (5), requests carry type + metadata only. No execution
 * happens here or anywhere in OperationService::propose() — concrete
 * handlers (and therefore concrete request shapes with real domain
 * meaning) arrive in Tasks 8, 10, and 15.
 */
final class OperationRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public readonly OperationType $type,
        public readonly string $target,
        public readonly array $metadata,
        public readonly OperationRisk $risk,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function make(
        OperationType $type,
        string $target,
        array $metadata = [],
        OperationRisk $risk = OperationRisk::Standard,
    ): self {
        return new self($type, $target, $metadata, $risk);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function configApply(string $path, string $expectedSha256, array $changes): self
    {
        return self::make(OperationType::ConfigApply, $path, [
            'expected_sha256' => $expectedSha256,
            'changes' => $changes,
        ]);
    }

    public static function configRestore(string $path, string $revisionId): self
    {
        return self::make(OperationType::ConfigRestore, $path, [
            'revision_id' => $revisionId,
        ]);
    }

    public static function pluginInstall(string $pluginId, string $releaseId): self
    {
        return self::make(OperationType::PluginInstall, $pluginId, [
            'release_id' => $releaseId,
        ], OperationRisk::Elevated);
    }

    public static function pluginUpdate(string $pluginId, string $releaseId): self
    {
        return self::make(OperationType::PluginUpdate, $pluginId, [
            'release_id' => $releaseId,
        ], OperationRisk::Elevated);
    }

    public static function pluginDisable(string $pluginId): self
    {
        return self::make(OperationType::PluginDisable, $pluginId, [], OperationRisk::Elevated);
    }

    public static function pluginRemove(string $pluginId): self
    {
        return self::make(OperationType::PluginRemove, $pluginId, [], OperationRisk::Elevated);
    }

    public static function pluginRollback(string $pluginId, string $rollbackArtifactId): self
    {
        return self::make(OperationType::PluginRollback, $pluginId, [
            'rollback_artifact_id' => $rollbackArtifactId,
        ], OperationRisk::Elevated);
    }

    /**
     * Risk defaults to Elevated because, without Task 10's CommandPolicy in
     * place yet, CraftKeeper cannot classify the command text itself.
     * Task 10 is expected to pass a precise risk once CommandPolicy exists.
     */
    public static function rconCommand(string $command, OperationRisk $risk = OperationRisk::Elevated): self
    {
        return self::make(OperationType::RconCommand, $command, [
            'command' => $command,
        ], $risk);
    }

    public static function serverStop(): self
    {
        return self::make(OperationType::ServerStop, 'server', [], OperationRisk::Elevated);
    }
}
