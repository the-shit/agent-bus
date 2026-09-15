<?php

namespace App\Bus;

final class Capture
{
    /**
     * @param  array<string, mixed>  $event
     */
    public static function eventName(array $event): string
    {
        $raw = $event['hook_event_name'] ?? $event['hookEventName'] ?? $event['type'] ?? '';

        if (! is_string($raw) || $raw === '') {
            return '';
        }

        return strtolower(str_replace(['-', '_', '.'], '', $raw));
    }

    /**
     * Raw session id candidates a reporter may have supplied, in priority
     * order. Normalization into a canonical id lives in BusIdentity; the old
     * PID-based fallback is gone because identity is never silently invented.
     *
     * @param  array<string, mixed>  $event
     * @return list<mixed>
     */
    public static function sessionIdCandidates(array $event): array
    {
        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $info = is_array($properties['info'] ?? null) ? $properties['info'] : (is_array($event['info'] ?? null) ? $event['info'] : []);
        $input = is_array($event['input'] ?? null) ? $event['input'] : [];

        return [
            $event['sessionId'] ?? null,
            $event['session_id'] ?? null,
            $event['sessionID'] ?? null,
            $event['rolloutId'] ?? null,
            $event['rollout_id'] ?? null,
            $properties['sessionID'] ?? null,
            $properties['sessionId'] ?? null,
            $info['id'] ?? null,
            $input['sessionID'] ?? null,
            $input['sessionId'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function model(array $event): string
    {
        $model = $event['model'] ?? '';

        return is_string($model) ? $model : '';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function isSubagent(array $event): bool
    {
        $type = $event['subagentType'] ?? $event['subagent_type'] ?? null;

        return is_string($type) && $type !== '';
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function toolPayload(array $event): array
    {
        $tool = $event['toolName'] ?? $event['tool_name'] ?? $event['tool'] ?? $event['input']['tool'] ?? '';
        $cwd = $event['cwd'] ?? $event['directory'] ?? $event['properties']['info']['directory'] ?? '';

        $payload = [];

        if (is_string($tool) && $tool !== '') {
            $payload['tool'] = $tool;
        }

        if (is_string($cwd) && $cwd !== '') {
            $payload['cwd'] = $cwd;
        }

        return $payload;
    }
}
