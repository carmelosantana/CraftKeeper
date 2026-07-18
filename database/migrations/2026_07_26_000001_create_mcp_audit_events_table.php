<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 18: one append-only row per MCP JSON-RPC tool/resource invocation
 * — client (via mcp_grant_id), subject, scope decision, correlation id,
 * REDACTED arguments, duration, and outcome. See App\Models\McpAuditEvent
 * and App\Mcp\Support\McpGuard (the only writer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_grant_id')->nullable()->constrained('mcp_grants')->nullOnDelete();
            $table->string('subject_type');
            $table->string('subject_name');
            $table->string('scope')->nullable();
            $table->string('correlation_id');
            $table->json('arguments')->nullable();
            $table->string('outcome');
            $table->string('denial_reason')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_events');
    }
};
