<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an App\Models\AiConversation. `content` is always safe to
 * display and safe to re-send as history: a user turn is passed through
 * App\Ai\SecretRedactor before it is ever persisted here (the operator
 * could have pasted a real secret into the chat box), and an assistant
 * turn is the model's own generated text. `tool_calls` is a redacted
 * summary of any tool CraftKeeper's AI agent invoked during this turn
 * (name, arguments, resulting operation id where relevant) — never a raw
 * secret, since none of App\Ai\Tools\* ever returns one.
 * `redaction_disclosures` records exactly what App\Ai\SecretRedactor
 * masked before this turn's context left CraftKeeper, so the UI's
 * RedactionDisclosure component can show it per-turn rather than only
 * once for the whole conversation.
 *
 * @property string $id
 * @property string $ai_conversation_id
 * @property string $role
 * @property string $content
 * @property list<array{title: string, url: string}>|null $citations
 * @property list<array<string, mixed>>|null $tool_calls
 * @property list<array{label: string|null, occurrences: int}>|null $redaction_disclosures
 * @property string|null $provider
 * @property string|null $error
 */
#[Fillable(['ai_conversation_id', 'role', 'content', 'citations', 'tool_calls', 'redaction_disclosures', 'provider', 'error'])]
class AiMessage extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'tool_calls' => 'array',
            'redaction_disclosures' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
