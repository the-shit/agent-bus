<?php

namespace App\Bus;

use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\KeyValue\Bucket;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\Stream;
use InvalidArgumentException;
use JsonException;
use LaravelNats\Laravel\NatsV2Gateway;
use RuntimeException;
use Throwable;

class NatsBus
{
    public function __construct(private readonly NatsV2Gateway $nats) {}

    public function isReachable(): bool
    {
        $socket = @fsockopen($this->host(), $this->port(), $errno, $errstr, 0.25);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function host(): string
    {
        return (string) config('nats_basis.connections.default.host', '127.0.0.1');
    }

    public function port(): int
    {
        return (int) config('nats_basis.connections.default.port', 4222);
    }

    public function streamName(): string
    {
        return (string) config('agent_bus.stream', 'AGENT_BUS');
    }

    /**
     * @return list<string>
     */
    public function subjects(): array
    {
        /** @var list<string> $subjects */
        $subjects = array_values(array_filter(
            (array) config('agent_bus.subjects', ['repo.>', 'session.>']),
            fn (mixed $subject): bool => is_string($subject) && $subject !== '',
        ));

        return $subjects;
    }

    public function kvBucket(): string
    {
        return (string) config('agent_bus.kv.bucket', 'sessions');
    }

    public function aliasesBucket(): string
    {
        return (string) config('agent_bus.aliases.bucket', 'session_aliases');
    }

    public function provision(): void
    {
        $stream = $this->stream();
        $configuration = $stream->getConfiguration();
        $exists = $stream->exists();

        $configuration->setSubjects($this->subjects());
        $configuration->setDuplicateWindow(max(2.0, (float) config('agent_bus.stream_limits.duplicate_window_seconds', 900)));
        $configuration->setMaxAge(max(0, (int) config('agent_bus.stream_limits.max_age_seconds', 604800)) * 1_000_000_000);
        $configuration->setMaxBytes(max(1_048_576, (int) config('agent_bus.stream_limits.max_bytes', 1_073_741_824)));
        $configuration->setMaxMessageSize(max(1_024, (int) config('agent_bus.stream_limits.max_message_bytes', 262_144)));

        $stream->createIfNotExists();

        if ($exists) {
            $stream->update();
        }

        $ttlSeconds = (int) config('agent_bus.kv.ttl_seconds', 90);
        $history = (int) config('agent_bus.kv.history', 1);
        $ttlNanos = $ttlSeconds * 1_000_000_000;

        $bucket = $this->bucket();
        $bucket->getConfiguration()
            ->setHistory($history)
            ->setTtl($ttlNanos);

        $stream = $bucket->getStream();
        $bucket->getConfiguration()->configureStream($stream->getConfiguration());
        // NATS refuses a duplicate window longer than max_age. New KV buckets
        // default to a two-minute window, which is invalid for our 90s TTL.
        $stream->getConfiguration()->setDuplicateWindow(min(2.0, max(0.001, $ttlSeconds)));
        $stream->update();

        // Alternate id -> canonical id map. No TTL: aliases are durable
        // pointers, unlike the 90s presence records.
        $aliases = $this->nats->jetstream()->api()->getBucket($this->aliasesBucket());
        $aliasStream = $aliases->getStream();
        $aliases->getConfiguration()->setHistory(1);
        $aliases->getConfiguration()->configureStream($aliasStream->getConfiguration());
        $aliasStream->update();
    }

    public function sessionTtlNanos(): int
    {
        return $this->bucket()->getStatus()->ttl;
    }

    public function streamExists(): bool
    {
        return $this->stream()->exists();
    }

    public function kvBucketExists(): bool
    {
        return $this->nats->jetstream()->stream('KV_'.$this->kvBucket())->exists();
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function publishEnvelope(string $subject, array $envelope, ?string $messageId = null): void
    {
        try {
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode the bus envelope as JSON.', 0, $exception);
        }

        $payload = $messageId === null
            ? $body
            : new Payload($body, ['Nats-Msg-Id' => $messageId]);

        $this->stream()->publish($subject, $payload);
    }

    public function completedWorkResultExists(string $requestId): bool
    {
        $this->assertRequestId($requestId);

        try {
            $response = $this->stream()->getLastMessage('work.result.'.$requestId);
        } catch (\Exception $exception) {
            if ($exception->getMessage() === 'no message found') {
                return false;
            }

            throw $exception;
        }

        $data = $response->message->data ?? null;

        if (! is_string($data) || $data === '') {
            return false;
        }

        $envelope = $this->decodeEnvelope($data);
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];

        return ($payload['requestId'] ?? null) === $requestId
            && in_array($payload['status'] ?? null, ['ok', 'error'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastEnvelope(string $subject): array
    {
        $response = $this->stream()->getLastMessage($subject);
        $data = $response->message->data ?? null;

        if (! is_string($data) || $data === '') {
            throw new RuntimeException("No message found on subject {$subject}.");
        }

        $envelope = $this->decodeEnvelope($data);

        if ($envelope === []) {
            throw new RuntimeException("Last message on {$subject} was not a JSON object.");
        }

        return $envelope;
    }

    public function putSession(string $id, string $json): void
    {
        $this->bucket()->put($id, $json);
    }

    public function deleteSession(string $id): void
    {
        $this->bucket()->delete($id);
    }

    public function getSession(string $id): ?string
    {
        $value = $this->bucket()->get($id);

        return is_string($value) ? $value : null;
    }

    /**
     * Canonical id whose presence is on the bus, resolving the given id
     * through the alias map first. Null when nothing is registered.
     */
    public function resolveSessionId(string $id): ?string
    {
        if ($this->getSession($id) !== null) {
            return $id;
        }

        $canonical = $this->getAlias($id);

        if ($canonical !== null && $this->getSession($canonical) !== null) {
            return $canonical;
        }

        return null;
    }

    public function putAlias(string $alternate, string $canonical): void
    {
        $this->aliasBucket()->put(self::aliasKey($alternate), json_encode([
            'alternate' => $alternate,
            'canonical' => $canonical,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));
    }

    public function getAlias(string $alternate): ?string
    {
        if ($alternate === '') {
            return null;
        }

        try {
            $value = $this->aliasBucket()->get(self::aliasKey($alternate));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        $canonical = is_array($decoded) ? ($decoded['canonical'] ?? null) : null;

        return is_string($canonical) && $canonical !== '' ? $canonical : null;
    }

    /**
     * Alternate ids hold dots/slashes the KV backing stream's single-token
     * subject cannot store (issue #30, bug a) — hash the key.
     */
    public static function aliasKey(string $alternate): string
    {
        return hash('sha256', $alternate);
    }

    /**
     * One KV getAll. Presence records as stored, with sessionId filled from the
     * key when the JSON omitted it.
     *
     * @return list<array<string, mixed>>
     */
    public function listPresence(): array
    {
        $sessions = [];

        foreach ($this->bucket()->getAll() as $entry) {
            if ($entry->key === '' || ! is_string($entry->value) || $entry->value === '') {
                continue;
            }

            $decoded = json_decode($entry->value, true);

            if (! is_array($decoded)) {
                $sessions[] = ['sessionId' => $entry->key];

                continue;
            }

            if (! isset($decoded['sessionId']) || ! is_string($decoded['sessionId']) || $decoded['sessionId'] === '') {
                $decoded['sessionId'] = $entry->key;
            }

            $sessions[] = $decoded;
        }

        return $sessions;
    }

    /**
     * @return list<string>
     */
    public function listSessionIds(): array
    {
        $ids = [];

        foreach ($this->bucket()->getAll() as $entry) {
            if ($entry->key !== '' && is_string($entry->value) && $entry->value !== '') {
                $ids[$entry->key] = $entry->key;
            }
        }

        return array_values($ids);
    }

    public function sidecarConsumerName(): string
    {
        $configured = config('agent_bus.sidecar.consumer');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->hostScopedConsumerName('agent-bus-sidecar');
    }

    public function ensureInboxConsumer(): void
    {
        $consumer = $this->stream()->getConsumer($this->sidecarConsumerName());

        if (! $consumer->exists()) {
            $consumer->getConfiguration()
                ->setSubjectFilter('session.*.inbox')
                ->setDeliverPolicy(DeliverPolicy::NEW)
                ->setAckPolicy(AckPolicy::EXPLICIT)
                ->setAckWait(60_000_000_000);
            $consumer->create();
        } elseif ($consumer->getConfiguration()->getAckWait() !== 60_000_000_000) {
            $consumer->getConfiguration()->setAckWait(60_000_000_000);
            $consumer->create(false);
        }
    }

    /**
     * Pull one inbox batch. Failures are retried or recorded before acknowledgement.
     *
     * @param  callable(string, string): void  $handler
     */
    public function consumeInbox(callable $handler, int $iterations = 1): int
    {
        $this->ensureInboxConsumer();

        $expires = (float) config('agent_bus.sidecar.expires', 0.5);

        $consumer = $this->stream()->getConsumer($this->sidecarConsumerName());
        $consumer->setBatching(1);
        $consumer->setExpires($expires > 0 ? $expires : 0.5);

        $processed = 0;
        $delivery = new InboxDelivery($this);
        for ($iteration = 0; $iteration < max(1, $iterations); $iteration++) {
            // Fetch one at a time: a slow prompt must not age other messages' leases.
            $queue = $consumer->getQueue();
            try {
                foreach ($queue->fetchAll(1) as $message) {
                    if (! $message->payload->isEmpty()) {
                        $delivery->handle($message, $handler);
                        $processed++;
                    }
                }
            } finally {
                $consumer->client->unsubscribe($queue);
            }
        }

        return $processed;
    }

    public function monitorConsumerName(): string
    {
        $configured = config('agent_bus.monitor.consumer');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->hostScopedConsumerName('agent-bus-monitor');
    }

    public function ensureMonitorConsumer(): void
    {
        $consumer = $this->stream()->getConsumer($this->monitorConsumerName());

        if (! $consumer->exists()) {
            $consumer->getConfiguration()
                ->setSubjectFilters($this->subjects())
                ->setDeliverPolicy(DeliverPolicy::NEW)
                ->setAckPolicy(AckPolicy::EXPLICIT);
            $consumer->create();
        }
    }

    /**
     * Pull one batch of bus envelopes from every subject and hand each to the handler.
     * Invalid payloads are acked (skipped) by the caller returning normally.
     *
     * @param  callable(string, array<string, mixed>, ?int): void  $handler
     */
    public function consumeMonitor(callable $handler, int $iterations = 1): int
    {
        $this->ensureMonitorConsumer();

        $batch = (int) config('agent_bus.monitor.batch', 8);
        $expires = (float) config('agent_bus.monitor.expires', 0.5);

        $consumer = $this->stream()->getConsumer($this->monitorConsumerName());
        $consumer->setIterations(max(1, $iterations));
        $consumer->setBatching(max(1, $batch));
        $consumer->setExpires($expires > 0 ? $expires : 0.5);

        return $consumer->handle(function (Payload $payload, string $replyTo) use ($handler): void {
            $subject = is_string($payload->subject) ? $payload->subject : '';
            $body = is_string($payload->body) ? $payload->body : '';
            $handler($subject, $this->decodeEnvelope($body), $this->streamSeqFromReplyTo($replyTo));
        });
    }

    public function ensureWorkConsumer(string $capability, string $consumerName): void
    {
        $this->assertCapability($capability);
        $this->assertConsumerName($consumerName);

        $consumer = $this->stream()->getConsumer($consumerName);

        if ($consumer->exists()) {
            return;
        }

        $ackWaitSeconds = max(1, (int) config('agent_bus.worker.ack_wait_seconds', 300));
        $consumer->getConfiguration()
            ->setSubjectFilter('work.request.'.$capability.'.*')
            ->setDeliverPolicy(DeliverPolicy::NEW)
            ->setAckPolicy(AckPolicy::EXPLICIT)
            ->setAckWait($ackWaitSeconds * 1_000_000_000);

        try {
            $consumer->create();
        } catch (Throwable $exception) {
            $again = $this->stream()->getConsumer($consumerName);

            if ($again->exists()) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * Pull one batch from a capability queue. Every worker in the same pool
     * uses the same durable consumer name, so each request has one owner.
     *
     * @param  callable(string, array<string, mixed>): void  $handler
     */
    public function consumeWork(
        string $capability,
        string $consumerName,
        callable $handler,
        int $iterations = 1,
    ): int {
        $this->ensureWorkConsumer($capability, $consumerName);

        $batch = (int) config('agent_bus.worker.batch', 8);
        $expires = (float) config('agent_bus.worker.expires', 0.5);
        $consumer = $this->stream()->getConsumer($consumerName);
        $consumer->setIterations(max(1, $iterations));
        $consumer->setBatching(max(1, $batch));
        $consumer->setExpires($expires > 0 ? $expires : 0.5);

        return $consumer->handle(function (Payload $payload) use ($handler): void {
            $subject = is_string($payload->subject) ? $payload->subject : '';
            $body = is_string($payload->body) ? $payload->body : '';
            $handler($subject, $this->decodeEnvelope($body));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEnvelope(string $body): array
    {
        $decoded = base64_decode($body, true);
        $payload = $decoded !== false ? $decoded : $body;
        $envelope = json_decode($payload, true);

        return is_array($envelope) ? $envelope : [];
    }

    private function streamSeqFromReplyTo(?string $replyTo): ?int
    {
        if ($replyTo === null || $replyTo === '') {
            return null;
        }

        if (! str_starts_with($replyTo, '$JS.ACK.')) {
            return null;
        }

        $tokens = explode('.', $replyTo);

        // Old format: \$JS.ACK.\u003cstream\u003e.\u003cconsumer\u003e.\u003credeliveryCount\u003e.\u003cstreamSeq\u003e.\u003cdeliverySequence\u003e.\u003ctimestamp\u003e.\u003cpending\u003e
        // (9 tokens). New format adds domain + account hash at positions 2–3.
        if (count($tokens) === 9) {
            array_splice($tokens, 2, 0, ['', '']);
        }

        if (count($tokens) < 11) {
            return null;
        }

        return is_numeric($tokens[7]) ? (int) $tokens[7] : null;
    }

    private function hostScopedConsumerName(string $prefix): string
    {
        $host = preg_replace('/[^A-Za-z0-9_-]+/', '-', gethostname() ?: 'host') ?? 'host';
        $host = trim($host, '-');

        if ($host === '') {
            $host = 'host';
        }

        return $prefix.'-'.$host;
    }

    private function assertCapability(string $capability): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $capability) !== 1) {
            throw new InvalidArgumentException('Work capability must be one lowercase NATS token.');
        }
    }

    private function assertConsumerName(string $consumerName): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $consumerName) !== 1) {
            throw new InvalidArgumentException('Work consumer name contains unsupported characters.');
        }
    }

    private function assertRequestId(string $requestId): void
    {
        if (preg_match('/^[^\s.*>]{1,128}$/', $requestId) !== 1) {
            throw new InvalidArgumentException('Work request id contains unsupported characters.');
        }
    }

    public function stream(): Stream
    {
        return $this->nats->jetstream()->stream($this->streamName());
    }

    /**
     * Drop every message on the configured stream. The live AGENT_BUS stream
     * is refused so a test or a caller cannot wipe production mail.
     */
    public function purgeStream(): void
    {
        if ($this->streamName() === 'AGENT_BUS') {
            throw new RuntimeException('Refusing to purge the live AGENT_BUS stream.');
        }

        $this->stream()->purge();
    }

    private function bucket(): Bucket
    {
        return $this->nats->jetstream()->api()->getBucket($this->kvBucket());
    }

    private function aliasBucket(): Bucket
    {
        return $this->nats->jetstream()->api()->getBucket($this->aliasesBucket());
    }
}
