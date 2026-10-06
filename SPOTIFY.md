# Spotify Event Integration

This directory contains tools to integrate `the-shit/music` playback events into the agent-bus NATS mesh.

## Files

- **`spotify-event-bridge.php`** - Tail `events.jsonl` and publish to NATS
- **`docs/spotify-integration.md`** - Architecture and setup docs
- **`bin/spotify-bridge`** - Helper script to run the bridge

## Quick Start

```bash
# 1. Start agent-bus NATS
cd /home/jordan/Projects/the-shit/agent-bus
composer install  # if not done yet

# 2. Configure music CLI to emit events
cd /home/jordan/Projects/the-shit/music
php spotify watch --json > ~/.config/spotify-cli/events.jsonl 2>&1 &

# 3. Start the bridge
cd /home/jordan/Projects/the-shit/agent-bus
php spotify-event-bridge.php

# 4. In another terminal, monitor events
php artisan agent-bus:monitor
```

## Event Flow

```
music CLI (spotify watch)
    ↓ JSON Lines
~/.config/spotify-cli/events.jsonl
    ↓ tail + publish
spotify-event-bridge.php
    ↓ NATS JetStream
spotify.events subject
    ↓ agent-bus:monitor
bus events table + broadcast
```

## NATS Subjects

The bridge publishes to `spotify.events`. The agent-bus config already streams `spotify.>`:

```php
'subjects' => [
    'repo.>',
    'session.>',
    'spotify.>',  // ← covers spotify.events
],
```

## Using Events in Agents

```php
// In an agent-bus command or sidecar
$bus->consumeMonitor(function (string $subject, array $envelope, ?int $seq): void {
    if ($subject === 'spotify.events') {
        $payload = $envelope['payload'] ?? [];
        $type = $payload['type'] ?? '';

        if ($type === 'track.changed') {
            // Handle new track
            $track = $payload['track'] ?? 'Unknown';
            $artist = $payload['artist'] ?? 'Unknown';
            // ... do something
        }
    }
});
```

## Webhook Alternative (if you prefer)

Instead of the file bridge, configure music CLI to POST to NATS:

```bash
# In the-shit/music repo
# Get NATS JetStream publish endpoint
curl -s http://127.0.0.1:8222/jsz | jq '.api endpoints[0]'

# Configure webhook (requires custom gateway that accepts POST to /js/stream/AGENT_BUS/messages)
php spotify webhook:configure \
  --url=http://127.0.0.1:8222/js/stream/AGENT_BUS/messages \
  --secret=nats-secret
```

Note: NATS doesn't have a built-in HTTP POST publish endpoint. The file-based bridge is more reliable.
