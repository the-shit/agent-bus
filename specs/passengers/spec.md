# SPEC: Agent-bus passengers

Status: DRAFT

Parents: the-shit/agent-bus README First four, issues #2 and #8. House ticket Asgard#252 (glass/locks wait).

**Story:** `bin/agent-bus` and the sidecar merged. Nothing publishes. Hooks still write session packets elsewhere. `sessions` would list corpses or nobody. Two chairs cannot see each other.

## Locked

- Transport is NATS JetStream on `docker compose` (`nats://127.0.0.1:4222`). Stream `AGENT_BUS`, KV `sessions`.
- `bin/agent-bus` does **not** boot Laravel. Emit/heartbeat/sessions are Composer autoload + a NATS client.
- One Composer NATS client is allowed. Do not boot the Laravel app on a hook.
- Envelope and subjects stay as the README: `repo.{owner}.{name}.{event}`, KV key = session id.
- Hooks map only: SessionStart/SessionEnd, PostToolUse → `toolCall`, Notification `idle_prompt` + Stop → `idle`, PostToolUseFailure/StopFailure → `errorRaised`.
- Fail open: dead broker does not block the tool.
- Heartbeat PUT every ≤30s. KV bucket TTL **90s**, history 1. `sessionEnd` deletes the key.
- Repo from `git remote get-url origin`. Do not invent a second registry.
- You own: `bin/agent-bus`, hook scripts under `hooks/` (sample global JSON in the README), Pest for Cli + KV TTL.
- Do not: merge, deploy, MCP tools (#9), OpenCode plugin (#5), homelab NATS (#16), music strip, path locks, tail `events.jsonl`, publish `phase_changed`.

## Done when

- [ ] `docker compose up -d` then `bin/agent-bus emit --type=toolCall --payload='{"tool":"run_terminal_command"}'` with session/cwd/repo set produces **one** message on `repo.the-shit.agent-bus.toolCall`
- [ ] `bin/agent-bus heartbeat --session <id>` PUTs presence JSON; `bin/agent-bus sessions` lists that id with `repo`
- [ ] Stop heartbeats: the KV key is gone within 90s (`sessions` no longer lists it)
- [ ] Pest: `php artisan test --compact tests/Unit/Bus tests/Feature/Bus` — fake NATS, prove allowlist mapping and that `phase_changed` is dropped
- [ ] Hook scripts read stdin JSON and call `bin/agent-bus`; a missing broker exits 0

## Out of scope

- MCP `list_sessions` / `send` (issue #9 / #4 prove with live sidecar)
- Music bar strip and file locks (Asgard#252 remaining boxes)
- Arch-desk wallpaper
- Installing global `~/.grok/hooks/` on the machine (README shows the copy; Jordan copies)

## Open decisions

- None for this slice. Demo boarding (MCP vs inject vs skill) is a later pick, not this PR.
