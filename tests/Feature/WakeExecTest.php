<?php

use App\Bus\Cli;
use App\Bus\NatsBus;
use App\Bus\Receipt;
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

/** Secrets an agent box commonly holds; none may reach a wake command unless passed. */
function wakeSecretEnv(): array
{
    return [
        'APP_KEY' => 'base64:probe-app-key',
        'DATABASE_URL' => 'postgres://probe:probe-db-pass@127.0.0.1/db',
        'REDIS_URL' => 'redis://:probe-redis-pass@127.0.0.1:6379',
        'SUPABASE_SERVICE_ROLE_KEY' => 'probe-supabase-key',
        'XAI_KEY' => 'probe-xai-key',
        'MYSQL_PWD' => 'probe-mysql-pwd',
        'SENTRY_DSN' => 'https://probe-sentry@o0.ingest.sentry.io/0',
        'SLACK_WEBHOOK_URL' => 'https://hooks.slack.com/services/PROBE/SLACK/HOOK',
        'SLACK_WEBHOOK' => 'https://hooks.slack.com/services/PROBE/SLACK/HOOK2',
        'DOCKER_AUTH_CONFIG' => '{"auths":{"probe":{"auth":"cHJvYmU="}}}',
        'SSH_AUTH_SOCK' => '/tmp/probe-ssh-agent.sock',
        'NATS_USER' => 'probe-nats-user',
        'NATS_PASS' => 'probe-nats-pass',
        'NATS_PASSWORD' => 'probe-nats-password',
        'TYPESAFE_API_KEY' => 'probe-typesafe-key',
        'AWS_ACCESS_KEY_ID' => 'probe-aws-id',
        'XDG_RUNTIME_DIR' => '/run/user/probe',
    ];
}

it('passes only allowlisted variables, the job ids, and --pass-env names to the wake command', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-env-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $out = tempnam(sys_get_temp_dir(), 'm6-env-');
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--attempt=2', '--payload={}']);
        $listened = Process::path(base_path())->timeout(15)->env([
            ...wakeSecretEnv(),
            'M6_PASSED' => 'passed-value',
            'M6_NOT_PASSED' => 'not-passed-value',
            'LC_ALL' => 'C.UTF-8',
            'TZ' => 'America/Phoenix',
        ])->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=5',
            '--pass-env=M6_PASSED', '--pass-env', 'XAI_KEY',
            '--exec=cat >/dev/null; env > '.escapeshellarg($out).'; printf \'{"summary":"ok","attempt":%s}\' "$AGENT_BUS_ATTEMPT" > "$AGENT_BUS_RESULT_FILE"',
        ]);
        $env = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($out))) as $line) {
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $env[$name] = $value;
        }
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job, '--attempt=2'])->output());
        $leaked = array_diff_key(array_intersect_key($env, wakeSecretEnv()), ['XAI_KEY' => true]);

        expect($listened->exitCode())->toBe(0)
            ->and($leaked)->toBe([])
            ->and($env)->not->toHaveKey('M6_NOT_PASSED')
            ->and($env['M6_PASSED'] ?? null)->toBe('passed-value')
            ->and($env['XAI_KEY'] ?? null)->toBe('probe-xai-key')
            ->and($env['LC_ALL'] ?? null)->toBe('C.UTF-8')
            ->and($env['TZ'] ?? null)->toBe('America/Phoenix')
            ->and($env)->toHaveKeys(['PATH', 'HOME', 'AGENT_BUS_JOB_ID', 'AGENT_BUS_ATTEMPT', 'AGENT_BUS_SESSION_ID', 'AGENT_BUS_RESULT_FILE'])
            ->and($env['AGENT_BUS_JOB_ID'])->toBe($job)
            ->and($env['AGENT_BUS_ATTEMPT'])->toBe('2')
            ->and($env)->not->toHaveKey('NATS_URL')
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'done', 'attempt' => 2, 'exitCode' => 0])
            ->and($receipt['payload']['result'] ?? null)->toBe(['summary' => 'ok', 'attempt' => 2]);
    } finally {
        @unlink($out);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('keeps only the allowlist and named pass-through variables', function () {
    $env = Cli::wakeEnvironment([
        ...wakeSecretEnv(),
        'PATH' => '/usr/bin',
        'HOME' => '/home/probe',
        'USER' => 'probe',
        'LANG' => 'C.UTF-8',
        'LC_TIME' => 'C',
        'TZ' => 'UTC',
        'TMPDIR' => '/tmp',
        'TERM' => 'dumb',
        'NATS_URL' => 'nats://127.0.0.1:4222',
    ], ['SSH_AUTH_SOCK']);

    expect(array_keys($env))->toBe(['SSH_AUTH_SOCK', 'PATH', 'HOME', 'USER', 'LANG', 'LC_TIME', 'TZ', 'TMPDIR', 'TERM'])
        ->and(Cli::passEnvNames('A, B_2,A'))->toBe(['A', 'B_2'])
        ->and(fn () => Cli::passEnvNames('BAD-NAME'))->toThrow(InvalidArgumentException::class);
});

it('kills a wake command that runs past --exec-timeout and does not redeliver it', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-timeout-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $pidFile = tempnam(sys_get_temp_dir(), 'm6-timeout-');
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={}']);
        $started = microtime(true);
        $listened = Process::path(base_path())->timeout(20)->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=15',
            '--exec-timeout=1', '--exec=cat >/dev/null; sleep 30 & echo $! > '.escapeshellarg($pidFile).'; wait',
        ]);
        $elapsed = microtime(true) - $started;
        $sleepPid = (int) trim((string) file_get_contents($pidFile));
        usleep(200_000);
        $info = wakeConsumerInfo($session);
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output());

        expect($listened->exitCode())->toBe(0)
            ->and($elapsed)->toBeLessThan(8.0)
            ->and($listened->errorOutput())->toContain('timed out')
            ->and($sleepPid)->toBeGreaterThan(1)
            // the whole process group is gone, not just the shell
            ->and(posix_kill($sleepPid, 0))->toBeFalse()
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'failed', 'exitCode' => 124, 'reason' => 'timeout'])
            ->and($info['num_ack_pending'] ?? null)->toBe(0)
            ->and($info['num_pending'] ?? null)->toBe(0);
    } finally {
        killWakeChild($pidFile);
        @unlink($pidFile);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('terminates a message that keeps killing the listener once it passes the redelivery cap', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-cap-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $pidFile = tempnam(sys_get_temp_dir(), 'm6-cap-');
    unlink($pidFile);
    $ran = $pidFile.'-ran';
    $env = ['AGENT_BUS_INBOX_ACK_WAIT_SECONDS' => '1', 'AGENT_BUS_INBOX_MAX_DELIVERIES' => '1'];
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        // send creates the consumer with these limits (ack wait 1 s, cap 1).
        agentBusCli(['send', '--session='.$session, '--job='.$job, '--payload={}'], $env);
        $first = Process::path(base_path())->timeout(20)->env($env)->start([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=15',
            '--exec=cat >/dev/null; sleep 30 & echo $! > '.escapeshellarg($pidFile).'; wait',
        ]);
        $deadline = microtime(true) + 10;
        while (! is_file($pidFile) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        usleep(100_000);
        $first->signal(SIGKILL);
        killWakeChild($pidFile);
        $first->wait();
        usleep(1_500_000); // past the 1 s ack wait

        $second = Process::path(base_path())->timeout(20)->env($env)->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=8',
            '--exec=touch '.escapeshellarg($ran),
        ]);
        usleep(200_000);
        $info = wakeConsumerInfo($session);
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output());

        expect($second->exitCode())->toBe(0)
            ->and($second->errorOutput())->toContain('delivered 2 times (cap 1)')
            ->and(is_file($ran))->toBeFalse()
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'failed', 'reason' => 'redelivery cap', 'deliveries' => 2])
            ->and($info['config']['max_deliver'] ?? null)->toBe(2)
            ->and($info['config']['ack_wait'] ?? null)->toBe(1_000_000_000)
            ->and($info['num_ack_pending'] ?? null)->toBe(0);
    } finally {
        killWakeChild($pidFile);
        @unlink($pidFile);
        @unlink($ran);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('reads the delivery count from both JetStream ack subject forms', function () {
    expect(Cli::deliveryCount('$JS.ACK.AGENT_BUS.agent-bus-inbox-x.3.10.4.1700000000000000000.0'))->toBe(3)
        ->and(Cli::deliveryCount('$JS.ACK.hub.ACCHASH.AGENT_BUS.agent-bus-inbox-x.5.10.4.1700000000000000000.0.tok'))->toBe(5)
        ->and(Cli::deliveryCount('_INBOX.abc'))->toBe(1)
        ->and(Cli::deliveryCount(null))->toBe(1);
});

it('validates --exec-timeout', function () {
    expect(Cli::execTimeout('2.5'))->toBe(2.5)
        ->and(Cli::execTimeout('0'))->toBe(0.0)
        ->and(fn () => Cli::execTimeout('soon'))->toThrow(InvalidArgumentException::class);
});

it('refuses a claim_job row without a bumped attempt', function (array $row) {
    PdoCoordQueue::claimedJob(['id' => 'f00', 'capability' => 'c', 'payload' => '{}', 'request_id' => null, ...$row]);
})->throws(RuntimeException::class)->with([
    'no attempt' => [[]],
    'attempt 0' => [['attempt' => 0]],
    'garbage' => [['attempt' => 'x']],
]);

it('maps a claim_job row with its attempt', function () {
    expect(PdoCoordQueue::claimedJob(['id' => 'f00', 'capability' => 'c', 'payload' => '{"a":1}', 'request_id' => null, 'attempt' => 2]))
        ->toMatchArray(['id' => 'f00', 'attempt' => 2, 'payload' => ['a' => 1]]);
});

it('caps --exec-timeout to the sender deadline in the envelope', function () {
    $now = 1_000_000;

    expect(Cli::boundedExecTimeout(540, []))->toBe([540.0, null])
        ->and(Cli::boundedExecTimeout(540, ['deadlineMs' => $now + 600_000], $now))->toBe([540.0, null])
        ->and(Cli::boundedExecTimeout(900, ['deadlineMs' => $now + 600_000], $now)[0])->toBe(590.0)
        ->and(Cli::boundedExecTimeout(900, ['deadlineMs' => $now + 600_000], $now)[1])->toContain('capping at 590s')
        ->and(Cli::boundedExecTimeout(0, ['deadlineMs' => $now + 600_000], $now)[0])->toBe(590.0)
        ->and(Cli::boundedExecTimeout(540, ['deadlineMs' => $now + 8_000], $now)[0])->toBeNull()
        ->and(Cli::execTimeout())->toBe(540.0)
        ->and(Cli::DEFAULT_EXEC_TIMEOUT + Cli::EXEC_KILL_GRACE + Cli::DEADLINE_MARGIN)->toBeLessThanOrEqual(600.0);
});

/** Put one envelope straight into a throwaway session's inbox, as coord-bridge does. */
function publishBridgeStyle(string $session, string $job, int $deadlineMs): void
{
    $bus = app(NatsBus::class);
    $bus->ensureSessionInbox($session);
    $bus->publishEnvelope('session.'.$session.'.inbox', [
        'v' => 2, 'sessionId' => $session, 'agentType' => 'coord-bridge', 'model' => '', 'repo' => 'the-shit/agent-bus',
        'type' => 'job', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'jobId' => $job, 'attempt' => 1,
        'sentAtMs' => Receipt::nowMs(), 'deadlineMs' => $deadlineMs, 'payload' => [],
    ], Receipt::inboxMessageId($session, $job, 1));
}

it('kills a wake command before the sender deadline even with no --exec-timeout', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-deadline-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $pidFile = tempnam(sys_get_temp_dir(), 'm6-deadline-');
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        // 12 s left: 12 - 5 grace - 5 margin = 2 s for the command.
        publishBridgeStyle($session, $job, Receipt::nowMs() + 12_000);
        $started = microtime(true);
        $listened = Process::path(base_path())->timeout(20)->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=15',
            '--exec-timeout=0', '--exec=cat >/dev/null; sleep 30 & echo $! > '.escapeshellarg($pidFile).'; wait',
        ]);
        $elapsed = microtime(true) - $started;
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output());

        expect($listened->exitCode())->toBe(0)
            ->and($listened->errorOutput())->toContain('capping at')
            ->and($elapsed)->toBeLessThan(9.0)
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'failed', 'reason' => 'timeout', 'exitCode' => 124]);
    } finally {
        killWakeChild($pidFile);
        @unlink($pidFile);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('does not run a wake command whose sender deadline has already passed', function () {
    app(NatsBus::class)->provision();
    $session = 'm6-late-'.bin2hex(random_bytes(4));
    $job = 'job-'.bin2hex(random_bytes(6));
    $ran = tempnam(sys_get_temp_dir(), 'm6-late-');
    unlink($ran);
    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);

    try {
        publishBridgeStyle($session, $job, Receipt::nowMs() - 1_000);
        $listened = Process::path(base_path())->timeout(15)->run([
            PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session, '--max=1', '--for=8',
            '--exec=touch '.escapeshellarg($ran),
        ]);
        usleep(200_000);
        $info = wakeConsumerInfo($session);
        $receipt = lastJsonLine(agentBusCli(['receipt', '--job='.$job])->output());

        expect($listened->exitCode())->toBe(0)
            ->and(is_file($ran))->toBeFalse()
            ->and($receipt['payload'] ?? [])->toMatchArray(['state' => 'failed', 'reason' => 'deadline passed'])
            ->and($info['num_ack_pending'] ?? null)->toBe(0)
            ->and($info['num_pending'] ?? null)->toBe(0);
    } finally {
        @unlink($ran);
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');
