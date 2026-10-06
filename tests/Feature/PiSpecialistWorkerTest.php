<?php

use App\Bus\NatsBus;
use App\Bus\PiSpecialistWorker;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('claims capability work through Pi and publishes the correlated result', function () {
    config(['agent_bus.specialist.pi_binary' => '/test/pi']);
    $request = [
        'repo' => 'the-shit/agent-bus',
        'payload' => [
            'requestId' => 'review-123',
            'task' => ['prompt' => 'Review the inbox implementation.'],
        ],
    ];
    $profile = specialistTestProfile();
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result("APPROVE\nNo blocking findings."));
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('review-123')->andReturnFalse();
    $bus->shouldReceive('consumeWork')
        ->once()
        ->withArgs(fn (string $capability, string $consumer, callable $handler): bool => $capability === 'code_review' && $consumer === 'qwen-reviewer')
        ->andReturnUsing(function (string $capability, string $consumer, callable $handler) use ($request): int {
            $handler('work.request.code_review.review-123', $request);

            return 1;
        });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.review-123', Mockery::on(function (array $envelope): bool {
            return $envelope['agentType'] === 'pi-worker'
                && $envelope['model'] === 'ollama/qwen-test'
                && $envelope['payload']['status'] === 'ok'
                && $envelope['payload']['result']['text'] === "APPROVE\nNo blocking findings.";
        }), 'work-result-review-123');

    $count = (new PiSpecialistWorker($bus))->tick($profile);

    expect($count)->toBe(1);
    Process::assertRan(function (PendingProcess $process): bool {
        return $process->path === base_path()
            && $process->command[0] === '/test/pi'
            && in_array('ollama/qwen-test', $process->command, true)
            && in_array('read,grep,find,ls', $process->command, true)
            && ! in_array('bash', $process->command, true);
    });
});

it('publishes an error result without starting Pi when the prompt is missing', function () {
    Process::preventStrayProcesses();
    Process::fake();
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('review-456')->andReturnFalse();
    $bus->shouldReceive('consumeWork')->once()->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
        $handler('work.request.code_review.review-456', [
            'payload' => ['requestId' => 'review-456', 'task' => []],
        ]);

        return 1;
    });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.review-456', Mockery::on(fn (array $envelope): bool => $envelope['payload']['status'] === 'error'
            && str_contains($envelope['payload']['error'], 'non-empty prompt')), 'work-result-review-456');

    expect((new PiSpecialistWorker($bus))->tick(specialistTestProfile()))->toBe(1);
    Process::assertNothingRan();
});

it('does not publish process exception details', function () {
    Process::preventStrayProcesses();
    Process::fake(function (): never {
        throw new RuntimeException('secret prompt and command arguments');
    });
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('review-789')->andReturnFalse();
    $bus->shouldReceive('consumeWork')->once()->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
        $handler('work.request.code_review.review-789', [
            'payload' => [
                'requestId' => 'review-789',
                'task' => ['prompt' => 'private review request'],
            ],
        ]);

        return 1;
    });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.review-789', Mockery::on(fn (array $envelope): bool => $envelope['payload']['error'] === 'Pi specialist process could not run.'
            && ! str_contains(json_encode($envelope), 'secret prompt')), 'work-result-review-789');

    expect((new PiSpecialistWorker($bus))->tick(specialistTestProfile()))->toBe(1);
});

it('does not publish failed process output', function () {
    Process::preventStrayProcesses();
    Process::fake(fn () => Process::result(
        output: 'private task output',
        errorOutput: 'secret environment value',
        exitCode: 17,
    ));
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('completedWorkResultExists')->once()->with('review-failed')->andReturnFalse();
    $bus->shouldReceive('consumeWork')->once()->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
        $handler('work.request.code_review.review-failed', [
            'payload' => [
                'requestId' => 'review-failed',
                'task' => ['prompt' => 'private review request'],
            ],
        ]);

        return 1;
    });
    $bus->shouldReceive('publishEnvelope')
        ->once()
        ->with('work.result.review-failed', Mockery::on(fn (array $envelope): bool => $envelope['payload']['error'] === 'Pi specialist exited with code 17.'
            && ! str_contains(json_encode($envelope), 'secret environment value')
            && ! str_contains(json_encode($envelope), 'private task output')), 'work-result-review-failed');

    expect((new PiSpecialistWorker($bus))->tick(specialistTestProfile()))->toBe(1);
});

it('acks a redelivered request without rerunning Pi when its result already exists', function () {
    Process::preventStrayProcesses();
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('consumeWork')->once()->andReturnUsing(function (string $capability, string $consumer, callable $handler): int {
        $handler('work.request.code_review.review-complete', [
            'payload' => [
                'requestId' => 'review-complete',
                'task' => ['prompt' => 'Do not run this twice.'],
            ],
        ]);

        return 1;
    });
    $bus->shouldReceive('completedWorkResultExists')->once()->with('review-complete')->andReturnTrue();
    $bus->shouldNotReceive('publishEnvelope');

    expect((new PiSpecialistWorker($bus))->tick(specialistTestProfile()))->toBe(1);
    Process::assertNothingRan();
});

/**
 * @return array{capability: string, consumer: string, model: string, agent_file: string, cwd: string, tools: list<string>, timeout: int}
 */
function specialistTestProfile(): array
{
    return [
        'capability' => 'code_review',
        'consumer' => 'qwen-reviewer',
        'model' => 'ollama/qwen-test',
        'agent_file' => base_path('tests/Fixtures/pi-specialist.md'),
        'cwd' => base_path(),
        'tools' => ['read', 'grep', 'find', 'ls'],
        'timeout' => 30,
    ];
}
