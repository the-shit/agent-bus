# SpotifyEvent Integration

This doc describes how `the-shit/music` playback events flow into the agent-bus NATS mesh.

## Architecture

```
Spotify Player
    ↓ (polls every 10s)
music CLI `spotify watch --json`
    ↓ (JSON Lines to ~/.config/spotify-cli/events.jsonl)
    ↓ (webhook POST to NATS HTTP gateway)
NATS JetStream subject: `spotify.>`
    ↓
agent-bus monitor consumer
    ↓
BusEnvelopeBroadcast event
```

## Setup

### 1. Configure music CLI webhook

```bash
# In the-shit/music repo
spotify webhook:configure \
  --url=http://127.0.0.1:8222/jsonz?subject=spotify.events \
  --secret=your-nats-secret
```

The NATS HTTP gateway expects:
- URL: `http://127.0.0.1:8222/jsonz?subject=<subject>`
- Body: raw JSON message
- Header: `Nats-Subject: <subject>` (optional, subject in query wins)

### 2. Agent-bus consumes from `spotify.>`

Subject pattern `spotify.>` in `agent_bus.php` should already cover this:

```php
'subjects' => [
    'repo.>',
    'session.>',
    'spotify.>',  // ← add this
],
```

### 3. Monitor command forwards to Reverb

The `AgentBusMonitorCommand` currently writes to DB. For real-time web clients, hook up Reverb:

```php
// In AgentBusMonitorCommand::handle()
broadcast(new BusEnvelopeBroadcast($envelope))->toOthers();
```

## Event format

Each music CLI event becomes a NATS envelope:

```json
{
  "type": "track.changed",
  "uri": "spotify:track:4uOHr23fG1fClqCw6eZJqy",
  "track": "Lofi Beats",
  "artist": "Chill Vendor",
  "album": "Study Vibes",
  "is_playing": true,
  "timestamp": "2026-09-18T20:30:00.000000Z"
}
```

Wrapped in bus envelope:

```json
{
  "type": "music.spotify",
  "payload": { ...music event... },
  "sessionId": "agent-bus",
  "agentType": "agent-bus",
  "Timestamp": "2026-09-18T20:30:00.000000Z"
}
```

## Using in agents

Agents can consume via `agent-bus:monitor` or directly via NATS:

```php
$bus->consumeMonitor(function (string $subject, array $envelope, ?int $seq): void {
    if (str_starts_with($subject, 'spotify.')) {
        // Handle music event
    }
});
```

## Testing

```bash
# Start NATS + agent-bus
cd /home/jordan/Projects/the-shit/agent-bus
docker compose up -d
bin/agent-bus provision

# In music repo, watch and emit
cd /home/jordan/Projects/the-shit/music
php spotify watch --json --interval=5 | tee /tmp/spotify-events.jsonl

# In agent-bus, monitor
bin/agent-bus agent-bus:monitor
```

## Alternative: Direct file consumer

If webhook forwarding fails, create a sidecar that:

1. Tail `~/.config/spotify-cli/events.jsonl`
2. Envelope each event with session metadata
3. Publish to `spotify.>` subject

This avoids webhook config but adds a file-polling sidecar.
