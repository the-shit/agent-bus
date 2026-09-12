<?php

use App\Bus\NatsBus;
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
