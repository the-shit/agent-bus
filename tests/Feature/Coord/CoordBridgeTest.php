<?php

use App\Bus\NatsBus;
use App\Coord\CoordBridge;
use App\Coord\CoordQueue;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

/*
 * M6 with coord as the source of truth: the bridge claims a coord job, puts
 * it in one throwaway bus session's inbox keyed by the coord job id, and maps
 * that job's bus receipts back to coord calls. coord is faked here; the
 * opt-in CoordLiveTest runs the same path against a real coord schema.
 */

afterEach(function () {
    NatsV2::disconnectAll();
});

final class FakeCoordQueue implements CoordQueue
{
    /** @var list<array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string}> */
    public array $queued = [];

    /** @var list<array{string, mixed}> */
    public array $calls = [];

    public int $claims = 0;

    /** Claims that throw before any succeeds (coord unreachable). */
    public int $claimErrors = 0;

    public function claim(array $capabilities, int $leaseSeconds): ?array
    {
        $this->claims++;

        if ($this->claimErrors > 0) {
            $this->claimErrors--;

            throw new RuntimeException('coord is down');
        }

        return array_shift($this->queued);
    }

    public function heartbeat(string $jobId, int $leaseSeconds): void
    {
        $this->calls[] = ['heartbeat', $jobId];
    }

    public function complete(string $jobId, array $result): void
    {
        $this->calls[] = ['complete', $jobId, $result];
    }

    public function fail(string $jobId, string $error, bool $retry = true): string
    {
        $this->calls[] = ['fail', $jobId, $error];

        return 'queued';
    }

    public function waitForNotification(int $timeoutMs): ?array
    {
        usleep(min($timeoutMs, 50) * 1000);

        return null;
    }
}

function fakeCoordJob(int $attempt = 1): array
{
    return [
        'attempt' => $attempt,
        'id' => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF)),
        'capability' => 'm6-probe',
        'payload' => ['text' => 'good morning: PRs'],
        'request_id' => null,
    ];
}

it('delivers a claimed coord job to the session by job id and completes it from the done receipt', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $session = 'm6-bridge-'.bin2hex(random_bytes(4));
    $coord = new FakeCoordQueue;
    $coord->queued[] = $job = fakeCoordJob();
    $out = tempnam(sys_get_temp_dir(), 'm6-bridge-');

    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
    $listener = Process::path(base_path())->timeout(30)->start([
        PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
        '--max=1', '--for=20', '--exec=cat > '.escapeshellarg($out),
    ]);

    try {
        usleep(800_000);
        $outcome = (new CoordBridge($coord, $bus, $session, ['m6-probe'], 60, 10.0))->handle($job);
        $listener->wait();
        $woke = json_decode((string) file_get_contents($out), true);

        expect($outcome)->toBe('done')
            ->and($woke['jobId'])->toBe($job['id'])
            ->and($woke['type'])->toBe('job')
            ->and($woke['payload']['task'])->toBe(['text' => 'good morning: PRs'])
            // A fast target can reach done before the bridge sees delivered,
            // so the heartbeat is optional; complete is not.
            ->and(end($coord->calls)[0])->toBe('complete')
            ->and(end($coord->calls)[2])->toMatchArray(['exitCode' => 0])
            ->and(array_count_values(array_column($coord->calls, 0))['complete'])->toBe(1);
    } finally {
        if ($listener->running()) {
            $listener->stop();
        }
        @unlink($out);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('fails the coord job (retry) when the session reports failed', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $session = 'm6-bridge-fail-'.bin2hex(random_bytes(4));
    $coord = new FakeCoordQueue;
    $job = fakeCoordJob();
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    $listener = Process::path(base_path())->timeout(30)->start([
        PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
        '--max=1', '--for=20', '--exec=cat >/dev/null; exit 3',
    ]);

    try {
        usleep(800_000);
        $outcome = (new CoordBridge($coord, $bus, $session, [], 60, 10.0))->handle($job);
        $listener->wait();

        expect($outcome)->toBe('requeued')
            ->and(end($coord->calls)[0])->toBe('fail')
            ->and(end($coord->calls)[2])->toContain('exit 3');
    } finally {
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('fails the coord job when nothing receipts it before the deadline', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $session = 'm6-bridge-deaf-'.bin2hex(random_bytes(4));
    $coord = new FakeCoordQueue;
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        $outcome = (new CoordBridge($coord, $bus, $session, [], 60, 0.5))->handle(fakeCoordJob());

        expect($outcome)->toBe('requeued')
            ->and($coord->calls[0][0])->toBe('fail')
            ->and($coord->calls[0][2])->toContain('never delivered');
    } finally {
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('does not claim work for a session that is not on the bus', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $coord = new FakeCoordQueue;
    $coord->queued[] = fakeCoordJob();

    $handled = (new CoordBridge($coord, $bus, 'm6-absent-'.bin2hex(random_bytes(4))))->run(forSeconds: 0.3, notifyTimeoutMs: 50);

    expect($handled)->toBe(0)
        ->and($coord->claims)->toBe(0)
        ->and($coord->queued)->toHaveCount(1);
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('re-handles a requeued coord job: attempt 2 is delivered again and completes after attempt 1 failed', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $session = 'm6-bridge-retry-'.bin2hex(random_bytes(4));
    $coord = new FakeCoordQueue;
    $first = fakeCoordJob(1);
    // coord requeues the same job id and bumps attempt on the next claim.
    $second = [...$first, 'attempt' => 2];
    $dir = sys_get_temp_dir().'/m6-retry-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    // Fails the first time it runs, succeeds the second.
    $exec = 'cat >> '.escapeshellarg($dir.'/seen').'; echo "$AGENT_BUS_ATTEMPT" >> '.escapeshellarg($dir.'/attempts')
        .'; if [ -f '.escapeshellarg($dir.'/once').' ]; then exit 0; fi; touch '.escapeshellarg($dir.'/once').'; exit 3';
    $listener = Process::path(base_path())->timeout(40)->start([
        PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
        '--max=2', '--for=30', '--exec='.$exec,
    ]);

    try {
        usleep(800_000);
        $bridge = new CoordBridge($coord, $bus, $session, [], 60, 10.0);
        $outcome1 = $bridge->handle($first);
        $outcome2 = $bridge->handle($second);
        $listener->wait();
        $seen = array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/seen')))));

        expect($outcome1)->toBe('requeued')
            ->and($outcome2)->toBe('done')
            ->and($seen)->toHaveCount(2)
            ->and(array_column($seen, 'jobId'))->toBe([$first['id'], $first['id']])
            ->and(array_column($seen, 'attempt'))->toBe([1, 2])
            ->and(array_map('intval', array_filter(explode("\n", (string) file_get_contents($dir.'/attempts')))))->toBe([1, 2])
            ->and(array_column($coord->calls, 0))->toContain('fail', 'complete')
            ->and(end($coord->calls)[0])->toBe('complete')
            ->and(end($coord->calls)[2])->toMatchArray(['exitCode' => 0]);
    } finally {
        if ($listener->running()) {
            $listener->stop();
        }
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('ignores a done receipt from a session the job was not delivered to', function () {
    $bus = app(NatsBus::class);
    $bus->provision();
    $session = 'm6-bridge-trust-'.bin2hex(random_bytes(4));
    $coord = new FakeCoordQueue;
    $job = fakeCoordJob();
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
    // Another session claims the job is done before the bridge even sends it.
    agentBusCli(['receipt', '--job='.$job['id'], '--state=done', '--session=m6-intruder-'.bin2hex(random_bytes(4))]);

    try {
        $outcome = (new CoordBridge($coord, $bus, $session, [], 60, 0.8))->handle($job);

        expect($outcome)->toBe('requeued')
            ->and(array_column($coord->calls, 0))->not->toContain('complete')
            ->and(end($coord->calls)[2])->toContain('never delivered');
    } finally {
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('hands a claimed job back to coord when the bus fails mid-delivery, and keeps running', function () {
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('resolveSessionId')->andReturn('m6-session');
    $bus->shouldReceive('ensureSessionInbox')->andThrow(new RuntimeException('NATS broker is down'));
    $coord = new FakeCoordQueue;
    $coord->claimErrors = 1;
    $coord->queued[] = $job = fakeCoordJob();
    $sleeps = [];
    $lines = [];
    $now = 0.0;

    $bridge = new CoordBridge(
        $coord, $bus, 'm6-session', [], 60, 1.0,
        log: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        clock: function () use (&$now): float {
            return $now += 0.01;
        },
        sleep: function (float $seconds) use (&$sleeps, &$now): void {
            $sleeps[] = $seconds;
            $now += $seconds;
        },
    );

    $handled = $bridge->run(forSeconds: 5.0, notifyTimeoutMs: 1);

    expect($handled)->toBe(0)
        // claim error, then the delivery error: both backed off, not fatal
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[1])->toBeGreaterThan($sleeps[0] - 0.0001)
        ->and($coord->calls[0][0])->toBe('fail')
        ->and($coord->calls[0][1])->toBe($job['id'])
        ->and($coord->calls[0][2])->toContain('bridge error on attempt 1')
        ->and(implode("\n", $lines))->toContain('coord is down')
        ->and(implode("\n", $lines))->toContain('NATS broker is down');
});
