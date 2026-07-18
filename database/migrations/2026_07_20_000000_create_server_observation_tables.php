<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Four tables back Task 11's bounded server observation (ambiguity
     * resolutions #2, #3, #4, #5): a fixed-cadence RCON sample history, a
     * player identity table, a player join/leave/kick/chat event log, and
     * a bounded recent console buffer. None of these are intended as
     * long-term/indefinite storage — App\Console\Commands\
     * PruneServerObservationData deletes rows past their retention window
     * (7 days for server_samples, 30 for player_events, 24 hours for
     * console_entries) on a daily schedule, and console_entries is ALSO
     * kept bounded synchronously by row count on every write (see
     * App\Server\LogTailService::pruneConsoleEntries()) so it can never
     * grow unbounded between prune runs.
     */
    public function up(): void
    {
        Schema::create('server_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->boolean('rcon_reachable');
            // Deliberately nullable, never defaulted to 0: an unreachable
            // (or unparseable) RCON response must never be represented as
            // "0 players online" — see App\Server\ServerStatusService and
            // Task 11's ambiguity resolution #5.
            $table->unsignedInteger('player_count')->nullable();
            $table->json('player_names')->nullable();
            $table->string('error_reason')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            // The exact username string as observed in console output —
            // never a looked-up or fabricated Mojang/Xbox UUID (Task 11's
            // ambiguity resolution #4).
            $table->string('username')->unique();
            $table->string('platform');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
        });

        Schema::create('player_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('kind');
            $table->string('platform')->nullable();
            $table->text('message')->nullable();
            // Sanitized/ANSI-stripped and bounded to the same 16 KiB cap
            // as console_entries.line before it ever reaches this column
            // — see App\Server\LogTailService.
            $table->text('raw_line');
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('console_entries', function (Blueprint $table) {
            $table->id();
            // Sanitized (ANSI/control-stripped) and capped at 16 KiB —
            // App\Server\LogTailService::MAX_ENTRY_BYTES. This is what is
            // both persisted here AND broadcast on the private
            // `server.console` channel — never the unbounded original.
            $table->text('line');
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('console_entries');
        Schema::dropIfExists('player_events');
        Schema::dropIfExists('players');
        Schema::dropIfExists('server_samples');
    }
};
