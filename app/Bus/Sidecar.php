<?php

namespace App\Bus;

use RuntimeException;

class Sidecar
{
    public function __construct(
        private readonly NatsBus $bus,
        private readonly Herdr $herdr,
        private readonly SessionPaneMap $map,
    ) {}

    /**
     * @return array<string, string>
     */
    public function rebuildMap(): array
    {
        return $this->map->build(
            $this->herdr->listAgents(),
            $this->bus->listSessionIds(),
            fn (string $alternate): ?string => $this->bus->getAlias($alternate),
        );
    }

    /**
     * Inject inbox JSON into the mapped pane. Returns the pane id, or null when dropped.
     *
     * @param  array<string, string>  $panes
     */
    public function deliver(string $subject, string $body, array $panes): ?string
    {
        $sessionId = $this->map->sessionIdFromMessage($subject, $body);

        if ($sessionId === null) {
            return null;
        }

        $paneId = $panes[$sessionId] ?? null;

        if ($paneId === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $this->herdr->prompt($paneId, $body);

        return $paneId;
    }

    /**
     * Rebuild the live map, pull one inbox batch, and prompt matching panes.
     *
     * @return list<array{sessionId: string, pane_id: string}>
     */
    public function tick(): array
    {
        $panes = $this->rebuildMap();
        $delivered = [];

        $this->bus->consumeInbox(function (string $subject, string $body) use ($panes, &$delivered): void {
            $paneId = $this->deliver($subject, $body, $panes);

            if ($paneId === null) {
                throw new RuntimeException('Recipient unavailable or inbox envelope invalid.');
            }

            $sessionId = $this->map->sessionIdFromMessage($subject, $body) ?? '';

            $delivered[] = [
                'sessionId' => $sessionId,
                'pane_id' => $paneId,
            ];
        });

        return $delivered;
    }
}
