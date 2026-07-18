<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 18: one row per MCP OAuth integration, exactly one per Passport
 * `oauth_clients` row (`oauth_client_id`) — see App\Models\McpGrant's own
 * docblock for why this table, not the live Passport access token, is the
 * authoritative source App\Policies\McpGrantPolicy enforces against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_grants', function (Blueprint $table) {
            $table->id();
            $table->string('oauth_client_id')->unique();
            $table->string('display_name');
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_grants');
    }
};
