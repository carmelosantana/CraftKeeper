<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Records a (token, endpoint, Idempotency-Key) tuple the first time it is
 * seen for a mutation-proposal-creating /api/v1 endpoint, and what
 * Operation it produced — see App\Support\Api\IdempotencyKeyStore, which
 * is the only code that reads or writes this table. Never serialized into
 * any API response itself (it exists purely as an internal lookup table).
 *
 * @property int $id
 * @property int $personal_access_token_id
 * @property string $endpoint
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $operation_id
 */
#[Fillable(['personal_access_token_id', 'endpoint', 'idempotency_key', 'request_hash', 'operation_id'])]
class ApiIdempotencyKey extends Model
{
    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
