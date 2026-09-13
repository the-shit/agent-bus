<?php

use App\Bus\NatsBus;
use App\Bus\Sidecar;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

it('prompts the mapped pane with the inbox JSON', function () {
    $sessionId = '01a08ef9-2515-7460-89bd-5efc21f28642';
    $paneId = 'w2:p2';
    $body = json_encode(['sessionId' => $sessionId, 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        expect($process->command[2] ?? null)->toBe('prompt');

        return Process::result('{"ok":true}');
    });

    $pane = app(Sidecar::class)->deliver(
        'session.'.$sessionId.'.inbox',
        $body,
        [$sessionId => $paneId],
    );

    expect($pane)->toBe($paneId);

    Process::assertRan(function (PendingProcess $process) use ($paneId, $body) {
        return $process->command === ['herdr', 'agent', 'prompt', $paneId, $body];
    });
});

it('does not prompt when the session is not in the map', function () {
    Process::preventStrayProcesses();
    Process::fake();

    $pane = app(Sidecar::class)->deliver(
        'session.unknown.inbox',
        json_encode(['sessionId' => 'unknown', 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR),
        ['on-the-bus' => 'w2:p2'],
    );

    expect($pane)->toBeNull();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'prompt');
});

it('does not prompt when the body is not JSON', function () {
    Process::preventStrayProcesses();
    Process::fake();

    $pane = app(Sidecar::class)->deliver(
        'session.on-the-bus.inbox',
        'not-json',
        ['on-the-bus' => 'w2:p2'],
    );

    expect($pane)->toBeNull();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'prompt');
});

it('maps herdr sessions that are also in KV and drops the rest', function () {
    $onBus = '01a08ef9-2515-7460-89bd-5efc21f28642';
    $herdrOnly = 'herdr-only';
    $kvOnly = 'kv-only';

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($onBus, $herdrOnly) {
        expect($process->command)->toBe(['herdr', 'agent', 'list']);

        return Process::result(sidecarHerdrListJson([
            sidecarHerdrAgent($herdrOnly, 'w2:p1'),
            sidecarHerdrAgent($onBus, 'w2:p2'),
        ]));
    });

    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('listSessionIds')->once()->andReturn([$onBus, $kvOnly]);
    $this->app->instance(NatsBus::class, $bus);

    expect($this->app->make(Sidecar::class)->rebuildMap())->toBe([$onBus => 'w2:p2']);
});

it('rebuilds the map when herdr list changes', function () {
    $sessionId = '01a08ef9-2515-7460-89bd-5efc21f28642';
    $lists = [
        sidecarHerdrListJson([sidecarHerdrAgent($sessionId, 'w2:p1')]),
        sidecarHerdrListJson([sidecarHerdrAgent($sessionId, 'w2:p8')]),
    ];
    $prompted = [];

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$lists, &$prompted) {
        $command = $process->command;

        if (($command[2] ?? null) === 'list') {
            return Process::result((string) array_shift($lists));
        }

        $prompted[] = $command;

        return Process::result('{"ok":true}');
    });

    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('listSessionIds')->andReturn([$sessionId]);
    $this->app->instance(NatsBus::class, $bus);

    $sidecar = $this->app->make(Sidecar::class);
    $first = $sidecar->rebuildMap();
    $second = $sidecar->rebuildMap();
    $body = json_encode(['sessionId' => $sessionId, 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR);
    $pane = $sidecar->deliver('session.'.$sessionId.'.inbox', $body, $second);

    expect($first)->toBe([$sessionId => 'w2:p1'])
        ->and($second)->toBe([$sessionId => 'w2:p8'])
        ->and($pane)->toBe('w2:p8')
        ->and($prompted)->toBe([
            ['herdr', 'agent', 'prompt', 'w2:p8', $body],
        ]);
});

it('ticks by consuming inbox JSON and prompting the mapped pane', function () {
    $sessionId = '01a08ef9-2515-7460-89bd-5efc21f28642';
    $paneId = 'w2:p2';
    $body = json_encode(['sessionId' => $sessionId, 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($sessionId, $paneId) {
        if (($process->command[2] ?? null) === 'list') {
            return Process::result(sidecarHerdrListJson([
                sidecarHerdrAgent($sessionId, $paneId),
            ]));
        }

        return Process::result('{"ok":true}');
    });

    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('listSessionIds')->once()->andReturn([$sessionId]);
    $bus->shouldReceive('consumeInbox')->once()->andReturnUsing(function (callable $handler) use ($sessionId, $body) {
        $handler('session.'.$sessionId.'.inbox', $body);

        return 1;
    });
    $this->app->instance(NatsBus::class, $bus);

    $delivered = $this->app->make(Sidecar::class)->tick();

    expect($delivered)->toBe([
        ['sessionId' => $sessionId, 'pane_id' => $paneId],
    ]);

    Process::assertRan(function (PendingProcess $process) use ($paneId, $body) {
        return $process->command === ['herdr', 'agent', 'prompt', $paneId, $body];
    });
});

it('fails sidecar when the broker is down', function () {
    config(['nats_basis.connections.default.port' => 1]);

    $this->artisan('sidecar', ['--once' => true])->assertFailed();
});

it('runs the sidecar off the one binary', function () {
    $result = Process::path(base_path())
        ->timeout(10)
        ->run([PHP_BINARY, base_path('bin/agent-bus'), 'list']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())
        ->toContain('sidecar')
        ->toContain('provision');
});

it('consumes an inbox message and would prompt this pane', function () {
    $sessionId = 'sidecar-'.bin2hex(random_bytes(4));
    $paneId = 'w2:p2';
    $envelope = [
        'sessionId' => $sessionId,
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'inbox',
        'timestamp' => '2026-09-11T06:00:00Z',
        'payload' => ['text' => 'ping'],
    ];
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($sessionId, $paneId) {
        $command = $process->command;

        if (($command[2] ?? null) === 'list') {
            return Process::result(sidecarHerdrListJson([
                sidecarHerdrAgent($sessionId, $paneId),
            ]));
        }

        if (($command[2] ?? null) === 'prompt') {
            return Process::result('{"ok":true}');
        }

        return Process::result(errorOutput: 'unexpected herdr command', exitCode: 1);
    });

    config(['agent_bus.sidecar.consumer' => 'sidecar-test-'.bin2hex(random_bytes(4))]);

    $bus = app(NatsBus::class);
    $bus->provision();
    $bus->putSession($sessionId, json_encode(['sessionId' => $sessionId], JSON_THROW_ON_ERROR));
    $bus->ensureInboxConsumer();
    $bus->publishEnvelope('session.'.$sessionId.'.inbox', $envelope);

    $this->artisan('sidecar', ['--once' => true])
        ->expectsOutputToContain("prompted {$paneId} for session {$sessionId}")
        ->assertSuccessful();

    Process::assertRan(function (PendingProcess $process) use ($paneId, $body) {
        return $process->command === ['herdr', 'agent', 'prompt', $paneId, $body];
    });
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

/**
 * @param  list<array<string, mixed>>  $agents
 */
function sidecarHerdrListJson(array $agents): string
{
    return json_encode([
        'id' => 'cli:agent:list',
        'result' => [
            'agents' => $agents,
            'type' => 'agent_list',
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function sidecarHerdrAgent(string $sessionId, string $paneId): array
{
    return [
        'agent' => 'grok',
        'agent_session' => [
            'agent' => 'grok',
            'kind' => 'id',
            'source' => 'herdr:grok',
            'value' => $sessionId,
        ],
        'pane_id' => $paneId,
    ];
}

it('reports an unmapped inbox to the transport as a delivery failure', function () {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result(sidecarHerdrListJson([]))]);
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('listSessionIds')->once()->andReturn([]);
    $bus->shouldReceive('consumeInbox')->once()->andReturnUsing(function (callable $handler) {
        $handler('session.missing.inbox', '{"sessionId":"missing"}');
    });
    $this->app->instance(NatsBus::class, $bus);

    expect(fn () => app(Sidecar::class)->tick())->toThrow(RuntimeException::class, 'Recipient unavailable');
    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'prompt');
});
