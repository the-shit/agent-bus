<?php

use App\Bus\ToolFailurePolicy;

it('uses deterministic policy after Jev classification', function (string $failureType, string $action) {
    $advice = (new ToolFailurePolicy(0.75))->advise([], [
        'confidence' => 0.9,
        'failureType' => ['value' => $failureType],
    ]);

    expect($advice['action'])->toBe($action)
        ->and($advice['automatic'])->toBeFalse();
})->with([
    ['model_misunderstanding', 'revise_call'],
    ['permission_denied', 'request_authority'],
    ['missing_dependency', 'repair_dependency'],
    ['policy_blocked', 'stop'],
    ['unknown', 'inspect_manually'],
]);

it('only advises a transient retry when the caller marks it safe', function () {
    $judgment = ['confidence' => 0.9, 'failureType' => ['value' => 'transient_external']];
    $policy = new ToolFailurePolicy(0.75);

    expect($policy->advise([], $judgment)['action'])->toBe('inspect_manually')
        ->and($policy->advise(['retrySafe' => true], $judgment)['action'])->toBe('retry_once');
});

it('falls back to manual inspection below the confidence threshold', function () {
    $advice = (new ToolFailurePolicy(0.75))->advise([], [
        'confidence' => 0.74,
        'failureType' => ['value' => 'permission_denied'],
    ]);

    expect($advice['action'])->toBe('inspect_manually')
        ->and($advice['automatic'])->toBeFalse();
});
