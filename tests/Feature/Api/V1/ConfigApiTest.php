<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationActorType;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
    $this->admin = User::factory()->create();
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function configReadToken(): string
{
    return User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;
}

/*
|--------------------------------------------------------------------------
| Reads
|--------------------------------------------------------------------------
*/

it('lists discovered config files, cursor-paginated', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");

    $this->withToken(configReadToken())
        ->getJson('/api/v1/config/files')
        ->assertOk()
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonFragment(['path' => 'server.properties']);
});

it('shows a single config file with a redacted body and an ETag', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=actual-secret-value\nmotd=hi\n");

    $response = $this->withToken(configReadToken())
        ->getJson('/api/v1/config/files/server.properties')
        ->assertOk();

    $response->assertJsonPath('data.path', 'server.properties');
    expect($response->headers->get('ETag'))->not->toBeNull();
    expect($response->getContent())->not->toContain('actual-secret-value');
});

it('returns 304 for a matching If-None-Match ETag', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $token = configReadToken();

    $first = $this->withToken($token)->getJson('/api/v1/config/files/server.properties')->assertOk();
    $etag = $first->headers->get('ETag');

    $this->withToken($token)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/v1/config/files/server.properties')
        ->assertStatus(304);
});

it('404s for an unsafe or nonexistent config path', function () {
    $this->withToken(configReadToken())
        ->getJson('/api/v1/config/files/does-not-exist.properties')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

/*
|--------------------------------------------------------------------------
| Propose: creates ONLY, never writes; stale hash -> 409; bad body -> 422
|--------------------------------------------------------------------------
*/

it('creates a config proposal that never writes the file', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/config/proposals', [
            'path' => 'server.properties',
            'base_sha256' => hash('sha256', $contents),
            'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'Hello world']],
        ])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'proposed');
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe($contents);
});

it('returns 409 for a stale base_sha256', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/config/proposals', [
            'path' => 'server.properties',
            'base_sha256' => str_repeat('0', 64),
            'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'Hello world']],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_hash');
});

it('returns 422 for a malformed proposal body', function () {
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/config/proposals', ['path' => 'server.properties'])
        ->assertStatus(422);

    $response->assertJsonPath('code', 'validation_failed');
    expect($response->json('details'))->toHaveKey('base_sha256');
});

it('returns the ORIGINAL proposal for a repeated Idempotency-Key instead of creating a duplicate', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;

    $body = [
        'path' => 'server.properties',
        'base_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'Hello world']],
    ];

    $first = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'my-key-1'])
        ->postJson('/api/v1/config/proposals', $body)
        ->assertCreated()
        ->json('data');

    $second = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'my-key-1'])
        ->postJson('/api/v1/config/proposals', $body)
        ->assertCreated()
        ->json('data');

    expect($second['id'])->toBe($first['id']);
    expect(Operation::query()->where('target', 'server.properties')->count())->toBe(1);
});

it('returns 409 when an Idempotency-Key is reused with a different body', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;
    $hash = hash('sha256', $contents);

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'reused-key'])
        ->postJson('/api/v1/config/proposals', [
            'path' => 'server.properties',
            'base_sha256' => $hash,
            'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'Hello world']],
        ])
        ->assertCreated();

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'reused-key'])
        ->postJson('/api/v1/config/proposals', [
            'path' => 'server.properties',
            'base_sha256' => $hash,
            'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'A totally different value']],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_conflict');
});

/*
|--------------------------------------------------------------------------
| Actor origin: a token-authored operation is never mistaken for a browser
| click (Fix 2 — previously OperationAuthor::user() was called here with
| no second argument, defaulting to 'web' and making an API-created
| operation byte-identical, in author_type/author_origin, to a UI click).
|--------------------------------------------------------------------------
*/

it('records author_origin as api for a proposal created through the REST API', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $token = User::factory()->create()->createToken('proposer', ['config:propose'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/config/proposals', [
            'path' => 'server.properties',
            'base_sha256' => hash('sha256', $contents),
            'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'Hello world']],
        ])
        ->assertCreated();

    $response->assertJsonPath('data.actor.origin', 'api');

    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->author_origin)->toBe('api')
        ->and($operation->author_type)->toBe(OperationActorType::Human);
});

it('still records author_origin as web for an operation proposed directly through the domain service (the web UI path)', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $changeRequest = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('motd', 'Hello world'),
    ]);
    $operation = app(ConfigChangeService::class)->propose($changeRequest, OperationAuthor::user($this->admin->id));

    expect($operation->author_origin)->toBe('web');
});

/*
|--------------------------------------------------------------------------
| Apply: only touches an already human-approved operation.
|--------------------------------------------------------------------------
*/

it('applies an already-approved operation and writes the real file', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $changeRequest = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('motd', 'Applied via API'),
    ]);
    $operation = app(ConfigChangeService::class)->propose($changeRequest, OperationAuthor::user($this->admin->id));

    // The ONLY human approval path — the web-session-authenticated
    // OperationService::approve() — never anything under /api/v1.
    app(OperationService::class)->approve($operation->id, $this->admin);

    $token = User::factory()->create()->createToken('applier', ['config:apply'])->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/config/proposals/{$operation->id}/apply", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded');

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=Applied via API\n");
});

it('refuses to apply an operation that has not been human-approved yet, with 409', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $changeRequest = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('motd', 'Should never land'),
    ]);
    $operation = app(ConfigChangeService::class)->propose($changeRequest, OperationAuthor::user($this->admin->id));

    expect($operation->status)->toBe(OperationStatus::Proposed);

    $token = User::factory()->create()->createToken('applier', ['config:apply'])->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/config/proposals/{$operation->id}/apply", [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'operation_not_approved');

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe($contents);
    expect($operation->fresh()->status)->toBe(OperationStatus::Proposed);
});

it('refuses to apply a rejected operation, with 409', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $changeRequest = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('motd', 'Should never land'),
    ]);
    $operation = app(ConfigChangeService::class)->propose($changeRequest, OperationAuthor::user($this->admin->id));
    app(OperationService::class)->reject($operation->id, $this->admin, 'no thanks');

    $token = User::factory()->create()->createToken('applier', ['config:apply'])->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/config/proposals/{$operation->id}/apply", [])
        ->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| Secrets never serialize.
|--------------------------------------------------------------------------
*/

it('never serializes a raw secret value in the config file show endpoint', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=actual-secret-value\nmotd=hi\n");

    $response = $this->withToken(configReadToken())
        ->getJson('/api/v1/config/files/server.properties')
        ->assertOk();

    expect($response->getContent())->not->toContain('actual-secret-value');
});

it('never serializes a raw secret value in a config proposal resource', function () {
    $contents = "rcon.password=old-secret\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $changeRequest = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);
    $operation = app(ConfigChangeService::class)->propose($changeRequest, OperationAuthor::user($this->admin->id));

    $response = $this->withToken(configReadToken())
        ->getJson("/api/v1/config/proposals/{$operation->id}")
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('old-secret')
        ->not->toContain('brand-new-secret');
});
