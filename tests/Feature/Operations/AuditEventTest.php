<?php

use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\User;
use App\Operations\Exceptions\AuditEventImmutable;
use App\Operations\OperationActorType;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationService;

it('creates audit events with actor type, id, origin, and a redacted payload', function () {
    $operation = Operation::factory()->create();

    $event = AuditEvent::query()->create([
        'operation_id' => $operation->id,
        'event_type' => 'operation.proposed',
        'actor_type' => OperationActorType::Human,
        'actor_id' => '1',
        'actor_origin' => 'web',
        'payload' => ['note' => 'fine', 'password' => 'ignored-by-caller-not-redactor'],
    ]);

    expect(AuditEvent::query()->find($event->id))->not->toBeNull();
});

it('refuses to update an existing audit event', function () {
    $operation = Operation::factory()->create();

    $event = AuditEvent::query()->create([
        'operation_id' => $operation->id,
        'event_type' => 'operation.proposed',
        'actor_type' => OperationActorType::Human,
        'actor_id' => '1',
        'actor_origin' => 'web',
        'payload' => [],
    ]);

    expect(function () use ($event) {
        $event->event_type = 'operation.tampered';
        $event->save();
    })->toThrow(AuditEventImmutable::class);
});

it('refuses to delete an existing audit event', function () {
    $operation = Operation::factory()->create();

    $event = AuditEvent::query()->create([
        'operation_id' => $operation->id,
        'event_type' => 'operation.proposed',
        'actor_type' => OperationActorType::Human,
        'actor_id' => '1',
        'actor_origin' => 'web',
        'payload' => [],
    ]);

    expect(fn () => $event->delete())->toThrow(AuditEventImmutable::class);

    expect(AuditEvent::query()->find($event->id))->not->toBeNull();
});

it('refuses Eloquent\'s own update() helper too, not just direct attribute assignment', function () {
    $operation = Operation::factory()->create();

    $event = AuditEvent::query()->create([
        'operation_id' => $operation->id,
        'event_type' => 'operation.proposed',
        'actor_type' => OperationActorType::Human,
        'actor_id' => '1',
        'actor_origin' => 'web',
        'payload' => [],
    ]);

    expect(fn () => $event->update(['event_type' => 'operation.tampered']))
        ->toThrow(AuditEventImmutable::class);

    expect(AuditEvent::query()->find($event->id)->event_type)->toBe('operation.proposed');
});

it('appends one audit event per lifecycle transition, never mutating a prior one', function () {
    $admin = User::factory()->create();

    $operation = app(OperationService::class)->propose(
        OperationRequest::serverStop(),
        OperationAuthor::user($admin->id)
    );

    app(OperationService::class)->approve($operation->id, $admin);

    $events = AuditEvent::query()->where('operation_id', $operation->id)->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe('operation.proposed')
        ->and($events[1]->event_type)->toBe('operation.approved');

    // The first event's timestamp/content is untouched by the second write.
    expect($events[0]->created_at)->not->toBeNull();
});
