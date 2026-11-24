<?php

use App\Bus\ToolFailureSignals;

it('projects raw failures onto a fixed non-sensitive schema', function () {
    $signals = ToolFailureSignals::from([
        'tool' => 'run_terminal_command',
        'args' => ['command' => 'deploy --token top-secret', 'path' => '/private/repo'],
        'error' => "Authorization: Bearer top-secret\nPermission denied at /private/repo/file.php:42",
        'retrySafe' => false,
    ]);

    expect($signals)->toBe([
        'schema' => 'tool_failure_signals.v1',
        'tool' => 'run_terminal_command',
        'argumentShape' => 'object',
        'argumentCount' => 2,
        'errorSignals' => ['permission_denied'],
        'errorLengthBucket' => 'short',
        'hasStackTrace' => false,
        'retrySafe' => false,
    ])->and(json_encode($signals))
        ->not->toContain('top-secret')
        ->not->toContain('/private/repo')
        ->not->toContain('deploy');
});

it('accepts only allowlisted precomputed signals', function () {
    $signals = ToolFailureSignals::from([
        'tool' => 'read',
        'argumentShape' => 'object',
        'argumentCount' => 4,
        'errorSignals' => ['timeout', 'invented', 'timeout'],
        'errorLengthBucket' => 'medium',
        'hasStackTrace' => true,
        'retrySafe' => true,
    ]);

    expect($signals['errorSignals'])->toBe(['timeout'])
        ->and($signals['retrySafe'])->toBeTrue()
        ->and($signals['argumentCount'])->toBe(4);
});
