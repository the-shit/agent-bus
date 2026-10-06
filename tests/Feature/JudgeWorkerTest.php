<?php

use App\Bus\JudgeGateway;
use App\Bus\JudgeWorker;
use App\Bus\NatsBus;
use App\Bus\ToolFailurePolicy;

it('publishes a correlated typed judgment result', function () {
    $request = [
        'repo' => 'the-shit/agent-bus',
        'payload' => [
            'requestId' => 'judgment-123',
            'capability' => 'tool_failure_triage',
            'task' => ['tool' => 'bash', 'error' => 'permission denied'],
        ],
    ];
    $gateway = Mockery::mock(JudgeGateway::class);
    $gateway->shouldReceive('judgeToolFailure')
        ->once()
        ->with($request['payload']['task'])
        ->andReturn([
            'model' => 'jev-test',
            'questionVersion' => 'tool-failure.v1',
            'confidence' => 0.9,
            'failureType' => ['value' => 'permission_denied', 'confidence' => 0.9, 'probabilities' => []],
        ]);
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('judgment-123')->andReturnFalse();
    $bus->shouldReceive('consumeWork')
        ->once()
        ->withArgs(function (string $capability, string $consumer, callable $handler): bool {
            return $capability === 'tool_failure_triage' && $consumer === 'agent-bus-judge';
        })
        ->andReturnUsing(function (string $capability, string $consumer, callable $handler) use ($request): int {
            $handler('work.request.tool_failure_triage.judgment-123', $request);

            return 1;
        });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.judgment-123', Mockery::on(function (array $envelope): bool {
            return $envelope['type'] === 'judgeResult'
                && $envelope['model'] === 'jev-test'
                && $envelope['payload']['status'] === 'ok'
                && $envelope['payload']['requestId'] === 'judgment-123'
                && $envelope['payload']['advice']['action'] === 'request_authority'
                && $envelope['payload']['advice']['automatic'] === false;
        }), 'work-result-judgment-123');

    $count = (new JudgeWorker($bus, $gateway, new ToolFailurePolicy(0.75)))->tick();

    expect($count)->toBe(1);
});

it('publishes an error result without executing advice when the gateway fails', function () {
    config(['agent_bus.judge.model' => 'jev-test']);

    $gateway = Mockery::mock(JudgeGateway::class);
    $gateway->shouldReceive('judgeToolFailure')->once()->andThrow(new RuntimeException("service\nfailed"));
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('failure-1')->andReturnFalse();
    $bus->shouldReceive('consumeWork')
        ->once()
        ->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
            $handler('work.request.tool_failure_triage.failure-1', [
                'payload' => [
                    'requestId' => 'failure-1',
                    'capability' => 'tool_failure_triage',
                    'task' => ['tool' => 'bash'],
                ],
            ]);

            return 1;
        });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.failure-1', Mockery::on(function (array $envelope): bool {
            return $envelope['payload']['status'] === 'error'
                && $envelope['payload']['error'] === 'Jev judge could not classify this failure.'
                && ! isset($envelope['payload']['judgment']);
        }), 'work-result-failure-1');

    expect((new JudgeWorker($bus, $gateway, new ToolFailurePolicy(0.75)))->tick())->toBe(1);
});

it('acks a redelivered request without calling TypeSafe when its result already exists', function () {
    $gateway = Mockery::mock(JudgeGateway::class);
    $gateway->shouldNotReceive('judgeToolFailure');
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('consumeWork')->once()->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
        $handler('work.request.tool_failure_triage.judgment-complete', [
            'payload' => [
                'requestId' => 'judgment-complete',
                'capability' => 'tool_failure_triage',
                'task' => ['tool' => 'bash'],
            ],
        ]);

        return 1;
    });
    $bus->shouldReceive('completedWorkResultExists')->once()->with('judgment-complete')->andReturnTrue();
    $bus->shouldNotReceive('publishEnvelope');

    expect((new JudgeWorker($bus, $gateway, new ToolFailurePolicy(0.75)))->tick())->toBe(1);
});

it('acks malformed correlation data without calling TypeSafe', function () {
    $gateway = Mockery::mock(JudgeGateway::class);
    $gateway->shouldNotReceive('judgeToolFailure');
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('consumeWork')
        ->once()
        ->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
            $handler('work.request.tool_failure_triage.subject-id', [
                'payload' => ['requestId' => 'different-id', 'capability' => 'tool_failure_triage'],
            ]);

            return 1;
        });
    $bus->shouldNotReceive('publishEnvelope');

    expect((new JudgeWorker($bus, $gateway, new ToolFailurePolicy(0.75)))->tick())->toBe(1);
});
