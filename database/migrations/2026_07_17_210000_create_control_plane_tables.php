<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Four tables back the operation lifecycle: `operations` is the
     * durable record of every proposed/approved/executed mutation,
     * `operation_steps` tracks execution progress within one operation,
     * `change_proposals` holds the (already redacted) proposed change set,
     * and `audit_events` is the append-only trail tied to an operation.
     *
     * Every persisted "input"-shaped column (`redacted_input`,
     * `change_proposals.before/after`, `audit_events.payload`) holds only
     * pre-redacted data — see App\Operations\InputRedactor — never raw
     * secret values, even encrypted. Real secrets live exclusively in the
     * `secrets` table (Task 4).
     */
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('status');
            $table->string('target')->nullable();
            $table->string('risk')->default('standard');

            $table->string('author_type');
            $table->string('author_id')->nullable();
            $table->string('author_origin')->nullable();

            $table->string('approved_by_type')->nullable();
            $table->string('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->string('rejected_by_type')->nullable();
            $table->string('rejected_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->json('redacted_input')->nullable();
            $table->text('outcome')->nullable();
            $table->string('error_code')->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('correlation_id');
        });

        Schema::create('operation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('name');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('output')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });

        Schema::create('change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->string('field')->nullable();
            $table->string('summary');
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('event_type');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('actor_origin')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('change_proposals');
        Schema::dropIfExists('operation_steps');
        Schema::dropIfExists('operations');
    }
};
