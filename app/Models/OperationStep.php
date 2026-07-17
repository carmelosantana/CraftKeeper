<?php

namespace App\Models;

use App\Operations\OperationStepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step within an Operation's execution (e.g. "execute", or a
 * handler-defined finer-grained step such as "download"/"verify"/"stage").
 * Populated by OperationService and, later, by concrete OperationHandler
 * implementations that want to report finer-grained progress than a
 * single execute step.
 *
 * @property int $id
 * @property string $operation_id
 * @property int $sequence
 * @property string $name
 * @property OperationStepStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $output
 * @property string|null $error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['operation_id', 'sequence', 'name', 'status', 'started_at', 'finished_at', 'output', 'error_code'])]
class OperationStep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OperationStepStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output' => 'array',
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
