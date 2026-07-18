<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationActorType;
use App\Operations\OperationStatus;
use App\Operations\OperationType;

/**
 * Resource-level authorization for /api/v1 Operation endpoints — defense
 * in depth ON TOP OF App\Http\Middleware\EnsureApiScope's route-level
 * scope check, not a replacement for it. Every method here answers "given
 * this operation's OWN state, is this action ever legal", independent of
 * which scope the caller's token happens to carry.
 *
 * Deliberately, permanently, has NO approve() or reject() method. There is
 * no scope, no token, and no method on this class that can ever authorize
 * moving an Operation to OperationStatus::Approved — that is exclusively
 * App\Operations\OperationService::approve()'s job, and that method only
 * accepts a real, authenticated App\Models\User approving through the
 * session-based web UI. Adding an approve() method here (even one that
 * always returns false) would be the wrong shape for this invariant: the
 * absence of the method is what makes "no API path can approve" true by
 * construction rather than by a runtime check that could be flipped.
 */
class ApiOperationPolicy
{
    /**
     * Any scoped token may VIEW any operation it's otherwise routed to
     * (the controller's own query already scopes which operations are
     * reachable, e.g. config:read only lists config.apply/config.restore
     * operations) — this exists mainly so `apply()` below has a symmetric
     * sibling and so a future, narrower view rule has an obvious home.
     */
    public function view(User $user, Operation $operation): bool
    {
        return true;
    }

    /**
     * Whether `config:apply` may execute $operation right now. Task 17's
     * central reconciliation: config:apply NEVER approves anything — it
     * may only execute a config.apply/config.restore operation that is
     * ALREADY OperationStatus::Approved. Since OperationService::approve()
     * is the only code path that can ever produce that status, and it
     * forces approved_by_type to OperationActorType::Human, checking BOTH
     * the status AND the approver type here is belt-and-suspenders: the
     * status alone is already structurally sufficient (see
     * docs/architecture/decisions.md's Task 17 entry), but asserting the
     * human-approver invariant explicitly means a future refactor of
     * OperationService that loosened that guarantee would fail this
     * policy loudly instead of silently starting to accept a
     * non-human-approved operation here.
     */
    public function apply(User $user, Operation $operation): bool
    {
        return in_array($operation->type, [OperationType::ConfigApply, OperationType::ConfigRestore], true)
            && $operation->status === OperationStatus::Approved
            && $operation->approved_by_type === OperationActorType::Human;
    }
}
