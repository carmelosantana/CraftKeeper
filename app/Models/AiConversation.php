<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI assistant chat thread. `context_scope` records what the operator
 * was looking at when they opened the assistant (a config path and/or
 * plugin id — see resources/js/features/assistant/AssistantDrawer.tsx),
 * purely so App\Ai\ContextBuilder can re-derive the same context on the
 * next turn; it is never itself sent to a provider unredacted.
 *
 * @property string $id
 * @property string|null $title
 * @property string|null $provider
 * @property array<string, mixed>|null $context_scope
 */
#[Fillable(['title', 'provider', 'context_scope'])]
class AiConversation extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_scope' => 'array',
        ];
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('created_at');
    }
}
