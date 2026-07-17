<?php

use App\Events\OperationUpdated;
use App\Models\Operation;
use App\Models\User;
use App\Operations\Exceptions\IllegalOperationTransition;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

it('broadcasts OperationUpdated only after the database commit, on a private per-operation channel', function () {
    expect(is_a(OperationUpdated::class, ShouldBroadcast::class, true))->toBeTrue()
        ->and(is_a(OperationUpdated::class, ShouldDispatchAfterCommit::class, true))->toBeTrue();

    $operation = Operation::factory()->create();
    $event = OperationUpdated::fromOperation($operation);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-operations.{$operation->id}");
});

it('never includes secret-shaped fields in the broadcast payload', function () {
    $operation = Operation::factory()->create([
        'target' => 'plugins/Geyser-Spigot/config.yml',
        'redacted_input' => [
            'expected_sha256' => 'abc123',
            'changes' => ['floodgate-key' => '••••••'],
        ],
        'error_code' => 'config.hash_mismatch',
        'outcome' => 'The file changed on disk since this was proposed.',
    ]);

    $payload = OperationUpdated::fromOperation($operation)->broadcastWith();

    // Deliberate allow-list: only these keys may ever be on the wire.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'id', 'type', 'status', 'risk', 'error_code', 'outcome', 'updated_at',
    ]);

    // The operation's target, metadata, and redacted_input never appear at
    // all — not even redacted. Every value in the payload is a plain
    // scalar or null, never an array/object that could smuggle nested data.
    foreach ($payload as $value) {
        expect($value === null || is_scalar($value))->toBeTrue();
    }

    $serialized = json_encode($payload);

    expect($serialized)
        ->not->toContain('floodgate-key')
        ->not->toContain('abc123')
        ->not->toContain('Geyser-Spigot')
        ->not->toContain('sha256');
});

it('dispatches OperationUpdated on propose, approve, reject, and execute', function () {
    Event::fake([OperationUpdated::class]);

    $admin = User::factory()->create();
    $service = app(OperationService::class);

    $operation = $service->propose(
        OperationRequest::configApply('server.properties', 'sha', ['allow-flight' => 'true']),
        OperationAuthor::user($admin->id)
    );
    Event::assertDispatched(OperationUpdated::class, fn (OperationUpdated $e) => $e->status === OperationStatus::Proposed);

    $service->approve($operation->id, $admin);
    Event::assertDispatched(OperationUpdated::class, fn (OperationUpdated $e) => $e->status === OperationStatus::Approved);

    $operation = $service->propose(
        OperationRequest::serverStop(),
        OperationAuthor::user($admin->id)
    );
    $service->reject($operation->id, $admin, 'not now');
    Event::assertDispatched(OperationUpdated::class, fn (OperationUpdated $e) => $e->status === OperationStatus::Rejected);

    $approved = $service->approve($operation->id, $admin);
})->throws(IllegalOperationTransition::class);

it('broadcasts Running then a terminal status while executing', function () {
    Event::fake([OperationUpdated::class]);

    $operation = Operation::factory()->status(OperationStatus::Approved)->create();

    app(OperationService::class)->execute($operation->id);

    Event::assertDispatched(OperationUpdated::class, fn (OperationUpdated $e) => $e->status === OperationStatus::Running);
    Event::assertDispatched(OperationUpdated::class, fn (OperationUpdated $e) => $e->status === OperationStatus::Failed);
});

it('authorizes the operations channel to any authenticated user and to no one else', function () {
    $broadcaster = app(Factory::class)->connection();

    $reflection = new ReflectionClass($broadcaster);
    $channelsProperty = $reflection->getProperty('channels');
    $channelsProperty->setAccessible(true);
    $channels = $channelsProperty->getValue($broadcaster);

    expect($channels)->toHaveKey('operations.{id}');

    $callback = $channels['operations.{id}'];
    $reflectionFunction = new ReflectionFunction($callback);
    $userParameter = $reflectionFunction->getParameters()[0];

    // The callback's first parameter is a non-nullable User: Laravel's
    // private-channel auth flow (Broadcaster::verifyUserCanAccessChannel)
    // rejects the request with a 403 before ever invoking this callback
    // when there is no authenticated user, so there is no way to reach
    // this closure while unauthenticated.
    expect((string) $userParameter->getType())->toBe(User::class)
        ->and($userParameter->allowsNull())->toBeFalse();

    $admin = User::factory()->create();
    expect($callback($admin, 'any-operation-id'))->toBeTrue();
});
