<p align="center">
  <img src="docs/logo.jpg" alt="Robot coding agents boarding a giant yellow school bus" width="720">
</p>

# Agent Bus

Coding agents that can't see each other are just expensive tabs. This is the bus they board.

NATS JetStream so Grok Build and OpenCode sessions can discover peers, subscribe to tool calls and idle, and send JSON to a live session — no pasting into someone else's TUI.

Laravel 13 skeleton. The bus is not wired yet. The spec below is what we are building.

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

Hooks already get `sessionId`, `cwd`, `toolName` on stdin. A tiny `agent-bus emit` CLI publishes. Laravel does not boot on every tool call.

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
```

That is the broker. The Laravel app, the CLI, the hooks, and the sidecar come next.

[Pint and Pest](https://github.com/the-shit/agent-bus/actions/workflows/tests.yml) run on every pull request.
