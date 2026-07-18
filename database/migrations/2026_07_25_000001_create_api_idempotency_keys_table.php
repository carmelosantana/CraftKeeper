<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs Task 17's Idempotency-Key contract for mutation-proposal creation
 * (Step 3 of the task brief): a (token, endpoint, key) tuple is recorded
 * the first time it is seen, pointing at the Operation it created. A
 * repeated request with the same key on the same endpoint from the same
 * token returns that ORIGINAL Operation instead of creating a duplicate —
 * see App\Support\Api\IdempotencyKeyStore. Scoped per PERSONAL ACCESS
 * TOKEN (not per user) so two different tokens belonging to the same admin
 * can never collide on the same key, and per endpoint so a key reused
 * across two different mutation routes is treated as a distinct request
 * rather than silently resolving to an unrelated operation.
 *
 * `request_hash` is a defensive extra beyond the brief's literal
 * requirement: a sha256 of the normalized request body, so a caller that
 * reuses a key with a MEANINGFULLY DIFFERENT payload gets a 409 (an
 * unambiguous "this key was already used for a different request") rather
 * than silently getting back a proposal that doesn't match what they just
 * asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('operations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['personal_access_token_id', 'endpoint', 'idempotency_key'], 'api_idempotency_keys_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
