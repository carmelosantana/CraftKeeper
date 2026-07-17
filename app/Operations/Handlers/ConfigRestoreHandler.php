<?php

namespace App\Operations\Handlers;

use App\Config\ConfigFormatRegistry;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\MinecraftFilesystem;
use App\Models\Operation;
use App\Operations\Handlers\Concerns\AppliesConfigChanges;
use App\Operations\OperationHandler;
use App\Operations\OperationResult;
use App\Operations\OperationType;

/**
 * The concrete OperationHandler for OperationType::ConfigRestore —
 * registered separately from App\Operations\Handlers\ConfigApplyHandler
 * (its own `operation.handler` container tag) so the two OperationTypes
 * resolve independently, per Task 8's ambiguity resolution #1, even
 * though both share the exact same write logic via
 * Concerns\AppliesConfigChanges. App\Config\ConfigRevisionService::
 * restore() is what CREATES a config.restore operation (a fresh proposal
 * of the changes needed to move the file toward a past revision); this
 * class is what actually applies it once a human approves it — restore
 * goes through the identical propose -> approve -> execute pipeline as
 * any other edit, never a direct file copy.
 */
final class ConfigRestoreHandler implements OperationHandler
{
    use AppliesConfigChanges;

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
    ) {}

    public function supports(OperationType $type): bool
    {
        return $type === OperationType::ConfigRestore;
    }

    public function execute(Operation $operation): OperationResult
    {
        return $this->applyApprovedChange($operation, 'config.restored', 'Restored');
    }
}
