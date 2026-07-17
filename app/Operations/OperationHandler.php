<?php

namespace App\Operations;

use App\Models\Operation;

/**
 * The extension seam for operation execution. A concrete handler (Tasks 8,
 * 10, 15 — e.g. ConfigApplyHandler, RconCommandHandler,
 * PluginOperationHandler) declares which OperationType(s) it supports and
 * knows how to execute and roll back an approved Operation of that type.
 *
 * No concrete implementation exists as of Task 5 — see
 * OperationHandlerRegistry for how handlers are discovered at runtime, and
 * OperationService::execute() for how a missing handler degrades cleanly
 * instead of crashing.
 */
interface OperationHandler
{
    /**
     * Whether this handler knows how to execute/roll back operations of
     * the given type. A single handler may support several related types
     * (e.g. one PluginOperationHandler for install/update/disable/remove/
     * rollback).
     */
    public function supports(OperationType $type): bool;

    /**
     * Carry out an approved operation. Called by OperationService only
     * after a human has approved the operation (Approved -> Running).
     */
    public function execute(Operation $operation): OperationResult;

    /**
     * Reverse a previously executed operation (Succeeded/Failed ->
     * RolledBack) — e.g. restoring a config snapshot or restoring a
     * replaced plugin JAR.
     */
    public function rollback(Operation $operation): OperationResult;
}
