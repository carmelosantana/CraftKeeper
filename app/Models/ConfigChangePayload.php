<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The REAL (unredacted) field-level change set behind one config
 * operation, encrypted at rest — the one place a secret value CraftKeeper
 * is actively proposing (e.g. a new rcon.password) is ever persisted.
 *
 * This deliberately mirrors App\Models\Secret's pattern (Task 4) rather
 * than living as a column on App\Models\Operation itself: Operation's own
 * columns (`redacted_input`, and every ChangeProposal/AuditEvent row tied
 * to it) are documented, tested invariants of Task 5's migration as
 * holding ONLY pre-redacted data, "never raw secret values, even
 * encrypted" — see docs/architecture/decisions.md, Task 5. Keeping the
 * one genuinely raw payload in its own single-purpose table with its own
 * `#[Hidden]` column, exactly like `secrets.value`, means every existing
 * guarantee about Operation/ChangeProposal/AuditEvent stays literally
 * true with zero special-casing, and the blast radius of "where could a
 * raw secret leak from" is exactly one column in one table, never joined
 * or eager-loaded by anything except the two config operation handlers
 * that legitimately need it (App\Operations\Handlers\ConfigApplyHandler /
 * ConfigRestoreHandler, via their shared Concerns\AppliesConfigChanges
 * trait) at the moment they actually write the file.
 *
 * `changes` uses Laravel's `encrypted:array` cast: the list of
 * {kind, path, value} change entries is JSON-encoded, then encrypted,
 * before it ever reaches the database, and transparently decrypted back
 * into an array on read. It is ALSO `#[Hidden]`, so it can never
 * accidentally reach a `toArray()`/`toJson()`/Inertia prop/broadcast even
 * if some future code path serializes this model — the only supported
 * way to read a real value back out is the `changes` attribute on a
 * model instance, exactly like `Secret::value`.
 *
 * Data minimization: a row is only useful while its Operation is IN-FLIGHT
 * (Proposed/Approved) — rollback restores from filesystem snapshots (see
 * Concerns\AppliesConfigChanges::rollback()), never from this table, so
 * once the owning Operation reaches a terminal outcome the row is dead
 * weight holding a decryptable-at-rest secret for no reason. deleteForOperation()
 * below is the one sanctioned way to remove it, called from exactly two
 * places once the raw value can never legitimately be needed again:
 * Concerns\AppliesConfigChanges::applyApprovedChange() (after execute()
 * finishes its write attempt, Succeeded or Failed — see that method's
 * try/finally) and OperationService::reject() (a Proposed operation that
 * never executes is dead the moment it's rejected). Both call sites only
 * ever DELETE by operation_id; neither reads or decrypts `changes`, so this
 * doesn't widen the "blast radius" described above — it only shrinks the
 * at-rest footprint of a payload nothing can use anymore. (A stale
 * Proposed operation that simply expires without ever being approved,
 * executed, or rejected has no such trigger today — there is no scheduled
 * job that transitions it out of Proposed — so its payload lingers until
 * TTL-expired execute() attempts and fails, or it's rejected. Noted as a
 * known gap rather than force-fit here.)
 *
 * @property int $id
 * @property string $operation_id
 * @property list<array{kind: string, path: string, value: mixed}> $changes
 */
#[Fillable(['operation_id', 'changes'])]
#[Hidden(['changes'])]
class ConfigChangePayload extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Delete this operation's raw change payload once it is dead. A plain,
     * column-blind `DELETE ... WHERE operation_id = ?` — see the class
     * docblock for why this is safe to call from outside the two config
     * operation handlers.
     */
    public static function deleteForOperation(string $operationId): void
    {
        static::query()->where('operation_id', $operationId)->delete();
    }
}
