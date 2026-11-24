<?php

namespace App\Bus;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Payload;
use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;

class Cli
{
    /**
     * Verbs that must never boot the framework. Hooks fire these on every tool
     * call, so bin/agent-bus dispatches them straight to this class.
     *
     * @var list<string>
     */
    public const HOT_VERBS = [
        'emit',
        'send',
        'inbox',
        'listen',
        'receipt',
        'heartbeat',
        'session-end',
        'sessions',
        'work-request',
        'work-result',
        'hook',
        'opencode',
    ];

    private ?Client $client = null;

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");

            return 1;
        }
    }

    public static function repoFromRemote(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return 'unknown/unknown';
        }

        if (str_starts_with($url, 'git@')) {
            $url = preg_replace('#^git@([^:]+):#', 'https://$1/', $url) ?? $url;
        } elseif (str_starts_with($url, 'ssh://git@')) {
            $url = preg_replace('#^ssh://git@#', 'https://', $url) ?? $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;
        $path = preg_replace('#\.git$#', '', $path) ?? $path;
        $parts = array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return 'unknown/unknown';
        }

        return $parts[count($parts) - 2].'/'.$parts[count($parts) - 1];
    }

    /**
     * @param  list<string>  $argv
     */
    private function dispatch(array $argv): int
    {
        [$positionals, $options] = $this->parse($argv);
        $command = $positionals[0] ?? '';

        return match ($command) {
            'emit' => $this->emit($options),
            'send' => $this->send($options),
            'inbox' => $this->inbox($options),
            'listen' => $this->listen($options),
            'receipt' => $this->receipt($options),
            'heartbeat' => $this->heartbeat($options),
            'session-end' => $this->sessionEnd($options),
            'sessions' => $this->sessions($positionals, $options),
            'work-request' => $this->workRequest($options),
            'work-result' => $this->workResult($options),
            'hook' => $this->fromStdin(GrokHook::class),
            'opencode' => $this->fromStdin(OpenCodeCapture::class),
            default => $this->usage(),
        };
    }

    /**
     * @param  array<string, string>  $options
     */
    private function emit(array $options): int
    {
        $envelope = $this->envelope($options, requireSession: false);
        $this->requireAgentType($envelope);
        [$envelope['sessionId'], $aliases] = $this->canonicalize($envelope['sessionId'], $envelope['agentType']);
        $this->requireSubjectSafe($envelope['sessionId']);

        try {
            $this->registerPresence($envelope);
            $this->putAliases($aliases, $envelope['sessionId']);
            $this->publish($this->repoSubject($envelope), $envelope);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 0;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * Envelope v2: presence-bearing events must say who they are.
     *
     * @param  array{agentType: string}  $envelope
     */
    private function requireAgentType(array $envelope): void
    {
        if ($envelope['agentType'] === '') {
            throw new InvalidArgumentException('Missing --agent-type (or AGENT_BUS_AGENT_TYPE); envelope v2 requires agentType.');
        }
    }

    /**
     * Canonical ids are dot-free by design (issue #30): the KV backing
     * streams and the inbox subject filter are single-token, so a dotted id
     * can neither persist nor receive. Alternates are free-form — they reach
     * the bus only as hashed alias keys — but a resolved id that is not one
     * clean NATS token is a caller bug and dies loudly here.
     */
    private function requireSubjectSafe(string $sessionId): void
    {
        if ($sessionId !== '' && preg_match('/^[^\s.*>]+$/', $sessionId) !== 1) {
            throw new InvalidArgumentException(
                "session id [{$sessionId}] is not a single NATS token (dots, wildcards, whitespace) — see issue #30."
            );
        }
    }

    /**
     * Normalize a reporter-supplied id onto the canonical `{kind}:{id}` form.
     * Explicit operator input that the resolver cannot place is kept verbatim.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function canonicalize(string $sessionId, string $agentType): array
    {
        if ($sessionId === '' || BusIdentity::isCanonical($sessionId)) {
            return [$sessionId, []];
        }

        $resolved = $agentType !== ''
            ? BusIdentity::resolve($agentType, ['sessionId' => $sessionId])
            : null;

        if ($resolved === null) {
            return [$sessionId, []];
        }

        return [$resolved, [$sessionId]];
    }

    /**
     * Auto-register presence for unknown ids before the first publish.
     * Implicit records are visibly marked; sessionStart upgrades to explicit.
     *
     * @param  array{sessionId: string, agentType: string, model: string, repo: string, type: string}  $envelope
     */
    private function registerPresence(array $envelope): void
    {
        if ($envelope['sessionId'] === '') {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $explicit = $envelope['type'] === 'sessionStart';
        $existing = $this->session($envelope['sessionId']);
        $record = null;

        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            $record = is_array($decoded) ? $decoded : null;
        }

        if ($record !== null) {
            // v1 rows have no `registered` marker. Treating that as explicit
            // froze degraded records forever — emit must repair them.
            $registered = $record['registered'] ?? null;
            $registered = is_string($registered) ? $registered : 'explicit';
            $degraded = (int) ($record['v'] ?? 1) !== 2
                || ! is_string($record['agentType'] ?? null)
                || $record['agentType'] === ''
                || ! is_string($record['sessionId'] ?? null)
                || $record['sessionId'] === '';

            if ($registered === 'explicit' && ! $degraded && ! $explicit) {
                return; // healthy explicit presence is managed by heartbeat
            }

            $record['v'] = 2;
            $record['sessionId'] = $envelope['sessionId'];
            $record['lastSeen'] = $now;
            $record['identity_quality'] = BusIdentity::quality($envelope['sessionId']);

            if ($explicit) {
                $record['registered'] = 'explicit';
            } elseif (! is_string($record['registered'] ?? null)) {
                $record['registered'] = 'implicit';
            }

            if (($record['agentType'] ?? '') === '' && $envelope['agentType'] !== '') {
                $record['agentType'] = $envelope['agentType'];
            }

            if (($record['model'] ?? '') === '' && $envelope['model'] !== '') {
                $record['model'] = $envelope['model'];
            }

            if (($record['repo'] ?? '') === '' && $envelope['repo'] !== '') {
                $record['repo'] = $envelope['repo'];
            }

            if (($record['host'] ?? '') === '') {
                $record['host'] = gethostname() ?: '';
            }

            if (! is_string($record['firstSeen'] ?? null) || $record['firstSeen'] === '') {
                $record['firstSeen'] = $now;
            }

            $this->putSession($envelope['sessionId'], json_encode($record, JSON_THROW_ON_ERROR));

            return;
        }

        $this->putSession($envelope['sessionId'], json_encode([
            'v' => 2,
            'sessionId' => $envelope['sessionId'],
            'agentType' => $envelope['agentType'],
            'model' => $envelope['model'],
            'host' => gethostname() ?: '',
            'repo' => $envelope['repo'],
            'registered' => $explicit ? 'explicit' : 'implicit',
            'identity_quality' => BusIdentity::quality($envelope['sessionId']),
            'firstSeen' => $now,
            'lastSeen' => $now,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $aliases
     */
    private function putAliases(array $aliases, string $canonicalId): void
    {
        if ($aliases === [] || $canonicalId === '') {
            return;
        }

        $bucket = $this->client()->getApi()->getBucket($this->aliasBucketName());
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($aliases as $alias) {
            $bucket->put($this->aliasKey($alias), json_encode([
                'alternate' => $alias,
                'canonical' => $canonicalId,
                'createdAt' => $now,
            ], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Canonical id whose presence is on the bus, resolving through the alias
     * map. Null when neither the id nor any alias of it is registered.
     */
    private function resolveOnBus(string $id): ?string
    {
        if ($this->session($id) !== null) {
            return $id;
        }

        $canonical = $this->aliasTarget($id);

        if ($canonical !== null && $this->session($canonical) !== null) {
            return $canonical;
        }

        return null;
    }

    private function aliasTarget(string $id): ?string
    {
        if (! $this->bucketExists($this->aliasBucketName())) {
            return null;
        }

        $value = $this->client()->getApi()->getBucket($this->aliasBucketName())->get($this->aliasKey($id));

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        $canonical = is_array($decoded) ? ($decoded['canonical'] ?? null) : null;

        return is_string($canonical) && $canonical !== '' ? $canonical : null;
    }

    /**
     * Alternate ids contain dots and slashes that the KV backing stream's
     * single-token subject cannot hold (issue #30, bug a) — hash the key.
     */
    private function aliasKey(string $alternate): string
    {
        return hash('sha256', $alternate);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function send(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $payload = $this->jsonObject($options['payload'] ?? '', 'payload');
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        $jobId = Receipt::jobId($options['job'] ?? '');
        $attempt = Receipt::attempt($options['attempt'] ?? null);
        $waitSeconds = max(0.0, (float) ($options['wait-receipt'] ?? 0));
        [$canonical] = $this->canonicalize($sessionId, $agentType);

        try {
            $resolved = $this->resolveOnBus($canonical);

            if ($resolved === null) {
                fwrite(STDERR, "session {$sessionId} is not on the bus\n");

                return 1;
            }

            $this->requireSubjectSafe($resolved);

            $envelope = $this->envelope([
                ...$options,
                'session' => $resolved,
                'type' => $options['type'] ?? 'inbox',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ], requireSession: true);
            $envelope['jobId'] = $jobId;
            $envelope['attempt'] = $attempt;
            $envelope['sentAtMs'] = Receipt::nowMs();

            $this->ensureInboxConsumer($resolved);
            $this->publish('session.'.$resolved.'.inbox', $envelope, Receipt::inboxMessageId($resolved, $jobId, $attempt));
            $this->publishReceipt($jobId, $attempt, 'queued', $resolved, $agentType);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        if ($waitSeconds <= 0) {
            return 0;
        }

        try {
            $receipt = $this->waitForReceipt($jobId, $attempt, 'delivered', $waitSeconds);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        if ($receipt === null) {
            fwrite(STDERR, "job {$jobId} was queued but not delivered within {$waitSeconds}s\n");

            return 3;
        }

        echo json_encode($receipt, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * Wake-on-arrival. Blocks on the session's own durable inbox and fires the
     * moment a message lands: prints it as one JSON line, publishes a
     * "delivered" receipt keyed by job id and attempt, and optionally runs
     * --exec with the envelope on stdin ("done" or "failed" receipt from its
     * exit code). With --exec the message is acknowledged only after the
     * command exits, and the listener keeps the message's ack deadline and the
     * session's presence fresh while it runs, so a crash mid-exec redelivers
     * the message instead of losing it. With --agent-type it also keeps the
     * session's presence fresh, so an idle agent stays addressable while it
     * waits.
     *
     * @param  array<string, string>  $options
     */
    private function listen(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        $exec = $options['exec'] ?? '';
        $execTimeout = self::execTimeout($options['exec-timeout'] ?? null);
        $passEnv = self::passEnvNames($options['pass-env'] ?? '');
        $maxDeliveries = self::maxDeliveries();
        $max = max(0, (int) ($options['max'] ?? 0));
        $for = max(0.0, (float) ($options['for'] ?? 0));
        $poll = (float) ($options['poll'] ?? 1.0);
        $poll = $poll > 0 ? min($poll, 30.0) : 1.0;
        [$canonical, $aliases] = $this->canonicalize($sessionId, $agentType);
        $this->requireSubjectSafe($canonical);

        $deadline = $for > 0 ? microtime(true) + $for : INF;
        $lastBeat = 0.0;
        $handled = 0;

        try {
            $resolved = $this->resolveOnBus($canonical);

            if ($resolved === null && $agentType === '') {
                fwrite(STDERR, "session {$sessionId} is not on the bus; pass --agent-type to register it\n");

                return 1;
            }

            $resolved ??= $canonical;
            $this->requireSubjectSafe($resolved);
            $this->ensureInboxConsumer($resolved);

            while (microtime(true) < $deadline) {
                if ($agentType !== '' && microtime(true) - $lastBeat >= 30.0) {
                    $this->listenPresence($resolved, $agentType, $options, $aliases);
                    $lastBeat = microtime(true);
                }

                $remaining = $deadline - microtime(true);
                $pulled = $this->pullInboxMessage($resolved, is_finite($remaining) ? max(0.1, min($poll, $remaining)) : $poll);

                if ($pulled === null) {
                    continue;
                }

                [$envelope, $message] = $pulled;
                $jobId = $this->envelopeJobId($envelope);
                $attempt = Receipt::attemptOf($envelope);
                $deliveries = self::deliveryCount($message->replyTo);

                if ($exec !== '' && $deliveries > $maxDeliveries) {
                    // It crashed or hung the listener every time: stop the loop.
                    fwrite(STDERR, sprintf(
                        "message for job %s attempt %d was delivered %d times (cap %d); terminating it without running --exec\n",
                        $jobId ?? '-', $attempt, $deliveries, $maxDeliveries,
                    ));
                    $this->term($message, 'agent-bus redelivery cap');

                    if ($jobId !== null) {
                        $this->publishReceiptQuietly($jobId, $attempt, 'failed', $resolved, $agentType, [
                            'reason' => 'redelivery cap',
                            'deliveries' => $deliveries,
                        ]);
                    }

                    $handled++;

                    if ($max > 0 && $handled >= $max) {
                        break;
                    }

                    continue;
                }

                $timeout = $execTimeout;

                if ($exec !== '') {
                    [$timeout, $note] = self::boundedExecTimeout($execTimeout, $envelope);

                    if ($note !== null) {
                        fwrite(STDERR, 'job '.($jobId ?? '-').' attempt '.$attempt.': '.$note."\n");
                    }

                    if ($timeout === null) {
                        // The sender already gave up on this attempt; running it now only races the retry.
                        $this->term($message, 'agent-bus receipt deadline passed');

                        if ($jobId !== null) {
                            $this->publishReceiptQuietly($jobId, $attempt, 'failed', $resolved, $agentType, ['reason' => 'deadline passed']);
                        }

                        $handled++;

                        if ($max > 0 && $handled >= $max) {
                            break;
                        }

                        continue;
                    }
                }

                $this->deliveredReceipt($jobId, $attempt, $resolved, $agentType, $envelope);

                echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";
                fflush(STDOUT);

                if ($exec === '') {
                    $this->ack($message);
                } else {
                    $lastProgress = microtime(true);
                    $progressEvery = max(1.0, min(10.0, self::inboxAckWaitSeconds() / 3));
                    $tick = function () use ($message, &$lastProgress, &$lastBeat, $progressEvery, $resolved, $agentType, $options, $aliases): void {
                        $now = microtime(true);

                        if ($now - $lastProgress >= $progressEvery) {
                            // Push the ack deadline out while the command runs.
                            $message->progress();
                            $lastProgress = $now;
                        }

                        if ($agentType !== '' && $now - $lastBeat >= 30.0) {
                            $this->listenPresence($resolved, $agentType, $options, $aliases);
                            $lastBeat = $now;
                        }
                    };

                    [$code, $result, $timedOut] = $this->runWake($exec, $envelope, $resolved, $jobId, $attempt, $tick, $timeout, $passEnv);

                    if ($timedOut) {
                        // Do not redeliver a hung attempt; the failed receipt lets coord retry.
                        fwrite(STDERR, sprintf("--exec for job %s attempt %d timed out after %ss; killed it\n", $jobId ?? '-', $attempt, $timeout));
                        $this->term($message, 'agent-bus exec timeout');
                    } else {
                        $this->ack($message);
                    }

                    if ($jobId !== null) {
                        $detail = ['exitCode' => $code];

                        if ($timedOut) {
                            $detail['reason'] = 'timeout';
                            $detail['timeoutSeconds'] = $timeout;
                        }

                        if ($result !== null) {
                            $detail['result'] = $result;
                        }

                        $this->publishReceiptQuietly($jobId, $attempt, $code === 0 ? 'done' : 'failed', $resolved, $agentType, $detail);
                    }
                }

                $handled++;

                if ($max > 0 && $handled >= $max) {
                    break;
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $aliases
     */
    private function listenPresence(string $resolved, string $agentType, array $options, array $aliases): void
    {
        $presence = $this->explicitPresence(
            $resolved,
            $agentType,
            $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            $this->option($options, 'capabilities', 'AGENT_BUS_CAPABILITIES'),
        );
        $presence['listening'] = true;
        $this->putSession($resolved, json_encode($presence, JSON_THROW_ON_ERROR));
        $this->putAliases($aliases, $resolved);
    }

    public const DEFAULT_EXEC_TIMEOUT = 540.0;

    /** Seconds between SIGTERM and SIGKILL for a timed-out wake command. */
    public const EXEC_KILL_GRACE = 5.0;

    /** Headroom kept between a killed command and the sender's receipt deadline. */
    public const DEADLINE_MARGIN = 5.0;

    /**
     * The timeout to use for one message. A sender that waits for the receipt
     * (coord-bridge) puts its deadline in the envelope as deadlineMs; the
     * command must be dead, kill grace included, a margin before then, or the
     * sender retries an attempt that is still running. Returns null when the
     * deadline is already too close, plus a note when the limit was capped.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{0: float|null, 1: string|null}
     */
    public static function boundedExecTimeout(float $timeout, array $envelope, ?int $nowMs = null): array
    {
        $deadline = $envelope['deadlineMs'] ?? null;

        if (! is_int($deadline)) {
            return [$timeout, null];
        }

        $left = ($deadline - ($nowMs ?? Receipt::nowMs())) / 1000;
        $allowed = floor($left - self::EXEC_KILL_GRACE - self::DEADLINE_MARGIN);

        if ($allowed < 1) {
            return [null, sprintf('the sender stops waiting in %.0fs, too soon to run --exec; terminating it', max(0, $left))];
        }

        if ($timeout <= 0 || $timeout > $allowed) {
            return [$allowed, sprintf(
                '--exec-timeout %s + %ss kill grace would outlive the sender\'s receipt wait (%.0fs left); capping at %ss',
                $timeout <= 0 ? 'none' : $timeout.'s', self::EXEC_KILL_GRACE, $left, $allowed,
            )];
        }

        return [$timeout, null];
    }

    private function term(Msg $message, string $reason): void
    {
        try {
            $message->term($reason);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'term failed, the message may be redelivered: '.$this->oneLine($exception->getMessage())."\n");
        }
    }

    /**
     * Seconds a wake command may run (--exec-timeout, AGENT_BUS_EXEC_TIMEOUT,
     * default 540). 0 disables the limit. The default stays below
     * coord-bridge's default 600 s receipt wait with room for the kill grace,
     * so the bridge never retries an attempt that is still running.
     */
    public static function execTimeout(?string $option = null): float
    {
        $value = $option ?? getenv('AGENT_BUS_EXEC_TIMEOUT');

        if (! is_string($value) || trim($value) === '') {
            return self::DEFAULT_EXEC_TIMEOUT;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('--exec-timeout must be a number of seconds (0 = no limit).');
        }

        return (float) $value;
    }

    /**
     * Deliveries of one inbox message that listen --exec will run before it
     * terminates the message (AGENT_BUS_INBOX_MAX_DELIVERIES, default 3).
     */
    public static function maxDeliveries(): int
    {
        $value = getenv('AGENT_BUS_INBOX_MAX_DELIVERIES');

        return is_string($value) && $value !== '' ? max(1, (int) $value) : 3;
    }

    /** Ack wait for new inbox consumers (AGENT_BUS_INBOX_ACK_WAIT_SECONDS, default 30). */
    public static function inboxAckWaitSeconds(): float
    {
        $value = getenv('AGENT_BUS_INBOX_ACK_WAIT_SECONDS');

        return is_string($value) && is_numeric($value) && (float) $value > 0 ? (float) $value : 30.0;
    }

    /**
     * Apply the inbox consumer limits shared by every sender and listener.
     * The server stops one past the cap so the listener sees the over-cap
     * delivery, terminates it and publishes "failed".
     */
    public static function configureInboxConsumer(\Basis\Nats\Consumer\Configuration $configuration): void
    {
        $configuration
            ->setAckWait((int) (self::inboxAckWaitSeconds() * 1_000_000_000))
            ->setMaxDeliver(self::maxDeliveries() + 1);

        $inactive = self::inboxInactiveSeconds();

        if ($inactive > 0) {
            // An abandoned session's consumer goes away on its own.
            $configuration->setInactiveThreshold($inactive * 1_000_000_000);
        }
    }

    /**
     * How many times JetStream has delivered this message, from its ack
     * subject ($JS.ACK.<stream>.<consumer>.<delivered>.… or the 12-token form
     * with domain and account hash). 1 when it cannot tell.
     */
    public static function deliveryCount(?string $replyTo): int
    {
        $tokens = explode('.', (string) $replyTo);

        if ($tokens[0] !== '$JS' || ($tokens[1] ?? '') !== 'ACK') {
            return 1;
        }

        $index = count($tokens) === 9 ? 4 : (count($tokens) >= 11 ? 6 : null);
        $value = $index !== null ? ($tokens[$index] ?? '') : '';

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : 1;
    }

    private function ack(Msg $message): void
    {
        try {
            $message->ack();
        } catch (Throwable $exception) {
            // Unacked mail is redelivered after the ack wait; say so and go on.
            fwrite(STDERR, 'ack failed, the message may be redelivered: '.$this->oneLine($exception->getMessage())."\n");
        }
    }

    /**
     * Read the furthest receipt for a job, or publish one with --state.
     *
     * @param  array<string, string>  $options
     */
    private function receipt(array $options): int
    {
        $jobId = trim($options['job'] ?? '');

        if ($jobId === '') {
            throw new InvalidArgumentException('Missing --job.');
        }

        $jobId = Receipt::jobId($jobId);
        $attempt = Receipt::attempt($options['attempt'] ?? null);
        $state = $options['state'] ?? '';

        try {
            if ($state !== '') {
                $state = Receipt::state($state);
                $sessionId = $this->sessionId($options, required: true);
                $this->requireSubjectSafe($sessionId);
                $detail = isset($options['payload']) ? $this->jsonObject($options['payload'], 'payload') : [];
                $receipt = $this->publishReceipt($jobId, $attempt, $state, $sessionId, $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'), $detail);
            } else {
                $until = Receipt::state($options['until'] ?? 'queued');
                $receipt = $this->waitForReceipt($jobId, $attempt, $until, max(0.0, (float) ($options['wait'] ?? 0)));
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        if ($receipt === null) {
            fwrite(STDERR, "no receipt for job {$jobId} attempt {$attempt}\n");

            return 2;
        }

        echo json_encode($receipt, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function publishReceipt(string $jobId, int $attempt, string $state, string $sessionId, string $agentType = '', array $detail = []): array
    {
        $receipt = Receipt::envelope($jobId, $attempt, $state, $sessionId, $agentType, $detail, $this->repo());
        $this->publish(Receipt::subject($jobId, $attempt, $state), $receipt);

        return $receipt;
    }

    /**
     * A receipt is advisory. Failing to publish one must not lose or re-run
     * the message that was already pulled and acknowledged.
     *
     * @param  array<string, mixed>  $detail
     */
    private function publishReceiptQuietly(string $jobId, int $attempt, string $state, string $sessionId, string $agentType = '', array $detail = []): void
    {
        try {
            $this->publishReceipt($jobId, $attempt, $state, $sessionId, $agentType, $detail);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'receipt '.$state.' for job '.$jobId.' not published: '.$this->oneLine($exception->getMessage())."\n");
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function deliveredReceipt(?string $jobId, int $attempt, string $sessionId, string $agentType, array $envelope): void
    {
        if ($jobId === null) {
            return;
        }

        $sentAt = $envelope['sentAtMs'] ?? null;
        $detail = is_int($sentAt) ? ['latencyMs' => max(0, Receipt::nowMs() - $sentAt)] : [];
        $this->publishReceiptQuietly($jobId, $attempt, 'delivered', $sessionId, $agentType, $detail);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function envelopeJobId(array $envelope): ?string
    {
        $jobId = $envelope['jobId'] ?? null;

        return is_string($jobId) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $jobId) === 1 ? $jobId : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForReceipt(string $jobId, int $attempt, string $atLeast, float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;

        do {
            foreach (Receipt::READ_ORDER as $state) {
                if (! Receipt::reached($state, $atLeast)) {
                    continue;
                }

                $receipt = $this->lastEnvelopeOn(Receipt::subject($jobId, $attempt, $state));

                if ($receipt !== null && (($receipt['payload']['jobId'] ?? null) === $jobId)) {
                    return $receipt;
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(100_000);
        } while (true);

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastEnvelopeOn(string $subject): ?array
    {
        try {
            $response = $this->client()->getApi()->getStream($this->streamName())->getLastMessage($subject);
        } catch (Throwable) {
            return null;
        }

        $data = $response->message->data ?? null;

        if (! is_string($data) || $data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);
        $envelope = json_decode($decoded !== false ? $decoded : $data, true);

        return is_array($envelope) ? $envelope : null;
    }

    /**
     * Run the wake command with the envelope on stdin (from a 0600 temp file,
     * so a command that never reads stdin cannot block the listener). Its
     * stdout and stderr go to this process's stderr so the listener's stdout
     * stays one JSON line per message. The child gets only allowlisted
     * environment variables (see wakeEnvironment) plus --pass-env names and
     * AGENT_BUS_SESSION_ID, AGENT_BUS_JOB_ID, AGENT_BUS_ATTEMPT and
     * AGENT_BUS_RESULT_FILE; a JSON object written to the result file becomes
     * the done receipt's result. $tick runs about every 50 ms while it runs.
     * The command runs in its own process group; past $timeout seconds the
     * group gets SIGTERM, then SIGKILL 5 s later, and the exit code is 124.
     *
     * @param  array<string, mixed>  $envelope
     * @param  (Closure(): void)|null  $tick
     * @param  list<string>  $passEnv
     * @return array{0: int, 1: array<string, mixed>|null, 2: bool}
     */
    private function runWake(string $command, array $envelope, string $sessionId, ?string $jobId, int $attempt, ?Closure $tick = null, float $timeout = 0.0, array $passEnv = []): array
    {
        $scratch = ScratchDir::path();
        $input = tempnam($scratch, 'agent-bus-wake-in-');
        $resultFile = tempnam($scratch, 'agent-bus-wake-result-');
        $stdin = false;

        try {
            if ($input === false || $resultFile === false) {
                fwrite(STDERR, "wake command did not start: no temp file\n");

                return [127, null, false];
            }

            chmod($input, 0600);
            chmod($resultFile, 0600);
            file_put_contents($input, json_encode($envelope, JSON_THROW_ON_ERROR)."\n");
            $stdin = fopen($input, 'r');

            $environment = self::wakeEnvironment(getenv(), $passEnv);
            $environment['AGENT_BUS_SESSION_ID'] = $sessionId;
            $environment['AGENT_BUS_JOB_ID'] = $jobId ?? '';
            $environment['AGENT_BUS_ATTEMPT'] = (string) $attempt;
            $environment['AGENT_BUS_RESULT_FILE'] = $resultFile;

            // setsid gives the command its own process group, so a timeout
            // kills everything it started, not just the shell.
            $argv = is_executable('/usr/bin/setsid')
                ? ['/usr/bin/setsid', '--wait', '/bin/sh', '-c', $command]
                : ['/bin/sh', '-c', $command];
            $process = $stdin === false ? false : proc_open($argv, [
                0 => $stdin,
                1 => STDERR,
                2 => STDERR,
            ], $pipes, null, $environment);

            if (! is_resource($process)) {
                fwrite(STDERR, "wake command did not start\n");

                return [127, null, false];
            }

            $started = microtime(true);
            $timedOut = false;
            $killAt = null;

            while (true) {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    // exitcode is only reported the first time running is false.
                    $code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];

                    break;
                }

                $now = microtime(true);

                if ($timeout > 0 && ! $timedOut && $now - $started >= $timeout) {
                    $timedOut = true;
                    $killAt = $now + self::EXEC_KILL_GRACE;
                    self::signalGroup($process, (int) $status['pid'], 15);
                } elseif ($killAt !== null && $now >= $killAt) {
                    self::signalGroup($process, (int) $status['pid'], 9);
                    $killAt = null;
                }

                if ($tick !== null) {
                    try {
                        $tick();
                    } catch (Throwable $exception) {
                        fwrite(STDERR, 'listener heartbeat failed: '.$this->oneLine($exception->getMessage())."\n");
                    }
                }

                usleep(50_000);
            }

            proc_close($process);

            if ($timedOut) {
                self::signalGroup(null, (int) $status['pid'], 9);

                return [124, null, true];
            }

            return [$code, self::wakeResult($resultFile), false];
        } finally {
            if (is_resource($stdin)) {
                fclose($stdin);
            }

            foreach ([$input, $resultFile] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** Variables a wake command always inherits; LC_* is allowed too. */
    public const WAKE_ENV_ALLOW = ['PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'TZ', 'TMPDIR', 'TERM'];

    /**
     * The environment a wake command inherits: an allowlist, not a denylist.
     * Anything else (database URLs, API keys, NATS credentials, SSH_AUTH_SOCK)
     * reaches the child only when named with --pass-env.
     *
     * @param  array<string, string>  $environment
     * @param  list<string>  $passThrough
     * @return array<string, string>
     */
    public static function wakeEnvironment(array $environment, array $passThrough = []): array
    {
        $kept = [];

        foreach ($environment as $name => $value) {
            $name = (string) $name;

            if (in_array($name, self::WAKE_ENV_ALLOW, true)
                || preg_match('/^LC_[A-Z_]+$/', $name) === 1
                || in_array($name, $passThrough, true)) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * --pass-env NAME (repeatable, or comma-separated) as a list of names.
     *
     * @return list<string>
     */
    public static function passEnvNames(string $option): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $option)), fn (string $name): bool => $name !== ''));

        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException('--pass-env takes environment variable names.');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Signal the wake command's process group (setsid made the shell its
     * leader), falling back to the shell alone.
     *
     * @param  resource|null  $process
     */
    private static function signalGroup($process, int $pid, int $signal): void
    {
        if ($pid > 1 && function_exists('posix_kill') && @posix_kill(-$pid, $signal)) {
            return;
        }

        if (is_resource($process)) {
            proc_terminate($process, $signal);
        }
    }

    /** @return array<string, mixed>|null */
    private static function wakeResult(string $file): ?array
    {
        $json = is_file($file) ? file_get_contents($file, length: 65_536) : false;

        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            fwrite(STDERR, "AGENT_BUS_RESULT_FILE did not hold a JSON object; ignoring it\n");

            return null;
        }

        return $decoded;
    }

    /**
     * Publish an advisory judgment request without booting the framework.
     *
     * @param  array<string, string>  $options
     */
    private function workRequest(array $options): int
    {
        $capability = $this->capability($options['capability'] ?? '');
        $requestId = bin2hex(random_bytes(16));
        $task = $this->jsonObject($options['payload'] ?? '{}', 'payload');
        $envelope = $this->envelope([
            ...$options,
            'type' => 'workRequest',
            'payload' => json_encode([
                'requestId' => $requestId,
                'capability' => $capability,
                'task' => $task,
                'replySubject' => 'work.result.'.$requestId,
            ], JSON_THROW_ON_ERROR),
        ], requireSession: false);
        $this->requireAgentType($envelope);
        [$envelope['sessionId']] = $this->canonicalize($envelope['sessionId'], $envelope['agentType']);
        $this->requireSubjectSafe($envelope['sessionId']);

        try {
            $this->publish('work.request.'.$capability.'.'.$requestId, $envelope);
            $wait = max(0.0, (float) ($options['wait'] ?? 0));

            if ($wait <= 0) {
                echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

                return 0;
            }

            $result = $this->waitForEnvelope('work.result.'.$requestId, $requestId, $wait);

            if ($result === null) {
                fwrite(STDERR, "work result {$requestId} timed out\n");

                return 1;
            }

            echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }
    }

    /**
     * Pull one addressed inbox message. The receiving process owns delivery;
     * no terminal multiplexer or Herdr pane is required.
     *
     * @param  array<string, string>  $options
     */
    private function inbox(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        [$canonical] = $this->canonicalize($sessionId, $agentType);

        try {
            $resolved = $this->resolveOnBus($canonical);

            if ($resolved === null) {
                fwrite(STDERR, "session {$sessionId} is not on the bus\n");

                return 1;
            }

            $this->requireSubjectSafe($resolved);
            $pulled = $this->pullInboxMessage($resolved, $this->inboxExpires());

            if ($pulled !== null) {
                // The inbox verb hands the message to its caller: ack now (at most once).
                $this->ack($pulled[1]);
            }

            $envelope = $pulled[0] ?? null;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        if ($envelope === null) {
            return 2;
        }

        $this->deliveredReceipt($this->envelopeJobId($envelope), Receipt::attemptOf($envelope), $resolved, $agentType, $envelope);

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    public static function inboxConsumerName(string $sessionId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $sessionId) ?? 'session';

        return 'agent-bus-inbox-'.$safe;
    }

    /**
     * A present session must be able to receive mail published before its
     * first pull. DeliverPolicy New skips anything published before the
     * durable consumer exists, so send creates that consumer first.
     */
    private function ensureInboxConsumer(string $sessionId): void
    {
        $name = self::inboxConsumerName($sessionId);
        $consumer = $this->client()->getApi()->getStream($this->streamName())->getConsumer($name);

        if ($consumer->exists()) {
            return;
        }

        $consumer->getConfiguration()
            ->setSubjectFilter('session.'.$sessionId.'.inbox')
            ->setDeliverPolicy(DeliverPolicy::NEW)
            ->setAckPolicy(AckPolicy::EXPLICIT);

        self::configureInboxConsumer($consumer->getConfiguration());

        try {
            $consumer->create();
        } catch (Throwable $exception) {
            $again = $this->client()->getApi()->getStream($this->streamName())->getConsumer($name);

            if ($again->exists()) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Seconds without a pull before NATS removes an inbox consumer; matches the
     * stream's 7-day max age. 0 keeps consumers forever.
     */
    public static function inboxInactiveSeconds(): int
    {
        $value = getenv('AGENT_BUS_INBOX_INACTIVE_SECONDS');

        return is_string($value) && $value !== '' ? max(0, (int) $value) : 604_800;
    }

    private function inboxExpires(): float
    {
        $expires = getenv('AGENT_BUS_INBOX_EXPIRES');
        $seconds = is_string($expires) ? (float) $expires : 0.0;

        return $seconds > 0 ? $seconds : 0.5;
    }

    /**
     * Pull one message from the session's inbox without acknowledging it.
     * The caller acks (inbox: at once; listen --exec: after the command).
     *
     * @return array{0: array<string, mixed>, 1: Msg}|null
     */
    private function pullInboxMessage(string $sessionId, float $expiresSeconds): ?array
    {
        $this->ensureInboxConsumer($sessionId);
        $consumer = $this->client()->getApi()->getStream($this->streamName())->getConsumer(self::inboxConsumerName($sessionId));
        $consumer->setBatching(1);
        $consumer->setExpires($expiresSeconds);

        $queue = $consumer->getQueue();
        $queue->setTimeout($expiresSeconds + 0.5);

        try {
            foreach ($queue->fetchAll(1) as $message) {
                if (! $message instanceof Msg || $message->payload->isEmpty()) {
                    continue;
                }

                $decoded = json_decode($message->payload->body, true);

                return [is_array($decoded) ? $decoded : ['raw' => $message->payload->body], $message];
            }
        } finally {
            $this->client()->unsubscribe($queue);
        }

        return null;
    }

    private function deleteInboxConsumer(string $sessionId): void
    {
        $consumer = $this->client()->getApi()->getStream($this->streamName())->getConsumer(self::inboxConsumerName($sessionId));

        if ($consumer->exists()) {
            $consumer->delete();
        }
    }

    /**
     * Publish a correlated result from any specialized worker.
     *
     * @param  array<string, string>  $options
     */
    private function workResult(array $options): int
    {
        $requestId = $options['request'] ?? '';
        $this->requireSubjectSafe($requestId);

        if ($requestId === '') {
            throw new InvalidArgumentException('Missing --request.');
        }

        $status = $options['status'] ?? 'ok';

        if (! in_array($status, ['ok', 'error'], true)) {
            throw new InvalidArgumentException('--status must be ok or error.');
        }

        $result = $this->jsonObject($options['payload'] ?? '{}', 'payload');
        $envelope = $this->envelope([
            ...$options,
            'type' => 'workResult',
            'payload' => json_encode([
                'requestId' => $requestId,
                'status' => $status,
                'result' => $result,
            ], JSON_THROW_ON_ERROR),
        ], requireSession: false);
        $this->requireAgentType($envelope);

        try {
            $this->publish('work.result.'.$requestId, $envelope);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForEnvelope(string $subject, string $requestId, float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;

        do {
            try {
                $response = $this->client()->getApi()->getStream($this->streamName())->getLastMessage($subject);
                $data = $response->message->data ?? null;

                if (is_string($data) && $data !== '') {
                    $decoded = base64_decode($data, true);
                    $payload = $decoded !== false ? $decoded : $data;
                    $envelope = json_decode($payload, true);

                    if (! is_array($envelope)) {
                        // Malformed result: wait like any other miss instead of spinning.
                        usleep(100_000);

                        continue;
                    }

                    $resultPayload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];

                    if (($resultPayload['requestId'] ?? null) === $requestId) {
                        return $envelope;
                    }
                }
            } catch (Throwable) {
                // The result subject does not exist yet.
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function capability(string $capability): string
    {
        $capability = strtolower(trim($capability));

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $capability) !== 1) {
            throw new InvalidArgumentException('Missing or invalid --capability; use one lowercase NATS token.');
        }

        return $capability;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function heartbeat(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        [$canonical, $aliases] = $this->canonicalize($sessionId, $agentType);
        $this->requireSubjectSafe($canonical);

        try {
            $presence = $this->explicitPresence(
                $canonical,
                $agentType,
                $this->option($options, 'model', 'AGENT_BUS_MODEL'),
                $this->option($options, 'capabilities', 'AGENT_BUS_CAPABILITIES'),
            );
            $this->putSession($canonical, json_encode($presence, JSON_THROW_ON_ERROR));
            $this->putAliases($aliases, $canonical);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($presence, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function explicitPresence(string $sessionId, string $agentType, string $model, string $capabilities = ''): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $firstSeen = $now;
        $existing = $this->session($sessionId);

        if (is_string($existing) && $existing !== '') {
            $record = json_decode($existing, true);
            $seen = is_array($record) ? ($record['firstSeen'] ?? null) : null;
            $firstSeen = is_string($seen) && $seen !== '' ? $seen : $now;
        }

        return [
            'v' => 2,
            'sessionId' => $sessionId,
            'agentType' => $agentType,
            'model' => $model,
            'capabilities' => $this->capabilities($capabilities),
            'host' => gethostname() ?: '',
            'repo' => $this->repo(),
            'registered' => 'explicit',
            'identity_quality' => BusIdentity::quality($sessionId),
            'timestamp' => $now,
            'firstSeen' => $firstSeen,
            'lastSeen' => $now,
        ];
    }

    /** @return list<string> */
    private function capabilities(string $capabilities): array
    {
        $values = [];

        foreach (explode(',', $capabilities) as $capability) {
            $capability = trim($capability);

            if ($capability !== '') {
                $values[$this->capability($capability)] = true;
            }
        }

        return array_keys($values);
    }

    /**
     * @param  list<string>  $positionals
     * @param  array<string, string>  $options
     */
    private function sessions(array $positionals, array $options): int
    {
        $subcommand = $positionals[1] ?? 'list';

        try {
            return match ($subcommand) {
                'list' => $this->listSessions($options),
                'get' => $this->getSession($positionals[2] ?? ''),
                default => $this->usage(),
            };
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }
    }

    /**
     * Default: one KV scan of full presence records. `--ids` keeps the old
     * string-array shape for scripts that only want keys.
     *
     * @param  array<string, string>  $options
     */
    private function listSessions(array $options): int
    {
        $idsOnly = ($options['ids'] ?? '') === '1';
        $sessions = [];

        if (! $this->bucketExists($this->kvBucket())) {
            echo "[]\n";

            return 0;
        }

        foreach ($this->client()->getApi()->getBucket($this->kvBucket())->getAll() as $entry) {
            if ($entry->key === '' || ! is_string($entry->value) || $entry->value === '') {
                continue;
            }

            if ($idsOnly) {
                $sessions[$entry->key] = $entry->key;

                continue;
            }

            $sessions[$entry->key] = $this->decodePresence($entry->key, $entry->value);
        }

        echo json_encode(array_values($sessions), JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePresence(string $key, string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return ['sessionId' => $key];
        }

        if (! isset($decoded['sessionId']) || ! is_string($decoded['sessionId']) || $decoded['sessionId'] === '') {
            $decoded['sessionId'] = $key;
        }

        return $decoded;
    }

    private function getSession(string $id): int
    {
        if ($id === '') {
            throw new InvalidArgumentException('Missing session id.');
        }

        $resolved = $this->resolveOnBus($id);

        if ($resolved === null) {
            fwrite(STDERR, "session {$id} is not on the bus\n");

            return 1;
        }

        echo $this->session($resolved)."\n";

        return 0;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function publish(string $subject, array $envelope, ?string $messageId = null): void
    {
        $body = json_encode($envelope, JSON_THROW_ON_ERROR);
        $payload = $messageId === null ? $body : new Payload($body, ['Nats-Msg-Id' => $messageId]);

        $this->client()->getApi()->getStream($this->streamName())->publish($subject, $payload);
    }

    private function session(string $id): ?string
    {
        if (! $this->bucketExists($this->kvBucket())) {
            return null;
        }

        $value = $this->client()->getApi()->getBucket($this->kvBucket())->get($id);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function putSession(string $id, string $json): void
    {
        $this->client()->getApi()->getBucket($this->kvBucket())->put($id, $json);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function sessionEnd(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        [$canonical] = $this->canonicalize($sessionId, $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'));

        try {
            $resolved = $this->resolveOnBus($canonical) ?? $canonical;
            $this->requireSubjectSafe($resolved);
            try {
                $this->deleteInboxConsumer($resolved);
            } catch (Throwable) {
                // Presence deletion still wins; an orphaned consumer is idle.
            }
            $this->deleteSession($resolved);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        return 0;
    }

    private function deleteSession(string $id): void
    {
        if (! $this->bucketExists($this->kvBucket())) {
            return;
        }

        $this->client()->getApi()->getBucket($this->kvBucket())->delete($id);
    }

    private function bucketExists(string $bucket): bool
    {
        return $this->client()->getApi()->getBucket($bucket)->getStream()->exists();
    }

    /**
     * @param  class-string<GrokHook|OpenCodeCapture>  $mapper
     */
    private function fromStdin(string $mapper): int
    {
        try {
            $raw = stream_get_contents(STDIN);

            if (! is_string($raw) || trim($raw) === '') {
                return 0;
            }

            try {
                $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 0;
            }

            if (! is_array($event)) {
                return 0;
            }

            foreach ($mapper::actions($event) as $action) {
                $this->perform($action);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");
        }

        return 0;
    }

    private function perform(CaptureAction $action): void
    {
        $options = [
            'session' => $action->sessionId,
            'agent-type' => $action->agentType,
            'type' => $action->type !== '' ? $action->type : 'capture',
            'payload' => json_encode($action->payload, JSON_THROW_ON_ERROR),
        ];

        if ($action->model !== '') {
            $options['model'] = $action->model;
        }

        try {
            match ($action->verb) {
                'emit' => $this->publishQuiet($options),
                'heartbeat' => $this->heartbeatQuiet($options),
                'sessionEnd' => $this->deleteSession($action->sessionId),
                default => null,
            };

            if ($action->verb !== 'sessionEnd') {
                $this->putAliases($action->aliases, $action->sessionId);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");
        }
    }

    /**
     * @param  array<string, string>  $options
     */
    private function publishQuiet(array $options): void
    {
        $envelope = $this->envelope($options, requireSession: false);
        $this->requireAgentType($envelope);
        $this->requireSubjectSafe($envelope['sessionId']);
        $this->registerPresence($envelope);
        $this->publish($this->repoSubject($envelope), $envelope);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function heartbeatQuiet(array $options): void
    {
        $sessionId = $this->sessionId($options, required: true);
        $presence = $this->explicitPresence(
            $sessionId,
            $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            $this->option($options, 'capabilities', 'AGENT_BUS_CAPABILITIES'),
        );

        $this->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $options
     * @return array{v: int, sessionId: string, agentType: string, model: string, repo: string, type: string, timestamp: string, payload: array<string, mixed>}
     */
    private function envelope(array $options, bool $requireSession): array
    {
        $type = $options['type'] ?? '';

        if ($type === '') {
            throw new InvalidArgumentException('Missing --type.');
        }

        $payloadJson = $options['payload'] ?? '{}';
        if ($payloadJson === '') {
            $payloadJson = '{}';
        }

        return [
            'v' => 2,
            'sessionId' => $this->sessionId($options, $requireSession),
            'agentType' => $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            'model' => $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            'repo' => $this->repo(),
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $this->jsonObject($payloadJson, 'payload'),
        ];
    }

    /**
     * @param  array{repo: string, type: string}  $envelope
     */
    private function repoSubject(array $envelope): string
    {
        [$owner, $name] = explode('/', $envelope['repo'], 2) + [1 => 'unknown'];

        return 'repo.'.$owner.'.'.$name.'.'.$envelope['type'];
    }

    /**
     * @param  array<string, string>  $options
     */
    private function sessionId(array $options, bool $required): string
    {
        $sessionId = $this->option($options, 'session', 'AGENT_BUS_SESSION_ID');

        if ($required && $sessionId === '') {
            throw new InvalidArgumentException('Missing --session (or AGENT_BUS_SESSION_ID).');
        }

        return $sessionId;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function option(array $options, string $name, string $envName, string $default = ''): string
    {
        if (isset($options[$name]) && $options[$name] !== '') {
            return $options[$name];
        }

        $fromEnv = getenv($envName);

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : $default;
    }

    private function jsonObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("{$label} is not JSON.");
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$label} must be a JSON object.");
        }

        return $decoded;
    }

    private function repo(): string
    {
        $proc = proc_open(
            ['git', 'remote', 'get-url', 'origin'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
        );

        if (! is_resource($proc)) {
            return 'unknown/unknown';
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if (! is_string($stdout)) {
            return 'unknown/unknown';
        }

        return self::repoFromRemote($stdout);
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $client = new Client($this->configuration());

            // basis-nats has one timeout knob for connect AND request
            // dispatch. Connect inside the tight fail-open budget, then give
            // JetStream round trips (KV get/put acks) room — issue #30, bug c.
            $client->ping();
            $client->configuration->timeout = $this->dispatchTimeout();

            $this->client = $client;
        }

        return $this->client;
    }

    private function configuration(): Configuration
    {
        $parts = NatsUrl::parse(NatsUrl::fromEnvironment());
        $user = getenv('NATS_USER');
        $pass = getenv('NATS_PASS');
        $tlsCaFile = getenv('NATS_TLS_CA');
        $tlsCertFile = getenv('NATS_TLS_CERT');
        $tlsKeyFile = getenv('NATS_TLS_KEY');

        return new Configuration(
            host: $parts['host'],
            port: $parts['port'],
            user: is_string($user) && $user !== '' ? $user : $parts['user'],
            pass: is_string($pass) && $pass !== '' ? $pass : $parts['pass'],
            tlsKeyFile: is_string($tlsKeyFile) && $tlsKeyFile !== '' ? $tlsKeyFile : null,
            tlsCertFile: is_string($tlsCertFile) && $tlsCertFile !== '' ? $tlsCertFile : null,
            tlsCaFile: is_string($tlsCaFile) && $tlsCaFile !== '' ? $tlsCaFile : null,
            reconnect: false,
            timeout: $this->connectTimeout(),
            maxReconnectAttempts: 0,
        );
    }

    /**
     * Loopback needs milliseconds; a tailnet broker needs room. AGENT_BUS_CONNECT_TIMEOUT overrides.
     */
    private function connectTimeout(): float
    {
        $raw = getenv('AGENT_BUS_CONNECT_TIMEOUT');
        $timeout = is_string($raw) ? (float) $raw : 0.0;

        return $timeout > 0 ? $timeout : 0.25;
    }

    /**
     * Request/response budget after the connection is up. AGENT_BUS_DISPATCH_TIMEOUT overrides.
     */
    private function dispatchTimeout(): float
    {
        $raw = getenv('AGENT_BUS_DISPATCH_TIMEOUT');
        $timeout = is_string($raw) ? (float) $raw : 0.0;

        return $timeout > 0 ? $timeout : 1.0;
    }

    private function streamName(): string
    {
        $stream = getenv('AGENT_BUS_STREAM');

        return is_string($stream) && $stream !== '' ? $stream : 'AGENT_BUS';
    }

    private function kvBucket(): string
    {
        $bucket = getenv('AGENT_BUS_KV_BUCKET');

        return is_string($bucket) && $bucket !== '' ? $bucket : 'sessions';
    }

    private function aliasBucketName(): string
    {
        $bucket = getenv('AGENT_BUS_ALIAS_BUCKET');

        return is_string($bucket) && $bucket !== '' ? $bucket : 'session_aliases';
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function parse(array $argv): array
    {
        array_shift($argv);

        $positionals = [];
        $options = [];
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];

            if (! str_starts_with($arg, '--')) {
                $positionals[] = $arg;

                continue;
            }

            $body = substr($arg, 2);

            if (str_contains($body, '=')) {
                [$key, $value] = explode('=', $body, 2);
                $options[$key] = self::optionValue($options, $key, $value);

                continue;
            }

            $next = $argv[$i + 1] ?? null;

            if (is_string($next) && ! str_starts_with($next, '--')) {
                $options[$body] = self::optionValue($options, $body, $next);
                $i++;

                continue;
            }

            $options[$body] = '1';
        }

        return [$positionals, $options];
    }

    /** Options that may repeat; their values are joined with commas. */
    private const REPEATABLE = ['pass-env'];

    /** @param  array<string, string>  $options */
    private static function optionValue(array $options, string $key, string $value): string
    {
        return in_array($key, self::REPEATABLE, true) && isset($options[$key]) ? $options[$key].','.$value : $value;
    }

    private function usage(): int
    {
        fwrite(STDERR, <<<'TXT'
Usage:
  bin/agent-bus emit --type=<type> --agent-type=<name> [--payload=<json>] [--session=<id>] [--model=<name>]
  bin/agent-bus send --session=<id-or-alias> --payload=<json> [--job=<id>] [--attempt=<n>] [--wait-receipt=<seconds>]
  bin/agent-bus inbox --session=<id-or-alias>
  bin/agent-bus listen --session=<id> [--agent-type=<name>] [--exec=<cmd>] [--exec-timeout=<seconds>] [--pass-env=<NAME>]... [--max=<n>] [--for=<seconds>]
  bin/agent-bus receipt --job=<id> [--attempt=<n>] [--until=<queued|delivered|done|failed>] [--wait=<seconds>]
  bin/agent-bus receipt --job=<id> [--attempt=<n>] --state=<delivered|done|failed> --session=<id> [--payload=<json>]
  bin/agent-bus heartbeat --session=<id> [--agent-type=<name>] [--model=<name>]
  bin/agent-bus session-end --session=<id-or-alias>
  bin/agent-bus sessions [--ids]
  bin/agent-bus sessions get <id-or-alias>
  bin/agent-bus work-request --capability=<name> --agent-type=<name> --payload=<json> [--session=<id>] [--wait=<seconds>]
  bin/agent-bus work-result --request=<id> --status=<ok|error> --agent-type=<name> --payload=<json> [--session=<id>]
  bin/agent-bus hook
  bin/agent-bus opencode

Ids normalize to {kind}:{provider_id}; alternates resolve via the session_aliases KV bucket.

Cold path (boots the framework):
  bin/agent-bus provision
  bin/agent-bus judge [--once]
TXT."\n");

        return 1;
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'NATS broker is down.';
    }
}
