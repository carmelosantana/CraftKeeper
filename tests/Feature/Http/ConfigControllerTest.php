<?php

use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TempMinecraftRoot;

function operationsService(): OperationService
{
    return app(OperationService::class);
}

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

/*
|--------------------------------------------------------------------------
| Auth gate
|--------------------------------------------------------------------------
*/

it('requires authentication for every configuration route', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");

    $this->get('/configurations')->assertRedirect('/login');
    $this->get('/configurations/server.properties')->assertRedirect('/login');
    $this->get('/configurations/history/server.properties')->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| Step 1 (the brief's own test, adapted to a real schema secret)
|--------------------------------------------------------------------------
|
| resources/schemas/config/geyser.json (the brief's literal example path)
| declares zero `secret: true` fields — Geyser/Floodgate's real secret is
| a Floodgate key PEM FILE, never a config.yml scalar value (see
| docs/architecture/decisions.md). `rcon.password` on server.properties is
| this repository's one real schema-secret field (the same one Task 8's
| own ConfigChangeServiceTest exercises), so it is the meaningful, non-
| vacuous target for this security property. The plugin-path variant below
| proves the literal brief route also works.
*/

it('never sends raw secret values to the browser', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=actual-secret-value\nmotd=hi\n");

    $this->actingAs($this->admin)
        ->get('/configurations/server.properties')
        ->assertOk()
        ->assertDontSee('actual-secret-value');
});

it('serves a plugin config file at the literal route the brief describes', function () {
    @mkdir($this->minecraftRoot.'/plugins/Geyser-Spigot', 0755, true);
    file_put_contents($this->minecraftRoot.'/plugins/Geyser-Spigot/config.yml', "bedrock:\n  port: 19132\nremote:\n  auth-type: floodgate\n");

    $this->actingAs($this->admin)
        ->get('/configurations/plugins/Geyser-Spigot/config.yml')
        ->assertOk()
        ->assertSee('19132');
});

/*
|--------------------------------------------------------------------------
| Secret redaction across ALL THREE edit-mode Inertia props
|--------------------------------------------------------------------------
*/

it('redacts a secret field in the guided, structured, and source props simultaneously', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=actual-secret-value\nmotd=hi\n");

    $response = $this->actingAs($this->admin)->get('/configurations/server.properties');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('config/Edit')
        ->where('guided', function (Collection $guided) {
            $rconField = collect($guided->get('groups'))
                ->flatMap(fn ($group) => $group['fields'])
                ->firstWhere('path', 'rcon.password');

            expect($rconField)->not->toBeNull()
                ->and($rconField['secret'])->toBeTrue()
                ->and($rconField['currentValue'])->toBe('••••••');

            return true;
        })
        ->where('structured', fn (Collection $structured) => $structured->get('data')['rcon.password'] === '••••••')
        ->where('source.contents', fn (string $source) => ! str_contains($source, 'actual-secret-value') && str_contains($source, '••••••'))
    );

    // Belt and suspenders: grep the fully rendered HTML/JSON payload, not
    // just the typed prop assertions above.
    expect($response->getContent())->not->toContain('actual-secret-value');
});

it('redacts secret values in the inventory preview and never filters on them', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=actual-secret-value\nmotd=hi\n");

    $shown = $this->actingAs($this->admin)->get('/configurations');
    $shown->assertOk();
    expect($shown->getContent())->not->toContain('actual-secret-value');

    // Search must never match on secret content, even though the raw
    // file literally contains this string.
    $searched = $this->actingAs($this->admin)->get('/configurations?q=actual-secret-value');
    $searched->assertInertia(fn (Assert $page) => $page->where('total', 0));
});

/*
|--------------------------------------------------------------------------
| The secret round-trip: an untouched sentinel is never written
|--------------------------------------------------------------------------
*/

it('never writes the literal mask sentinel to disk when a secret field is left unchanged', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=original-secret\nmotd=hi\n");

    $response = $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', "rcon.password=original-secret\nmotd=hi\n"),
        'values' => [
            'rcon.password' => '••••••', // untouched sentinel
            'motd' => 'updated motd',
        ],
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('config/Edit')->has('proposal'));

    $operation = Operation::query()->sole();
    operationsService()->approve($operation->id, $this->admin);
    $executed = operationsService()->execute($operation->id);

    expect($executed->status)->toBe(OperationStatus::Succeeded);

    $written = file_get_contents($this->minecraftRoot.'/server.properties');
    expect($written)->toContain('rcon.password=original-secret')
        ->not->toContain('••••••')
        ->toContain('motd=updated motd');
});

it('updates a secret value when the operator types a real new one', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=original-secret\nmotd=hi\n");

    $response = $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', "rcon.password=original-secret\nmotd=hi\n"),
        'values' => [
            'rcon.password' => 'brand-new-secret',
            'motd' => 'hi',
        ],
    ]);

    $response->assertOk();
    $operation = Operation::query()->sole();

    operationsService()->approve($operation->id, $this->admin);
    operationsService()->execute($operation->id);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toContain('rcon.password=brand-new-secret');
});

it('never adds every untouched schema field\'s default value when the real guided form re-submits its full displayed state', function () {
    // The real Edit.tsx page seeds ITS OWN local state from every guided
    // field's `currentValue` (buildGuided()'s baseline: the file's real
    // value if present, else the schema default) and resubmits ALL of it
    // on save, not just the field the operator actually touched — most
    // server-properties.json fields (difficulty, gamemode, pvp, ...)
    // aren't in this trimmed fixture at all, so the SUBMITTED value for
    // each of THOSE is literally the schema default the browser displayed
    // it with. Only allow-flight was really edited.
    $contents = "motd=hi\nallow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $edit = $this->actingAs($this->admin)->get('/configurations/server.properties');
    $edit->assertOk();

    /** @var array<int, array<string, mixed>> $fields */
    $fields = collect($edit->inertiaProps('guided.groups'))
        ->flatMap(fn (array $group) => $group['fields'])
        ->all();

    $values = [];
    foreach ($fields as $field) {
        $values[$field['path']] = $field['path'] === 'allow-flight' ? true : $field['currentValue'];
    }

    $response = $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', $contents),
        'values' => $values,
    ]);

    $response->assertOk();
    $operation = Operation::query()->sole();

    // The only REAL field-path change is allow-flight — every other
    // schema field's untouched, default-filled control must not appear
    // as a change at all.
    expect($operation->redacted_input['changed_fields'])->toBe(['allow-flight']);

    operationsService()->approve($operation->id, $this->admin);
    operationsService()->execute($operation->id);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=hi\nallow-flight=true\n");
});

/*
|--------------------------------------------------------------------------
| Cross-mode equivalence (ambiguity resolution #2)
|--------------------------------------------------------------------------
*/

it('produces an identical domain change from guided, structured, and source mode for the same edit', function () {
    $original = "motd=hi\nallow-flight=false\n";
    $baseSha = hash('sha256', $original);

    $viaGuided = function () use ($original, $baseSha) {
        TempMinecraftRoot::destroy($this->minecraftRoot);
        $this->minecraftRoot = TempMinecraftRoot::create();
        config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
        file_put_contents($this->minecraftRoot.'/server.properties', $original);

        $this->actingAs($this->admin)->post('/configurations/server.properties', [
            'mode' => 'guided',
            'base_sha256' => $baseSha,
            'values' => ['allow-flight' => true, 'motd' => 'hi'],
        ])->assertOk();

        $operation = Operation::query()->latest()->first();
        operationsService()->approve($operation->id, $this->admin);
        operationsService()->execute($operation->id);

        return file_get_contents($this->minecraftRoot.'/server.properties');
    };

    $resultGuided = $viaGuided();

    Operation::query()->delete();

    $viaStructured = function () use ($original, $baseSha) {
        TempMinecraftRoot::destroy($this->minecraftRoot);
        $this->minecraftRoot = TempMinecraftRoot::create();
        config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
        file_put_contents($this->minecraftRoot.'/server.properties', $original);

        $this->actingAs($this->admin)->post('/configurations/server.properties', [
            'mode' => 'structured',
            'base_sha256' => $baseSha,
            'values' => ['motd' => 'hi', 'allow-flight' => true],
        ])->assertOk();

        $operation = Operation::query()->latest()->first();
        operationsService()->approve($operation->id, $this->admin);
        operationsService()->execute($operation->id);

        return file_get_contents($this->minecraftRoot.'/server.properties');
    };

    $resultStructured = $viaStructured();

    Operation::query()->delete();

    $viaSource = function () use ($original, $baseSha) {
        TempMinecraftRoot::destroy($this->minecraftRoot);
        $this->minecraftRoot = TempMinecraftRoot::create();
        config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
        file_put_contents($this->minecraftRoot.'/server.properties', $original);

        $this->actingAs($this->admin)->post('/configurations/server.properties', [
            'mode' => 'source',
            'base_sha256' => $baseSha,
            'source' => "motd=hi\nallow-flight=true\n",
        ])->assertOk();

        $operation = Operation::query()->latest()->first();
        operationsService()->approve($operation->id, $this->admin);
        operationsService()->execute($operation->id);

        return file_get_contents($this->minecraftRoot.'/server.properties');
    };

    $resultSource = $viaSource();

    expect($resultGuided)->toBe("motd=hi\nallow-flight=true\n")
        ->and($resultStructured)->toBe($resultGuided)
        ->and($resultSource)->toBe($resultGuided);
});

/*
|--------------------------------------------------------------------------
| Conflict -> 409
|--------------------------------------------------------------------------
*/

it('returns 409 with base, disk, and proposed values on a stale edit', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nallow-flight=false\n");

    $response = $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => 'a-stale-hash-from-before-the-file-changed',
        'values' => ['allow-flight' => true, 'motd' => 'hi'],
        'base_source' => "motd=hi\nallow-flight=false\n",
    ]);

    $response->assertStatus(409);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('config/Conflict')
        ->where('path', 'server.properties')
        ->where('disk', "motd=hi\nallow-flight=false\n")
        ->has('proposed', 1)
        ->where('proposed.0.path', 'allow-flight')
        ->where('proposed.0.after', 'true')
    );

    expect(Operation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Metadata-row filtering (ambiguity resolution #5)
|--------------------------------------------------------------------------
*/

it('shows only real field-path changes in the proposal, filtering out generic metadata rows', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nallow-flight=false\n");

    $response = $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', "motd=hi\nallow-flight=false\n"),
        'values' => ['allow-flight' => true, 'motd' => 'hi'],
    ]);

    $response->assertOk();
    $operation = Operation::query()->sole();

    // The Operation really does carry noisy generic rows underneath...
    expect($operation->changeProposals()->pluck('field')->all())->toContain('diff')->toContain('base_sha256');

    // ...but the Inertia prop the UI renders shows only the real one.
    $response->assertInertia(fn (Assert $page) => $page
        ->has('proposal.fields', 1)
        ->where('proposal.fields.0.path', 'allow-flight')
    );
});

/*
|--------------------------------------------------------------------------
| Approve / reject
|--------------------------------------------------------------------------
*/

it('rejects a proposal without ever writing the file', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nallow-flight=false\n");

    $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', "motd=hi\nallow-flight=false\n"),
        'values' => ['allow-flight' => true, 'motd' => 'hi'],
    ])->assertOk();

    $operation = Operation::query()->sole();

    $this->actingAs($this->admin)
        ->post("/configurations/operations/{$operation->id}/reject")
        ->assertRedirect('/configurations/server.properties');

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=hi\nallow-flight=false\n")
        ->and($operation->fresh()->status)->toBe(OperationStatus::Rejected);
});

/*
|--------------------------------------------------------------------------
| History + restore
|--------------------------------------------------------------------------
*/

it('lists revision history and restore builds a fresh reviewable operation, not an immediate write', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nallow-flight=false\n");

    $this->actingAs($this->admin)->post('/configurations/server.properties', [
        'mode' => 'guided',
        'base_sha256' => hash('sha256', "motd=hi\nallow-flight=false\n"),
        'values' => ['allow-flight' => true, 'motd' => 'hi'],
    ])->assertOk();

    $operation = Operation::query()->sole();
    operationsService()->approve($operation->id, $this->admin);
    operationsService()->execute($operation->id);

    $history = $this->actingAs($this->admin)->get('/configurations/history/server.properties');
    $history->assertOk();
    $history->assertInertia(fn (Assert $page) => $page
        ->component('config/History')
        ->has('revisions', 1)
        ->where('revisions.0.kind', 'apply'));

    $revision = ConfigRevision::query()->sole();
    $writtenAfterApply = file_get_contents($this->minecraftRoot.'/server.properties');

    $restoreResponse = $this->actingAs($this->admin)->post("/configurations/revisions/{$revision->id}/restore");
    $restoreResponse->assertRedirect();
    expect($restoreResponse->headers->get('Location'))->toContain('operation=');

    // Restoring never writes immediately — it only creates a new Proposed
    // operation for review, exactly like a normal edit.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe($writtenAfterApply);
    expect(Operation::query()->where('type', 'config.restore')->sole()->status)->toBe(OperationStatus::Proposed);
});
