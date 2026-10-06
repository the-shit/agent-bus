# SPEC: music events board the bus through a connector

Status: DRAFT

**Story:** Playback already hops hosts (start on one machine, last player elsewhere). Agents on the bus cannot hear it. A Herdr "Ecosystem Connector" pane started tailing `events.jsonl` into NATS, then grew HTTP, Reverb, and Eloquent. The bus does not swallow the music app. A connector publishes envelopes. The music CLI stays in `the-shit/music`.

## Locked

- Cold-path process. Tails `~/.config/spotify-cli/events.jsonl` (or the music CLI's documented JSONL). Publishes envelope v2 onto `spotify.>` (already in `config/agent_bus.php`).
- `NATS_URL` like every other client. Fail open if the broker is down (exit 0, one stderr line).
- You own: `bin/` connector (or a cold `bin/agent-bus` verb), `tests/` mapper + broker skip, maybe `config/agent_bus.php` subjects only if `spotify.>` is missing.
- Do not: HTTP, Reverb, Eloquent, `bus_events`, `public/`, `bootstrap/web.php`, hot-path `emit`. Do not merge. Do not deploy.

## Done when

- [ ] Play (or fixture-append) one track line; `nats sub 'spotify.>'` (or Pest against a live broker) sees one envelope with `"v": 2` and the track payload
- [ ] `vendor/bin/pest --filter=music` (or `--filter=spotify`) covers the mapper; broker tests skip without 4222

## Out of scope

- Putting Spotify inside `app/Bus` hot path
- Dashboard / Reverb / database
- Replacing GitHub issues as the inbox
- Always-on broker on the homelab (#16)

## Open decisions

- Verb name: dedicated `bin/…-connector` vs `bin/agent-bus` cold verb
