<?php

use App\Bus\NatsBus;
use App\Models\BusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('agent_bus.monitor.batch', 1000);
    config()->set('agent_bus.monitor.expires', 2.0);
});

afterEach(function () {
    NatsV2::disconnectAll();
});

it('persists a toolCall envelope mirrored by agent-bus:monitor', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $subject = 'repo.the-shit.agent-bus.bus-event-'.bin2hex(random_bytes(4));
    $envelope = [
        'sessionId' => '01a08ef9-2515-7460-89bd-5efc21f28642',
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'timestamp' => '2026-09-11T06:00:00Z',
        'payload' => [
            'tool' => 'run_terminal_command',
            'exit_code' => 0,
        ],
    ];

    $bus->publishEnvelope($subject, $envelope);

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $event = BusEvent::where('subject', $subject)->first();

    expect($event)->not->toBeNull()
        ->and($event->type)->toBe('toolCall')
        ->and($event->session_id)->toBe('01a08ef9-2515-7460-89bd-5efc21f28642')
        ->and($event->model)->toBe('grok-4.6')
        ->and($event->subject)->toBe($subject)
        ->and($event->payload)->toMatchArray(['tool' => 'run_terminal_command'])
        ->and($event->seq)->toBeInt()
        ->and($event->occurred_at)->not->toBeNull();
})->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

it('does not duplicate already-acked sequences when the monitor runs again', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $subject = 'repo.the-shit.agent-bus.bus-event-dup-'.bin2hex(random_bytes(4));
    $envelope = [
        'sessionId' => '01a08ef9-2515-7460-89bd-5efc21f28642',
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'timestamp' => '2026-09-11T06:00:00Z',
        'payload' => ['tool' => 'run_terminal_command'],
    ];

    $bus->publishEnvelope($subject, $envelope);

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $seq = BusEvent::where('subject', $subject)->value('seq');

    expect($seq)->toBeInt();

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    expect(BusEvent::where('subject', $subject)->count())->toBe(1)
        ->and(BusEvent::where('seq', $seq)->count())->toBe(1);
})->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

it('persists tool outcomes emitted through bin/agent-bus with AGENT_BUS_MODEL', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $result = Process::path(base_path())
        ->timeout(5)
        ->env(['AGENT_BUS_MODEL' => 'agent-bus-ft-v1.local'])
        ->run([
            PHP_BINARY,
            base_path('bin/agent-bus'),
            'emit',
            '--type=toolCall',
            '--payload={"tool":"x","exit_code":1,"error":"boom"}',
            '--session=emit-study',
        ]);

    expect($result->exitCode())->toBe(0);

    $this->artisan('agent-bus:monitor --once')->assertSuccessful();

    $event = BusEvent::where('subject', 'repo.the-shit.agent-bus.toolCall')
        ->where('model', 'agent-bus-ft-v1.local')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['exit_code'])->toBe(1)
        ->and($event->payload['tool'])->toBe('x')
        ->and($event->payload['error'])->toBe('boom');
})->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
