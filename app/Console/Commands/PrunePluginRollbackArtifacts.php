<?php

namespace App\Console\Commands;

use App\Models\PluginRollbackArtifact;
use Illuminate\Console\Command;

/**
 * Task 15's ambiguity resolution #3: "Keep 3 artifacts per plugin for 30
 * days" — enforced as a conjunction, not two independent limits: an
 * artifact survives only while it is BOTH within the most recent
 * `rollback_retention_count` preserved for its plugin AND newer than
 * `rollback_retention_days` old. Whichever bound is more restrictive for
 * a given artifact prunes it first. Run daily via ->withSchedule() in
 * bootstrap/app.php, mirroring App\Console\Commands\
 * PruneServerObservationData's own daily-prune shape (Task 11).
 *
 * Deletes the on-disk JAR under {data_root}/plugin-rollbacks BEFORE the
 * database row, so a process interrupted mid-run never leaves a
 * dangling row pointing at already-deleted bytes without also being
 * re-prunable on the next run (the row would simply fail to find its
 * file and still get deleted).
 */
class PrunePluginRollbackArtifacts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'plugins:prune-rollback-artifacts';

    /**
     * @var string
     */
    protected $description = 'Prune preserved plugin rollback artifacts past retention (3 per plugin, 30 days).';

    public function handle(): int
    {
        $keep = (int) config('craftkeeper.plugins.rollback_retention_count');
        $days = (int) config('craftkeeper.plugins.rollback_retention_days');
        $cutoff = now()->subDays($days);

        $deleted = 0;

        $relativePaths = PluginRollbackArtifact::query()->distinct()->pluck('relative_path');

        foreach ($relativePaths as $relativePath) {
            $artifacts = PluginRollbackArtifact::query()
                ->where('relative_path', $relativePath)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            foreach ($artifacts as $index => $artifact) {
                $tooOld = $artifact->created_at !== null && $artifact->created_at->lt($cutoff);
                $beyondKeepCount = $index >= $keep;

                if (! $tooOld && ! $beyondKeepCount) {
                    continue;
                }

                if (is_file($artifact->storage_path)) {
                    @unlink($artifact->storage_path);
                }

                $artifact->delete();
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} plugin rollback artifact(s).");

        return self::SUCCESS;
    }
}
