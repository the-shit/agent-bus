<?php

namespace App\Bus;

class SessionPaneMap
{
    /**
     * @param  list<array<string, mixed>>  $agents
     * @param  list<string>  $kvSessionIds
     * @return array<string, string>
     */
    public function build(array $agents, array $kvSessionIds): array
    {
        $onTheBus = array_fill_keys($kvSessionIds, true);
        $map = [];

        foreach ($agents as $agent) {
            $sessionId = $this->sessionIdFromAgent($agent);
            $paneId = $agent['pane_id'] ?? null;

            if ($sessionId === null || ! is_string($paneId) || $paneId === '') {
                continue;
            }

            if (! isset($onTheBus[$sessionId])) {
                continue;
            }

            $map[$sessionId] = $paneId;
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
