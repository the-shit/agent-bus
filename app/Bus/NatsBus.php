<?php

namespace App\Bus;

use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\KeyValue\Bucket;
use Basis\Nats\Stream\Stream;
use JsonException;
use LaravelNats\Laravel\NatsV2Gateway;
use RuntimeException;

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

    public function provision(): void
    {
        $stream = $this->stream();
        $configuration = $stream->getConfiguration();

        if (! $stream->exists()) {
            $configuration->setSubjects($this->subjects());
            $configuration->setDuplicateWindow(2.0);
        }

        $stream->createIfNotExists();

        $ttlSeconds = (int) config('agent_bus.kv.ttl_seconds', 90);
        $history = (int) config('agent_bus.kv.history', 1);
        $ttlNanos = $ttlSeconds * 1_000_000_000;

        $bucket = $this->bucket();
        $bucket->getConfiguration()
            ->setHistory($history)
            ->setTtl($ttlNanos);

        $stream = $bucket->getStream();
        $bucket->getConfiguration()->configureStream($stream->getConfiguration());
        $stream->update();
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
    public function publishEnvelope(string $subject, array $envelope): void
    {
        try {
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode the bus envelope as JSON.', 0, $exception);
        }

        $this->stream()->publish($subject, $body);
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

        $decoded = base64_decode($data, true);
        $body = $decoded !== false ? $decoded : $data;
        $envelope = json_decode($body, true);

        if (! is_array($envelope)) {
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

        $host = preg_replace('/[^A-Za-z0-9_-]+/', '-', gethostname() ?: 'host') ?? 'host';
        $host = trim($host, '-');

        if ($host === '') {
            $host = 'host';
        }

        return 'agent-bus-sidecar-'.$host;
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

    private function stream(): Stream
    {
        return $this->nats->jetstream()->stream($this->streamName());
    }

    private function bucket(): Bucket
    {
        return $this->nats->jetstream()->api()->getBucket($this->kvBucket());
    }
}
