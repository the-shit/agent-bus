<?php

namespace App\Bus;

class SessionPaneMap
{
    /**
     * Herdr-reported values (provider UUID, jsonl path, claim id, or nothing)
     * converge onto the canonical id via BusIdentity before they touch the
     * KV list — verbatim comparison went dead when #31/#32 made KV keys
     * canonical `{kind}:{id}`. An agent with no session value falls back to
     * pane-grade `herdr:{pane_id}`. Values that still miss KV get one alias
     * lookup before being dropped.
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  list<string>  $kvSessionIds
     * @param  null|callable(string): ?string  $aliasResolver
     * @return array<string, string>
     */
    public function build(array $agents, array $kvSessionIds, ?callable $aliasResolver = null): array
    {
        $onTheBus = array_fill_keys($kvSessionIds, true);
        $map = [];

        foreach ($agents as $agent) {
            $raw = $this->sessionIdFromAgent($agent);
            $paneId = is_string($agent['pane_id'] ?? null) && $agent['pane_id'] !== ''
                ? $agent['pane_id']
                : null;

            $event = $raw !== null ? ['sessionId' => $raw] : [];

            if ($paneId !== null) {
                $event['pane_id'] = $paneId;
            }

            // No session value: pane-grade identity. With a session value:
            // the kind herdr declared (pi/grok/ses_* shapes) applies.
            $kind = $raw !== null ? $this->kindFromAgent($agent) : 'herdr';

            $resolved = BusIdentity::resolve($kind, $event) ?? $raw;

            if ($resolved === null || $paneId === null) {
                continue;
            }

            if (! isset($onTheBus[$resolved]) && $aliasResolver !== null && $raw !== null) {
                $alias = $aliasResolver($raw);

                if (is_string($alias) && $alias !== '' && isset($onTheBus[$alias])) {
                    $resolved = $alias;
                }
            }

            if (! isset($onTheBus[$resolved])) {
                continue;
            }

            $map[$resolved] = $paneId;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agentsFromListJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $agents = $decoded['result']['agents'] ?? $decoded['agents'] ?? [];

        if (! is_array($agents)) {
            return [];
        }

        return array_values(array_filter($agents, 'is_array'));
    }

    public function sessionIdFromSubject(string $subject): ?string
    {
        if (preg_match('/^session\.(.+)\.inbox$/', $subject, $matches) !== 1) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : null;
    }

    public function sessionIdFromMessage(string $subject, string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && is_string($decoded['sessionId'] ?? null) && $decoded['sessionId'] !== '') {
            return $decoded['sessionId'];
        }

        return $this->sessionIdFromSubject($subject);
    }

    /**
     * @param  array<string, mixed>  $agent
     */
    private function kindFromAgent(array $agent): string
    {
        $session = is_array($agent['agent_session'] ?? null) ? $agent['agent_session'] : [];
        $kind = $session['agent'] ?? $agent['agent'] ?? null;

        return is_string($kind) && $kind !== '' ? strtolower($kind) : 'herdr';
    }

    /**
     * @param  array<string, mixed>  $agent
     */
    private function sessionIdFromAgent(array $agent): ?string
    {
        $session = $agent['agent_session'] ?? null;

        if (! is_array($session)) {
            return null;
        }

        $value = $session['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
