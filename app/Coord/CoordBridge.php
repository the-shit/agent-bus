<?php

namespace App\Coord;

use App\Bus\NatsBus;
use App\Bus\Receipt;
use Closure;

/**
 * Wake-on-arrival for coord jobs. Claims a job (coord is the source of truth),
 * puts it in one bus session's inbox keyed by the coord job id, and follows
 * that job's bus receipts back into coord:
 *
 *   delivered -> coord.heartbeat_job   (the target is awake and holds it)
 *   done      -> coord.complete_job
 *   failed    -> coord.fail_job (retry)
 *   no receipt before the deadline -> coord.fail_job (retry)
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
    ) {
        $this->log = $log ?? static function (string $line): void {};
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Run until $maxJobs jobs are handled (0 = no limit) or $forSeconds pass
     * (0 = forever). Returns the jobs handled.
     */
    public function run(int $maxJobs = 0, float $forSeconds = 0.0, int $notifyTimeoutMs = 5000): int
    {
        $deadline = $forSeconds > 0 ? ($this->clock)() + $forSeconds : INF;
        $handled = 0;

        while (($this->clock)() < $deadline) {
            if ($this->bus->resolveSessionId($this->sessionId) === null) {
                // Do not claim work for a session that cannot receive it.
                ($this->log)("session {$this->sessionId} is not on the bus; not claiming");
                $this->coord->waitForNotification($notifyTimeoutMs);

                continue;
            }

            $job = $this->coord->claim($this->capabilities, $this->leaseSeconds);

            if ($job === null) {
                // Catch-up claim found nothing: sleep until coord says something changed.
                $this->coord->waitForNotification($notifyTimeoutMs);

                continue;
            }

            $this->handle($job);
            $handled++;

            if ($maxJobs > 0 && $handled >= $maxJobs) {
                break;
            }
        }

        return $handled;
    }

    /**
     * @param  array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string}  $job
     * @return string the coord outcome: done, failed, or requeued
     */
    public function handle(array $job): string
    {
        $jobId = Receipt::jobId($job['id']);
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
            'sentAtMs' => Receipt::nowMs(),
            'payload' => [
                'jobId' => $jobId,
                'requestId' => $job['request_id'],
                'capability' => $job['capability'],
                'task' => $job['payload'],
            ],
        ];

        $this->bus->ensureSessionInbox($session);
        $this->bus->publishEnvelope('session.'.$session.'.inbox', $envelope, Receipt::inboxMessageId($session, $jobId));
        $this->bus->publishEnvelope(Receipt::subject($jobId, 'queued'), Receipt::envelope($jobId, 'queued', $session, 'coord-bridge'));
        ($this->log)("job {$jobId} claimed and sent to {$session}");

        $deadline = ($this->clock)() + $this->receiptWaitSeconds;
        $lastBeat = ($this->clock)();
        $delivered = false;

        while (($this->clock)() < $deadline) {
            $receipt = $this->furthestReceipt($jobId);
            $state = $receipt['payload']['state'] ?? null;

            if ($state === 'done') {
                $result = $receipt['payload']['result'] ?? null;
                $this->coord->complete($jobId, is_array($result) ? $result : array_filter([
                    'exitCode' => $receipt['payload']['exitCode'] ?? null,
                    'session' => $session,
                ], fn ($value) => $value !== null));
                ($this->log)("job {$jobId} done");

                return 'done';
            }

            if ($state === 'failed') {
                $status = $this->coord->fail($jobId, 'target reported failed'.(isset($receipt['payload']['exitCode']) ? ' (exit '.$receipt['payload']['exitCode'].')' : ''));
                ($this->log)("job {$jobId} failed -> {$status}");

                return $status === 'queued' ? 'requeued' : 'failed';
            }

            if ($state === 'delivered' && ! $delivered) {
                $delivered = true;
                $this->coord->heartbeat($jobId, $this->leaseSeconds);
                $lastBeat = ($this->clock)();
                ($this->log)("job {$jobId} delivered");
            }

            if (($this->clock)() - $lastBeat >= $this->leaseSeconds / 2) {
                $this->coord->heartbeat($jobId, $this->leaseSeconds);
                $lastBeat = ($this->clock)();
            }

            usleep(100_000);
        }

        $status = $this->coord->fail($jobId, $delivered ? 'no done receipt before the deadline' : 'never delivered to '.$session);
        ($this->log)("job {$jobId} timed out -> {$status}");

        return $status === 'queued' ? 'requeued' : 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    private function furthestReceipt(string $jobId): array
    {
        foreach (Receipt::READ_ORDER as $state) {
            $receipt = $this->bus->lastEnvelopeOrNull(Receipt::subject($jobId, $state));

            if ($receipt !== null && ($receipt['payload']['jobId'] ?? null) === $jobId) {
                return $receipt;
            }
        }

        return [];
    }
}
