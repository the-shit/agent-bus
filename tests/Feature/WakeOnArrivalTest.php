<?php

use App\Bus\Cli;
use App\Bus\NatsBus;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

/*
 * M6 (shadetheory/agent-bus#1): wake-on-arrival and receipts keyed by job id.
 * Every test uses a throwaway session id and cleans it up; nothing here may
 * address a live session.
 */

afterEach(function () {
    NatsV2::disconnectAll();
});

function throwawaySession(string $label): string
{
    return 'm6-'.$label.'-'.bin2hex(random_bytes(4));
}

function cleanupThrowaway(string $sessionId): void
{
    agentBusCli(['session-end', '--session='.$sessionId]);
}

/** @return array<string, mixed> */
function jsonLine(string $output, int $index = 0): array
{
    $lines = array_values(array_filter(explode("\n", trim($output)), fn (string $line): bool => $line !== ''));

    return json_decode($lines[$index] ?? 'null', true) ?? [];
}

describe('receipts keyed by job id', function () {
    it('send tags the message with the given job id and records queued', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('queued');
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
        $job = 'job-'.bin2hex(random_bytes(6));

        try {
            $sent = agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"ping"}']);
            $receipt = agentBusCli(['receipt', '--job='.$job]);

            expect($sent->exitCode())->toBe(0)
                ->and(jsonLine($sent->output())['jobId'])->toBe($job)
                ->and($receipt->exitCode())->toBe(0)
                ->and(jsonLine($receipt->output())['payload'])->toMatchArray(['jobId' => $job, 'state' => 'queued', 'sessionId' => $session]);
        } finally {
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('generates a job id when the sender gives none', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('genid');
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

        try {
            $sent = agentBusCli(['send', '--session='.$session, '--payload={"text":"ping"}']);

            expect(jsonLine($sent->output())['jobId'])->toMatch('/^[0-9a-f]{32}$/');
        } finally {
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('moves the receipt to delivered when the target pulls its inbox', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('delivered');
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
        $job = 'job-'.bin2hex(random_bytes(6));

        try {
            agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"ping"}']);
            $pulled = agentBusCli(['inbox', '--session='.$session], ['AGENT_BUS_INBOX_EXPIRES' => '0.5']);
            $receipt = agentBusCli(['receipt', '--job='.$job, '--until=delivered', '--wait=2']);

            expect($pulled->exitCode())->toBe(0)
                ->and(jsonLine($pulled->output())['jobId'])->toBe($job)
                ->and(jsonLine($receipt->output())['payload']['state'])->toBe('delivered')
                ->and(jsonLine($receipt->output())['payload']['latencyMs'])->toBeInt();
        } finally {
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('does not let a late queued receipt hide delivered', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('order');
        $job = 'job-'.bin2hex(random_bytes(6));

        agentBusCli(['receipt', '--job='.$job, '--state=delivered', '--session='.$session]);
        agentBusCli(['receipt', '--job='.$job, '--state=queued', '--session='.$session]);
        $receipt = agentBusCli(['receipt', '--job='.$job]);

        expect(jsonLine($receipt->output())['payload']['state'])->toBe('delivered');
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('delivers a re-sent job once', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('dedupe');
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
        $job = 'job-'.bin2hex(random_bytes(6));

        try {
            agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"one"}']);
            agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"one"}']);
            $first = agentBusCli(['inbox', '--session='.$session], ['AGENT_BUS_INBOX_EXPIRES' => '0.5']);
            $second = agentBusCli(['inbox', '--session='.$session], ['AGENT_BUS_INBOX_EXPIRES' => '0.3']);

            expect($first->exitCode())->toBe(0)
                ->and($second->exitCode())->toBe(2);
        } finally {
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('reports a missing receipt with exit 2', function () {
        app(NatsBus::class)->provision();
        $result = agentBusCli(['receipt', '--job=never-'.bin2hex(random_bytes(4))]);

        expect($result->exitCode())->toBe(2);
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('rejects a job id that is not one subject token', function () {
        $result = agentBusCli(['send', '--session=x', '--job=a.b', '--payload={}'], ['NATS_URL' => 'nats://127.0.0.1:1']);

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('--job must be');
    });
});

describe('wake-on-arrival', function () {
    it('AT-6: wakes an idle listening session within 5 s and returns a delivered receipt keyed by job id', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('wake');
        $job = 'job-'.bin2hex(random_bytes(6));

        $listener = Process::path(base_path())->timeout(30)->start([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen',
            '--session='.$session, '--agent-type=probe', '--max=1', '--for=20',
        ]);

        try {
            // Idle: registered, nothing in its inbox, blocked on its own consumer.
            $deadline = microtime(true) + 5;
            while (agentBusCli(['sessions', 'get', $session])->exitCode() !== 0 && microtime(true) < $deadline) {
                usleep(100_000);
            }
            usleep(1_500_000);

            $started = microtime(true);
            $sent = agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"wake up"}', '--wait-receipt=5'], []);
            $elapsed = microtime(true) - $started;

            $listened = $listener->wait();
            $woke = jsonLine($listened->output());
            $receipt = jsonLine($sent->output(), 1);

            expect($sent->exitCode())->toBe(0)
                ->and($elapsed)->toBeLessThan(5.0)
                ->and($receipt['payload'])->toMatchArray(['jobId' => $job, 'state' => 'delivered', 'sessionId' => $session])
                ->and($receipt['payload']['latencyMs'])->toBeLessThan(5000)
                ->and($woke['jobId'])->toBe($job)
                ->and($woke['payload'])->toBe(['text' => 'wake up'])
                ->and($listened->exitCode())->toBe(0);
        } finally {
            if ($listener->running()) {
                $listener->stop();
            }
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('runs --exec with the envelope on stdin and records done from its exit code', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('exec');
        $job = 'job-'.bin2hex(random_bytes(6));
        $out = tempnam(sys_get_temp_dir(), 'm6-exec-');
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

        try {
            agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"run"}']);
            $listened = Process::path(base_path())->timeout(15)->run([
                PHP_BINARY, base_path('bin/agent-bus'), 'listen',
                '--session='.$session, '--max=1', '--for=5',
                '--exec=cat > '.escapeshellarg($out).'; printf %s "$AGENT_BUS_JOB_ID" >> '.escapeshellarg($out),
            ]);
            $receipt = agentBusCli(['receipt', '--job='.$job]);
            $written = (string) file_get_contents($out);

            expect($listened->exitCode())->toBe(0)
                ->and($written)->toContain('"text":"run"')
                ->and($written)->toEndWith($job)
                ->and(jsonLine($receipt->output())['payload'])->toMatchArray(['state' => 'done', 'exitCode' => 0]);
        } finally {
            @unlink($out);
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('records failed when the wake command exits non-zero', function () {
        app(NatsBus::class)->provision();
        $session = throwawaySession('execfail');
        $job = 'job-'.bin2hex(random_bytes(6));
        agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

        try {
            agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={}']);
            Process::path(base_path())->timeout(15)->run([
                PHP_BINARY, base_path('bin/agent-bus'), 'listen',
                '--session='.$session, '--max=1', '--for=5', '--exec=cat >/dev/null; exit 7',
            ]);

            expect(jsonLine(agentBusCli(['receipt', '--job='.$job])->output())['payload'])
                ->toMatchArray(['state' => 'failed', 'exitCode' => 7]);
        } finally {
            cleanupThrowaway($session);
        }
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('refuses to listen for an unregistered session without --agent-type', function () {
        app(NatsBus::class)->provision();
        $result = agentBusCli(['listen', '--session='.throwawaySession('ghost'), '--for=1']);

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('pass --agent-type');
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');

    it('keeps listen and receipt on the hot path', function () {
        expect(Cli::HOT_VERBS)->toContain('listen', 'receipt');
    });
});

describe('house/board#105 end-to-end', function () {
    it('a throwaway fixture session heartbeats, is sent a message, pulls its own inbox, and leaves no trace', function () {
        $bus = app(NatsBus::class);
        $bus->provision();
        $session = throwawaySession('board105');
        $job = 'board105-'.bin2hex(random_bytes(6));

        $beat = agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe', '--model=fixture']);
        $listed = agentBusCli(['sessions', 'get', $session]);
        $sent = agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"board 105 fixture ping"}']);
        $queued = agentBusCli(['receipt', '--job='.$job]);
        $pulled = agentBusCli(['inbox', '--session='.$session], ['AGENT_BUS_INBOX_EXPIRES' => '0.5']);
        $delivered = agentBusCli(['receipt', '--job='.$job, '--until=delivered', '--wait=2']);
        $ended = agentBusCli(['session-end', '--session='.$session]);
        $gone = agentBusCli(['sessions', 'get', $session]);

        expect($beat->exitCode())->toBe(0)
            ->and($listed->exitCode())->toBe(0)
            ->and($sent->exitCode())->toBe(0)
            ->and(jsonLine($queued->output())['payload']['state'])->toBe('queued')
            ->and($pulled->exitCode())->toBe(0)
            ->and(jsonLine($pulled->output())['payload'])->toBe(['text' => 'board 105 fixture ping'])
            ->and(jsonLine($pulled->output())['jobId'])->toBe($job)
            ->and(jsonLine($delivered->output())['payload']['state'])->toBe('delivered')
            ->and($ended->exitCode())->toBe(0)
            ->and($gone->exitCode())->not->toBe(0)
            ->and($bus->stream()->getConsumer(Cli::inboxConsumerName($session))->exists())->toBeFalse();
    })->skip(fn () => brokerIsDown(), 'NATS broker is not running');
});
