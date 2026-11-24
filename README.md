<p align="center">
  <img src="docs/logo.jpg" alt="Robot coding agents boarding a giant yellow school bus" width="720">
</p>

# Agent Bus

Coding agents that can't see each other are just expensive tabs. This is the bus they board.

NATS JetStream so any agent process can discover peers, advertise capabilities,
receive addressed JSON, and claim specialized work — no shared terminal manager
required.

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

- **Subjects:** `repo.{owner}.{name}.{event}` for fan-out,
  `session.{sessionId}.inbox` for addressed send, and
  `work.request.{capability}.{requestId}` / `work.result.{requestId}` for
  specialized work.
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

- **Send:** MCP/CLI `send` publishes to `session.{id}.inbox`. The receiving process pulls and owns delivery; no terminal multiplexer or pane injection is involved. Prove: two agents, one send, the other receives the JSON. No paste.
- **First clients:** Pi, Grok Build, and OpenCode.

## First four

1. **Stand JetStream + KV** — this compose file, stream `AGENT_BUS`, KV `sessions`. Done when `nats stream info` and `nats kv get sessions <id>` work from two terminals.
2. **Hook capture allowlist** — global Grok hooks → `agent-bus emit`. Done when a PostToolUse for Composer shows one `toolCall` on the stream and `phase_changed` does not.
3. **Presence MCP** — `list_sessions` / `get_session` read KV. Done when this pane's session appears after SessionStart and vanishes after SessionEnd.
4. **Addressed inbox** — MCP `send` + recipient-side pull. Done when a second agent receives a JSON ping with no paste.

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
autoloader alone; `provision`, `monitor`, and `mcp` boot Laravel Zero. Measured on this
checkout, PHP 8.4:

| Path | Verbs | Cost |
|---|---|---|
| Hot | `emit` `send` `inbox` `heartbeat` `session-end` `sessions` `work-request` `work-result` `hook` `opencode` | 66 ms |
| Cold | `provision` `monitor` `mcp` `judge` `specialist` `app:build` | 150 ms |

A test runs every hot verb in a subprocess and fails if a single framework class
gets loaded.

```bash
bin/agent-bus emit --type=toolCall --payload='{"tool":"composer"}'
# sessionId from --session or AGENT_BUS_SESSION_ID
# broker down: exit 0, one stderr line

bin/agent-bus send --session 01a08ef9-2515-7460-89bd-5efc21f28642 --payload='{"text":"ping"}'
# unknown session: fail closed

bin/agent-bus inbox --session 01a08ef9-2515-7460-89bd-5efc21f28642
bin/agent-bus heartbeat --session 01a08ef9-2515-7460-89bd-5efc21f28642 \
  --agent-type=pi --capabilities=code_review,tool_failure_triage
bin/agent-bus sessions
# JSON array of presence records (sessionId, agentType, lastSeen, …)
bin/agent-bus sessions --ids
# string array of KV keys, the old shape
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

### Delivery guarantee: at most once (inbox) / at least once (listen --exec)

The addressed inbox delivers **at most once**:

- `inbox` acknowledges a message when it is pulled, before the agent acts on it. If the agent crashes after the pull, the message is gone.
- `listen --exec` is the exception: it acknowledges only after the command exits and keeps the ack deadline fresh while it runs, so a listener that dies mid-command gets the message redelivered (at least once). A message delivered more than `AGENT_BUS_INBOX_MAX_DELIVERIES` times (default 3) is terminated without running the command, logged, and receipted `failed`.
- **Wake commands must be idempotent per job id and attempt.** Besides redelivery, if `coord-bridge` sees a real `done` but `complete_job` then throws (coord down), the bridge fails the attempt with a retry and coord runs the job again as the next attempt.
- An inbox consumer nobody pulls from for 7 days (`AGENT_BUS_INBOX_INACTIVE_SECONDS`, 0 = never) is removed by NATS, with its unread mail.
- `session-end` deletes the session's inbox consumer, and unread mail goes with it.
- Every sender (CLI `send` and MCP `send`) creates the target's inbox consumer before publishing. The consumer uses DeliverPolicy New, so a message published before the consumer exists would never be delivered.
- `provision` rewrites an existing stream's limits: 7-day max age, 1 GiB max bytes, 256 KiB per message, and a 900 s duplicate window. Anything older than 7 days ages out.

Work that must not be lost belongs in a job ledger (sbprod `coord`), not in the inbox.

## Board Grok Build and OpenCode

This repo ships the adapters. They only map the README allowlist onto `bin/agent-bus`.

- Grok: `.grok/hooks/agent-bus.json` → `bin/agent-bus hook` (stdin JSON). Trust the folder (`/hooks-trust`) so project hooks run.
- OpenCode: `.opencode/plugin/agent-bus.js` → `bin/agent-bus opencode`.

A PostToolUse / `tool.execute.after` for `run_terminal_command` publishes **one** `toolCall` on `repo.{owner}.{name}.toolCall`. `phase_changed` and other unlisted events publish nothing. Dead broker: `hook` / `opencode` exit 0, no Laravel stack trace.

To board every Grok session on the machine, copy `hooks/grok.json` to `~/.grok/hooks/` and replace `/ABS/PATH/TO/agent-bus` with this checkout. Do not install that from CI.

Heartbeat PUTs presence JSON (`sessionId`, `agentType`, `repo`, `lastSeen`). `sessions` lists the id. Stop heartbeats: the KV key is gone within 90s. `sessionEnd` deletes immediately.

## Wake-on-arrival and receipts by job id (M6)

Every `send` carries a `jobId` and an `attempt`. Pass a coord job id with
`--job=<uuid>` (or one is generated) and coord's `jobs.attempt` with
`--attempt=<n>` (default 1). Resending the same attempt of a job to the same
session is de-duplicated by JetStream (`Nats-Msg-Id`
`inbox:{session}:{jobId}:{attempt}`, inside the stream duplicate window); the
next attempt of a requeued job gets through and is handled again.

Receipts are published on `work.receipt.{jobId}.{attempt}.{state}`, one subject
per attempt and state, so attempt 2 never sees attempt 1's `failed`:

| state | written by | means |
|---|---|---|
| `queued` | sender (`send`, MCP `send`, `coord-bridge`) | the broker accepted the publish |
| `delivered` | target (`inbox` or `listen`) | the target process pulled it |
| `done` / `failed` | target (`listen --exec` exit code, or `receipt --state`) | the target finished |

A reader takes the furthest state of one attempt (`receipt --attempt=<n>`,
default 1), so a late `queued` never hides `delivered`.

```bash
# target: block on its own inbox and fire on arrival (keeps presence fresh)
bin/agent-bus listen --session=pi:abc --agent-type=pi --exec='my-agent-turn'

# sender: wait up to 5 s for the target to take it
bin/agent-bus send --session=pi:abc --job=<coord-job-uuid> --payload='{"text":"ping"}' --wait-receipt=5
bin/agent-bus receipt --job=<coord-job-uuid> --until=done --wait=600
```

`listen --exec` runs the command with the envelope on stdin (from a 0600 temp
file, so a command that never reads stdin cannot stall the listener) and with
`AGENT_BUS_JOB_ID`, `AGENT_BUS_ATTEMPT`, `AGENT_BUS_SESSION_ID` and
`AGENT_BUS_RESULT_FILE` set. Exit 0 records `done`, and any other exit records
`failed`. A JSON object written to `AGENT_BUS_RESULT_FILE` becomes the `done`
receipt's `result` (and the coord job's result). While the command runs, the
listener extends the message's ack deadline and, with `--agent-type`, refreshes
presence every 30 s.

The command's environment is an **allowlist**: `PATH`, `HOME`, `USER`,
`LOGNAME`, `SHELL`, `LANG`, `LC_*`, `TZ`, `TMPDIR`, `TERM`, the four
`AGENT_BUS_*` job variables above, and any name given with `--pass-env NAME`
(repeatable, or comma-separated). Everything else stays out, including
`NATS_URL` and NATS credentials, `TYPESAFE_API_KEY`, database and Redis URLs,
`SSH_AUTH_SOCK` and `XDG_RUNTIME_DIR`. A command that calls `bin/agent-bus`
itself needs `--pass-env NATS_URL` (and the NATS credentials it should have).

`--exec-timeout=<seconds>` (or `AGENT_BUS_EXEC_TIMEOUT`, default 900, 0 = no
limit) bounds the command. It runs in its own process group; past the limit
the group gets SIGTERM, then SIGKILL 5 s later. The message is terminated (not
redelivered) and receipted `failed` with `exitCode` 124 and `reason: timeout`,
so `coord-bridge` fails the attempt and coord can retry it as the next attempt.

New inbox consumers are created with `max_deliver` = cap + 1 (so the listener
sees the over-cap delivery and can receipt it), an ack wait of
`AGENT_BUS_INBOX_ACK_WAIT_SECONDS` (default 30), and the 7-day inactive
threshold. Existing consumers keep their old settings until they are recreated.

### coord bridge

sbprod `coord` is the source of truth for jobs, and the bus only carries job ids.
`bin/agent-bus coord-bridge --session=<id> --capabilities=a,b` does four things:

1. It runs `LISTEN coord` through Supavisor session mode.
2. It claims a job with `coord.claim_job`.
3. It puts the job in that session's inbox, keyed by the coord job id and
   coord's `attempt` (claim_job bumps it on every claim).
4. It maps that attempt's receipts back to coord: `delivered` calls
   `heartbeat_job`, `done` calls `complete_job`, and `failed` or a timeout calls
   `fail_job` with a retry. A requeued job is claimed again as the next attempt
   and delivered again.

The bridge asserts that coord bumps `attempt` on every claim. A claim row with
no attempt, or attempt < 1, is an error. A job that comes back with an attempt
this bridge already delivered (in memory, or any receipt for that attempt from
the target session on the bus, which survives a bridge restart) is failed
**without retry** and logged as `ERROR ... coord.claim_job must bump attempt`,
instead of being silently de-duplicated by JetStream.

It never claims work for a session that is not on the bus. On any error after a
claim (bus or coord down) it calls `fail_job` with a retry, so a job is never
left leased, then backs off (1 s doubling to 30 s) and keeps running; if even
`fail_job` fails, `reclaim_expired_jobs` requeues the job when its lease runs
out. A dropped coord connection is reopened (and `LISTEN` re-issued) on the next
call.

Who may finish a job: only a `done`/`failed` receipt whose `sessionId` is the
session the bridge delivered to, for the attempt it delivered, moves the coord
job; receipts from any other session are ignored. **This is advisory.** The bus
has no per-sender authentication, so anyone with broker credentials can publish
a receipt naming the target session (or publish a later spoof that masks the
real `done`, so the attempt times out). Real enforcement needs all three of:

1. per-agent NATS users;
2. the session in the receipt subject
   (`work.receipt.{jobId}.{attempt}.{session}.{state}`), with the bridge reading
   only its delivered session's subject;
3. NATS permissions that let each agent user publish only on its own session's
   receipts (`work.receipt.*.*.<its-session>.>`) and subscribe only to its own
   `session.<its-session>.inbox`.

That is not built yet.

**Upgrade together.** Receipt subjects moved from `work.receipt.{jobId}.{state}`
to `work.receipt.{jobId}.{attempt}.{state}`, and the inbox msg-id from
`inbox:{session}:{jobId}` to `inbox:{session}:{jobId}:{attempt}`. Mixed versions
miss each other's receipts. Upgrade the Thor CLI (`bin/agent-bus`), every running
`coord-bridge`, and the house `agent_bus_send` tool at the same time.

Configure it with `COORD_DB_HOST`, `COORD_DB_PORT` (15442), `COORD_DB_USER`
(`<role>.sbprod`), and `COORD_DB_PASSWORD_FILE`. The bridge refuses a password
file that group or other can read (it must be 0600 or 0400).

Tests: `tests/Feature/WakeOnArrivalTest.php` covers AT-6 and the house/board#105
fixture end-to-end test. `tests/Feature/Coord/CoordBridgeTest.php` runs against a
faked coord. `tests/Feature/WakeExecTest.php` covers ack-after-exec, redelivery after a
crash, the scrubbed environment and result file, and the password-file check.
`tests/Feature/Coord/CoordLiveTest.php` is opt-in and needs a
throwaway coord Postgres, never live sbprod; it covers AT-6 through coord and a
job that fails on attempt 1 and completes on attempt 2.

## Specialized work and Jev

Workers advertise comma-separated capabilities in heartbeat presence. A caller
publishes one correlated request and may wait for its result:

```bash
bin/agent-bus work-request \
  --capability=tool_failure_triage \
  --agent-type=pi \
  --session=pi:requester \
  --payload='{"tool":"bash","argumentShape":"object","argumentCount":1,"errorSignals":["permission_denied"],"errorLengthBucket":"short","hasStackTrace":false,"retrySafe":false}' \
  --wait=8
```

`bin/agent-bus judge` is the first capability worker. It consumes
`tool_failure_triage`, asks TypeSafe Jev for one bounded classification, then
applies deterministic local policy. The response is advisory and always marks
`automatic: false`; it never retries a tool, grants permission, or executes a
command. Before the external request, failures are projected onto a fixed signal
schema (tool name, argument shape/count, coarse error flags, length bucket,
stack-trace boolean, and retry safety). Raw arguments and error text are never
sent to TypeSafe. `TYPESAFE_API_KEY` belongs on the worker, not in every agent
process.

All workers in one capability pool share a durable consumer, so one request is
owned by one worker. Qwen code review, Grok image generation, and game building
can use the same subject contract without becoming Herdr agents.

The first Pi specialist profile is a read-only local Qwen reviewer:

```bash
NATS_URL=nats://odin:4222 bin/agent-bus specialist qwen-reviewer

NATS_URL=nats://odin:4222 bin/agent-bus work-request \
  --capability=code_review \
  --agent-type=pi \
  --session=pi:requester \
  --payload='{"prompt":"Review app/Bus for delivery and security defects."}' \
  --wait=600
```

The worker invokes Pi with `ollama/qwen3-coder-next:48k` and the reviewer
persona. **Behaviour change:** by default it now runs with `--no-tools
--no-mcp` in the system temp dir (`AGENT_BUS_QWEN_REVIEW_CWD`), so the reviewer
sees only the prompt. Set `AGENT_BUS_QWEN_REVIEW_TOOLS=read,grep,find,ls` to
give it read tools; it then refuses a cwd in this checkout, in `$HOME`, or in any
directory holding env, key or credential files. `--no-mcp` and `--no-tools`
need a Pi that has them: Pi 1.6.0 on Thor does (`pi --help` lists both). Requests are passed as argument-array
values rather than through a shell. Process failures return bounded generic
errors so prompts and process arguments do not leak into result subjects.

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
Good for long-running cold commands and for running verbs by hand.

**Not for hooks.** PHAR stub overhead puts the hot path at ~206 ms against
66 ms for the script — roughly 140 ms added to every tool call. Point
`hooks/grok.json` at `bin/agent-bus` in a checkout and keep hooks cheap.

### Inbox delivery failures

`NatsBus::consumeInbox` acknowledges an inbox message only after its handler
returns, or after JetStream stores a terminal failure receipt. A handler that
throws is retried with exponential delay (5 seconds initially, capped at 60
seconds). `AGENT_BUS_SIDECAR_MAX_ATTEMPTS` defaults to 5;
`AGENT_BUS_SIDECAR_RETRY_SECONDS` controls the initial delay (the env names are
kept from the removed Herdr sidecar so existing durable consumers and settings
keep working). Attempt counts live in JetStream and survive consumer restarts.

Exhausted deliveries appear on `repo.agent-bus.delivery.failed`, with the original
body, subject, stream-sequence message ID, consumer, attempt count, and exception
class. If storing that receipt fails, the original remains unacknowledged.
Receipts have the stream's retention policy; no automatic replay or Mattermost
notification is added. Consumers of these receipts must treat the original body
as untrusted message content.

Inbox pulls are now one message at a time (the former batch setting is
removed), with a 60-second acknowledgement timeout, including existing durable
consumers. Heartbeat renewal and duplicate delivery after a crash between
handling and acknowledgement remain separate work. This is at-least-once
transport delivery, not proof that an agent completed its task.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the code-approval and merge rule.
