<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Three tables back Task 8's reversible config operations:
     * `config_files` is the lightweight registry a path's history hangs
     * off of, `config_revisions` is one row per successfully applied/
     * restored change (pointing at a captured snapshot under
     * {DATA_ROOT}/snapshots/ for the real content, plus pre-redacted
     * display text), and `config_change_payloads` holds the one genuinely
     * raw, secret-capable value in this task — the real field-level
     * change set behind a still-pending-or-executing operation — encrypted
     * at rest via Laravel's `encrypted:array` cast (see
     * App\Models\ConfigChangePayload's docblock for why this is a
     * dedicated table rather than a column on `operations`).
     */
    public function up(): void
    {
        Schema::create('config_files', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('format');
            $table->string('schema_id')->nullable();
            $table->timestamps();
        });

        Schema::create('config_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_file_id')->constrained('config_files')->cascadeOnDelete();
            $table->foreignUuid('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            // 'apply' | 'restore' — see App\Config\ConfigChangeService.
            $table->string('kind')->default('apply');
            $table->string('sha256');
            $table->string('snapshot_path');
            $table->string('summary')->nullable();
            $table->text('redacted_diff')->nullable();
            $table->string('restart_impact')->nullable();
            $table->string('risk')->nullable();
            $table->string('author_type')->nullable();
            $table->string('author_id')->nullable();
            $table->string('author_origin')->nullable();
            $table->timestamps();

            $table->index('sha256');
        });

        Schema::create('config_change_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('operations')->cascadeOnDelete();
            // Laravel's `encrypted:array` cast (see App\Models\
            // ConfigChangePayload) encrypts this column's value
            // application-side before it ever reaches the database. The
            // column itself only ever stores AES-256-GCM ciphertext.
            $table->text('changes');
            $table->timestamps();

            $table->unique('operation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_change_payloads');
        Schema::dropIfExists('config_revisions');
        Schema::dropIfExists('config_files');
    }
};
