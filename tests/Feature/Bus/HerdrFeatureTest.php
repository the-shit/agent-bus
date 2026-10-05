<?php

use App\Bus\Herdr;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('lists agents from herdr agent list', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['herdr', 'agent', 'list']) {
            return Process::result(file_get_contents(__DIR__.'/../../Fixtures/herdr-agent-list.json'));
        }

        return Process::result(errorOutput: 'unexpected herdr command', exitCode: 1);
    });

    $herdr = new Herdr;
    $agents = $herdr->listAgents();

    expect($agents)->toHaveCount(3)
        ->and($agents[2]['pane_id'])->toBe('w2:p8')
        ->and($agents[2]['agent_session']['value'])->toBe('01a090c7-256d-7222-a356-fd21fbe3afed');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['herdr', 'agent', 'list']);
});

it('throws on herdr agent list failure', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['herdr', 'agent', 'list']) {
            return Process::result(errorOutput: 'herdr: command not found', exitCode: 1);
        }

        return Process::result(output: '{}');
    });

    $herdr = new Herdr;
    $herdr->listAgents();
})->throws(\RuntimeException::class, 'herdr agent list failed: herdr: command not found');

it('prompts a pane with JSON body', function () {
    Process::fake(function (PendingProcess $process) {
        if (($process->command[2] ?? null) === 'prompt') {
            return Process::result(output: '{"id":"test"}');
        }

        return Process::result(output: '{}');
    });

    $herdr = new Herdr;
    $paneId = 'w2:p2';
    $body = json_encode(['sessionId' => 'test', 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR);
    $herdr->prompt($paneId, $body);

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['herdr', 'agent', 'prompt', $paneId, $body]);
});

it('throws on herdr prompt failure', function () {
    Process::fake(function (PendingProcess $process) {
        if (($process->command[2] ?? null) === 'prompt') {
            return Process::result(errorOutput: 'pane not found', exitCode: 1);
        }

        return Process::result(output: '{}');
    });

    $herdr = new Herdr;
    $herdr->prompt('w2:p1', '{"test":true}');
})->throws(\RuntimeException::class, 'herdr agent prompt failed: pane not found');

it('uses custom herdr binary from config', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['/usr/local/bin/herdr', 'agent', 'prompt', 'w2:p1', '{}']) {
            return Process::result(output: '{"id":"test"}');
        }

        return Process::result(output: '{}');
    });

    config(['agent_bus.herdr.binary' => '/usr/local/bin/herdr']);

    $herdr = new Herdr;
    $herdr->prompt('w2:p1', '{}');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['/usr/local/bin/herdr', 'agent', 'prompt', 'w2:p1', '{}']);
});

it('defaults herdr binary to herdr when not configured', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['herdr', 'agent', 'list']) {
            return Process::result(output: '{}');
        }

        return Process::result(output: '{}');
    });

    config(['agent_bus.herdr.binary' => '']);

    $herdr = new Herdr;
    $herdr->listAgents();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['herdr', 'agent', 'list']);
});
