<?php

namespace App\Bus;

final class NatsUrl
{
    public const DEFAULT = 'nats://127.0.0.1:4222';

    /**
     * Parse nats://[user:pass@]host[:port]. Missing pieces fall back to loopback 4222.
     *
     * @return array{host: string, port: int, user: ?string, pass: ?string}
     */
    public static function parse(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            $url = self::DEFAULT;
        }

        if (! str_contains($url, '://')) {
            $url = 'nats://'.$url;
        }

        $parts = parse_url($url) ?: [];

        return [
            'host' => is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1',
            'port' => isset($parts['port']) ? (int) $parts['port'] : 4222,
            'user' => is_string($parts['user'] ?? null) ? $parts['user'] : null,
            'pass' => is_string($parts['pass'] ?? null) ? $parts['pass'] : null,
        ];
    }

    public static function fromEnvironment(): string
    {
        $url = getenv('NATS_URL');

        return is_string($url) && trim($url) !== '' ? $url : self::DEFAULT;
    }
}
