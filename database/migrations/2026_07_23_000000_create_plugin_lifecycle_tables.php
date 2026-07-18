<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two tables back Task 15's plugin lifecycle: the mirror of Task 8's
     * App\Models\ConfigChangePayload pattern, adapted for plugins.
     *
     * `plugin_operation_plans` is App\Plugins\PluginLifecycleService's own
     * "the rich, reviewable plan behind one plugin.* Operation" record —
     * created right after App\Operations\OperationService::propose()
     * returns a real Operation id (mirroring App\Config\
     * ConfigChangeService's propose-then-attach-payload order), one row
     * per plugin.install/update/disable/remove/rollback Operation.
     * `quarantine_path` points at the verified, already-checksummed
     * artifact awaiting App\Operations\Handlers\PluginOperationHandler's
     * atomic install/update (null for disable/remove/rollback, which move
     * or restore an ALREADY-installed file rather than staging a new
     * one). `plan` is the full install-plan JSON the UI renders (artifact
     * identity, source, checksum, compatibility evidence, dependencies,
     * conflicts, file changes, rollback artifact, restart requirement) —
     * never a secret-shaped payload, so (unlike ConfigChangePayload) this
     * is plain JSON, not encrypted, and is kept after the operation goes
     * terminal for history/audit display; only the on-disk quarantine
     * FILE is deleted at that point (`quarantine_cleaned_at`) — see
     * App\Plugins\PluginLifecycleService::cleanupQuarantineFor().
     *
     * `plugin_rollback_artifacts` is where a JAR PluginOperationHandler
     * preserved before overwriting/removing it lives —
     * {data_root}/plugin-rollbacks/{...} — keyed by the plugin's logical
     * relative_path so App\Console\Commands\PrunePluginRollbackArtifacts
     * can enforce "keep 3 per plugin for 30 days"
     * (`craftkeeper.plugins.rollback_retention_*`).
     */
    public function up(): void
    {
        Schema::create('plugin_operation_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            // 'install' | 'update' | 'disable' | 'remove' | 'rollback'.
            $table->string('kind');
            // The logical plugins/ relative path this operation targets
            // (existing, for update/disable/remove/rollback; the
            // PROSPECTIVE path for a brand-new install).
            $table->string('target_relative_path');
            // App\Plugins\PluginProvenance value, or null for a manual
            // upload with no catalog source.
            $table->string('source')->nullable();
            $table->string('release_name')->nullable();
            $table->string('release_version')->nullable();
            // Absolute path to the verified artifact.jar awaiting
            // install/update, or null once cleaned up / not applicable.
            $table->string('quarantine_path')->nullable();
            $table->string('verified_sha256')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // FK-ish (by id) into plugin_rollback_artifacts — the
            // artifact a plugin.rollback operation will restore, or the
            // artifact THIS operation preserved (install/update/remove),
            // populated once execute() actually preserves one.
            $table->unsignedBigInteger('rollback_artifact_id')->nullable();
            // The full install plan the UI renders — see class docblock.
            $table->json('plan');
            $table->timestamp('quarantine_cleaned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plugin_rollback_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('relative_path')->index();
            $table->string('storage_path');
            $table->string('sha256');
            $table->unsignedBigInteger('size_bytes');
            $table->uuid('source_operation_id')->nullable();
            // 'pre-update' | 'pre-remove' — why this artifact was
            // preserved; disable never preserves a copy here (it renames
            // in place to .jar.disabled, never losing the bytes).
            $table->string('reason');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin_rollback_artifacts');
        Schema::dropIfExists('plugin_operation_plans');
    }
};
