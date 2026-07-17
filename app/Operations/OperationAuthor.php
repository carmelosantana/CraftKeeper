<?php

namespace App\Operations;

/**
 * Who proposed (or is acting on) an operation: an actor type, an opaque
 * identifier for that actor, and where the request originated. Recorded
 * verbatim on every Operation and AuditEvent.
 *
 * This is deliberately the only way to describe a non-human actor.
 * OperationService::approve()/reject() do not accept an OperationAuthor at
 * all — they accept a real App\Models\User — so there is no code path by
 * which an OperationAuthor::mcp()/ai() actor can approve or reject
 * anything, however this class evolves.
 */
final class OperationAuthor
{
    private function __construct(
        public readonly OperationActorType $type,
        public readonly ?string $id,
        public readonly string $origin,
    ) {}

    /**
     * A real, authenticated human administrator.
     */
    public static function user(int|string $id, string $origin = 'web'): self
    {
        return new self(OperationActorType::Human, (string) $id, $origin);
    }

    /**
     * An MCP client acting on the admin's behalf. Can propose; can never
     * approve or reject (see class docblock).
     */
    public static function mcp(string $clientId, string $origin = 'mcp'): self
    {
        return new self(OperationActorType::Mcp, $clientId, $origin);
    }

    /**
     * A hosted/local AI assistant session acting on the admin's behalf.
     * Can propose; can never approve or reject (see class docblock).
     */
    public static function ai(string $sessionId, string $origin = 'ai'): self
    {
        return new self(OperationActorType::Ai, $sessionId, $origin);
    }

    /**
     * CraftKeeper itself, acting automatically — e.g. running an
     * already-approved operation, or attempting a compensating rollback
     * after a failed post-write verification. Not a new human decision.
     */
    public static function system(string $origin = 'system'): self
    {
        return new self(OperationActorType::System, null, $origin);
    }

    public function isHuman(): bool
    {
        return $this->type === OperationActorType::Human;
    }
}
