<?php

use App\Models\Operation;
use App\Operations\Handlers\ConfigApplyHandler;
use App\Operations\Handlers\ConfigRestoreHandler;
use App\Operations\Handlers\PluginOperationHandler;
use App\Operations\Handlers\RconCommandHandler;
use App\Operations\Handlers\ServerStopHandler;
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
    // As of Task 8, ConfigApplyHandler/ConfigRestoreHandler were the
    // first two concrete handlers registered via the `operation.handler`
    // container tag (see App\Providers\AppServiceProvider); Task 10 added
    // RconCommandHandler and ServerStopHandler the same way; Task 15 adds
    // PluginOperationHandler for every plugin.* type — every OperationType
    // now resolves to a real handler.
    $registry = app(OperationHandlerRegistry::class);

    expect($registry)->toBeInstanceOf(OperationHandlerRegistry::class)
        ->and($registry->resolve(OperationType::ConfigApply))->toBeInstanceOf(ConfigApplyHandler::class)
        ->and($registry->resolve(OperationType::ConfigRestore))->toBeInstanceOf(ConfigRestoreHandler::class)
        ->and($registry->resolve(OperationType::RconCommand))->toBeInstanceOf(RconCommandHandler::class)
        ->and($registry->resolve(OperationType::ServerStop))->toBeInstanceOf(ServerStopHandler::class)
        ->and($registry->resolve(OperationType::PluginInstall))->toBeInstanceOf(PluginOperationHandler::class)
        ->and($registry->resolve(OperationType::PluginUpdate))->toBeInstanceOf(PluginOperationHandler::class)
        ->and($registry->resolve(OperationType::PluginDisable))->toBeInstanceOf(PluginOperationHandler::class)
        ->and($registry->resolve(OperationType::PluginRemove))->toBeInstanceOf(PluginOperationHandler::class)
        ->and($registry->resolve(OperationType::PluginRollback))->toBeInstanceOf(PluginOperationHandler::class);
});
