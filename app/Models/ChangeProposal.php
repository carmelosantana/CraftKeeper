<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One redacted, human-reviewable field change proposed by an Operation.
 * OperationService derives a flat set of these from an OperationRequest's
 * (already redacted) metadata at propose() time; domain-specific services
 * built on top (e.g. Task 8's ConfigChangeService) may create richer ones
 * of their own with real before/after values for fields that are not
 * secret.
 *
 * `before`/`after` are free-form redacted text, never raw secret values —
 * see App\Operations\InputRedactor.
 *
 * @property int $id
 * @property string $operation_id
 * @property string|null $field
 * @property string $summary
 * @property string|null $before
 * @property string|null $after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['operation_id', 'field', 'summary', 'before', 'after'])]
class ChangeProposal extends Model
{
    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
