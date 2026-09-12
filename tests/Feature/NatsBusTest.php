<?php

use App\Bus\NatsBus;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

it('reports the broker is unreachable when nothing listens', function () {
    config(['nats_basis.connections.default.port' => 1]);

    expect(app(NatsBus::class)->isReachable())->toBeFalse();
});

it('fails provision when the broker is down', function () {
    config(['nats_basis.connections.default.port' => 1]);

    $this->artisan('provision')->assertFailed();
});

it('creates stream AGENT_BUS and KV bucket sessions', function () {
    $bus = app(NatsBus::class);

    $this->artisan('provision')->assertSuccessful();

    expect($bus->streamExists())->toBeTrue()
        ->and($bus->kvBucketExists())->toBeTrue();
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('does not fail when provision runs twice', function () {
    $this->artisan('provision')->assertSuccessful();
    $this->artisan('provision')->assertSuccessful();
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('gives KV sessions a 90 second TTL', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    expect($bus->sessionTtlNanos())->toBe(90_000_000_000);
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('publishes a JSON envelope and reads it back', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    $subject = 'repo.the-shit.agent-bus.prove';
    $envelope = [
        'sessionId' => '01a08ef9-2515-7460-89bd-5efc21f28642',
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'timestamp' => '2026-09-11T06:00:00Z',
        'payload' => [
            'tool' => 'run_terminal_command',
            'cwd' => '/home/you/agent-bus',
        ],
    ];

    $bus->publishEnvelope($subject, $envelope);

    expect($bus->lastEnvelope($subject))->toBe($envelope);

    $sessionId = 'prove-session';
    $json = json_encode(['sessionId' => $sessionId], JSON_THROW_ON_ERROR);
    $bus->putSession($sessionId, $json);

    expect($bus->getSession($sessionId))->toBe($json);
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');
