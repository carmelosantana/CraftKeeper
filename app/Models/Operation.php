<?php

namespace App\Models;

use App\Operations\OperationActorType;
use App\Operations\OperationRisk;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single proposed/approved/executed mutation moving through
 * CraftKeeper's audited operation lifecycle. See App\Operations\OperationService
 * for the only supported way to create or transition one — this model
 * intentionally exposes no business logic of its own beyond persistence
 * and its relations.
 *
 * @property string $id
 * @property OperationType $type
 * @property OperationStatus $status
 * @property string|null $target
 * @property OperationRisk $risk
 * @property OperationActorType $author_type
 * @property string|null $author_id
 * @property string|null $author_origin
 * @property OperationActorType|null $approved_by_type
 * @property string|null $approved_by_id
 * @property Carbon|null $approved_at
 * @property OperationActorType|null $rejected_by_type
 * @property string|null $rejected_by_id
 * @property Carbon|null $rejected_at
 * @property array<string, mixed>|null $redacted_input
 * @property string|null $outcome
 * @property string|null $error_code
 * @property string|null $correlation_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'type', 'status', 'target', 'risk',
    'author_type', 'author_id', 'author_origin',
    'approved_by_type', 'approved_by_id', 'approved_at',
    'rejected_by_type', 'rejected_by_id', 'rejected_at',
    'redacted_input', 'outcome', 'error_code', 'correlation_id',
    'started_at', 'finished_at',
])]
class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'status' => OperationStatus::class,
            'risk' => OperationRisk::class,
            'author_type' => OperationActorType::class,
            'approved_by_type' => OperationActorType::class,
            'rejected_by_type' => OperationActorType::class,
            'redacted_input' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OperationStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(OperationStep::class);
    }

    /**
     * @return HasMany<ChangeProposal, $this>
     */
    public function changeProposals(): HasMany
    {
        return $this->hasMany(ChangeProposal::class);
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
