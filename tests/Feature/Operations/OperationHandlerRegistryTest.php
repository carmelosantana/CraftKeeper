<?php

use App\Models\Operation;
use App\Operations\Handlers\ConfigApplyHandler;
use App\Operations\Handlers\ConfigRestoreHandler;
use App\Operations\OperationHandler;
use App\Operations\OperationHandlerRegistry;
use App\Operations\OperationResult;
use App\Operations\OperationType;

it('resolves no handler for any type when none is registered', function () {
    $registry = new OperationHandlerRegistry;

    foreach (OperationType::cases() as $type) {
        expect($registry->resolve($type))->toBeNull();
    }
});

it('resolves the first registered handler that supports the requested type', function () {
    $configHandler = new class implements OperationHandler
    {
        public function supports(OperationType $type): bool
        {
            return in_array($type, [OperationType::ConfigApply, OperationType::ConfigRestore], true);
        }

        public function execute(Operation $operation): OperationResult
        {
            return OperationResult::success();
        }

        public function rollback(Operation $operation): OperationResult
        {
            return OperationResult::success();
        }
    };

    $registry = new OperationHandlerRegistry;
    $registry->register($configHandler);

    expect($registry->resolve(OperationType::ConfigApply))->toBe($configHandler)
        ->and($registry->resolve(OperationType::ConfigRestore))->toBe($configHandler)
        ->and($registry->resolve(OperationType::ServerStop))->toBeNull();
});

it('binds every registered handler via the container tag convention', function () {
    // As of Task 8, ConfigApplyHandler/ConfigRestoreHandler are the first
    // two concrete handlers registered via the `operation.handler`
    // container tag (see App\Providers\AppServiceProvider) — every other
    // OperationType still has no handler until Tasks 10/15 add theirs.
    $registry = app(OperationHandlerRegistry::class);

    expect($registry)->toBeInstanceOf(OperationHandlerRegistry::class)
        ->and($registry->resolve(OperationType::ConfigApply))->toBeInstanceOf(ConfigApplyHandler::class)
        ->and($registry->resolve(OperationType::ConfigRestore))->toBeInstanceOf(ConfigRestoreHandler::class)
        ->and($registry->resolve(OperationType::PluginInstall))->toBeNull()
        ->and($registry->resolve(OperationType::RconCommand))->toBeNull()
        ->and($registry->resolve(OperationType::ServerStop))->toBeNull();
});
