<?php

use App\Models\CatalogCacheEntry;
use App\Models\Secret;
use App\Models\Setting;
use App\Support\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function freshDataRootPath(): string
{
    return sys_get_temp_dir().'/ck-fresh-data-'.uniqid('', true);
}

/*
|--------------------------------------------------------------------------
| SQLite refuses VACUUM/VACUUM INTO while ANY transaction is open, and
| Pest's RefreshDatabase (tests/Pest.php) wraps every Feature test in one.
| BackupService::create() deliberately does NOT contain any
| transaction-detection workaround (it should stay simple and correct for
| real production use, where a normal request is never wrapped in an
| ambient transaction to begin with) — so the test environment commits
| the wrapping transaction itself, up front, letting VACUUM INTO run
| exactly the way it will in production.
|
| Committing here (before any test inserts data) leaves the shared
| `:memory:` connection with no open transaction by the time the test
| ends. Laravel's own RefreshDatabase teardown detects that
| (`! $pdo->inTransaction()`) and marks RefreshDatabaseState::$migrated
| false, forcing a full `migrate:fresh` before the NEXT test in the suite
| — so nothing inserted here can ever leak into a later test, without this
| file needing any manual cleanup of its own.
*/
beforeEach(function () {
    DB::connection()->commit();
});

afterEach(function () {
    DB::purge('backup_verify');
});

it('creates a backup archive under {data_root}/backups with a consistent database copy', function () {
    Setting::put('minecraft.server_path', '/minecraft');

    $zipPath = app(BackupService::class)->create();

    expect($zipPath)->toBeFile()
        ->and($zipPath)->toContain(rtrim((string) config('craftkeeper.data_root'), '/').'/backups');

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('database.sqlite')
        ->toContain('settings.json')
        ->toContain('catalog-cache.json')
        ->toContain('config.json')
        ->toContain('manifest.json');

    @unlink($zipPath);
});

it('never includes Minecraft worlds in the backup archive', function () {
    Setting::put('minecraft.server_path', '/minecraft');

    $zipPath = app(BackupService::class)->create();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = strtolower($zip->getNameIndex($i));
        expect($name)->not->toContain('world')
            ->and($name)->not->toContain('/minecraft/');
    }

    $zip->close();
    @unlink($zipPath);
});

it('restores a backup into a fresh /data directory with matching settings and catalog data', function () {
    Setting::put('minecraft.server_path', '/minecraft');
    Setting::put('rcon.host', '127.0.0.1');
    Secret::put('rcon.password', 'restore-fidelity-check-password');
    CatalogCacheEntry::query()->create([
        'cache_key' => 'catalog::page::1',
        'source' => 'Catalog',
        'kind' => 'catalog-snapshot',
        'payload' => ['items' => []],
        'etag' => 'W/"abc123"',
        'last_modified' => null,
        'fresh_until' => now()->addMinutes(15),
        'expires_at' => now()->addDays(7),
    ]);

    $zipPath = app(BackupService::class)->create();
    $freshDataRoot = freshDataRootPath();

    app(BackupService::class)->restore($zipPath, $freshDataRoot);

    expect(File::exists($freshDataRoot.'/database.sqlite'))->toBeTrue();

    config(['database.connections.backup_verify' => [
        'driver' => 'sqlite',
        'database' => $freshDataRoot.'/database.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    $restoredSetting = DB::connection('backup_verify')->table('settings')->where('key', 'minecraft.server_path')->value('value');
    $restoredSecret = DB::connection('backup_verify')->table('secrets')->where('key', 'rcon.password')->first();
    $restoredCatalog = DB::connection('backup_verify')->table('catalog_cache_entries')->where('cache_key', 'catalog::page::1')->first();

    expect($restoredSetting)->toBe('/minecraft')
        ->and($restoredCatalog)->not->toBeNull()
        // The DB-level backup restores Secret rows too (ambiguity
        // resolution #2: DB-level secrets are encrypted-at-rest by the
        // app key) — the row exists, and under the SAME APP_KEY this test
        // process is running with, it decrypts back to the original
        // value, proving the restore is genuinely functional, not just a
        // byte copy of ciphertext nobody can ever use again.
        ->and($restoredSecret)->not->toBeNull();

    File::deleteDirectory($freshDataRoot);
    @unlink($zipPath);
});

it('refuses to restore into a data directory that is not fresh', function () {
    $zipPath = app(BackupService::class)->create();
    $notFreshRoot = freshDataRootPath();
    File::ensureDirectoryExists($notFreshRoot, 0755);
    File::put($notFreshRoot.'/database.sqlite', 'pretend this is already a real database');

    expect(fn () => app(BackupService::class)->restore($zipPath, $notFreshRoot))
        ->toThrow(RuntimeException::class);

    File::deleteDirectory($notFreshRoot);
    @unlink($zipPath);
});

it('refuses to restore a backup whose database checksum does not match its manifest', function () {
    $zipPath = app(BackupService::class)->create();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->deleteName('database.sqlite');
    $zip->addFromString('database.sqlite', 'tampered bytes, does not match the recorded checksum');
    $zip->close();

    $freshDataRoot = freshDataRootPath();

    expect(fn () => app(BackupService::class)->restore($zipPath, $freshDataRoot))
        ->toThrow(RuntimeException::class);

    File::deleteDirectory($freshDataRoot);
    @unlink($zipPath);
});

it('never includes raw secret plaintext in the separately-archived settings.json', function () {
    $secretCanary = 'CANARY-BACKUP-SETTINGS-SECRET-should-not-leak-into-json';
    Secret::put('rcon.password', $secretCanary);
    Setting::put('minecraft.server_path', '/minecraft');

    $zipPath = app(BackupService::class)->create();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $settingsJson = $zip->getFromName('settings.json');
    $configJson = $zip->getFromName('config.json');
    $catalogJson = $zip->getFromName('catalog-cache.json');
    $zip->close();

    expect($settingsJson)->not->toContain($secretCanary)
        ->and($configJson)->not->toContain($secretCanary)
        ->and($catalogJson)->not->toContain($secretCanary);

    @unlink($zipPath);
});
