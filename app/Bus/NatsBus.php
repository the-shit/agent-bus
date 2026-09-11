<?php

namespace App\Bus;

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

        $bucket = $this->bucket();
        $bucket->getConfiguration()
            ->setHistory($history)
            ->setTtl($ttlSeconds * 1_000_000_000);
        $bucket->getStream();
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

    public function getSession(string $id): ?string
    {
        $value = $this->bucket()->get($id);

        return is_string($value) ? $value : null;
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
