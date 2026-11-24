<?php

namespace App\Bus;

/**
 * One agent, many names. This resolver maps whatever a reporter supplied
 * (provider UUID, jsonl path, ses_* id) onto the canonical bus id
 * `{kind}:{provider_id}`. It never invents identity: no provider id in the
 * event means null, and the caller decides what to do with that.
 */
final class BusIdentity
{
    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const KNOWN_KINDS = ['grok', 'pi', 'opencode', 'codex'];

    /**
     * Canonical id for the reporter's event, or null when the event carries
     * no usable provider id.
     *
     * @param  array<string, mixed>  $event
     */
    public static function resolve(string $kind, array $event): ?string
    {
        $kind = strtolower(trim($kind));

        foreach (Capture::sessionIdCandidates($event) as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $canonical = self::fromCandidate($kind, trim($candidate));

            if ($canonical !== null) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Alternate ids worth pointing at the canonical id: the raw values
     * reporters supplied (jsonl path, bare UUID, ses_* id).
     *
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    public static function aliases(string $canonicalId, array $event): array
    {
        $aliases = [];

        foreach (Capture::sessionIdCandidates($event) as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && trim($candidate) !== $canonicalId) {
                $aliases[trim($candidate)] = true;
            }
        }

        return array_keys($aliases);
    }

    /**
     * Every canonical id names a provider session. The pane-grade
     * `herdr:{pane_id}` fallback is gone, so presence records keep reporting
     * `identity_quality: session` for readers that still look at the field.
     */
    public static function quality(string $canonicalId): string
    {
        return 'session';
    }

    public static function isCanonical(string $id): bool
    {
        // The id part may itself hold colons.
        return preg_match('/^([a-z][a-z0-9]*):\S+$/', $id) === 1
            && in_array(strtolower(substr($id, 0, strpos($id, ':'))), self::KNOWN_KINDS, true);
    }

    private static function fromCandidate(string $kind, string $candidate): ?string
    {
        if (self::isCanonical($candidate)) {
            return $candidate;
        }

        $uuid = self::uuidFrom($candidate);

        return match ($kind) {
            'grok', 'codex', 'pi' => $uuid !== null ? $kind.':'.$uuid : null,
            'opencode' => str_starts_with($candidate, 'ses_') ? 'opencode:'.$candidate : null,
            default => null,
        };
    }

    /**
     * Bare UUID or the UUID segment of a session jsonl filename/path —
     * never the path itself.
     */
    private static function uuidFrom(string $candidate): ?string
    {
        if (preg_match('/'.self::UUID_PATTERN.'/', $candidate, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[0]);
    }
}
