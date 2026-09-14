# SPEC: Agent Identity Normalization

_Status: proposed · 2026-09-13 · author: pi (with Jordan)_

## Problem

One agent, many names. The same live session can appear on the bus under
different ids depending on who reported it:

| Reporter   | Identity used                        | Example |
|------------|--------------------------------------|---------|
| Grok hooks | provider UUID                        | `01a09b75-b217-…` |
| pi (jordan-bus ext) | `PI_SESSION_ID` env           | `01a09e6a-…` |
| Herdr (pi panes) | jsonl file **path**            | `/home/jordan/.pi/agent/sessions/…jsonl` |
| Herdr (hive panes) | _none_ — no `agent_session`    | anonymous |
| Fallback (`Capture::sessionId`) | `{kind}-{sha1(cwd)[:8]}-{pid}` | changes every restart |

Consequences already observed in production:

- Codex (2026-09-13) could not find its own session in presence:
  "Herdr reports this Codex session is not itself registered."
- `emit` accepts events for sessions that never sent `sessionStart` —
  phantom emitters with no presence record.
- Herdr-observed identity and self-reported identity never merge, so
  `send` addressed to one misses the agent entirely.
- PID-based fallback ids are unstable: restart the agent, lose the thread
  of continuity.

## Design

### 1. Canonical id: `{kind}:{provider_id}`

One resolver, `App\Bus\BusIdentity::resolve(string $kind, array $event): string`.

Per-kind provider id, in priority order:

- **grok** — grok session UUID (hook payload `sessionId`)
- **pi** — UUID segment of the session jsonl filename
  (`…T05-36-19-422Z_01a09e6a-….jsonl` → `01a09e6a-…`), never the path
- **opencode** — `ses_*` id from hook payload
- **codex** — rollout UUID
- **herdr-only panes** — `herdr:{pane_id}` as last resort, flagged
  `identity_quality: "pane"` (honest about being a pane, not a session)

The PID fallback is removed. If no provider id exists, the resolver
returns `null` and the caller decides (reject or pane-grade id) —
identity is never silently invented.

### 2. Auto-register on first emit

Any `emit` for an unknown canonical id synthesizes a presence record
before publishing:

```json
{
  "sessionId": "grok:01a09b75-…",
  "agentType": "grok",
  "model": "grok-4.6",
  "host": "thor",
  "registered": "implicit",
  "firstSeen": "…", "lastSeen": "…"
}
```

`sessionStart` upgrades `registered` to `"explicit"`. Presence KV keeps
the 90s TTL; `implicit` records are discoverable but visibly marked.

### 3. Alias map

New KV bucket `session_aliases`: alternate id → canonical id.
Written when a reporter supplies a known alternate (jsonl path, pane id,
raw UUID). `sessions get` and `send` resolve through aliases first, so
Herdr's view and the agent's self-report converge on one inbox.

### 4. Envelope v2

Presence records require `agentType`; missing → rejected with a clear
error from `emit`. `model` and `host` filled when known. Envelope gains
top-level `"v": 2`; consumers treat missing `v` as v1 (current behavior)
during a 2-week overlap, then v1 emit is refused.

## Migration

- Presence KV is ephemeral (90s TTL) — nothing to backfill.
- Alias KV starts empty; populated going forward.
- No breaking change for stream consumers until the v1 cutoff date
  (announced in AGENTS.md when shipped).

## Done when

- [ ] `BusIdentity::resolve` with per-kind adapters + unit tests
      (grok UUID, pi filename, opencode ses_*, codex rollout, herdr pane)
- [ ] `emit` auto-registers implicit presence; integration test:
      `emit --type=toolCall` for a fresh id → `sessions get` returns
      `registered: implicit`
- [ ] `session_aliases` KV; `send` to a jsonl-path alias reaches the
      canonical session's inbox
- [ ] Envelope v2 validation on `emit`; v1 deprecation date written
      into AGENTS.md
- [ ] Repeat of the 2026-09-13 experiment: Codex finds **itself** in
      `list_sessions`

## Non-goals

- Cross-host identity (tailnet-wide agent registry) — separate spec.
- Retroactive identity for historical stream events.
