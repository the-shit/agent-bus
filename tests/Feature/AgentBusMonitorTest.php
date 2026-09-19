<?php

use App\Bus\NatsBus;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

it('fails monitor when the broker is down', function () {
    config(['nats_basis.connections.default.port' => 1]);

    $this->artisan('monitor', ['--once' => true])->assertFailed();
});

it('runs the monitor off the one binary', function () {
    $result = Process::path(base_path())
        ->timeout(10)
        ->run([PHP_BINARY, base_path('bin/agent-bus'), 'list']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())
        ->toContain('monitor');
});

it('prints a published envelope on stdout', function () {
    $token = bin2hex(random_bytes(4));
    $type = 'monitorProve'.$token;
    $subject = 'repo.the-shit.agent-bus.'.$type;
    $envelope = [
        'sessionId' => 'grok:monitor-'.$token,
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => $type,
        'timestamp' => '2026-09-11T06:00:00Z',
        'payload' => ['tool' => 'run_terminal_command'],
    ];

    config(['agent_bus.monitor.consumer' => 'monitor-test-'.$token]);

    $bus = app(NatsBus::class);
    $bus->provision();
    $bus->ensureMonitorConsumer();
    $bus->publishEnvelope($subject, $envelope);

    $this->artisan('monitor', ['--once' => true])
        ->expectsOutputToContain('bridged '.$type.' on '.$subject)
        ->assertSuccessful();
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');
