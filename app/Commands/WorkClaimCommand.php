<?php

namespace App\Commands;

use App\Bus\NatsBus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('work-claim {--capability=} {--consumer=} {--session=} {--agent-type=} {--wait=8}')]
#[Description('Claim one capability request on the shared durable consumer and publish its result')]
class WorkClaimCommand extends Command
{
    private const LIVE_CAPABILITIES = ['code_review', 'tool_failure_triage'];

    public function handle(NatsBus $bus): int
    {
        $capability = strtolower(trim((string) $this->option('capability')));
        $sessionId = trim((string) $this->option('session'));
        $agentType = trim((string) $this->option('agent-type'));
        $wait = max(0.0, (float) $this->option('wait'));

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $capability) !== 1) {
            $this->error('Missing or invalid --capability; use one lowercase NATS token.');

            return self::FAILURE;
        }

        if (in_array($capability, self::LIVE_CAPABILITIES, true)) {
            $this->error("refusing live capability {$capability}");

            return self::FAILURE;
        }

        if ($agentType === '') {
            $this->error('Missing --agent-type.');

            return self::FAILURE;
        }

        if (preg_match('/^[^\s.*>]+$/', $sessionId) !== 1) {
            $this->error('session id is not a single NATS token.');

            return self::FAILURE;
        }

        $consumerName = trim((string) $this->option('consumer'));

        if ($consumerName === '') {
            $consumerName = 'agent-bus-claim-'.$capability;
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $consumerName) !== 1) {
            $this->error('Work consumer name contains unsupported characters.');

            return self::FAILURE;
        }

        if (! $bus->isReachable()) {
            $this->error("NATS is not reachable at {$bus->host()}:{$bus->port()}. Check NATS_URL and the broker.");

            return self::FAILURE;
        }

        config(['agent_bus.worker.batch' => 1]);

        try {
            $bus->ensureWorkConsumer($capability, $consumerName);
        } catch (Throwable $exception) {
            $this->error($this->oneLine($exception->getMessage()));

            return self::FAILURE;
        }

        $claimed = null;
        $deadline = microtime(true) + $wait;

        try {
            do {
                $bus->consumeWork(
                    $capability,
                    $consumerName,
                    function (string $subject, array $envelope) use ($bus, $capability, $sessionId, $agentType, &$claimed): void {
                        if ($claimed !== null) {
                            return;
                        }

                        $requestId = $this->requestId($capability, $subject, $envelope);

                        if ($requestId === null || $bus->completedWorkResultExists($requestId)) {
                            return;
                        }

                        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
                        $task = is_array($payload['task'] ?? null) ? $payload['task'] : [];
                        $claimed = [
                            'v' => 2,
                            'sessionId' => $sessionId,
                            'agentType' => $agentType,
                            'model' => '',
                            'repo' => 'the-shit/agent-bus',
                            'type' => 'workResult',
                            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                            'payload' => [
                                'requestId' => $requestId,
                                'capability' => $capability,
                                'status' => 'ok',
                                'result' => [
                                    'worker' => $sessionId,
                                    'task' => $task,
                                ],
                            ],
                        ];
                        $bus->publishEnvelope('work.result.'.$requestId, $claimed, 'work-result-'.$requestId);
                    },
                );
            } while ($claimed === null && $wait > 0 && microtime(true) < $deadline);
        } catch (Throwable $exception) {
            $this->error($this->oneLine($exception->getMessage()));

            return self::FAILURE;
        }

        if ($claimed === null) {
            $this->error('no work request claimed');

            return 2;
        }

        $this->output->write(json_encode($claimed, JSON_THROW_ON_ERROR)."\n");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function requestId(string $capability, string $subject, array $envelope): ?string
    {
        $prefix = 'work.request.'.$capability.'.';
        $fromSubject = str_starts_with($subject, $prefix) ? substr($subject, strlen($prefix)) : '';
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $fromPayload = is_string($payload['requestId'] ?? null) ? $payload['requestId'] : '';

        if ($fromSubject === '' || $fromPayload !== $fromSubject) {
            return null;
        }

        return preg_match('/^[^\s.*>]+$/', $fromSubject) === 1 ? $fromSubject : null;
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'Work claim failed.';
    }
}
