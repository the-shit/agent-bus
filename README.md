<p align="center">
  <img src="docs/logo.jpg" alt="Robot coding agents boarding a giant yellow school bus" width="720">
</p>

# Agent Bus

Coding agents that can't see each other are just expensive tabs. This is the bus they board.

NATS JetStream so Grok Build and OpenCode sessions can discover peers, subscribe to tool calls and idle, and send JSON to a live session — no pasting into someone else's TUI.

Laravel Zero 13 console app. First clients board **this** NATS bus (JetStream `AGENT_BUS` + KV `sessions`). Not Cloudflare Durable Objects.

## The lie

Two agents on the same machine still work like strangers. You copy a path into chat. Markdown inboxes and pane-prompt hacks pretend they are a mesh. They are mailboxes. They do not have presence. They do not have an inbox with delivery.

## Locked

- **Transport:** NATS JetStream. Not Kafka. Not Redis pub/sub as the product.
- **Stand-up:** `docker compose up -d` — `nats:2-alpine` with `-js` on `4222`.
- **Identity:** provider session id when it exists (Grok Build always has one). Else deterministic `grok|opencode-<repoHash>-<pid>`.
- **Repo:** `git remote get-url origin` when you need it. Do not invent a second registry for the tree.
- **Envelope:** JSON. Not free-form chat.

```json
{
  "sessionId": "01a08ef9-2515-7460-89bd-5efc21f28642",
  "agentType": "grok",
  "model": "grok-4.6",
  "repo": "the-shit/agent-bus",
  "type": "toolCall",
  "timestamp": "2026-09-11T06:00:00Z",
  "payload": { "tool": "run_terminal_command", "cwd": "/home/you/agent-bus" }
}
```

- **Subjects:** `repo.{owner}.{name}.{event}` for fan-out. `session.{sessionId}.inbox` for addressed send.
- **Presence:** NATS KV bucket `sessions`. MCP `list_sessions` reads KV. Pane lists are a local adapter, not the registry.
- **Capture:** harness hooks. Not the session `events.jsonl` dump. One Researcher session already logged 10,714 events, 10,639 of them `phase_changed`. That noise stays on disk.

| On the bus | Hook | Why |
|---|---|---|
| `sessionStart` / `sessionEnd` | SessionStart / SessionEnd | node join / leave |
| `toolCall` | PostToolUse | subscribe to a tool |
| `idle` | Notification `idle_prompt` + Stop | subscribe to ready |
| `errorRaised` | PostToolUseFailure / StopFailure | page a peer |

Stay local: `phase_changed`, permission chatter, MCP connect, token stream, the jsonl file.

Hooks already get `sessionId`, `cwd`, `toolName` on stdin. `agent-bus emit`
publishes. The framework does not boot on every tool call.

- **Send:** MCP/CLI `send` publishes to `session.{id}.inbox`. A host sidecar subscribed to *this* session injects into the target (Herdr `agent prompt` for Grok). Prove: two agents, one send, the other turn starts with the JSON. No paste.
- **First clients:** Grok Build and OpenCode.

## First four

1. **Stand JetStream + KV** — this compose file, stream `AGENT_BUS`, KV `sessions`. Done when `nats stream info` and `nats kv get sessions <id>` work from two terminals.
2. **Hook capture allowlist** — global Grok hooks → `agent-bus emit`. Done when a PostToolUse for Composer shows one `toolCall` on the stream and `phase_changed` does not.
3. **Presence MCP** — `list_sessions` / `get_session` read KV. Done when this pane's session appears after SessionStart and vanishes after SessionEnd.
4. **Addressed inbox** — MCP `send` + sidecar. Done when a second agent receives a JSON ping with no paste.

## Not this

- Replacing GitHub issues as the inbox
- Dumping every harness telemetry event onto the stream
- A second catalog for sessions
- Building our own NATS client — `zaeem2396/laravel-nats` is a real Laravel 13 package when the app layer needs it

## Run the broker

```bash
docker compose up -d
# client port 4222, HTTP monitor 8222
bin/agent-bus provision
```

That stands JetStream stream `AGENT_BUS` (subjects `repo.>`, `session.>`) and KV bucket `sessions` (history 1, 90s TTL). Running provision twice is a no-op.

Prove with Pest (skips if nothing is listening on 4222 — it does not pretend it published):

```bash
vendor/bin/pest tests/Feature/NatsBusTest.php
```

CI stands a broker up and runs with `AGENT_BUS_REQUIRE_BROKER=1`, which turns
those skips into failures. A broker that quietly fails to start cannot make the
suite look green.

Prove with the nats CLI if you have it:

```bash
nats stream info AGENT_BUS
nats kv info sessions
nats kv put sessions demo '{"sessionId":"demo"}'
nats kv get sessions demo
```

## Emit and send

`bin/agent-bus` is one binary with two paths. Hook verbs run on Composer's
autoloader alone; `provision` and `sidecar` boot Laravel Zero. Measured on this
checkout, PHP 8.4:

| Path | Verbs | Cost |
|---|---|---|
| Hot | `emit` `send` `heartbeat` `session-end` `sessions` `hook` `opencode` | 66 ms |
| Cold | `provision` `sidecar` `app:build` | 150 ms |

A test runs every hot verb in a subprocess and fails if a single framework class
gets loaded.

```bash
bin/agent-bus emit --type=toolCall --payload='{"tool":"composer"}'
# sessionId from --session or AGENT_BUS_SESSION_ID
# broker down: exit 0, one stderr line

bin/agent-bus send --session 01a08ef9-2515-7460-89bd-5efc21f28642 --payload='{"text":"ping"}'
# unknown session: fail closed

bin/agent-bus heartbeat --session 01a08ef9-2515-7460-89bd-5efc21f28642
bin/agent-bus sessions
bin/agent-bus sessions get 01a08ef9-2515-7460-89bd-5efc21f28642
```

Connects to `nats://127.0.0.1:4222`. Override with `NATS_URL`, credentials
inline for a remote broker: `nats://user:pass@homelab.tail:4222`. One variable
drives both paths. Loopback answers fast; raise `AGENT_BUS_CONNECT_TIMEOUT`
(seconds, default `0.25`) when the broker is across a tailnet.

```bash
bin/agent-bus session-end --session 01a08ef9-2515-7460-89bd-5efc21f28642
# crash net is KV TTL 90s; this is the polite leave
```

## Board Grok Build and OpenCode

This repo ships the adapters. They only map the README allowlist onto `bin/agent-bus`.

- Grok: `.grok/hooks/agent-bus.json` → `bin/agent-bus hook` (stdin JSON). Trust the folder (`/hooks-trust`) so project hooks run.
- OpenCode: `.opencode/plugin/agent-bus.js` → `bin/agent-bus opencode`.

A PostToolUse / `tool.execute.after` for `run_terminal_command` publishes **one** `toolCall` on `repo.{owner}.{name}.toolCall`. `phase_changed` and other unlisted events publish nothing. Dead broker: `hook` / `opencode` exit 0, no Laravel stack trace.

To board every Grok session on the machine, copy `hooks/grok.json` to `~/.grok/hooks/` and replace `/ABS/PATH/TO/agent-bus` with this checkout. Do not install that from CI.

Heartbeat PUTs presence JSON (`sessionId`, `agentType`, `repo`, `lastSeen`). `sessions` lists the id. Stop heartbeats: the KV key is gone within 90s. `sessionEnd` deletes immediately.

## Host sidecar

One process per host. It maps Grok session ids from `herdr agent list` (`agent_session.value` → `pane_id`) onto KV `sessions`, then injects inbox JSON with `herdr agent prompt` so the **model** sees it. No paste. No markdown drop. No `wtype`.

```bash
bin/agent-bus provision
bin/agent-bus sidecar
```

Leave it running. From another pane (session A):

```bash
bin/agent-bus send --session <B> --payload '{"text":"ping"}'
```

Session B's next turn contains that JSON. Restart the sidecar, send again, it still delivers.

Pest proves the map and that the sidecar would prompt that pane (Herdr is faked; NATS tests skip when the broker is down). Live two-Grok prove is the issue, not CI.

```bash
vendor/bin/pest tests/Feature/AgentBusSidecarTest.php tests/Unit/Bus/SessionPaneMapTest.php
```

[Pint and Pest](https://github.com/the-shit/agent-bus/actions/workflows/tests.yml) run on every pull request.

## MCP server

`bin/agent-bus mcp` speaks MCP JSON-RPC 2.0 over stdin/stdout. Point an MCP
client at it; do not pipe it yourself.

```bash
bin/agent-bus mcp
```

Tools:

- `list_sessions` — read every session from KV `sessions`. Pass `repo` to filter.
- `get_session` — read one session. Required: `sessionId`.
- `send` — publish to `session.{sessionId}.inbox`. Required: `sessionId` +
  `payload` (JSON object). Fails closed when the session is not on the bus.

Errors return `isError: true` in the MCP tool result. Unknown methods and
malformed requests return JSON-RPC error objects. Nothing is written to stdout
besides protocol JSON.

## Compile a binary

```bash
composer install --no-dev
php bin/agent-bus app:build agent-bus
./builds/agent-bus provision
```

One ~30 MB file, no checkout and no `composer install` on the target machine.
Good for the sidecar and for running verbs by hand.

**Not for hooks.** PHAR stub overhead puts the hot path at ~206 ms against
66 ms for the script — roughly 140 ms added to every tool call. Point
`hooks/grok.json` at `bin/agent-bus` in a checkout and keep hooks cheap.

### Inbox delivery failures

The sidecar acknowledges an inbox message only after successful Herdr injection,
or after JetStream stores a terminal failure receipt. Missing recipients,
invalid envelopes, and injection errors retry with exponential delay (5 seconds
initially, capped at 60 seconds). `AGENT_BUS_SIDECAR_MAX_ATTEMPTS` defaults to 5;
`AGENT_BUS_SIDECAR_RETRY_SECONDS` controls the initial delay. Attempt counts live
in JetStream and survive sidecar restarts.

Exhausted deliveries appear on `repo.agent-bus.delivery.failed`, with the original
body, subject, stream-sequence message ID, consumer, attempt count, and exception
class. If storing that receipt fails, the original remains unacknowledged.
Receipts have the stream's retention policy; no automatic replay or Mattermost
notification is added. Consumers of these receipts must treat the original body
as untrusted message content.

Inbox pulls are now one message at a time (the former sidecar batch setting is
removed), with a 60-second acknowledgement timeout, including existing durable
consumers. Heartbeat renewal and duplicate injection after a crash between
prompting and acknowledgement remain separate work. This is at-least-once
transport delivery, not proof that an agent completed its task.
