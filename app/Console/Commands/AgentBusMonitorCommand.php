<?php

namespace App\Console\Commands;

use App\Bus\NatsBus;
use App\Events\BusEnvelopeBroadcast;
use App\Models\BusEvent;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

#[Signature('agent-bus:monitor {--once : Broadcast one batch of backed-up envelopes and exit}')]
#[Description('Forward AGENT_BUS envelopes from JetStream to dashboard browsers and mirror them to bus_events')]
class AgentBusMonitorCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus): int
    {
        if (! $bus->isReachable()) {
            $this->error("NATS is not listening on {$bus->host()}:{$bus->port()}. Start the broker with docker compose up -d.");

            return self::FAILURE;
        }

        try {
            $bus->provision();
            $bus->ensureMonitorConsumer();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Monitoring {$bus->streamName()}; Ctrl+C to stop.");

        $running = true;

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        do {
            try {
                $count = $bus->consumeMonitor(function (string $subject, array $envelope, ?int $seq): void {
                    if ($envelope === []) {
                        return;
                    }

                    broadcast(new BusEnvelopeBroadcast($envelope));

                    $this->mirrorToBusEvents($subject, $envelope, $seq);

                    $this->line('bridged '.($envelope['type'] ?? '?').' on '.$subject);
                });
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                Sleep::sleep(1);

                continue;
            }

            $this->line("Bridged {$count} envelope(s) this pass.");

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        } while ($running);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function mirrorToBusEvents(string $subject, array $envelope, ?int $seq): void
    {
        if ($seq === null) {
            return;
        }

        BusEvent::firstOrCreate([
            'seq' => $seq,
        ], $this->mirrorAttributes($subject, $envelope));
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function mirrorAttributes(string $subject, array $envelope): array
    {
        return [
            'subject' => $subject,
            'session_id' => (string) ($envelope['sessionId'] ?? ''),
            'agent_type' => array_key_exists('agentType', $envelope) ? (string) $envelope['agentType'] : null,
            'model' => array_key_exists('model', $envelope) ? (string) $envelope['model'] : null,
            'repo' => array_key_exists('repo', $envelope) ? (string) $envelope['repo'] : null,
            'type' => (string) ($envelope['type'] ?? ''),
            'payload' => $envelope['payload'] ?? [],
            'occurred_at' => $this->parseOccurredAt($envelope['timestamp'] ?? null),
            'received_at' => now(),
        ];
    }

    private function parseOccurredAt(mixed $timestamp): ?Carbon
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return new Carbon($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
