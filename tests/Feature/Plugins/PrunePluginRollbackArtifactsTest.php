<?php

use App\Console\Commands\PrunePluginRollbackArtifacts;
use App\Models\PluginRollbackArtifact;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config(['craftkeeper.data_root' => $this->dataRoot]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->dataRoot);
});

function makeRollbackArtifact(string $relativePath, string $content, mixed $createdAt): PluginRollbackArtifact
{
    $dir = rtrim(config('craftkeeper.data_root'), '/').'/plugin-rollbacks/'.str_replace('/', '_', $relativePath);
    File::ensureDirectoryExists($dir, 0755);
    $path = $dir.'/'.uniqid('artifact-', true).'.jar';
    file_put_contents($path, $content);

    $artifact = PluginRollbackArtifact::query()->create([
        'relative_path' => $relativePath,
        'storage_path' => $path,
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'reason' => 'pre-update',
    ]);

    $artifact->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $artifact;
}

it('keeps only the 3 most recent artifacts per plugin, deleting older ones and their bytes', function () {
    config(['craftkeeper.plugins.rollback_retention_count' => 3, 'craftkeeper.plugins.rollback_retention_days' => 3650]);

    $artifacts = [];
    for ($i = 5; $i >= 1; $i--) {
        $artifacts[$i] = makeRollbackArtifact('plugins/Foo.jar', "content-{$i}", now()->subHours($i));
    }

    $this->artisan(PrunePluginRollbackArtifacts::class)->assertExitCode(0);

    // The 3 most recent (i=1,2,3 — smallest subHours = most recent) survive.
    expect(PluginRollbackArtifact::query()->count())->toBe(3)
        ->and(PluginRollbackArtifact::query()->whereIn('id', [$artifacts[1]->id, $artifacts[2]->id, $artifacts[3]->id])->count())->toBe(3);

    // The 2 oldest are gone, including their on-disk bytes.
    expect(PluginRollbackArtifact::find($artifacts[4]->id))->toBeNull()
        ->and(PluginRollbackArtifact::find($artifacts[5]->id))->toBeNull()
        ->and(is_file($artifacts[4]->storage_path))->toBeFalse()
        ->and(is_file($artifacts[5]->storage_path))->toBeFalse();

    // Survivors' bytes are untouched.
    expect(is_file($artifacts[1]->storage_path))->toBeTrue();
});

it('deletes an artifact older than the retention window even if fewer than the keep-count exist for that plugin', function () {
    config(['craftkeeper.plugins.rollback_retention_count' => 3, 'craftkeeper.plugins.rollback_retention_days' => 30]);

    $old = makeRollbackArtifact('plugins/Bar.jar', 'old-content', now()->subDays(31));
    $recent = makeRollbackArtifact('plugins/Bar.jar', 'recent-content', now()->subDay());

    $this->artisan(PrunePluginRollbackArtifacts::class)->assertExitCode(0);

    expect(PluginRollbackArtifact::find($old->id))->toBeNull()
        ->and(is_file($old->storage_path))->toBeFalse()
        ->and(PluginRollbackArtifact::find($recent->id))->not->toBeNull()
        ->and(is_file($recent->storage_path))->toBeTrue();
});

it('prunes each plugin independently — a large history for one plugin does not affect another', function () {
    config(['craftkeeper.plugins.rollback_retention_count' => 1, 'craftkeeper.plugins.rollback_retention_days' => 3650]);

    makeRollbackArtifact('plugins/A.jar', 'a-old', now()->subHours(2));
    $aRecent = makeRollbackArtifact('plugins/A.jar', 'a-recent', now()->subHour());
    $bOnly = makeRollbackArtifact('plugins/B.jar', 'b-only', now()->subHour());

    $this->artisan(PrunePluginRollbackArtifacts::class)->assertExitCode(0);

    expect(PluginRollbackArtifact::query()->where('relative_path', 'plugins/A.jar')->count())->toBe(1)
        ->and(PluginRollbackArtifact::query()->where('relative_path', 'plugins/A.jar')->first()->id)->toBe($aRecent->id)
        ->and(PluginRollbackArtifact::find($bOnly->id))->not->toBeNull();
});
