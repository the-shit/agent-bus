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
#[Description('Forward AGENT_BUS envelopes from JetStream to dashboard browsers')]
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

                    $attributes = [
                        'subject' => $subject,
                        'session_id' => $envelope['sessionId'] ?? null,
                        'agent_type' => $envelope['agentType'] ?? null,
                        'model' => $envelope['model'] ?? null,
                        'repo' => $envelope['repo'] ?? null,
                        'type' => $envelope['type'] ?? 'unknown',
                        'payload' => $envelope['payload'] ?? [],
                        'occurred_at' => isset($envelope['timestamp']) ? Carbon::parse($envelope['timestamp']) : null,
                    ];

                    if ($seq !== null) {
                        BusEvent::updateOrCreate(['seq' => $seq], $attributes);
                    } else {
                        BusEvent::create($attributes);
                    }

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
}