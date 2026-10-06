<?php

namespace App\Bus;

use Illuminate\Support\Str;

final class Capture
{
    private const int PARAMETER_BUDGET = 4096;

    private const int PARAMETER_DEPTH = 4;

    private const int PARAMETER_ITEMS = 25;

    private const int PARAMETER_STRING_LENGTH = 1000;

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
     * @param  array<string, mixed>  $event
     */
    public static function sessionId(array $event, string $kind): string
    {
        foreach (self::sessionIdCandidates($event) as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $cwd = is_string($event['cwd'] ?? null) ? $event['cwd'] : (string) getcwd();

        return $kind.'-'.substr(sha1($cwd), 0, 8).'-'.getmypid();
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

        $callId = self::firstString([
            $event['callID'] ?? null,
            $event['callId'] ?? null,
            $event['call_id'] ?? null,
        ]);

        if ($callId !== null) {
            $payload['callId'] = $callId;
        }

        $rawParameters = $event['toolInput']
            ?? $event['tool_input']
            ?? $event['args']
            ?? $event['arguments']
            ?? null;

        if (is_array($rawParameters)) {
            $payload['params'] = self::sanitizeParameters(
                $rawParameters,
                is_string($tool) ? $tool : '',
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function eventId(array $event, string $sessionId, string $eventType): string
    {
        $nativeId = self::firstString([
            $event['eventId'] ?? null,
            $event['eventID'] ?? null,
            $event['event_id'] ?? null,
            $event['callID'] ?? null,
            $event['callId'] ?? null,
            $event['call_id'] ?? null,
        ]);

        return implode('.', [
            self::idPart($sessionId, 'unknown-session'),
            self::idPart($eventType, 'event'),
            self::idPart($nativeId ?? (string) Str::uuid7(), 'event'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function correlationId(array $event, string $sessionId): ?string
    {
        $correlationId = self::firstString([
            $event['correlationId'] ?? null,
            $event['correlationID'] ?? null,
            $event['correlation_id'] ?? null,
        ]);

        if ($correlationId !== null) {
            return self::idPart($sessionId, 'unknown-session').'.correlation.'.self::idPart($correlationId, 'unknown');
        }

        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $turnId = self::firstString([
            $event['turnId'] ?? null,
            $event['turnID'] ?? null,
            $event['turn_id'] ?? null,
            $event['messageId'] ?? null,
            $event['messageID'] ?? null,
            $event['message_id'] ?? null,
            $properties['turnId'] ?? null,
            $properties['turnID'] ?? null,
            $properties['messageId'] ?? null,
            $properties['messageID'] ?? null,
        ]);

        if ($turnId === null) {
            return null;
        }

        return self::idPart($sessionId, 'unknown-session').'.turn.'.self::idPart($turnId, 'unknown');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function causationId(array $event): ?string
    {
        return self::firstString([
            $event['causationId'] ?? null,
            $event['causationID'] ?? null,
            $event['causation_id'] ?? null,
            $event['parentEventId'] ?? null,
            $event['parent_event_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string|int, mixed>  $parameters
     * @return array<string|int, mixed>
     */
    private static function sanitizeParameters(array $parameters, string $tool): array
    {
        $budget = self::PARAMETER_BUDGET;
        $truncated = false;
        $sanitized = self::sanitizeArray($parameters, $tool, 0, $budget, $truncated);

        if ($truncated) {
            $sanitized['_truncated'] = true;
        }

        return $sanitized;
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private static function sanitizeArray(array $values, string $tool, int $depth, int &$budget, bool &$truncated): array
    {
        if ($depth >= self::PARAMETER_DEPTH || $budget <= 0) {
            $truncated = true;

            return [];
        }

        $sanitized = [];

        foreach ($values as $index => $value) {
            if (count($sanitized) >= self::PARAMETER_ITEMS || $budget <= 0) {
                $truncated = true;

                break;
            }

            $key = is_int($index) ? $index : (string) $index;
            $budget -= is_int($key) ? 1 : strlen($key);
            $sanitized[$key] = self::sanitizeValue($value, (string) $key, $tool, $depth + 1, $budget, $truncated);
        }

        return $sanitized;
    }

    private static function sanitizeValue(mixed $value, string $key, string $tool, int $depth, int &$budget, bool &$truncated): mixed
    {
        if (self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (self::isContentParameter($tool, $key) && is_string($value)) {
            return '[omitted '.strlen($value).' bytes]';
        }

        if (is_array($value)) {
            return self::sanitizeArray($value, $tool, $depth, $budget, $truncated);
        }

        if (is_string($value)) {
            $value = self::redactSecrets($value);
            $available = max(0, min(self::PARAMETER_STRING_LENGTH, $budget));

            if (strlen($value) > $available) {
                $truncated = true;
                $value = substr($value, 0, $available).'…';
            }

            $budget -= strlen($value);

            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $budget -= strlen(json_encode($value) ?: '');

            return $value;
        }

        $truncated = true;

        return '['.get_debug_type($value).']';
    }

    private static function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:authorization|cookie|password|passwd|secret|token|api[_-]?key|private[_-]?key)/i', $key) === 1;
    }

    private static function isContentParameter(string $tool, string $key): bool
    {
        return preg_match('/(?:write|edit|patch)/i', $tool) === 1
            && preg_match('/^(?:content|text|patch|old_string|new_string)$/i', $key) === 1;
    }

    private static function redactSecrets(string $value): string
    {
        $value = preg_replace('/(Bearer\s+)[^\s\'\"]+/i', '$1[redacted]', $value) ?? $value;

        return preg_replace(
            '/((?:api[_-]?key|token|password|secret)\s*[=:]\s*)[^\s\'\"]+/i',
            '$1[redacted]',
            $value,
        ) ?? $value;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function idPart(string $value, string $fallback): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value)) ?? '';
        $sanitized = trim($sanitized, '-');

        return $sanitized !== '' ? substr($sanitized, 0, 120) : $fallback;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<mixed>
     */
    private static function sessionIdCandidates(array $event): array
    {
        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $info = is_array($properties['info'] ?? null) ? $properties['info'] : (is_array($event['info'] ?? null) ? $event['info'] : []);
        $input = is_array($event['input'] ?? null) ? $event['input'] : [];

        return [
            $event['sessionId'] ?? null,
            $event['session_id'] ?? null,
            $event['sessionID'] ?? null,
            $properties['sessionID'] ?? null,
            $properties['sessionId'] ?? null,
            $info['id'] ?? null,
            $input['sessionID'] ?? null,
            $input['sessionId'] ?? null,
        ];
    }
}
