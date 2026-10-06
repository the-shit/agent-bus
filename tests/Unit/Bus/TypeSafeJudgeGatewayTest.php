<?php

use App\Bus\TypeSafeJudgeGateway;

it('returns typed tool failure judgments from one TypeSafe request', function () {
    $sent = [];
    $gateway = new TypeSafeJudgeGateway(
        transport: function (string $endpoint, array $headers, array $payload, float $connectTimeout, float $timeout) use (&$sent): array {
            $sent = compact('endpoint', 'headers', 'payload', 'connectTimeout', 'timeout');

            return [
                'model' => 'jev-test',
                'answers' => [
                    'failure_type' => [
                        'type' => 'choice',
                        'choice' => 'permission_denied',
                        'confidence' => 0.91,
                        'probabilities' => ['permission_denied' => 0.91, 'unknown' => 0.09],
                    ],
                ],
            ];
        },
        apiKey: 'test-key',
        endpoint: 'https://typesafe.test/systemone',
        model: 'jev-test',
        connectTimeout: 1.5,
        timeout: 4.0,
    );

    $judgment = $gateway->judgeToolFailure([
        'tool' => 'bash',
        'error' => 'permission denied',
    ]);

    expect($sent['endpoint'])->toBe('https://typesafe.test/systemone')
        ->and($sent['headers']['Authorization'])->toBe('Bearer test-key')
        ->and($sent['payload']['model'])->toBe('jev-test')
        ->and($sent['payload']['state'])->toBe([
            'schema' => 'tool_failure_signals.v1',
            'tool' => 'bash',
            'argumentShape' => 'none',
            'argumentCount' => 0,
            'errorSignals' => ['permission_denied'],
            'errorLengthBucket' => 'short',
            'hasStackTrace' => false,
            'retrySafe' => false,
        ])
        ->and(json_encode($sent['payload']['state']))->not->toContain('permission denied')
        ->and($sent['payload']['questions'])->toHaveKey('failure_type')
        ->and($sent['payload']['questions'])->not->toHaveKey('next_action')
        ->and($judgment['questionVersion'])->toBe('tool-failure.v1')
        ->and($judgment['failureType']['value'])->toBe('permission_denied')
        ->and($judgment['confidence'])->toBe(0.91);
});

it('rejects a response without the typed choice', function () {
    $gateway = new TypeSafeJudgeGateway(
        transport: fn (): array => ['answers' => []],
        apiKey: 'test-key',
        endpoint: 'https://typesafe.test/systemone',
        model: 'jev-test',
        connectTimeout: 1.0,
        timeout: 2.0,
    );

    expect(fn () => $gateway->judgeToolFailure(['tool' => 'bash']))
        ->toThrow(RuntimeException::class, 'failure_type');
});

it('rejects a choice outside the declared failure taxonomy', function () {
    $gateway = new TypeSafeJudgeGateway(
        transport: fn (): array => [
            'answers' => [
                'failure_type' => [
                    'type' => 'choice',
                    'choice' => 'invented_action',
                    'confidence' => 1.0,
                ],
            ],
        ],
        apiKey: 'test-key',
        endpoint: 'https://typesafe.test/systemone',
        model: 'jev-test',
        connectTimeout: 1.0,
        timeout: 2.0,
    );

    expect(fn () => $gateway->judgeToolFailure(['tool' => 'bash']))
        ->toThrow(RuntimeException::class, 'unknown tool-failure choice');
});
