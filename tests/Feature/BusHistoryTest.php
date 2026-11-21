<?php

use App\Bus\BusHistory;
use App\Models\BusEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'database.connections.bus_history' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'agent_bus.history_store.connection' => 'bus_history',
        'agent_bus.history_store.enabled' => true,
    ]);

    Schema::connection('bus_history')->dropIfExists('bus_events');
    Schema::connection('bus_history')->create('bus_events', function (Blueprint $table) {
        $table->id();
        $table->string('event_id')->nullable()->unique();
        $table->string('correlation_id')->nullable();
        $table->string('causation_id')->nullable();
        $table->unsignedBigInteger('seq')->nullable()->unique();
        $table->string('subject');
        $table->string('session_id')->nullable();
        $table->string('agent_type')->nullable();
        $table->string('model')->nullable();
        $table->string('repo')->nullable();
        $table->string('type');
        $table->json('payload');
        $table->timestamp('occurred_at')->nullable();
        $table->timestamp('received_at')->useCurrent();
    });
});

it('does nothing when history persistence is disabled', function () {
    config(['agent_bus.history_store.enabled' => false]);

    $history = new BusHistory;
    expect($history->enabled())->toBeFalse();

    $history->persist('repo.the-shit.agent-bus.example', [
        'type' => 'toolCall',
        'payload' => ['tool' => 'composer'],
    ], 1);

    expect(BusEvent::on('bus_history')->count())->toBe(0);
});

it('persists an envelope onto bus_events', function () {
    $history = new BusHistory;
    expect($history->enabled())->toBeTrue();

    $history->persist('repo.the-shit.agent-bus.toolCall', [
        'event_id' => 'evt-1',
        'sessionId' => 'grok:abc',
        'agentType' => 'grok',
        'model' => 'grok-4.6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'timestamp' => '2026-11-20T19:00:00Z',
        'payload' => ['tool' => 'composer'],
    ], 42);

    $row = BusEvent::on('bus_history')->first();
    expect($row)->not->toBeNull()
        ->and($row->event_id)->toBe('evt-1')
        ->and($row->seq)->toBe(42)
        ->and($row->subject)->toBe('repo.the-shit.agent-bus.toolCall')
        ->and($row->session_id)->toBe('grok:abc')
        ->and($row->agent_type)->toBe('grok')
        ->and($row->type)->toBe('toolCall')
        ->and($row->payload)->toBe(['tool' => 'composer']);
});

it('is idempotent on event_id and seq', function () {
    $history = new BusHistory;
    $envelope = [
        'event_id' => 'evt-dup',
        'type' => 'idle',
        'payload' => [],
    ];

    $history->persist('repo.the-shit.agent-bus.idle', $envelope, 7);
    $history->persist('repo.the-shit.agent-bus.idle', $envelope, 7);
    $history->persist('repo.the-shit.agent-bus.idle', ['event_id' => 'evt-other', 'type' => 'idle', 'payload' => []], 7);

    expect(BusEvent::on('bus_history')->count())->toBe(1);
});

it('fails open when the history database errors', function () {
    $errors = [];
    $history = new BusHistory(onError: function (Throwable $exception) use (&$errors): void {
        $errors[] = $exception->getMessage();
    });

    Schema::connection('bus_history')->drop('bus_events');

    $history->persist('repo.the-shit.agent-bus.boom', [
        'event_id' => 'evt-boom',
        'type' => 'errorRaised',
        'payload' => [],
    ], 9);

    expect($errors)->not->toBeEmpty()
        ->and(fn () => $history->persist('repo.the-shit.agent-bus.boom', ['type' => 'errorRaised', 'payload' => []], 10))
        ->not->toThrow(Throwable::class);
});

it('derives event_id from the stream sequence when the envelope has none', function () {
    $history = new BusHistory;
    $history->persist('repo.the-shit.agent-bus.idle', [
        'type' => 'idle',
        'payload' => [],
    ], 99);

    expect(BusEvent::on('bus_history')->value('event_id'))->toBe('js:AGENT_BUS:99');
});
