<?php

use App\Models\BusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-13 03:00:00'));
});

it('writes a digest of failed .local tool calls and excludes other models', function () {
    $path = storage_path('app/studies/failures-2026-09-13.md');

    File::delete($path);

    BusEvent::create([
        'seq' => 1001,
        'subject' => 'repo.the-shit.agent-bus.toolCall',
        'session_id' => 'session-a',
        'model' => 'agent-bus-ft-v1.local',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'payload' => ['tool' => 'x', 'exit_code' => 1, 'error' => 'boom'],
        'occurred_at' => Carbon::parse('2026-09-13T02:30:00Z'),
        'received_at' => now(),
    ]);

    BusEvent::create([
        'seq' => 1002,
        'subject' => 'repo.the-shit.agent-bus.toolCall',
        'session_id' => 'session-success',
        'model' => 'agent-bus-ft-v1.local',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'payload' => ['tool' => 'x', 'exit_code' => 0],
        'occurred_at' => now()->subMinutes(10),
        'received_at' => now(),
    ]);

    BusEvent::create([
        'seq' => 1003,
        'subject' => 'repo.the-shit.agent-bus.toolCall',
        'session_id' => 'session-other',
        'model' => 'gpt-6',
        'repo' => 'the-shit/agent-bus',
        'type' => 'toolCall',
        'payload' => ['tool' => 'x', 'exit_code' => 1],
        'occurred_at' => now()->subMinutes(5),
        'received_at' => now(),
    ]);

    $this->artisan('agent-bus:study-failures')
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $digest = File::get($path);

    expect($digest)
        ->toContain('**1 failed tool call(s)** across **1 .local model(s)**')
        ->toContain('agent-bus-ft-v1.local')
        ->toContain('/ x /')
        ->toContain('session-a')
        ->not->toContain('gpt-6');
});
