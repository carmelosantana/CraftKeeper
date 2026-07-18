<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One table backs Task 10's secret-shaped-command redaction: when a
     * console command's raw text matches App\Console\CommandPolicy::
     * looksLikeSecret() (e.g. a plugin's `/login <password>` command),
     * the operation's own `target`/`redacted_input` only ever hold a
     * category + redacted display value (Task 10's ambiguity resolution
     * #6) — the real text needed to actually run the command over RCON
     * lives here instead, encrypted at rest via Laravel's `encrypted`
     * cast, exactly mirroring `config_change_payloads` (Task 8). See
     * App\Models\RconCommandPayload's docblock for the full reasoning.
     */
    public function up(): void
    {
        Schema::create('rcon_command_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('operations')->cascadeOnDelete();
            // Laravel's `encrypted` cast (see App\Models\RconCommandPayload)
            // encrypts this column's value application-side before it
            // ever reaches the database. The column itself only ever
            // stores AES-256-GCM ciphertext.
            $table->text('command');
            $table->timestamps();

            $table->unique('operation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rcon_command_payloads');
    }
};
