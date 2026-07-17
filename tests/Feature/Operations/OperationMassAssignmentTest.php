<?php

use App\Models\Operation;
use App\Operations\OperationActorType;
use App\Operations\OperationRisk;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Trust boundary regression: "Only application services may create an
| Operation." An Operation must never be mintable in an already-Approved
| (or otherwise elevated) state via mass assignment, since that would let
| a future integration skip the human approval gate entirely by simply
| passing 'status' => 'approved' in a request-derived array.
|--------------------------------------------------------------------------
*/

it('ignores an elevated status mass-assigned on Operation::create like an untrusted integration would attempt', function () {
    $operation = Operation::create([
        'type' => OperationType::ConfigApply,
        'target' => 'server.properties',
        'risk' => OperationRisk::Standard,
        'author_type' => OperationActorType::Mcp,
        'author_id' => 'attacker-controlled-client',
        'author_origin' => 'mcp',
        'redacted_input' => ['allow-flight' => 'true'],
        'correlation_id' => (string) Str::uuid(),
        // The injection attempt: an untrusted caller trying to mint an
        // already-approved operation and skip the human approval gate.
        'status' => OperationStatus::Approved->value,
        'approved_at' => now(),
        'approved_by_type' => OperationActorType::Human->value,
        'approved_by_id' => '1',
    ]);

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull()
        ->and($operation->approved_by_type)->toBeNull()
        ->and($operation->approved_by_id)->toBeNull();
});

it('ignores an elevated status mass-assigned via Operation::update on an existing Proposed operation', function () {
    $operation = Operation::factory()->status(OperationStatus::Proposed)->create();

    $operation->update([
        'status' => OperationStatus::Approved->value,
        'approved_at' => now(),
    ]);

    expect($operation->fresh()->status)->toBe(OperationStatus::Proposed)
        ->and($operation->fresh()->approved_at)->toBeNull();
});
