# Agent Bus

A Laravel Zero console application. NATS JetStream carries agent presence and
addressed messages between coding-agent sessions. There is no HTTP layer, no
database, and no Eloquent — do not add them.

## The two paths

`bin/agent-bus` is one binary with two execution paths, and the split is the
whole performance design.

| Path | Verbs | Boots | Cost |
|---|---|---|---|
| Hot | `emit` `send` `inbox` `heartbeat` `session-end` `sessions` `hook` `opencode` | Composer autoload only | ~66 ms |
| Cold | `provision` `sidecar` `app:build` | Laravel Zero kernel | ~150 ms |

Hot verbs fire from harness hooks on **every tool call**. They must never boot
the framework. `Cli::HOT_VERBS` is the list `bin/agent-bus` dispatches on, and
`tests/Feature/AgentBusCliTest.php` runs each one in a subprocess and asserts
zero `Illuminate\`, `LaravelZero\`, or `Symfony\Component\Console\` classes
loaded. If you add a hot verb, add it to that constant and to the test dataset.

Anything needing config, the container, or `Process` belongs on the cold path.

## Layout

- `app/Bus/` — the bus. `Capture`, `GrokHook`, `OpenCodeCapture` are pure
  static mappers from a harness event to `CaptureAction`s; they take arrays and
  return arrays, and their tests never touch a container. `BusIdentity` is the
  one resolver from reporter-supplied ids to canonical `{kind}:{provider_id}`;
  it returns null rather than inventing identity. Alternates (jsonl paths, raw
  UUIDs, pane ids) live in the `session_aliases` KV bucket under sha256 keys —
  the KV backing streams use single-token subjects, so raw alternates can
  never be keys themselves (issue #30). Resolved ids are validated as one
  clean NATS token at the write boundary: dot-free by design, no stream
  surgery (issue #30 design comment).
- `app/Commands/` — cold-path commands. Laravel Zero discovers this directory.
- `app/Providers/NatsServiceProvider.php` — extends the package provider to
  make `NATS_URL` the single broker setting and to drop the package's `nats:*`
  commands.
- `bin/agent-bus` — the entry point and the path dispatcher.

## Conventions

- PHP 8.3+. Explicit return types and parameter type hints everywhere.
- Curly braces on every control structure, even one-liners.
- Constructor property promotion. No empty constructors.
- PHPDoc blocks with array shapes over inline comments.
- Run `vendor/bin/pint` before finishing. CI runs `vendor/bin/pint --test`.
- Envelope v2 shipped 2026-09-15: `emit` requires `agentType` and stamps
  top-level `"v": 2`. Consumers treat a missing `v` as v1 during the overlap;
  v1 emit is refused after **2026-09-29**.

## Failure posture

This is load-bearing and easy to get backwards.

- **Hooks fail open.** `emit`, `hook`, and `opencode` exit 0 with one stderr
  line when the broker is down. A dead bus must never block somebody's tool
  call or print a stack trace into their session.
- **Addressed sends fail closed.** `send` exits 1 when the target session is
  not in KV. Delivering into the void is worse than refusing.

## Tests

```bash
vendor/bin/pest                      # whole suite
vendor/bin/pest --filter=sidecar     # one slice
```

NATS-backed tests skip when nothing listens on the broker port. That is for
laptops without a broker — in CI, `AGENT_BUS_REQUIRE_BROKER=1` turns those
skips into failures, so a broker that fails to start cannot make the suite
look green. Never make a NATS test pass by stubbing the broker away; stand one
up with `docker compose up -d`.

Unit tests stay on plain PHPUnit and must not need the container. Feature tests
extend `Tests\TestCase` and boot the Zero kernel.

## Building

```bash
composer install --no-dev
php bin/agent-bus app:build agent-bus
```

The compiled binary is ~30 MB and adds roughly 140 ms to the hot path, so it is
for the sidecar and for humans. **Hooks should point at `bin/agent-bus` in a
checkout, not at the PHAR.**
