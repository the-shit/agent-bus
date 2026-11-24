<?php

namespace App\Bus;

use App\Models\BusEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

class BusHistory
{
    /**
     * @param  (callable(Throwable): void)|null  $onError
     */
    public function __construct(private $onError = null) {}

    public function enabled(): bool
    {
        return (bool) config('agent_bus.history_store.enabled', false);
    }

    public function connectionName(): string
    {
        $connection = config('agent_bus.history_store.connection', 'bus_history');

        return is_string($connection) && $connection !== '' ? $connection : 'bus_history';
    }

    /**
     * Persist one consumed envelope. Fail-open: never throw to the caller.
     * Idempotent on event_id and seq unique constraints.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function persist(string $subject, array $envelope, ?int $seq = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            BusEvent::on($this->connectionName())->create($this->attributes($subject, $envelope, $seq));
        } catch (UniqueConstraintViolationException) {
            // Duplicate event_id or seq: already stored.
        } catch (Throwable $exception) {
            error_log('bus_history persist failed: '.$exception->getMessage());

            if ($this->onError !== null) {
                ($this->onError)($exception);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function attributes(string $subject, array $envelope, ?int $seq = null): array
    {
        $eventId = $this->stringValue($envelope['event_id'] ?? $envelope['id'] ?? null);

        if ($eventId === null && $seq !== null) {
            $eventId = 'js:'.$this->streamName().':'.$seq;
        }

        $type = $this->stringValue($envelope['type'] ?? null) ?? 'unknown';
        $payload = $envelope['payload'] ?? [];

        if (! is_array($payload)) {
            $payload = ['value' => $payload];
        }

        $occurred = $this->stringValue($envelope['timestamp'] ?? $envelope['occurred_at'] ?? $envelope['occurredAt'] ?? null);

        return [
            'event_id' => $eventId,
            'correlation_id' => $this->stringValue($envelope['correlation_id'] ?? $envelope['correlationId'] ?? null),
            'causation_id' => $this->stringValue($envelope['causation_id'] ?? $envelope['causationId'] ?? null),
            'seq' => $seq,
            'subject' => $subject,
            'session_id' => $this->stringValue($envelope['sessionId'] ?? $envelope['session_id'] ?? null),
            'agent_type' => $this->stringValue($envelope['agentType'] ?? $envelope['agent_type'] ?? null),
            'model' => $this->stringValue($envelope['model'] ?? null),
            'repo' => $this->stringValue($envelope['repo'] ?? null),
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => $occurred,
            'received_at' => now(),
        ];
    }

    private function streamName(): string
    {
        $stream = config('agent_bus.stream', 'AGENT_BUS');

        return is_string($stream) && $stream !== '' ? $stream : 'AGENT_BUS';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
