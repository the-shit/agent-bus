<?php

use App\Bus\JudgeWorker;
use App\Bus\NatsBus;

it('fails before touching NATS when the TypeSafe key is missing', function () {
    config(['agent_bus.judge.api_key' => null]);

    $this->artisan('judge', ['--once' => true])
        ->expectsOutputToContain('TYPESAFE_API_KEY is not configured')
        ->assertFailed();
});

it('processes one judgment batch and exits', function () {
    config(['agent_bus.judge.api_key' => 'test-key']);

    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('isReachable')->once()->andReturn(true);
    $bus->shouldReceive('provision')->once();
    $bus->shouldReceive('ensureWorkConsumer')->once()->with('tool_failure_triage', 'agent-bus-judge');
    $bus->shouldReceive('putSession')->twice()->withArgs(function (string $id, string $json): bool {
        $presence = json_decode($json, true);

        return str_starts_with($id, 'judge:tool_failure_triage:')
            && $presence['capabilities'] === ['tool_failure_triage'];
    });
    $bus->shouldReceive('deleteSession')->once();
    $this->app->instance(NatsBus::class, $bus);

    $worker = Mockery::mock(JudgeWorker::class);
    $worker->shouldReceive('tick')->once()->andReturn(2);
    $this->app->instance(JudgeWorker::class, $worker);

    $this->artisan('judge', ['--once' => true])
        ->expectsOutputToContain('Jev judge is listening for tool_failure_triage work')
        ->expectsOutputToContain('Judged 2 request(s)')
        ->assertSuccessful();
});
