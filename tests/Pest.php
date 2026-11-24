<?php

use App\Bus\NatsBus;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel Zero kernel so they can drive the cold-path
| commands. Unit tests stay on plain PHPUnit: App\Bus mappers are pure
| functions and must not need a container to prove.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Broker availability
|--------------------------------------------------------------------------
|
| NATS-backed tests skip on a laptop with no broker. In CI that would hide
| the whole product, so AGENT_BUS_REQUIRE_BROKER=1 turns the skip into a
| failure instead.
|
*/

function brokerIsDown(): bool
{
    $reachable = app(NatsBus::class)->isReachable();

    if (! $reachable && getenv('AGENT_BUS_REQUIRE_BROKER') === '1') {
        throw new RuntimeException(
            'AGENT_BUS_REQUIRE_BROKER=1 but nothing is listening on the NATS port.'
        );
    }

    return ! $reachable;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function agentBusCli(array $arguments, array $environment = []): ProcessResult
{
    return Process::path(base_path())
        ->timeout(5)
        ->env($environment)
        ->run([
            PHP_BINARY,
            base_path('bin/agent-bus'),
            ...$arguments,
        ]);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function agentBusCliWithStdin(array $arguments, string $stdin, array $environment = []): ProcessResult
{
    return Process::path(base_path())
        ->timeout(5)
        ->env($environment)
        ->input($stdin)
        ->run([
            PHP_BINARY,
            base_path('bin/agent-bus'),
            ...$arguments,
        ]);
}

function stderrLines(ProcessResult $result): array
{
    return array_values(array_filter(
        explode("\n", trim($result->errorOutput())),
        fn (string $line): bool => $line !== '',
    ));
}
