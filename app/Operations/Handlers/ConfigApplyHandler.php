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
 * The first concrete OperationHandler for OperationType::ConfigApply —
 * registered against App\Operations\OperationHandlerRegistry via the
 * `operation.handler` container tag (see App\Providers\AppServiceProvider),
 * per Task 5's documented extension convention. This is what makes
 * approve() -> execute() actually write a config change: before this
 * class existed, OperationService::execute() degraded every config.apply
 * operation to a Failed "operation.no_handler_registered" result.
 *
 * All the actual logic — re-reading the file, re-validating, the
 * snapshot-then-write order, the TOCTOU hash re-check, and the
 * compensating rollback on a post-write verification failure — lives in
 * the shared Concerns\AppliesConfigChanges trait, since it is identical
 * for App\Operations\Handlers\ConfigRestoreHandler; only supports() and
 * the audit-event/success-message wording differ between the two.
 */
final class ConfigApplyHandler implements OperationHandler
{
    use AppliesConfigChanges;

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
    ) {}

    public function supports(OperationType $type): bool
    {
        return $type === OperationType::ConfigApply;
    }

    public function execute(Operation $operation): OperationResult
    {
        return $this->applyApprovedChange($operation, 'config.applied', 'Applied');
    }
}
