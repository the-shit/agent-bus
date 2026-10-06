<?php

namespace App\Bus;

final class ToolFailureSignals
{
    private const ALLOWED_SIGNALS = [
        'authentication',
        'invalid_argument',
        'missing_dependency',
        'network',
        'not_found',
        'parse_or_syntax',
        'permission_denied',
        'policy_blocked',
        'rate_limited',
        'timeout',
    ];

    /**
     * Project arbitrary local failure data onto the only schema permitted to
     * leave the worker for TypeSafe. Raw arguments and errors are discarded.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function from(array $state): array
    {
        $error = is_string($state['error'] ?? null) ? $state['error'] : '';
        $args = $state['args'] ?? null;
        $providedSignals = is_array($state['errorSignals'] ?? null) ? $state['errorSignals'] : [];
        $signals = self::normalizeSignals($providedSignals);

        if ($signals === []) {
            $signals = self::detectSignals($error);
        }

        $argumentCount = is_numeric($state['argumentCount'] ?? null)
            ? (int) $state['argumentCount']
            : (is_array($args) ? count($args) : 0);

        return [
            'schema' => 'tool_failure_signals.v1',
            'tool' => self::tool($state['tool'] ?? null),
            'argumentShape' => self::argumentShape($state['argumentShape'] ?? null, $args),
            'argumentCount' => max(0, min(100, $argumentCount)),
            'errorSignals' => $signals,
            'errorLengthBucket' => self::lengthBucket($state['errorLengthBucket'] ?? null, $error),
            'hasStackTrace' => is_bool($state['hasStackTrace'] ?? null)
                ? $state['hasStackTrace']
                : self::hasStackTrace($error),
            'retrySafe' => ($state['retrySafe'] ?? false) === true,
        ];
    }

    private static function tool(mixed $tool): string
    {
        if (! is_string($tool)) {
            return 'unknown';
        }

        $normalized = strtolower(substr($tool, 0, 80));

        return preg_match('/^[a-z0-9_.:-]+$/', $normalized) === 1 ? $normalized : 'unknown';
    }

    private static function argumentShape(mixed $provided, mixed $args): string
    {
        if (is_string($provided) && in_array($provided, ['list', 'object', 'scalar', 'none'], true)) {
            return $provided;
        }

        if ($args === null) {
            return 'none';
        }

        if (! is_array($args)) {
            return 'scalar';
        }

        return array_is_list($args) ? 'list' : 'object';
    }

    /** @param array<mixed> $signals @return list<string> */
    private static function normalizeSignals(array $signals): array
    {
        $normalized = [];

        foreach ($signals as $signal) {
            if (is_string($signal) && in_array($signal, self::ALLOWED_SIGNALS, true)) {
                $normalized[$signal] = true;
            }
        }

        return array_keys($normalized);
    }

    /** @return list<string> */
    private static function detectSignals(string $error): array
    {
        $patterns = [
            'authentication' => '/\b(?:unauthenticated|invalid api key|login required)\b/i',
            'invalid_argument' => '/\b(?:invalid argument|unknown option|missing (?:required )?(?:argument|parameter))\b/i',
            'missing_dependency' => '/\b(?:command not found|module not found|class not found|missing dependency)\b/i',
            'network' => '/\b(?:dns|connection refused|connection reset|network unreachable|could not resolve)\b/i',
            'not_found' => '/\b(?:not found|no such file|does not exist)\b/i',
            'parse_or_syntax' => '/\b(?:parse error|syntax error|unexpected token)\b/i',
            'permission_denied' => '/\b(?:permission denied|forbidden|not authorized|access denied)\b/i',
            'policy_blocked' => '/\b(?:policy blocked|blocked by policy|approval required|sandbox)\b/i',
            'rate_limited' => '/\b(?:rate limit|too many requests|http 429)\b/i',
            'timeout' => '/\b(?:timed? out|deadline exceeded|timeout)\b/i',
        ];
        $signals = [];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $error) === 1) {
                $signals[] = $name;
            }
        }

        return $signals;
    }

    private static function lengthBucket(mixed $provided, string $error): string
    {
        if (is_string($provided) && in_array($provided, ['empty', 'short', 'medium', 'long'], true)) {
            return $provided;
        }

        $length = mb_strlen($error);

        return match (true) {
            $length === 0 => 'empty',
            $length <= 200 => 'short',
            $length <= 2_000 => 'medium',
            default => 'long',
        };
    }

    private static function hasStackTrace(string $error): bool
    {
        return preg_match('/(?:#\d+\s+|stack trace|\bat\s+[^\s]+\([^)]*:\d+)/i', $error) === 1;
    }
}
