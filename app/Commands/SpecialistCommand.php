<?php

namespace App\Commands;

use App\Bus\NatsBus;
use App\Bus\PiSpecialistWorker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('specialist {profile : Configured specialist profile} {--once : Pull one request and exit}')]
#[Description('Run a Pi model as a capability worker without Herdr')]
class SpecialistCommand extends Command
{
    public function handle(NatsBus $bus, PiSpecialistWorker $worker): int
    {
        $profile = $this->profile((string) $this->argument('profile'));

        if ($profile === null) {
            $this->error('Unknown specialist profile. Available: '.implode(', ', array_keys($this->profiles())));

            return self::FAILURE;
        }

        if (! $bus->isReachable()) {
            $this->error("NATS is not reachable at {$bus->host()}:{$bus->port()}. Check NATS_URL and the broker.");

            return self::FAILURE;
        }

        $workerId = $worker->workerId($profile['capability']);

        try {
            $bus->provision();
            $bus->ensureWorkConsumer($profile['capability'], $profile['consumer']);
            $this->heartbeat($bus, $workerId, $profile);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$profile['model']} is listening for {$profile['capability']} work as {$workerId}.");
        $running = true;

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        try {
            do {
                $this->heartbeat($bus, $workerId, $profile);

                try {
                    $count = $worker->tick($profile);
                } catch (Throwable $exception) {
                    $this->error($exception->getMessage());

                    if ($this->option('once')) {
                        return self::FAILURE;
                    }

                    continue;
                }

                if ($count > 0) {
                    $this->line("Completed {$count} {$profile['capability']} request(s).");
                }

                if ($this->option('once')) {
                    return self::SUCCESS;
                }
            } while ($running);
        } finally {
            try {
                $bus->deleteSession($workerId);
            } catch (Throwable) {
                // Presence also expires through the KV TTL.
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{capability: string, consumer: string, model: string, agent_file: string, cwd: string, tools: list<string>, timeout: int}>
     */
    private function profiles(): array
    {
        $profiles = config('agent_bus.specialist.profiles', []);

        return is_array($profiles) ? $profiles : [];
    }

    /**
     * @return array{capability: string, consumer: string, model: string, agent_file: string, cwd: string, tools: list<string>, timeout: int}|null
     */
    private function profile(string $name): ?array
    {
        $profile = $this->profiles()[$name] ?? null;

        if (! is_array($profile)) {
            return null;
        }

        $tools = array_values(array_filter(
            (array) ($profile['tools'] ?? []),
            fn (mixed $tool): bool => is_string($tool) && preg_match('/^[a-z][a-z0-9_-]*$/', $tool) === 1,
        ));

        $normalized = [
            'capability' => $profile['capability'] ?? null,
            'consumer' => $profile['consumer'] ?? null,
            'model' => $profile['model'] ?? null,
            'agent_file' => $profile['agent_file'] ?? null,
            'cwd' => $profile['cwd'] ?? null,
            'tools' => $tools,
            'timeout' => max(10, (int) ($profile['timeout'] ?? 180)),
        ];

        foreach (['capability', 'consumer', 'model', 'agent_file', 'cwd'] as $key) {
            if (! is_string($normalized[$key]) || $normalized[$key] === '') {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * @param  array{capability: string, consumer: string, model: string, agent_file: string, cwd: string, tools: list<string>, timeout: int}  $profile
     */
    private function heartbeat(NatsBus $bus, string $workerId, array $profile): void
    {
        $bus->putSession($workerId, json_encode([
            'sessionId' => $workerId,
            'agentType' => 'pi-worker',
            'model' => $profile['model'],
            'repo' => 'the-shit/agent-bus',
            'capabilities' => [$profile['capability']],
            'presence' => 'explicit',
            'lastSeen' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));
    }
}
