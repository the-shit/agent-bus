<?php

namespace App\Commands;

use App\Bus\NatsBus;
use App\Models\BusEvent;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class AgentBusMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent-bus:monitor {--once : Monitor one batch of backed-up envelopes and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Forward AGENT_BUS envelopes from JetStream to dashboard browsers';

    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus): int
    {
        if (! $bus->isReachable()) {
            $this->error("NATS is not reachable at {$bus->host()}:{$bus->port()}. Check NATS_URL and the broker.");

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
        $this->newLine();

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

                    $type = $envelope['type'] ?? 'unknown';
                    $agent = $envelope['agentType'] ?? 'unknown';
                    $repo = $envelope['repo'] ?? 'unknown/unknown';
                    $sessionId = $envelope['sessionId'] ?? 'unknown';
                    $payloadTool = $envelope['payload']['tool'] ?? null;

                    // Parse repo for cleaner display
                    $repoParts = explode('/', $repo);
                    $repoDisplay = count($repoParts) >= 2 ? end($repoParts) : $repo;

                    // Build the output line with colors
                    $typeLine = $type === 'toolCall' ? "\033[36mT\033[0m" : ($type === 'idle' ? "\033[33m.\033[0m" : ($type === 'sessionStart' ? "\033[32mS\033[0m" : "\033[0m?\033[0m"));
                    $line = "\033[36m[$typeLine]\033[0m \033[34m[$agent]\033[0m \033[33m[$repoDisplay]\033[0m";

                    if ($payloadTool) {
                        $line .= " \033[1m$payloadTool\033[0m";
                    }

                    $line .= " (\033[90m$sessionId\033[0m)";

                    $this->line($line);

                    // Persist to database
                    $attributes = [
                        'subject' => $subject,
                        'session_id' => $sessionId,
                        'agent_type' => $agent,
                        'model' => $envelope['model'] ?? null,
                        'repo' => $repo,
                        'type' => $type,
                        'payload' => $envelope['payload'] ?? [],
                        'occurred_at' => isset($envelope['timestamp']) ? Carbon::parse($envelope['timestamp']) : null,
                    ];

                    if ($seq !== null) {
                        BusEvent::updateOrCreate(['seq' => $seq], $attributes);
                    } else {
                        BusEvent::create($attributes);
                    }
                });
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                if ($this->option('once')) {
                    return self::FAILURE;
                }

                Sleep::sleep(1);

                continue;
            }

            $this->line("\033[90mBridged $count envelope(s) this pass.\033[0m");

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            $this->newLine();
        } while ($running);

        return self::SUCCESS;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
