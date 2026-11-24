<?php

namespace App\Bus;

final class ToolFailurePolicy
{
    public function __construct(private readonly ?float $confidenceThreshold = null) {}

    /**
     * Turn a calibrated classification into advisory policy. Jev classifies;
     * code owns the workflow and never grants authority or executes the action.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $judgment
     * @return array{action: string, reason: string, automatic: false}
     */
    public function advise(array $state, array $judgment): array
    {
        $confidence = is_numeric($judgment['confidence'] ?? null) ? (float) $judgment['confidence'] : 0.0;
        $threshold = $this->confidenceThreshold ?? (float) config('agent_bus.judge.confidence_threshold', 0.75);

        if ($confidence < $threshold) {
            return $this->result('inspect_manually', 'Jev confidence is below the configured threshold.');
        }

        $failureType = $judgment['failureType']['value'] ?? 'unknown';

        return match ($failureType) {
            'model_misunderstanding' => $this->result('revise_call', 'Revise the tool, arguments, path, or syntax before trying again.'),
            'transient_external' => ($state['retrySafe'] ?? false) === true
                ? $this->result('retry_once', 'The failure appears transient and the caller marked one retry safe.')
                : $this->result('inspect_manually', 'The failure appears transient, but retry safety was not established.'),
            'permission_denied' => $this->result('request_authority', 'Stop and obtain the required approval, credential, or access.'),
            'missing_dependency' => $this->result('repair_dependency', 'Inspect or restore the missing dependency before trying again.'),
            'policy_blocked' => $this->result('stop', 'The attempted operation conflicts with an explicit policy.'),
            default => $this->result('inspect_manually', 'The available evidence does not support an automatic recovery.'),
        };
    }

    /** @return array{action: string, reason: string, automatic: false} */
    private function result(string $action, string $reason): array
    {
        return ['action' => $action, 'reason' => $reason, 'automatic' => false];
    }
}
