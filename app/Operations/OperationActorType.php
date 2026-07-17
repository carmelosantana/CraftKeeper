<?php

namespace App\Operations;

/**
 * Who (or what) is acting on an operation. `Human` is always a real
 * App\Models\User row; `Mcp`/`Ai` are non-human actors that may propose an
 * operation but can never approve or reject one — see OperationAuthor and
 * OperationService::approve()/reject(), which only accept a User.
 * `System` is CraftKeeper itself, acting automatically (e.g. executing an
 * already-approved operation, or attempting an automatic rollback after a
 * failed post-write verification) — not a new human decision.
 */
enum OperationActorType: string
{
    case Human = 'human';
    case Mcp = 'mcp';
    case Ai = 'ai';
    case System = 'system';
}
