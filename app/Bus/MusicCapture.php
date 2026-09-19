<?php

namespace App\Bus;

final class MusicCapture
{
    public const AGENT_TYPE = 'music';

    public const REPO = 'the-shit/music';

    /**
     * Map one music JSONL object to a JetStream subject and envelope v2.
     * Null when the line is not a publishable event.
     *
     * @param  array<string, mixed>  $event
     * @return array{subject: string, envelope: array<string, mixed>}|null
     */
    public static function board(array $event): ?array
    {
        $eventName = $event['event'] ?? null;

        if (! is_string($eventName) || $eventName === '') {
            return null;
        }

        if (preg_match('/[\s*>]/', $eventName) === 1) {
            return null;
        }

        $subject = str_starts_with($eventName, 'spotify.')
            ? $eventName
            : 'spotify.'.$eventName;

        $type = str_starts_with($eventName, 'spotify.')
            ? substr($eventName, strlen('spotify.'))
            : $eventName;

        if ($type === '' || $subject === 'spotify.') {
            return null;
        }

        $data = $event['data'] ?? [];
        $payload = is_array($data) ? $data : [];

        return [
            'subject' => $subject,
            'envelope' => [
                'v' => 2,
                'sessionId' => '',
                'agentType' => self::AGENT_TYPE,
                'model' => '',
                'repo' => self::REPO,
                'type' => $type,
                'timestamp' => self::timestamp($event),
                'payload' => $payload,
            ],
        ];
    }

    /**
     * Decode one JSONL line, then board it. Null for blank or poison JSON.
     *
     * @return array{subject: string, envelope: array<string, mixed>}|null
     */
    public static function fromLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $event = json_decode($line, true);

        if (! is_array($event)) {
            return null;
        }

        return self::board($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function timestamp(array $event): string
    {
        $raw = $event['timestamp'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        $parsed = strtotime($raw);

        if ($parsed === false) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $parsed);
    }
}
