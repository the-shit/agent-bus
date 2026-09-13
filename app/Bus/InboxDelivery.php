<?php

namespace App\Bus;

use Basis\Nats\Message\Msg;
use RuntimeException;
use Throwable;

class InboxDelivery
{
    public function __construct(private readonly NatsBus $bus) {}

    /** @param callable(string, string): void $handler */
    public function handle(Msg $message, callable $handler): void
    {
        $tokens = explode('.', $message->replyTo ?? '');
        // JetStream ACK subjects have either the legacy or domain/account form.
        $offset = count($tokens) === 9 ? 4 : 6;
        if (! str_starts_with($message->replyTo ?? '', '$JS.ACK.')
            || ! isset($tokens[$offset + 1])
            || ! ctype_digit($tokens[$offset]) || ! ctype_digit($tokens[$offset + 1])) {
            throw new RuntimeException('Missing JetStream delivery metadata; refusing to acknowledge.');
        }
        $attempt = (int) $tokens[$offset];
        $sequence = (int) $tokens[$offset + 1];

        try {
            $handler($message->subject, $message->payload->body);
        } catch (Throwable $exception) {
            if ($attempt < max(1, (int) config('agent_bus.sidecar.max_attempts', 5))) {
                $delay = max(0.01, (float) config('agent_bus.sidecar.retry_seconds', 5));
                $message->nack(min(60, $delay * (2 ** min(10, max(0, $attempt - 1)))));

                return;
            }

            // JetStream confirms storage before the original message is acknowledged.
            // A publication failure leaves the original pending for another attempt.
            $this->bus->publishEnvelope('repo.agent-bus.delivery.failed', [
                'type' => 'delivery_failed',
                'message_id' => $this->bus->streamName().':'.$sequence,
                'consumer' => $this->bus->sidecarConsumerName(),
                'subject' => $message->subject,
                'attempts' => $attempt,
                'error_class' => $exception::class,
                'body' => $message->payload->body,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        $message->ack();
    }
}
