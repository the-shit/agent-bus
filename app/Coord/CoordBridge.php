<?php

namespace App\Coord;

use App\Bus\NatsBus;
use App\Bus\Receipt;
use Closure;
use PDOException;
use Throwable;

/**
 * Wake-on-arrival for coord jobs. Claims a job (coord is the source of truth),
 * puts it in one bus session's inbox keyed by the coord job id and attempt,
 * and follows that attempt's bus receipts back into coord:
 *
 *   delivered -> coord.heartbeat_job   (the target is awake and holds it)
 *   done      -> coord.complete_job
 *   failed    -> coord.fail_job (retry)
 *   no receipt before the deadline -> coord.fail_job (retry)
 *   bridge error after the claim   -> coord.fail_job (retry), then back off
 *
 * coord bumps jobs.attempt on every claim, so a requeued job gets fresh
 * receipt subjects and a fresh inbox msg-id and is handled again.
 *
 * Trust model: only a receipt from the session the bridge delivered to, for
 * the attempt it delivered, moves the job. The bus has no per-sender auth, so
 * this stops strays and other sessions, not a client holding broker
 * credentials; restrict publish on work.receipt.> with NATS permissions for
 * that.
 *
 * The target session runs `bin/agent-bus listen`, which fires on arrival.
 * The bridge itself wakes on LISTEN coord instead of polling.
 */
final class CoordBridge
{
    /** @var Closure(string): void */
    private Closure $log;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(float): void */
    private Closure $sleep;

    /**
     * Last attempt delivered per job id, newest last (bounded).
     *
     * @var array<string, int>
     */
    private array $lastAttempt = [];

    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        private readonly CoordQueue $coord,
        private readonly NatsBus $bus,
        private readonly string $sessionId,
        private readonly array $capabilities = [],
        private readonly int $leaseSeconds = 300,
        private readonly float $receiptWaitSeconds = 600.0,
        ?Closure $log = null,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * Run until $maxJobs jobs are handled (0 = no limit) or $forSeconds pass
     * (0 = forever). Returns the jobs handled. An error (coord or bus down)
     * is logged and retried with backoff, never fatal; a job claimed before
     * the error is handed back to coord.
     */
    public function run(int $maxJobs = 0, float $forSeconds = 0.0, int $notifyTimeoutMs = 5000): int
    {
        $deadline = $forSeconds > 0 ? ($this->clock)() + $forSeconds : INF;
        $handled = 0;
        $errors = 0;

        while (($this->clock)() < $deadline) {
            try {
                if ($this->bus->resolveSessionId($this->sessionId) === null) {
                    // Do not claim work for a session that cannot receive it.
                    ($this->log)("session {$this->sessionId} is not on the bus; not claiming");
                    $this->coord->waitForNotification($notifyTimeoutMs);
                    $errors = 0;

                    continue;
                }

                $job = $this->coord->claim($this->capabilities, $this->leaseSeconds);

                if ($job === null) {
                    // Catch-up claim found nothing: sleep until coord says something changed.
                    $this->coord->waitForNotification($notifyTimeoutMs);
                    $errors = 0;

                    continue;
                }

                $this->handle($job);
                $handled++;
                $errors = 0;
            } catch (Throwable $exception) {
                $errors++;
                $backoff = min(30.0, 2 ** min($errors - 1, 5));
                ($this->log)('bridge error: '.self::describe($exception)."; retrying in {$backoff}s");
                ($this->sleep)(min($backoff, max(0.0, $deadline - ($this->clock)())));

                continue;
            }

            if ($maxJobs > 0 && $handled >= $maxJobs) {
                break;
            }
        }

        return $handled;
    }

    /**
     * @param  array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string, attempt?: int}  $job
     * @return string the coord outcome: done, failed, or requeued
     */
    public function handle(array $job): string
    {
        $jobId = Receipt::jobId($job['id']);
        $attempt = Receipt::attempt($job['attempt'] ?? 1);
        $this->requireBumpedAttempt($jobId, $attempt);

        try {
            return $this->deliver($job, $jobId, $attempt);
        } catch (Throwable $exception) {
            // Never leave a job leased on an error: hand it back for a retry.
            try {
                $status = $this->coord->fail($jobId, 'bridge error on attempt '.$attempt.': '.self::describe($exception));
                ($this->log)("job {$jobId} attempt {$attempt} bridge error -> {$status}");
            } catch (Throwable $failError) {
                ($this->log)("job {$jobId} attempt {$attempt}: could not fail it (".self::describe($failError).'); coord.reclaim_expired_jobs requeues it when the lease runs out');
            }

            throw $exception;
        }
    }

    /**
     * @param  array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string, attempt?: int}  $job
     */
    private function deliver(array $job, string $jobId, int $attempt): string
    {
        $session = $this->bus->resolveSessionId($this->sessionId) ?? $this->sessionId;

        $envelope = [
            'v' => 2,
            'sessionId' => $session,
            'agentType' => 'coord-bridge',
            'model' => '',
            'repo' => 'the-shit/agent-bus',
            'type' => 'job',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'jobId' => $jobId,
            'attempt' => $attempt,
            'sentAtMs' => Receipt::nowMs(),
            'payload' => [
                'jobId' => $jobId,
                'attempt' => $attempt,
                'requestId' => $job['request_id'],
                'capability' => $job['capability'],
                'task' => $job['payload'],
            ],
        ];

        $this->bus->ensureSessionInbox($session);
        $this->bus->publishEnvelope('session.'.$session.'.inbox', $envelope, Receipt::inboxMessageId($session, $jobId, $attempt));
        $this->bus->publishEnvelope(Receipt::subject($jobId, $attempt, 'queued'), Receipt::envelope($jobId, $attempt, 'queued', $session, 'coord-bridge'));
        ($this->log)("job {$jobId} attempt {$attempt} claimed and sent to {$session}");

        $deadline = ($this->clock)() + $this->receiptWaitSeconds;
        $lastBeat = ($this->clock)();
        $delivered = false;

        while (($this->clock)() < $deadline) {
            $receipt = $this->furthestReceipt($jobId, $attempt, $session);
            $state = $receipt['payload']['state'] ?? null;

            if ($state === 'done') {
                $result = $receipt['payload']['result'] ?? null;
                $this->coord->complete($jobId, is_array($result) ? $result : array_filter([
                    'exitCode' => $receipt['payload']['exitCode'] ?? null,
                    'session' => $session,
                    'attempt' => $attempt,
                ], fn ($value) => $value !== null));
                ($this->log)("job {$jobId} attempt {$attempt} done");

                return 'done';
            }

            if ($state === 'failed') {
                $status = $this->coord->fail($jobId, 'target reported failed on attempt '.$attempt.(isset($receipt['payload']['exitCode']) ? ' (exit '.$receipt['payload']['exitCode'].')' : ''));
                ($this->log)("job {$jobId} attempt {$attempt} failed -> {$status}");

                return $status === 'queued' ? 'requeued' : 'failed';
            }

            if ($state === 'delivered' && ! $delivered) {
                $delivered = true;
                $this->coord->heartbeat($jobId, $this->leaseSeconds);
                $lastBeat = ($this->clock)();
                ($this->log)("job {$jobId} attempt {$attempt} delivered");
            }

            if (($this->clock)() - $lastBeat >= $this->leaseSeconds / 2) {
                $this->coord->heartbeat($jobId, $this->leaseSeconds);
                $lastBeat = ($this->clock)();
            }

            usleep(100_000);
        }

        $status = $this->coord->fail($jobId, ($delivered ? 'no done receipt before the deadline' : 'never delivered to '.$session).' on attempt '.$attempt);
        ($this->log)("job {$jobId} attempt {$attempt} timed out -> {$status}");

        return $status === 'queued' ? 'requeued' : 'failed';
    }

    /**
     * coord.claim_job bumps jobs.attempt on every claim. If a job comes back
     * with an attempt this bridge already delivered (in memory, or a receipt
     * for that attempt from the target session on the bus), fail it loudly and without
     * retry instead of letting JetStream de-duplicate it into silence.
     */
    private function requireBumpedAttempt(string $jobId, int $attempt): void
    {
        $previous = $this->lastAttempt[$jobId] ?? null;
        $seen = $previous !== null && $attempt <= $previous;

        if (! $seen) {
            // Across bridge restarts: any receipt for this attempt from the
            // session it would go to (including this bridge's own "queued").
            $session = $this->bus->resolveSessionId($this->sessionId) ?? $this->sessionId;

            foreach (Receipt::READ_ORDER as $state) {
                $receipt = $this->bus->lastEnvelopeOrNull(Receipt::subject($jobId, $attempt, $state));

                if (is_array($receipt) && (($receipt['payload']['sessionId'] ?? null) === $session)) {
                    $seen = true;

                    break;
                }
            }
        }

        if (! $seen) {
            unset($this->lastAttempt[$jobId]);
            $this->lastAttempt[$jobId] = $attempt;

            if (count($this->lastAttempt) > 10_000) {
                array_shift($this->lastAttempt);
            }

            return;
        }

        $message = "coord returned job {$jobId} with attempt {$attempt}, which was already delivered"
            .($previous !== null ? " (last attempt {$previous})" : '')
            .'; coord.claim_job must bump attempt on every claim';
        ($this->log)('ERROR '.$message);

        try {
            $this->coord->fail($jobId, $message, retry: false);
        } catch (Throwable $exception) {
            ($this->log)("job {$jobId}: could not fail it (".self::describe($exception).')');
        }

        throw new AttemptNotBumped($message);
    }

    /**
     * The furthest receipt for this attempt from the session it went to.
     * Receipts from any other session are ignored.
     *
     * @return array<string, mixed>
     */
    private function furthestReceipt(string $jobId, int $attempt, string $session): array
    {
        foreach (Receipt::READ_ORDER as $state) {
            if ($state === 'queued') {
                continue;
            }

            $receipt = $this->bus->lastEnvelopeOrNull(Receipt::subject($jobId, $attempt, $state));
            $payload = is_array($receipt['payload'] ?? null) ? $receipt['payload'] : [];

            if (($payload['jobId'] ?? null) === $jobId
                && ($payload['attempt'] ?? null) === $attempt
                && ($payload['sessionId'] ?? null) === $session
                && ($receipt['sessionId'] ?? null) === $session) {
                return $receipt;
            }
        }

        return [];
    }

    private static function describe(Throwable $exception): string
    {
        // PDO messages can echo connection details; keep the SQLSTATE only.
        if ($exception instanceof PDOException) {
            return 'coord database error (SQLSTATE '.(string) $exception->getCode().')';
        }

        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');

        return (new \ReflectionClass($exception))->getShortName().($message !== '' ? ': '.mb_substr($message, 0, 200) : '');
    }
}
