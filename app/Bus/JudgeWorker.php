<?php

namespace App\Bus;

use Throwable;

class JudgeWorker
{
    public function __construct(
        private readonly NatsBus $bus,
        private readonly JudgeGateway $gateway,
        private readonly ToolFailurePolicy $policy,
    ) {}

    public function tick(): int
    {
        return $this->bus->consumeWork(
            $this->capability(),
            $this->consumerName(),
            function (string $subject, array $envelope): void {
                $correlationId = $this->correlationId($subject, $envelope);

                if ($correlationId === null) {
                    return;
                }

                if ($this->bus->completedWorkResultExists($correlationId)) {
                    return;
                }

                $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
                $state = is_array($payload['task'] ?? null) ? $payload['task'] : [];
                $startedAt = hrtime(true);

                try {
                    $judgment = $this->gateway->judgeToolFailure($state);
                    $advice = $this->policy->advise($state, $judgment);
                    $resultPayload = [
                        'requestId' => $correlationId,
                        'capability' => $this->capability(),
                        'status' => 'ok',
                        'judgment' => $judgment,
                        'advice' => $advice,
                        'latencyMs' => $this->elapsedMilliseconds($startedAt),
                    ];
                } catch (Throwable $exception) {
                    $resultPayload = [
                        'requestId' => $correlationId,
                        'capability' => $this->capability(),
                        'status' => 'error',
                        'error' => 'Jev judge could not classify this failure.',
                        'latencyMs' => $this->elapsedMilliseconds($startedAt),
                    ];
                }

                $judgment = is_array($resultPayload['judgment'] ?? null) ? $resultPayload['judgment'] : [];

                $this->bus->publishEnvelope('work.result.'.$correlationId, [
                    'v' => 2,
                    'sessionId' => 'judge:'.(gethostname() ?: 'host'),
                    'agentType' => 'judge',
                    'model' => is_string($judgment['model'] ?? null)
                        ? $judgment['model']
                        : (string) config('agent_bus.judge.model', 'jev-latest'),
                    'repo' => is_string($envelope['repo'] ?? null) ? $envelope['repo'] : 'unknown/unknown',
                    'type' => 'judgeResult',
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    'payload' => $resultPayload,
                ], 'work-result-'.$correlationId);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function correlationId(string $subject, array $envelope): ?string
    {
        $prefix = 'work.request.'.$this->capability().'.';
        $fromSubject = str_starts_with($subject, $prefix)
            ? substr($subject, strlen($prefix))
            : '';
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $fromPayload = is_string($payload['requestId'] ?? null) ? $payload['requestId'] : '';

        if ($fromSubject === '' || $fromPayload !== $fromSubject) {
            return null;
        }

        return preg_match('/^[^\s.*>]+$/', $fromSubject) === 1 ? $fromSubject : null;
    }

    private function capability(): string
    {
        return (string) config('agent_bus.judge.capability', 'tool_failure_triage');
    }

    private function consumerName(): string
    {
        return (string) config('agent_bus.judge.consumer', 'agent-bus-judge');
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'Judgment failed.';
    }
}
