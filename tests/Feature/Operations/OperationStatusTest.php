<?php

use App\Operations\OperationStatus;

it('allows exactly the transitions documented in the plan', function () {
    $legal = [
        [OperationStatus::Proposed, OperationStatus::Approved],
        [OperationStatus::Proposed, OperationStatus::Rejected],
        [OperationStatus::Approved, OperationStatus::Running],
        [OperationStatus::Running, OperationStatus::Succeeded],
        [OperationStatus::Running, OperationStatus::Failed],
        [OperationStatus::Succeeded, OperationStatus::RolledBack],
        [OperationStatus::Failed, OperationStatus::RolledBack],
    ];

    foreach ($legal as [$from, $to]) {
        expect($from->canTransitionTo($to))->toBeTrue("{$from->value} -> {$to->value} should be legal");
    }
});

it('rejects every transition not documented in the plan', function () {
    $cases = OperationStatus::cases();

    foreach ($cases as $from) {
        foreach ($cases as $to) {
            $legal = in_array($to, $from->legalNextStatuses(), true);

            expect($from->canTransitionTo($to))->toBe($legal, "{$from->value} -> {$to->value}");
        }
    }
});

it('treats Rejected and RolledBack as terminal', function () {
    expect(OperationStatus::Rejected->isTerminal())->toBeTrue()
        ->and(OperationStatus::RolledBack->isTerminal())->toBeTrue();
});

it('never allows a status to transition to itself', function () {
    foreach (OperationStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
