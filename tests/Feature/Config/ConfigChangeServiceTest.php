<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Models\ChangeProposal;
use App\Models\ConfigChangePayload;
use App\Models\Operation;
use App\Operations\OperationAuthor;
use App\Operations\OperationRisk;
use App\Operations\OperationStatus;
use Illuminate\Support\Facades\DB;
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

/*
|--------------------------------------------------------------------------
| Happy path: proposal contents
|--------------------------------------------------------------------------
*/

it('creates a Proposed operation with base hash, diff, restart impact, and documentation citations', function () {
    $contents = "motd=hi\nallow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('allow-flight', true),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->type->value)->toBe('config.apply')
        ->and($operation->target)->toBe('server.properties')
        ->and($operation->redacted_input['base_sha256'])->toBe(hash('sha256', $contents))
        ->and($operation->redacted_input['changed_fields'])->toBe(['allow-flight'])
        ->and($operation->redacted_input['valid'])->toBeTrue()
        ->and($operation->redacted_input['restart_impact'])->toBe('restart')
        ->and($operation->redacted_input['diff'])->toContain('-allow-flight=false')
        ->and($operation->redacted_input['diff'])->toContain('+allow-flight=true')
        ->and($operation->redacted_input['documentation'][0]['path'])->toBe('allow-flight')
        ->and($operation->redacted_input['documentation'][0]['url'])->toContain('minecraft.wiki')
        ->and($operation->redacted_input['expires_at'])->not->toBeNull();
});

it('classifies risk as elevated when a changed field is schema-flagged high risk', function () {
    $contents = "rcon.password=old\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'new-secret-value'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    expect($operation->risk)->toBe(OperationRisk::Elevated);
});

it('records exactly one rich ChangeProposal row per changed field', function () {
    $contents = "motd=hi\nallow-flight=false\npvp=true\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('allow-flight', true),
        ConfigChange::replace('pvp', false),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    $proposals = ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'allow-flight')->orWhere('field', 'pvp')->get();

    expect(ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'allow-flight')->sole()->before)->toBe('false')
        ->and(ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'allow-flight')->sole()->after)->toBe('true')
        ->and(ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'pvp')->sole()->before)->toBe('true')
        ->and(ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'pvp')->sole()->after)->toBe('false');
});

/*
|--------------------------------------------------------------------------
| willNormalize() warning surfaced on the proposal
|--------------------------------------------------------------------------
*/

it('records a normalization warning on the proposal when the adapter reports it will reformat the file', function () {
    // A brand-new NESTED key on a YAML file always falls back to a full
    // structural re-serialize (YamlAdapter::willNormalize() === true),
    // per Task 7's documented "normalize" classification.
    $contents = "settings:\n  debug: false\n";
    file_put_contents($this->minecraftRoot.'/config.yml', $contents);

    $request = new ConfigChangeRequest('config.yml', hash('sha256', $contents), [
        ConfigChange::add('settings.new-nested.value', 'x'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    $warnings = array_filter($operation->redacted_input['diagnostics'], fn ($d) => $d['severity'] === 'warning');

    expect(collect($warnings)->pluck('message')->implode(' '))->toContain('reformat');
});

/*
|--------------------------------------------------------------------------
| Validation prevents approval: InvalidConfigChange is caught, not thrown
|--------------------------------------------------------------------------
*/

it('catches an unrepresentable change as a validation failure on the proposal instead of throwing a 500', function () {
    // TOML has no null/nil scalar type at all — a null value always
    // throws InvalidConfigChange from TomlAdapter::applyChanges().
    $contents = "name = \"old\"\n";
    file_put_contents($this->minecraftRoot.'/plugin.toml', $contents);

    $request = new ConfigChangeRequest('plugin.toml', hash('sha256', $contents), [
        ConfigChange::replace('name', null),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    expect($operation)->toBeInstanceOf(Operation::class)
        ->and($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->redacted_input['valid'])->toBeFalse();

    $errors = array_filter($operation->redacted_input['diagnostics'], fn ($d) => $d['severity'] === 'error');
    expect($errors)->not->toBeEmpty();
});

it('never writes the file when the requested change is invalid', function () {
    $contents = "name = \"old\"\n";
    file_put_contents($this->minecraftRoot.'/plugin.toml', $contents);

    $request = new ConfigChangeRequest('plugin.toml', hash('sha256', $contents), [
        ConfigChange::replace('name', null),
    ]);

    app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    expect(file_get_contents($this->minecraftRoot.'/plugin.toml'))->toBe($contents);
});

/*
|--------------------------------------------------------------------------
| Secret redaction: diff, ChangeProposal, redacted_input all masked,
| while the encrypted raw payload holds the real value.
|--------------------------------------------------------------------------
*/

it('masks a secret field everywhere in the proposal but stores the real value encrypted for execution', function () {
    $contents = "rcon.password=old-secret\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    // The stored (already-redacted) operation metadata.
    expect($operation->redacted_input['diff'])
        ->not->toContain('old-secret')
        ->not->toContain('brand-new-secret')
        ->toContain('••••••');

    // The rich ChangeProposal row for the secret field.
    $proposal = ChangeProposal::query()->where('operation_id', $operation->id)->where('field', 'rcon.password')->sole();
    expect($proposal->before)->toBe('••••••')
        ->and($proposal->after)->toBe('••••••');

    // Nothing on the Operation row (serialized) ever contains either value.
    $serialized = json_encode($operation->toArray());
    expect($serialized)->not->toContain('old-secret')->not->toContain('brand-new-secret');

    // But the REAL value is available, encrypted, to whatever executes
    // the approved operation.
    $payload = ConfigChangePayload::query()->where('operation_id', $operation->id)->sole();
    expect($payload->changes)->toBe([
        ['kind' => 'replace', 'path' => 'rcon.password', 'value' => 'brand-new-secret'],
    ]);

    // And the raw database column truly is ciphertext, not plaintext.
    $rawColumn = DB::table('config_change_payloads')->where('operation_id', $operation->id)->value('changes');
    expect($rawColumn)->not->toContain('brand-new-secret');
});

it('hides ConfigChangePayload::changes from array/json serialization', function () {
    $contents = "rcon.password=old-secret\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));
    $payload = ConfigChangePayload::query()->where('operation_id', $operation->id)->sole();

    expect($payload->toArray())->not->toHaveKey('changes');
    expect(json_encode($payload))->not->toContain('brand-new-secret');
});
