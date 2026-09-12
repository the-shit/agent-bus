<?php

namespace App\Commands;

use App\Bus\NatsBus;
use App\Bus\Sidecar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('sidecar {--once : Pull pending inbox messages once and exit}')]
#[Description('Map sessionId to pane_id and inject inbox JSON via herdr agent prompt')]
class SidecarCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus, Sidecar $sidecar): int
    {
        if (! $bus->isReachable()) {
            $this->error("NATS is not listening on {$bus->host()}:{$bus->port()}. Start the broker with docker compose up -d.");

            return self::FAILURE;
        }

        try {
            $bus->provision();
            $bus->ensureInboxConsumer();
            $panes = $sidecar->rebuildMap();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Mapped '.count($panes).' session(s) to panes.');

        $running = true;

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        do {
            try {
                $delivered = $sidecar->tick();
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                Sleep::sleep(1);

                continue;
            }

            foreach ($delivered as $row) {
                $this->line("prompted {$row['pane_id']} for session {$row['sessionId']}");
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        } while ($running);

        return self::SUCCESS;
    }
}
