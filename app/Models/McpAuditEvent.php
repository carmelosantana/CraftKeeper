<?php

namespace App\Models;

use App\Mcp\Exceptions\McpAuditEventImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only record of a single MCP JSON-RPC tool/resource/prompt
 * invocation — Task 18's full-audit requirement: client (via
 * `mcp_grant_id`), tool/resource, scope decision, correlation id,
 * REDACTED arguments, duration, and outcome. Mirrors App\Models\
 * AuditEvent's immutability guarantee (no update/delete, ever) for the
 * same reason: history must never be edited or deleted by any
 * application code path.
 *
 * `mcp_grant_id` is nullable because a request that never resolved to any
 * grant at all (no bearer token, or a token for a client with no
 * App\Models\McpGrant row) is still audited — denied, but recorded, never
 * silently dropped.
 *
 * `arguments` is ALWAYS the output of App\Operations\InputRedactor::redact()
 * before it reaches this column — see App\Mcp\Support\McpGuard, the only
 * writer of this model. A raw secret must never appear here.
 *
 * @property int $id
 * @property int|null $mcp_grant_id
 * @property string $subject_type
 * @property string $subject_name
 * @property string|null $scope
 * @property string $correlation_id
 * @property array<string, mixed>|null $arguments
 * @property string $outcome
 * @property string|null $denial_reason
 * @property int $duration_ms
 * @property Carbon|null $created_at
 */
#[Fillable([
    'mcp_grant_id', 'subject_type', 'subject_name', 'scope', 'correlation_id',
    'arguments', 'outcome', 'denial_reason', 'duration_ms',
])]
class McpAuditEvent extends Model
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw McpAuditEventImmutable::forUpdate();
        });

        static::deleting(function (): never {
            throw McpAuditEventImmutable::forDelete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<McpGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(McpGrant::class, 'mcp_grant_id');
    }
}
