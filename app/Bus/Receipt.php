<?php

namespace App\Bus;

use InvalidArgumentException;

/**
 * Delivery receipts keyed by job id. Pure helpers: the hot path loads this
 * class without the framework.
 *
 * Each state has its own subject, work.receipt.{jobId}.{state}, so a late or
 * duplicate "queued" can never hide a "delivered" that already happened.
 * Readers take the highest-ranked state that exists.
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

    public static function state(string $state): string
    {
        if (! array_key_exists($state, self::RANK)) {
            throw new InvalidArgumentException('--state must be one of queued, delivered, done, failed.');
        }

        return $state;
    }

    public static function subject(string $jobId, string $state): string
    {
        return 'work.receipt.'.$jobId.'.'.self::state($state);
    }

    /**
     * JetStream de-duplicates on this id inside the stream's duplicate window,
     * so re-sending one job to one session publishes the inbox message once.
     */
    public static function inboxMessageId(string $sessionId, string $jobId): string
    {
        return 'inbox:'.$sessionId.':'.$jobId;
    }

    public static function reached(string $state, string $atLeast): bool
    {
        return self::RANK[self::state($state)] >= self::RANK[self::state($atLeast)];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function envelope(string $jobId, string $state, string $sessionId, string $agentType = '', array $detail = [], string $repo = 'unknown/unknown'): array
    {
        return [
            'v' => 2,
            'sessionId' => $sessionId,
            'agentType' => $agentType,
            'model' => '',
            'repo' => $repo,
            'type' => 'receipt',
            'jobId' => $jobId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => [
                'jobId' => $jobId,
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
