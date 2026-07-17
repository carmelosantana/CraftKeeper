<?php

namespace App\Operations;

/**
 * A coarse, operation-level risk classification, persisted on every
 * Operation and surfaced to the human approver before they approve it.
 * Domain-specific classifiers (e.g. Task 10's CommandPolicy::classify(),
 * which inspects the actual RCON command text) produce a more precise
 * CommandRisk and feed it back into this generic field when they build
 * the OperationRequest.
 */
enum OperationRisk: string
{
    case Standard = 'standard';
    case Elevated = 'elevated';
}
