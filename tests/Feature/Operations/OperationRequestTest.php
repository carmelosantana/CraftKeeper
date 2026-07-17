<?php

use App\Operations\OperationRequest;
use App\Operations\OperationType;

it('builds a request for every canonical operation type from the plan', function () {
    $requests = [
        OperationType::ConfigApply->value => OperationRequest::configApply('server.properties', 'sha', ['allow-flight' => 'true']),
        OperationType::ConfigRestore->value => OperationRequest::configRestore('server.properties', 'revision-1'),
        OperationType::PluginInstall->value => OperationRequest::pluginInstall('example', 'release-1'),
        OperationType::PluginUpdate->value => OperationRequest::pluginUpdate('example', 'release-2'),
        OperationType::PluginDisable->value => OperationRequest::pluginDisable('example'),
        OperationType::PluginRemove->value => OperationRequest::pluginRemove('example'),
        OperationType::PluginRollback->value => OperationRequest::pluginRollback('example', 'rollback-1'),
        OperationType::RconCommand->value => OperationRequest::rconCommand('list'),
        OperationType::ServerStop->value => OperationRequest::serverStop(),
    ];

    foreach ($requests as $expectedType => $request) {
        expect($request->type->value)->toBe($expectedType);
    }
});

it('matches the brief\'s verbatim configApply factory shape', function () {
    $request = OperationRequest::configApply('server.properties', 'expected-sha', ['allow-flight' => 'true']);

    expect($request->type)->toBe(OperationType::ConfigApply)
        ->and($request->target)->toBe('server.properties')
        ->and($request->metadata)->toBe([
            'expected_sha256' => 'expected-sha',
            'changes' => ['allow-flight' => 'true'],
        ]);
});
