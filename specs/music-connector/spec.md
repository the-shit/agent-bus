# SPEC: music events board the bus through a connector

Status: LOCKED

**Story:** `the-shit/music` already appends JSON lines to `~/.config/spotify-cli/events.jsonl`. Agents on the bus cannot hear them. A dirty daily checkout tailed that file, then grew HTTP, Reverb, and Eloquent. This repo only boards envelopes. The music CLI stays in `the-shit/music`.

## Locked

- Cold-path Laravel Zero command `bin/agent-bus music`, discovered under `app/Commands/` like `sidecar` / `provision`. Do not add a second binary. Do not add `music` to `Cli::HOT_VERBS`. Do not copy `/home/jordan/Projects/the-shit/agent-bus/bin/spotify-bus-connector` (hardcoded `127.0.0.1:4222`, `Illuminate\Support\Str` snake subjects, no `"v": 2`, no `NATS_URL`, fail-closed on a missing file).
- Tail default `~/.config/spotify-cli/events.jsonl` (music CLI `ConfigHelper::eventsPath()`). `--file=` overrides for fixtures. `--once` reads from the offset to EOF and exits (Pest). Default loop is `tail -F` (wait if the file is missing; do not `exit 1`).
- `NATS_URL` via existing `NatsUrl` / `NatsServiceProvider`. Fail open: broker down → exit 0, one stderr line, no stack trace, no `Illuminate\` / `artisan` dump. `--once` returns immediately. The long-running loop logs that one line, does not advance the offset, sleeps, retries.
- Envelope v2 on subject `spotify.>`. Mapper is a pure static class (arrays in, `{subject, envelope}|null` out). Unit tests never boot the container. Feature/broker tests skip without 4222 (`brokerIsDown()`); `AGENT_BUS_REQUIRE_BROKER=1` still turns that skip into a failure.
- You own (build): `app/Commands/MusicCommand.php`, `app/Bus/MusicCapture.php` (name may vary; not a hot verb), `config/agent_bus.php` subjects, `NatsBus::provision()` subject update. Test owns `tests/` + fixtures.
- Do not: HTTP, Reverb, Eloquent, `bus_events`, `public/`, `bootstrap/web.php`, hot-path `emit`, `putSession` / presence for music, webhook-to-NATS `8222`, merge, deploy.

### JSONL (source of truth)

Each line is one object from `event:emit`:

```json
{
  "component": "spotify",
  "event": "spotify.track.changed",
  "data": {
    "type": "track_changed",
    "uri": "spotify:track:2ZvVKaRZzeEdmTrpTrhEvR",
    "track": "Ana's Song (Open Fire)",
    "artist": "Silverchair",
    "album": "Neon Ballroom",
    "is_playing": false,
    "timestamp": "2026-08-22T08:14:11+00:00"
  },
  "timestamp": "2026-08-22T08:14:11+00:00"
}
```

`event` is already `spotify.{name}` (`track.changed`, `track.played`, `playback.paused`, …). `data` is the track payload. Live file on 2026-09-19: 1237 lines, 501 `spotify.track.changed`. Offset is load-bearing — do not replay from byte 0.

### Mapper

`MusicCapture::board(array $line): ?array`

- Skip (return null): invalid / non-object lines, empty `event`, `event` containing whitespace / `*` / `>`.
- Subject = `event` if it already starts with `spotify.`; else prefix `spotify.` once. Do not `Str::snake`. `spotify.track.changed` stays three NATS tokens, covered by `spotify.>`.
- Envelope:

```json
{
  "v": 2,
  "sessionId": "",
  "agentType": "music",
  "model": "",
  "repo": "the-shit/music",
  "type": "track.changed",
  "timestamp": "2026-08-22T08:14:11Z",
  "payload": { "type": "track_changed", "uri": "spotify:track:…", "track": "…", "artist": "…", "album": "…", "is_playing": false, "timestamp": "2026-08-22T08:14:11+00:00" }
}
```

- `type` = `event` with one leading `spotify.` stripped.
- `payload` = `data` when it is an array, else `[]`.
- `timestamp` = JSONL timestamp normalized to `Y-m-d\TH:i:s\Z`; if unparseable, `gmdate` now.
- `sessionId` stays empty. Music is not a coding session. Do not invent an id. Do not write KV `sessions`.
- Board every well-formed line (setup/auth/devices included). Consumers filter `spotify.track.>` if they only want playback.
- Byte offset in `{jsonl}.offset`. Advance only after a successful publish. Advance (and skip) on poison JSON so a bad line cannot stall the tail.

### Stream

This clone's `config/agent_bus.php` and the live `AGENT_BUS` stream are `repo.>`, `session.>` only. The daily clone's uncommitted `spotify.>` is not master.

- Add `spotify.>` to `config/agent_bus.php` `subjects`.
- `provision()` must `setSubjects` to that list and `STREAM.UPDATE` when the stream already exists. Today's `setSubjects` only runs on create — a publish to `spotify.track.changed` will miss the stream until this is fixed.
- `music` calls `provision()` on start the way `sidecar` does.

## Done when

- [ ] `vendor/bin/pest --filter=music` — mapper: fixture line → subject `spotify.track.changed` + envelope `"v": 2` and the track payload. `--once --file=` against a live broker publishes one message (skip without 4222). Broker down: exit 0, one stderr line.
- [ ] Music JSONL (or fixture append), connector running, broker up: `nats sub 'spotify.>'` shows one envelope with `"v": 2` and the track payload.

## Out of scope

- Putting Spotify inside the hot path (`Cli::HOT_VERBS` / `emit`)
- Dashboard / Reverb / `BusEvent` / `public/` / `bootstrap/web.php`
- Changing the music CLI (`the-shit/music`)
- Dedicated `bin/spotify-bus-connector`
- Always-on homelab broker (#16, #21)
- Replacing GitHub issues as the inbox

## Open decisions

None. Verb is `music`. Subject is the JSONL `event` field.
