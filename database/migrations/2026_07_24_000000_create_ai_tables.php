<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task 16: the AI assistant's own durable state. `ai_conversations` is
     * one chat thread; `ai_messages` holds every turn (user and assistant)
     * within it, plus citations and a per-message redaction disclosure so
     * the UI can always show exactly what was sent to a provider for that
     * turn — never just for the conversation as a whole.
     *
     * Every column here is safe-by-construction: `content` is either the
     * operator's own typed text (already passed through
     * App\Ai\SecretRedactor before being persisted — see
     * App\Ai\AssistantService) or the assistant's own generated answer,
     * `tool_calls` is a redacted summary (tool name, arguments, and
     * resulting operation id — never a raw secret, since none of the three
     * AI tools ever return one), and `citations`/`redaction_disclosures`
     * are metadata about what happened, not raw content. No column here
     * ever holds a plaintext secret value.
     */
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->string('provider')->nullable();
            $table->json('context_scope')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role');
            $table->longText('content');
            $table->json('citations')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('redaction_disclosures')->nullable();
            $table->string('provider')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index('ai_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
