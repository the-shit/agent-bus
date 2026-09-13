<?php

namespace App\Bus;

use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\KeyValue\Bucket;
use Basis\Nats\Message\Payload;
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
                ->setAckPolicy(AckPolicy::EXPLICIT);
            $consumer->create();
        }
    }

    /**
     * Pull one inbox batch. Unknown sessions are acked (dropped) by the caller returning normally.
     *
     * @param  callable(string, string): void  $handler
     */
    public function consumeInbox(callable $handler, int $iterations = 1): int
    {
        $this->ensureInboxConsumer();

        $batch = (int) config('agent_bus.sidecar.batch', 8);
        $expires = (float) config('agent_bus.sidecar.expires', 0.5);

        $consumer = $this->stream()->getConsumer($this->sidecarConsumerName());
        $consumer->setIterations(max(1, $iterations));
        $consumer->setBatching(max(1, $batch));
        $consumer->setExpires($expires > 0 ? $expires : 0.5);

        return $consumer->handle(function (Payload $payload) use ($handler): void {
            $subject = is_string($payload->subject) ? $payload->subject : '';
            $handler($subject, $payload->body);
        });
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
                ->setDeliverPolicy(DeliverPolicy::ALL)
                ->setAckPolicy(AckPolicy::EXPLICIT);
            $consumer->create();
        }
    }

    /**
     * Pull one batch of bus envelopes from every subject and hand each to the handler.
     * Invalid payloads are acked (skipped) by the caller returning normally.
     *
     * @param  callable(string, array<string, mixed>): void  $handler
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

    private function hostScopedConsumerName(string $prefix): string
    {
        $host = preg_replace('/[^A-Za-z0-9_-]+/', '-', gethostname() ?: 'host') ?? 'host';
        $host = trim($host, '-');

        if ($host === '') {
            $host = 'host';
        }

        return $prefix.'-'.$host;
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
