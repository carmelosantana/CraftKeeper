<?php

use App\Models\Operation;
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

it('binds an empty registry by default via the container tag convention', function () {
    $registry = app(OperationHandlerRegistry::class);

    expect($registry)->toBeInstanceOf(OperationHandlerRegistry::class)
        ->and($registry->resolve(OperationType::ConfigApply))->toBeNull();
});
