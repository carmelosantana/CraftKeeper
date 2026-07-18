<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two tables back Task 13's plugin inspection/inventory.
     *
     * `plugin_installations` is one row per plugin JAR
     * App\Plugins\PluginInventoryService has ever seen on disk, keyed by
     * its LOGICAL relative path — always the enabled form (e.g.
     * "plugins/EssentialsX.jar"), even while the file itself currently
     * sits at "...jar.disabled" (see App\Plugins\DiscoveredPlugin's
     * docblock for why). Carries the last App\Plugins\JarInspector
     * reading plus the last computed App\Plugins\PluginCompatibilityService
     * assessment. Never deleted by reconciliation when its file
     * disappears — `missing_since` is set instead, so provenance/history
     * survives a file being moved away and back (see
     * PluginInventoryService::reconcile()).
     *
     * `plugin_artifacts` is the content-addressed table Task 14/15
     * populate on downloading a JAR from a known source (CraftKeeper
     * Catalog, Hangar, Modrinth). Task 13 only ever READS this table (by
     * sha256) to decide whether a changed checksum should be attributed
     * to that known source rather than silently overwriting a "Manual"
     * provenance — see PluginInventoryService::resolveProvenanceForChange().
     * Nothing in this task writes a row here.
     */
    public function up(): void
    {
        Schema::create('plugin_installations', function (Blueprint $table) {
            $table->id();
            $table->string('relative_path')->unique();
            $table->string('name')->nullable()->index();
            $table->string('version')->nullable();
            $table->string('main_class')->nullable();
            $table->string('api_version')->nullable();
            $table->json('hard_dependencies');
            $table->json('soft_dependencies');
            // 'paper-plugin.yml' | 'plugin.yml' | null (no metadata found).
            $table->string('metadata_source')->nullable();
            $table->string('sha256')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('file_modified_at')->nullable();
            $table->boolean('enabled')->default(true);
            // Plan's provenance vocabulary applied to plugins: Manual,
            // Catalog, Hangar, Modrinth — see App\Plugins\PluginProvenance.
            $table->string('provenance')->default('Manual');
            $table->boolean('duplicate_name')->default(false);
            $table->json('inspection_diagnostics');
            // 'compatible' | 'incompatible' | 'unknown' | 'warning' | null
            // (not yet assessed) — see App\Plugins\PluginCompatibilityState.
            $table->string('compatibility_state')->nullable();
            $table->json('compatibility_evidence');
            $table->timestamp('last_seen_at')->nullable();
            // Set the moment a previously-tracked file disappears from
            // disk; cleared the moment it reappears at the same logical
            // path. Never deleted outright by reconciliation.
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });

        Schema::create('plugin_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('sha256')->unique();
            $table->unsignedBigInteger('size_bytes');
            // Which known source this exact byte sequence came from
            // (e.g. "Hangar"); null for an artifact recorded without a
            // known source.
            $table->string('source')->nullable();
            $table->string('version')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin_artifacts');
        Schema::dropIfExists('plugin_installations');
    }
};
