<?php

use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function activityReadToken(): string
{
    return User::factory()->create()->createToken('reader', ['activity:read'])->plainTextToken;
}

it('lists every operation type in the activity feed, most recent first, cursor-paginated', function () {
    $older = Operation::factory()->ofType(OperationType::PluginDisable)->create();
    $newer = Operation::factory()->ofType(OperationType::ServerStop)->create();

    $response = $this->withToken(activityReadToken())
        ->getJson('/api/v1/operations')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect(array_search($newer->id, $ids, true))->toBeLessThan(array_search($older->id, $ids, true));
});

it('shows a single operation by id', function () {
    $operation = Operation::factory()->create();

    $this->withToken(activityReadToken())
        ->getJson("/api/v1/operations/{$operation->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $operation->id);
});

it('paginates the activity feed with an opaque cursor', function () {
    $ops = Operation::factory()->count(3)->create();

    $first = $this->withToken(activityReadToken())
        ->getJson('/api/v1/operations?per_page=2')
        ->assertOk();

    expect($first->json('meta.has_more'))->toBeTrue();
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->withToken(activityReadToken())
        ->getJson('/api/v1/operations?per_page=2&cursor='.urlencode($cursor))
        ->assertOk();

    $firstIds = collect($first->json('data'))->pluck('id')->all();
    $secondIds = collect($second->json('data'))->pluck('id')->all();

    expect(array_intersect($firstIds, $secondIds))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| rcon command scope nuance: rcon:safe only for Safe commands, rcon:admin
| required for Elevated ones; neither can ever approve.
|--------------------------------------------------------------------------
*/

it('proposes a Safe rcon command with rcon:safe and never auto-approves it', function () {
    $token = User::factory()->create()->createToken('rcon', ['rcon:safe'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertCreated();

    $id = $response->json('data.id');
    $operation = Operation::query()->findOrFail($id);

    expect($operation->type)->toBe(OperationType::RconCommand)
        ->and($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull();
});

it('refuses an Elevated rcon command from an rcon:safe-only token', function () {
    $token = User::factory()->create()->createToken('rcon', ['rcon:safe'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'ban Notch'])
        ->assertForbidden();

    expect(Operation::query()->count())->toBe(0);
});

it('lets rcon:admin propose an Elevated command but it stays Proposed, never Approved', function () {
    $token = User::factory()->create()->createToken('rcon-admin', ['rcon:admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'ban Notch'])
        ->assertCreated();

    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->risk->value)->toBe('elevated');
});

it('validates the rcon command body', function () {
    $token = User::factory()->create()->createToken('rcon', ['rcon:safe'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', [])
        ->assertStatus(422);
});

it('returns the ORIGINAL rcon proposal for a repeated Idempotency-Key', function () {
    $token = User::factory()->create()->createToken('rcon', ['rcon:safe'])->plainTextToken;

    $first = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rcon-once'])
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertCreated()
        ->json('data');

    $second = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'rcon-once'])
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertCreated()
        ->json('data');

    expect($second['id'])->toBe($first['id']);
    expect(Operation::query()->where('type', 'rcon.command')->count())->toBe(1);
});
