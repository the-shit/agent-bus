<?php

use Illuminate\Support\Facades\Process;

/*
 * Opt-in: the full M6 path against a real coord schema
 * (house/toolkit sbprod/coord/migrations/0001_coord_init.sql).
 * Never point this at live sbprod; use a throwaway Postgres with the
 * migration applied and stand-in roles. Skipped unless COORD_TEST_HOST is set.
 *
 *   COORD_TEST_HOST, COORD_TEST_PORT
 *   COORD_TEST_WORKER_USER, COORD_TEST_WORKER_PASSWORD_FILE  (runs coord-bridge)
 *   COORD_TEST_SENDER_USER, COORD_TEST_SENDER_PASSWORD_FILE  (inserts the job)
 */

function coordTestConfigured(): bool
{
    return (string) getenv('COORD_TEST_HOST') !== '';
}

function coordTestPdo(string $who): PDO
{
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=postgres', getenv('COORD_TEST_HOST'), (int) (getenv('COORD_TEST_PORT') ?: 5432)),
        (string) getenv("COORD_TEST_{$who}_USER"),
        trim((string) file_get_contents((string) getenv("COORD_TEST_{$who}_PASSWORD_FILE"))),
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

it('AT-6 via coord: a job inserted for an idle Thor session wakes it, and coord shows done within 5 s', function () {
    $session = 'm6-coord-'.bin2hex(random_bytes(4));
    $capability = 'm6-probe-'.bin2hex(random_bytes(3));
    $worker = (string) getenv('COORD_TEST_WORKER_USER');

    agentBusCli(['heartbeat', '--session='.$session, '--agent-type=probe']);
    $listener = Process::path(base_path())->timeout(30)->start([
        PHP_BINARY, base_path('bin/agent-bus'), 'listen', '--session='.$session,
        '--agent-type=probe', '--max=1', '--for=20', '--exec=cat >/dev/null',
    ]);
    $bridge = Process::path(base_path())->timeout(30)->env([
        'COORD_DB_HOST' => (string) getenv('COORD_TEST_HOST'),
        'COORD_DB_PORT' => (string) (getenv('COORD_TEST_PORT') ?: 5432),
        'COORD_DB_USER' => $worker,
        'COORD_DB_PASSWORD_FILE' => (string) getenv('COORD_TEST_WORKER_PASSWORD_FILE'),
    ])->start([
        PHP_BINARY, base_path('bin/agent-bus'), 'coord-bridge', '--session='.$session,
        '--capabilities='.$capability, '--max=1', '--for=20', '--lease=60', '--wait=10',
    ]);

    try {
        usleep(2_000_000); // both idle: bridge in LISTEN coord, listener on its inbox

        $sender = coordTestPdo('SENDER');
        $insert = $sender->prepare("INSERT INTO coord.jobs (capability, assigned_agent, payload) VALUES (:cap, :agent, '{\"text\":\"good morning: PRs\"}'::jsonb) RETURNING id::text");
        $started = microtime(true);
        $insert->execute(['cap' => $capability, 'agent' => $worker]);
        $jobId = (string) $insert->fetchColumn();

        $status = 'queued';
        while (microtime(true) - $started < 10 && ! in_array($status, ['done', 'failed'], true)) {
            usleep(50_000);
            $row = $sender->prepare('SELECT status FROM coord.jobs WHERE id = CAST(:id AS uuid)');
            $row->execute(['id' => $jobId]);
            $status = (string) $row->fetchColumn();
        }
        $elapsed = microtime(true) - $started;
        fwrite(STDERR, sprintf("[m6] coord job %s %s after %.0f ms\n", $jobId, $status, $elapsed * 1000));

        $events = $sender->prepare('SELECT kind FROM coord.events WHERE job_id = CAST(:id AS uuid) ORDER BY id');
        $events->execute(['id' => $jobId]);
        $kinds = $events->fetchAll(PDO::FETCH_COLUMN);
        $receipt = json_decode(trim(agentBusCli(['receipt', '--job='.$jobId])->output()), true);

        expect($status)->toBe('done')
            ->and($elapsed)->toBeLessThan(5.0)
            ->and($kinds)->toContain('job.claimed', 'job.done')
            ->and($receipt['payload'])->toMatchArray(['jobId' => $jobId, 'state' => 'done']);
    } finally {
        foreach ([$listener, $bridge] as $process) {
            if ($process->running()) {
                $process->stop();
            }
        }
        agentBusCli(['session-end', '--session='.$session]);
    }
})->skip(fn () => ! coordTestConfigured() || brokerIsDown(), 'COORD_TEST_HOST not set (opt-in) or NATS broker is not running');
