<?php

namespace App\Models;

use App\Operations\Exceptions\AuditEventImmutable;
use App\Operations\OperationActorType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only audit trail entry. CraftKeeper never edits or deletes
 * history: `updating`/`deleting` model events are blocked below so the
 * audit trail cannot be tampered with by any application code path,
 * however it gets there — this class exposes creation only, no update or
 * delete API. (This guard operates at the application/Eloquent layer, per
 * the plan; a raw mass `AuditEvent::query()->delete()` bypasses Eloquent
 * model events entirely — a general Eloquent limitation, not specific to
 * this model — and is out of this task's scope.)
 *
 * @property int $id
 * @property string|null $operation_id
 * @property string $event_type
 * @property OperationActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_origin
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 */
#[Fillable(['operation_id', 'event_type', 'actor_type', 'actor_id', 'actor_origin', 'payload'])]
class AuditEvent extends Model
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw AuditEventImmutable::forUpdate();
        });

        static::deleting(function (): never {
            throw AuditEventImmutable::forDelete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => OperationActorType::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
