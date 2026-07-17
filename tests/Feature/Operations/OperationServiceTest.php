<?php

use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\User;
use App\Operations\Exceptions\IllegalOperationTransition;
use App\Operations\OperationActorType;
use App\Operations\OperationAuthor;
use App\Operations\OperationHandler;
use App\Operations\OperationHandlerRegistry;
use App\Operations\OperationRequest;
use App\Operations\OperationResult;
use App\Operations\OperationRisk;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationStepStatus;
use App\Operations\OperationType;

/*
|--------------------------------------------------------------------------
| The brief's verbatim lifecycle + separation-of-duty test
|--------------------------------------------------------------------------
*/

it('does not execute a proposed mutation before human approval', function () {
    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'expected-sha', ['allow-flight' => 'true']),
        OperationAuthor::mcp('client-1')
    );

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull();
});

it('never executes anything from propose(), even for a human author', function () {
    $user = User::factory()->create();

    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'expected-sha', ['allow-flight' => 'true']),
        OperationAuthor::user($user->id)
    );

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull()
        ->and($operation->started_at)->toBeNull()
        ->and($operation->finished_at)->toBeNull();
});

it('persists actor type, id, origin, risk, redacted input, and a correlation id on propose', function () {
    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'expected-sha', ['allow-flight' => 'true']),
        OperationAuthor::mcp('client-1', 'mcp-session-42')
    );

    expect($operation->author_type)->toBe(OperationActorType::Mcp)
        ->and($operation->author_id)->toBe('client-1')
        ->and($operation->author_origin)->toBe('mcp-session-42')
        ->and($operation->risk)->toBe(OperationRisk::Standard)
        ->and($operation->redacted_input)->toBe(['expected_sha256' => 'expected-sha', 'changes' => ['allow-flight' => 'true']])
        ->and($operation->correlation_id)->not->toBeNull();
});

it('writes an audit event when an operation is proposed', function () {
    $operation = app(OperationService::class)->propose(
        OperationRequest::serverStop(),
        OperationAuthor::mcp('client-1')
    );

    $event = AuditEvent::query()->where('operation_id', $operation->id)->sole();

    expect($event->event_type)->toBe('operation.proposed')
        ->and($event->actor_type)->toBe(OperationActorType::Mcp)
        ->and($event->actor_id)->toBe('client-1');
});

/*
|--------------------------------------------------------------------------
| Separation of duties
|--------------------------------------------------------------------------
*/

it('exposes approve() as human-only at the type level', function () {
    $method = new ReflectionMethod(OperationService::class, 'approve');
    $type = $method->getParameters()[1]->getType();

    expect($type)->not->toBeNull()
        ->and((string) $type)->toBe(User::class);
});

it('exposes reject() as human-only at the type level', function () {
    $method = new ReflectionMethod(OperationService::class, 'reject');
    $type = $method->getParameters()[1]->getType();

    expect($type)->not->toBeNull()
        ->and((string) $type)->toBe(User::class);
});

it('lets a human approve an MCP-authored operation', function () {
    $admin = User::factory()->create();

    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'sha', ['allow-flight' => 'true']),
        OperationAuthor::mcp('client-1')
    );

    $approved = app(OperationService::class)->approve($operation->id, $admin);

    expect($approved->status)->toBe(OperationStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->approved_by_type)->toBe(OperationActorType::Human)
        ->and($approved->approved_by_id)->toBe((string) $admin->id);
});

it('lets a human approve their own proposed operation (self-approval is normal for a single-admin product)', function () {
    $admin = User::factory()->create();

    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'sha', ['allow-flight' => 'true']),
        OperationAuthor::user($admin->id)
    );

    $approved = app(OperationService::class)->approve($operation->id, $admin);

    expect($approved->status)->toBe(OperationStatus::Approved);
});

it('lets a human reject an AI-authored operation with a reason', function () {
    $admin = User::factory()->create();

    $operation = app(OperationService::class)->propose(
        OperationRequest::pluginRemove('example-plugin'),
        OperationAuthor::ai('session-1')
    );

    $rejected = app(OperationService::class)->reject($operation->id, $admin, 'not needed right now');

    expect($rejected->status)->toBe(OperationStatus::Rejected)
        ->and($rejected->outcome)->toBe('not needed right now')
        ->and($rejected->rejected_by_type)->toBe(OperationActorType::Human);
});

/*
|--------------------------------------------------------------------------
| Illegal transitions
|--------------------------------------------------------------------------
*/

it('rejects approving an operation that is not Proposed', function () {
    $admin = User::factory()->create();
    $operation = Operation::factory()->status(OperationStatus::Approved)->create();

    app(OperationService::class)->approve($operation->id, $admin);
})->throws(IllegalOperationTransition::class);

it('rejects rejecting an operation that is not Proposed', function () {
    $admin = User::factory()->create();
    $operation = Operation::factory()->status(OperationStatus::Rejected)->create();

    app(OperationService::class)->reject($operation->id, $admin, 'too late');
})->throws(IllegalOperationTransition::class);

it('rejects executing an operation that is not Approved', function () {
    $operation = Operation::factory()->status(OperationStatus::Proposed)->create();

    app(OperationService::class)->execute($operation->id);
})->throws(IllegalOperationTransition::class);

it('rejects rolling back an operation that has not reached a terminal state', function () {
    $operation = Operation::factory()->status(OperationStatus::Running)->create();

    app(OperationService::class)->rollback($operation->id, OperationAuthor::system());
})->throws(IllegalOperationTransition::class);

it('allows the documented rollback path from both Succeeded and Failed', function (OperationStatus $from) {
    $operation = Operation::factory()->status($from)->create();

    $rolledBack = app(OperationService::class)->rollback($operation->id, OperationAuthor::system());

    expect($rolledBack->status)->toBe(OperationStatus::RolledBack);
})->with([
    'Succeeded' => [OperationStatus::Succeeded],
    'Failed' => [OperationStatus::Failed],
]);

/*
|--------------------------------------------------------------------------
| The execution seam: no handler registered degrades cleanly
|--------------------------------------------------------------------------
*/

it('degrades cleanly to Failed with a typed error code when no handler is registered', function () {
    $admin = User::factory()->create();

    // ConfigApply/ConfigRestore have real handlers as of Task 8 (see
    // App\Providers\AppServiceProvider); serverStop() still has none
    // until Task 15, so it remains a faithful "no handler" example here.
    $operation = app(OperationService::class)->propose(
        OperationRequest::serverStop(),
        OperationAuthor::user($admin->id)
    );

    app(OperationService::class)->approve($operation->id, $admin);

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('operation.no_handler_registered')
        ->and($result->outcome)->not->toBeNull();
});

it('never throws when executing an operation type with no registered handler', function () {
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    expect(fn () => app(OperationService::class)->execute($operation->id))->not->toThrow(Throwable::class);
});

it('records an execution step alongside the failed operation when no handler is registered', function () {
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    app(OperationService::class)->execute($operation->id);

    $step = $operation->fresh()->steps()->sole();

    expect($step->status)->toBe(OperationStepStatus::Failed)
        ->and($step->error_code)->toBe('operation.no_handler_registered');
});

it('runs a registered handler and preserves its diagnostic error code on failure', function () {
    // Uses PluginInstall (no real handler until Task 15) rather than
    // ConfigApply, which Task 8 gave a real handler that would otherwise
    // resolve ahead of this test's own fake one — see
    // OperationHandlerRegistry::resolve()'s "first match wins" contract.
    $handler = new class implements OperationHandler
    {
        public function supports(OperationType $type): bool
        {
            return $type === OperationType::PluginInstall;
        }

        public function execute(Operation $operation): OperationResult
        {
            return OperationResult::failure('config.hash_mismatch', 'The file changed on disk since this was proposed.');
        }

        public function rollback(Operation $operation): OperationResult
        {
            return OperationResult::success();
        }
    };

    app(OperationHandlerRegistry::class)->register($handler);

    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::PluginInstall)->create();

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('config.hash_mismatch');
});

it('converts an exception thrown by a handler into a failed operation instead of crashing', function () {
    $handler = new class implements OperationHandler
    {
        public function supports(OperationType $type): bool
        {
            return true;
        }

        public function execute(Operation $operation): OperationResult
        {
            throw new RuntimeException('disk full');
        }

        public function rollback(Operation $operation): OperationResult
        {
            return OperationResult::success();
        }
    };

    app(OperationHandlerRegistry::class)->register($handler);

    // ServerStop (no real handler until Task 15) rather than the
    // default ConfigApply, which Task 8's own handler would otherwise
    // resolve ahead of this test's "supports everything" fake.
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    expect(fn () => app(OperationService::class)->execute($operation->id))->not->toThrow(Throwable::class);

    $result = $operation->fresh();
    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('operation.handler_exception');
});

it('runs a registered handler and transitions to Succeeded', function () {
    $handler = new class implements OperationHandler
    {
        public function supports(OperationType $type): bool
        {
            return true;
        }

        public function execute(Operation $operation): OperationResult
        {
            return OperationResult::success('applied cleanly');
        }

        public function rollback(Operation $operation): OperationResult
        {
            return OperationResult::success();
        }
    };

    app(OperationHandlerRegistry::class)->register($handler);

    // Same reasoning as the preceding test: ServerStop still has no real
    // handler, so this test's own fake is the only one that resolves.
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and($result->error_code)->toBeNull()
        ->and($result->outcome)->toBe('applied cleanly');
});
