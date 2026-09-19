<?php

namespace App\Commands;

use App\Bus\NatsBus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('monitor {--once : Print one batch of envelopes and exit}')]
#[Description('Print AGENT_BUS envelopes from JetStream to stdout')]
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
                $count = $bus->consumeMonitor(function (string $subject, array $envelope): void {
                    if ($envelope === []) {
                        return;
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
