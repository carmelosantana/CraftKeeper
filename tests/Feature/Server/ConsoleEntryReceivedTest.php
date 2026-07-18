<?php

use App\Events\ConsoleEntryReceived;
use App\Models\ConsoleEntry;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

it('broadcasts on a single private server.console channel and dispatches only after the DB commit', function () {
    expect(is_a(ConsoleEntryReceived::class, ShouldBroadcast::class, true))->toBeTrue()
        ->and(is_a(ConsoleEntryReceived::class, ShouldDispatchAfterCommit::class, true))->toBeTrue();

    $entry = ConsoleEntry::query()->create(['line' => '[10:00:00 INFO]: hello', 'occurred_at' => now()]);
    $event = ConsoleEntryReceived::fromEntry($entry);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-server.console');
});

it('broadcasts exactly the allow-listed fields — id, line, occurred_at — nothing else', function () {
    $entry = ConsoleEntry::query()->create(['line' => '[10:00:00 INFO]: hello world', 'occurred_at' => now()]);
    $payload = ConsoleEntryReceived::fromEntry($entry)->broadcastWith();

    expect(array_keys($payload))->toEqualCanonicalizing(['id', 'line', 'occurred_at']);

    foreach ($payload as $value) {
        expect($value === null || is_scalar($value))->toBeTrue();
    }
});

it('authorizes the server.console channel to any authenticated user and to no one else', function () {
    $broadcaster = app(Factory::class)->connection();

    $reflection = new ReflectionClass($broadcaster);
    $channelsProperty = $reflection->getProperty('channels');
    $channelsProperty->setAccessible(true);
    $channels = $channelsProperty->getValue($broadcaster);

    expect($channels)->toHaveKey('server.console');

    $callback = $channels['server.console'];
    $reflectionFunction = new ReflectionFunction($callback);
    $userParameter = $reflectionFunction->getParameters()[0];

    // Same reasoning as operations.{id} (Task 5): Laravel's private-channel
    // auth flow rejects an unauthenticated request with a 403 before this
    // closure is ever invoked, so a non-nullable User parameter is
    // sufficient to restrict this channel to the signed-in admin.
    expect((string) $userParameter->getType())->toBe(User::class)
        ->and($userParameter->allowsNull())->toBeFalse();

    $admin = User::factory()->create();
    expect($callback($admin))->toBeTrue();
});
