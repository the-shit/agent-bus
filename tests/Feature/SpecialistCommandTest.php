<?php

use App\Bus\NatsBus;
use App\Bus\PiSpecialistWorker;

it('rejects an unknown specialist profile', function () {
    config(['agent_bus.specialist.profiles' => []]);

    $this->artisan('specialist', ['profile' => 'missing', '--once' => true])
        ->expectsOutputToContain('Unknown specialist profile')
        ->assertFailed();
});

it('advertises capability presence and processes one specialist request', function () {
    $profile = [
        'capability' => 'code_review',
        'consumer' => 'qwen-reviewer',
        'model' => 'ollama/qwen-test',
        'agent_file' => base_path('tests/Fixtures/pi-specialist.md'),
        'cwd' => base_path(),
        'tools' => ['read', 'grep', 'find', 'ls'],
        'timeout' => 30,
    ];
    config(['agent_bus.specialist.profiles' => ['qwen-reviewer' => $profile]]);
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('isReachable')->once()->andReturnTrue();
    $bus->shouldReceive('provision')->once();
    $bus->shouldReceive('ensureWorkConsumer')->once()->with('code_review', 'qwen-reviewer');
    $bus->shouldReceive('putSession')->twice()->withArgs(function (string $id, string $json): bool {
        $presence = json_decode($json, true);

        return str_starts_with($id, 'pi-worker:code_review:')
            && $presence['capabilities'] === ['code_review'];
    });
    $bus->shouldReceive('deleteSession')->once();
    $this->app->instance(NatsBus::class, $bus);
    $worker = Mockery::mock(PiSpecialistWorker::class);
    $worker->shouldReceive('workerId')->once()->andReturn('pi-worker:code_review:thor');
    $worker->shouldReceive('tick')->once()->with($profile)->andReturn(1);
    $this->app->instance(PiSpecialistWorker::class, $worker);

    $this->artisan('specialist', ['profile' => 'qwen-reviewer', '--once' => true])
        ->expectsOutputToContain('listening for code_review work')
        ->expectsOutputToContain('Completed 1 code_review request(s)')
        ->assertSuccessful();
});
