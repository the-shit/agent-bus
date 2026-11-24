<?php

namespace App\Coord;

/**
 * The slice of sbprod coord (house/toolkit sbprod/coord) the bus bridge uses.
 * coord is the source of truth for jobs; the bus only carries job ids.
 */
interface CoordQueue
{
    /**
     * Claim the next queued job this role may run, or null. attempt is
     * coord's jobs.attempt after the claim (1 on the first claim).
     *
     * @param  list<string>  $capabilities
     * @return array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string, attempt?: int}|null
     */
    public function claim(array $capabilities, int $leaseSeconds): ?array;

    public function heartbeat(string $jobId, int $leaseSeconds): void;

    /** @param  array<string, mixed>  $result */
    public function complete(string $jobId, array $result): void;

    public function fail(string $jobId, string $error, bool $retry = true): string;

    /**
     * Block until a coord notification arrives or the timeout passes.
     * Returns the decoded NOTIFY payload (ids and state only) or null.
     *
     * @return array<string, mixed>|null
     */
    public function waitForNotification(int $timeoutMs): ?array;
}
