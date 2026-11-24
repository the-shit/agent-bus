<?php

use App\Bus\Cli;
use App\Bus\NatsBus;
use App\Coord\PdoCoordQueue;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

/*
 * listen --exec hardening from Artisan's review of #48: ack after the command,
 * stdin from a file, a scrubbed child environment, and a result file.
 * Throwaway sessions only.
 */

afterEach(function () {
    NatsV2::disconnectAll();
});

/** @return array<string, mixed>|null */
function wakeConsumerInfo(string $session): ?array
{
    try {
        $response = app(NatsBus::class)->stream()->getConsumer(Cli::inboxConsumerName($session))->info();
    } catch (Throwable) {
        return null;
    }

    $info = json_decode(is_object($response) && isset($response->body) ? (string) $response->body : '', true);

    return is_array($info) ? $info : null;
}

function killWakeChild(string $pidFile): void
{
    $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

    if ($pid > 1) {
        // The test's own `sleep 30`, started by the wake command it spawned.
        @posix_kill($pid, SIGKILL);
    }
}

/** @return array<string, mixed> */
function lastJsonLine(string $output): array
{
    $lines = array_values(array_filter(explode("\n", trim($output))));

    return json_decode((string) end($lines), true) ?? [];
}

it('acks a listen --exec message only after the command exits', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-ackafter-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $started = tempnam(sys_get_temp_dir(), 'm6-ack-');
    unlink($started);
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={}']);
        $listener = Process::path(base_path())->timeout(20)->start([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
            '--max=1', '--for=15', '--exec=cat >/dev/null; touch '.escapeshellarg($started).'; sleep 2',
        ]);

        $deadline = microtime(true) + 10;
        while (! is_file($started) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $during = wakeConsumerInfo($session);
        $listener->wait();
        $after = wakeConsumerInfo($session);

        expect(is_file($started))->toBeTrue()
            ->and($during['num_ack_pending'] ?? null)->toBe(1)
            ->and($after['num_ack_pending'] ?? null)->toBe(0)
            ->and($after['num_pending'] ?? null)->toBe(0)
            ->and(lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output())['payload']['state'] ?? null)->toBe('done');
    } finally {
        @unlink($started);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('redelivers a listen --exec message when the listener dies mid-command', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-redeliver-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $started = tempnam(sys_get_temp_dir(), 'm6-kill-');
    unlink($started);
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={"text":"again"}']);
        $listener = Process::path(base_path())->timeout(20)->start([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
            '--max=1', '--for=15', '--exec=cat >/dev/null; sleep 30 & echo $! > '.escapeshellarg($started).'; wait',
        ]);

        $deadline = microtime(true) + 10;
        while (! is_file($started) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $listener->signal(SIGKILL);
        // The orphaned wake command still holds the listener's stderr.
        killWakeChild($started);
        $listener->wait();
        $info = wakeConsumerInfo($session);

        // Still pending, not lost: NATS redelivers it after the ack wait.
        expect(is_file($started))->toBeTrue()
            ->and($info['num_ack_pending'] ?? null)->toBe(1);
    } finally {
        killWakeChild($started);
        @unlink($started);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('gives the wake command a scrubbed environment, its ids, and a result file', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-env-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $out = tempnam(sys_get_temp_dir(), 'm6-env-');
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--attempt=2', '--payload={}']);
        $listened = Process::path(base_path())->timeout(15)->env([
            'NATS_USER' => 'probe-user',
            'NATS_PASS' => 'probe-pass-value',
            'NATS_TLS_KEY' => '/probe/tls.key',
            'TYPESAFE_API_KEY' => 'probe-typesafe-value',
            'GITHUB_TOKEN' => 'probe-gh-value',
            'COORD_DB_PASSWORD_FILE' => '/probe/coord.pw',
            'M6_KEEP_ME' => 'kept',
        ])->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=5',
            '--exec=cat >/dev/null; env > '.escapeshellarg($out).'; printf \'{"summary":"ok","attempt":%s}\' "$AGENT_BUS_ATTEMPT" > "$AGENT_BUS_RESULT_FILE"',
        ]);
        $env = (string) file_get_contents($out);
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job, '--attempt=2'])->output());

        expect($listened->exitCode())->toBe(0)
            ->and($env)->toContain('M6_KEEP_ME=kept')
            ->and($env)->toContain('AGENT_BUS_JOB_ID='.$job)
            ->and($env)->toContain('AGENT_BUS_ATTEMPT=2')
            ->and($env)->toContain('AGENT_BUS_SESSION_ID='.$session)
            ->and($env)->not->toContain('probe-pass-value')
            ->and($env)->not->toContain('probe-user')
            ->and($env)->not->toContain('/probe/tls.key')
            ->and($env)->not->toContain('probe-typesafe-value')
            ->and($env)->not->toContain('probe-gh-value')
            ->and($env)->not->toContain('/probe/coord.pw')
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'done', 'attempt' => 2, 'exitCode' => 0])
            ->and($receipt['payload']['result'] ?? null)->toBe(['summary' => 'ok', 'attempt' => 2]);
    } finally {
        @unlink($out);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('does not block on a wake command that never reads a large envelope', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-bigstdin-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        // Bigger than a pipe buffer: a blocking pipe write would hang here.
        $sent = agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload='.json_encode(['blob' => str_repeat('x', 100_000)])]);
        $started = microtime(true);
        $listened = Process::path(base_path())->timeout(15)->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=8', '--exec=exit 0',
        ]);

        expect($sent->exitCode())->toBe(0)
            ->and($listened->exitCode())->toBe(0)
            ->and(microtime(true) - $started)->toBeLessThan(6.0)
            ->and(lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output())['payload']['state'] ?? null)->toBe('done');
    } finally {
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('scrubs credentials but keeps ordinary variables in the wake environment', function () {
    $env = Cli::wakeEnvironment([
        'PATH' => '/usr/bin',
        'HOME' => '/home/probe',
        'NATS_URL' => 'nats://127.0.0.1:4222',
        'NATS_USER' => 'u',
        'NATS_PASS' => 'p',
        'NATS_TOKEN' => 't',
        'NATS_NKEY' => 'n',
        'NATS_CREDS' => '/c',
        'NATS_TLS_CERT' => '/cert',
        'TYPESAFE_API_KEY' => 'k',
        'COORD_DB_PASSWORD_FILE' => '/pw',
        'OPENAI_API_KEY' => 'k',
        'AWS_SECRET_ACCESS_KEY' => 's',
        'DB_PASSWORD' => 'p',
        'AGENT_BUS_SESSION_ID' => 'probe',
    ]);

    expect(array_keys($env))->toBe(['PATH', 'HOME', 'NATS_URL', 'AGENT_BUS_SESSION_ID'])
        ->and(Cli::wakeEnvironment(['NATS_URL' => 'nats://user:secret@127.0.0.1:4222']))->toBe([]);
});

it('refuses a coord password file that group or other can read', function () {
    $file = tempnam(sys_get_temp_dir(), 'm6-pw-');
    file_put_contents($file, 'not-a-real-password');

    try {
        chmod($file, 0644);
        expect(fn () => PdoCoordQueue::requirePrivate($file))->toThrow(RuntimeException::class, '0600');

        chmod($file, 0600);
        PdoCoordQueue::requirePrivate($file);
        expect(true)->toBeTrue();
    } finally {
        unlink($file);
    }
});
