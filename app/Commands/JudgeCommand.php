<?php

namespace App\Commands;

use App\Bus\JudgeWorker;
use App\Bus\NatsBus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('judge {--once : Pull one batch of judgment requests and exit}')]
#[Description('Run the TypeSafe Jev worker for tool_failure_triage capability requests')]
class JudgeCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus, JudgeWorker $worker): int
    {
        if ((string) config('agent_bus.judge.api_key', '') === '') {
            $this->error('TYPESAFE_API_KEY is not configured.');

            return self::FAILURE;
        }

        if (! $bus->isReachable()) {
            $this->error("NATS is not reachable at {$bus->host()}:{$bus->port()}. Check NATS_URL and the broker.");

            return self::FAILURE;
        }

        try {
            $bus->provision();
            $bus->ensureWorkConsumer($this->capability(), $this->consumerName());
            $this->heartbeat($bus, $this->workerId());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Jev judge is listening for {$this->capability()} work.");
        $running = true;

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        try {
            do {
                $this->heartbeat($bus, $this->workerId());

                try {
                    $count = $worker->tick();
                } catch (Throwable $exception) {
                    $this->error($exception->getMessage());

                    if ($this->option('once')) {
                        return self::FAILURE;
                    }

                    continue;
                }

                if ($count > 0) {
                    $this->line("Judged {$count} request(s).");
                }

                if ($this->option('once')) {
                    return self::SUCCESS;
                }
            } while ($running);
        } finally {
            try {
                $bus->deleteSession($this->workerId());
            } catch (Throwable) {
                // Presence also expires through the KV TTL.
            }
        }

        return self::SUCCESS;
    }

    private function capability(): string
    {
        return (string) config('agent_bus.judge.capability', 'tool_failure_triage');
    }

    private function consumerName(): string
    {
        return (string) config('agent_bus.judge.consumer', 'agent-bus-judge');
    }

    private function workerId(): string
    {
        $host = preg_replace('/[^A-Za-z0-9_-]+/', '-', gethostname() ?: 'host') ?? 'host';

        return 'judge:'.$this->capability().':'.trim($host, '-');
    }

    private function heartbeat(NatsBus $bus, string $workerId): void
    {
        $bus->putSession($workerId, json_encode([
            'sessionId' => $workerId,
            'agentType' => 'judge',
            'model' => (string) config('agent_bus.judge.model', 'jev-latest'),
            'repo' => 'the-shit/agent-bus',
            'capabilities' => [$this->capability()],
            'presence' => 'explicit',
            'lastSeen' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));
    }
}
