<?php

use Illuminate\Support\Facades\File;

it('reports application and database readiness', function () {
    $this->getJson('/up')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['checks' => ['database', 'data_directory']]);
});

it('creates the configured data directory on demand and reports it writable', function () {
    $path = storage_path('craftkeeper-test-'.uniqid());

    config(['craftkeeper.data_root' => $path]);

    expect(is_dir($path))->toBeFalse();

    $this->getJson('/up')
        ->assertOk()
        ->assertJsonPath('checks.data_directory.status', 'ok')
        ->assertJsonPath('checks.data_directory.path', $path);

    expect(is_dir($path))->toBeTrue()
        ->and(is_writable($path))->toBeTrue();

    File::deleteDirectory($path);
});

it('reports the data directory check as failing when the path cannot be made writable', function () {
    $parent = storage_path('craftkeeper-test-'.uniqid());
    mkdir($parent, 0500, true);

    config(['craftkeeper.data_root' => $parent.'/nested']);

    $this->getJson('/up')
        ->assertStatus(503)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('checks.data_directory.status', 'error');

    chmod($parent, 0700);
    File::deleteDirectory($parent);
});

it('reports the database check as failing when the connection cannot be established', function () {
    config([
        'database.connections.health_check_unreachable' => [
            'driver' => 'sqlite',
            'database' => storage_path('craftkeeper-missing-'.uniqid().'.sqlite'),
            'prefix' => '',
        ],
        'database.default' => 'health_check_unreachable',
    ]);

    $this->getJson('/up')
        ->assertStatus(503)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('checks.database.status', 'error');

    config(['database.default' => 'sqlite']);
});
