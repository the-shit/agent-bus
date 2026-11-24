<?php

namespace App\Bus;

use InvalidArgumentException;

/**
 * Delivery receipts keyed by job id. Pure helpers: the hot path loads this
 * class without the framework.
 *
 * Receipts are keyed by job id and attempt: work.receipt.{jobId}.{attempt}.{state}.
 * Each state has its own subject, so a late or duplicate "queued" can never
 * hide a "delivered" that already happened; readers take the highest-ranked
 * state that exists. The attempt keeps a requeued coord job (coord bumps
 * jobs.attempt on every claim) from seeing the previous attempt's "failed".
 */
final class Receipt
{
    /** @var array<string, int> */
    public const RANK = [
        'queued' => 0,
        'delivered' => 1,
        'done' => 2,
        'failed' => 2,
    ];

    /**
     * Highest rank first, so a reader stops at the furthest state reached.
     *
     * @var list<string>
     */
    public const READ_ORDER = ['done', 'failed', 'delivered', 'queued'];

    /**
     * A caller-supplied job id (for example a coord.jobs uuid), or a fresh
     * random one. Job ids become one NATS subject token, so no dots.
     */
    public static function jobId(string $given = ''): string
    {
        $given = trim($given);

        if ($given === '') {
            return bin2hex(random_bytes(16));
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $given) !== 1) {
            throw new InvalidArgumentException('--job must be 1-128 characters of A-Z, a-z, 0-9, _ or -.');
        }

        return $given;
    }

    public const MAX_ATTEMPT = 1_000_000;

    /**
     * The delivery attempt for a job id; coord's jobs.attempt for coord jobs,
     * 1 for a plain send.
     */
    public static function attempt(int|string|null $given = null): int
    {
        if ($given === null || $given === '') {
            return 1;
        }

        $text = is_int($given) ? (string) $given : trim($given);

        if (preg_match('/^[1-9][0-9]{0,6}$/', $text) !== 1 || (int) $text > self::MAX_ATTEMPT) {
            throw new InvalidArgumentException('--attempt must be a whole number from 1 to '.self::MAX_ATTEMPT.'.');
        }

        return (int) $text;
    }

    /**
     * The attempt an envelope carries; 1 when it carries none (older senders).
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function attemptOf(array $envelope): int
    {
        try {
            $attempt = $envelope['attempt'] ?? null;

            return self::attempt(is_int($attempt) || is_string($attempt) ? $attempt : null);
        } catch (InvalidArgumentException) {
            return 1;
        }
    }

    public static function state(string $state): string
    {
        if (! array_key_exists($state, self::RANK)) {
            throw new InvalidArgumentException('--state must be one of queued, delivered, done, failed.');
        }

        return $state;
    }

    public static function subject(string $jobId, int $attempt, string $state): string
    {
        return 'work.receipt.'.$jobId.'.'.self::attempt($attempt).'.'.self::state($state);
    }

    /**
     * JetStream de-duplicates on this id inside the stream's duplicate window,
     * so re-sending one attempt of one job to one session publishes the inbox
     * message once, while the next attempt of a requeued job gets through.
     */
    public static function inboxMessageId(string $sessionId, string $jobId, int $attempt): string
    {
        return 'inbox:'.$sessionId.':'.$jobId.':'.self::attempt($attempt);
    }

    public static function reached(string $state, string $atLeast): bool
    {
        return self::RANK[self::state($state)] >= self::RANK[self::state($atLeast)];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function envelope(string $jobId, int $attempt, string $state, string $sessionId, string $agentType = '', array $detail = [], string $repo = 'unknown/unknown'): array
    {
        return [
            'v' => 2,
            'sessionId' => $sessionId,
            'agentType' => $agentType,
            'model' => '',
            'repo' => $repo,
            'type' => 'receipt',
            'jobId' => $jobId,
            'attempt' => self::attempt($attempt),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => [
                'jobId' => $jobId,
                'attempt' => self::attempt($attempt),
                'state' => self::state($state),
                'sessionId' => $sessionId,
                'atMs' => self::nowMs(),
                ...$detail,
            ],
        ];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
