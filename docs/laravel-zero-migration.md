# Spec: move Agent Bus from Laravel to Laravel Zero

## Why

Agent Bus was started with `laravel new`. It is a console application that
talks to a message broker. It has never had a route worth serving, a model
worth persisting, or a view worth rendering.

The measurements that decided it, taken on the pre-migration checkout:

| Check | Before |
|---|---|
| `bin/agent-bus emit` cold start | 79 ms, zero `Illuminate\` classes loaded |
| `php artisan` boot | 235 ms |
| Bare `php -r` | 44 ms |
| Suite | 59 tests, 45 passed, **14 skipped** |

The hot path already bypassed the framework entirely — `bin/agent-bus` loaded
Composer's autoloader and nothing else. The framework was booting for exactly
two commands. Everything else Laravel shipped (Eloquent, HTTP kernel, sessions,
queues, Blade, Vite, Tailwind, a `User` model, three migrations) was dead
weight the repo carried and agents had to read past.

Laravel Zero keeps what is actually used: the container, `config()`, the
`Process` facade, `Sleep`, Pest's console helpers, and the ability to host a
third-party Laravel service provider. It adds `app:build`.

## Non-goals

This migration changes packaging. It does not change the bus.

Out of scope, tracked separately:

- Stream retention limits on `AGENT_BUS`
- A `from` field in the envelope so a recipient can tell who pinged it
- Moving the OpenCode allowlist into the plugin so it stops spawning PHP per event
- The non-deterministic fallback session id
- Broker authentication (issue 16)
- MCP tools (issue 9)

## Design

### One binary, two paths

`bin/agent-bus` dispatches on the first argument before deciding whether to
boot anything.

```
bin/agent-bus emit ...     →  Cli::run()            Composer autoload only
bin/agent-bus provision    →  Zero kernel           full container
```

`Cli::HOT_VERBS` is the list. Hot verbs fire from harness hooks on every tool
call, so the rule is absolute: no framework class may load for them.

This is enforced, not documented. `tests/Fixtures/hot-path-probe.php` runs a
verb through the real binary in a subprocess, registers a shutdown function,
and records every loaded class whose name starts with `Illuminate\`,
`LaravelZero\`, or `Symfony\Component\Console\`. The test asserts that list is
empty, for all six hook verbs. The previous test only grepped the file for the
string `bootstrap/app.php`, which the new binary legitimately contains.

### Commands

| Before | After |
|---|---|
| `php artisan nats:provision` | `bin/agent-bus provision` |
| `php artisan agent-bus:sidecar` | `bin/agent-bus sidecar` |
| `bin/agent-bus-sidecar` | removed, the one binary covers it |

### NATS_URL is the only broker setting

Before, the hot path read `NATS_URL` and the framework path read
`nats_basis.connections.default.*`. Two ways to point at one broker.

`App\Bus\NatsUrl` parses `nats://[user:pass@]host[:port]` and both paths use
it. `App\Providers\NatsServiceProvider` extends the package provider and
projects `NATS_URL` onto the package's connection config, so issue 16's
tailnet broker needs one variable. It also drops the package's 21 `nats:*`
console commands, which are noise in this binary.

Connect timeout moves from a hardcoded `0.25` to `AGENT_BUS_CONNECT_TIMEOUT`,
because 250 ms is right for loopback and wrong across a tailnet.

### Laravel Zero does not set the signal resolver

Found by running the suite against a live broker. Laravel Zero does not
register Illuminate's `ArtisanServiceProvider`, which is where Laravel calls
`Signals::resolveAvailabilityUsing()`. Without it `$this->trap()` dereferences
a null callable and fatals.

The sidecar traps `SIGTERM`/`SIGINT` to leave the bus politely rather than
waiting out the 90-second KV TTL, so this is on the main path, not an edge.
`AppServiceProvider::register()` installs the same resolver Laravel uses.

This bug is invisible without a broker: the command returns early on the
reachability check before reaching the trap. It is the concrete argument for
the CI change below.

### CI runs a real broker

Before, every JetStream and KV test skipped in CI and the workflow had a
comment telling you not to add a broker. The core of the product was proven
only on somebody's laptop.

CI now starts `nats:2-alpine -js -m 8222` with the same flags as
`docker-compose.yml` and waits on `/healthz`. A service container cannot pass
`-js`, so it runs as a plain `docker run` step.

Skipping is still correct on a laptop with no broker, so the skip stays — but
`AGENT_BUS_REQUIRE_BROKER=1` turns it into a failure. CI sets it. A broker that
quietly fails to start now fails the build instead of producing a green run
that proved nothing.

A third job compiles the binary and runs it, so `app:build` cannot rot.

## The PHAR, honestly

`app:build` produces one ~30 MB file that runs with no checkout and no
`composer install`. That was the headline reason to move.

It does not work for hooks. Measured on this checkout:

| Entry | Hot path |
|---|---|
| `bin/agent-bus` script | 66 ms |
| Compiled binary, uncompressed | 206 ms |
| Compiled binary, GZ compressed | 529 ms |

PHAR stub and signature overhead adds ~140 ms to every tool call. GZ
compression triples that, so `box.json` sets `compression: NONE` and accepts
30 MB over 109 MB.

So the binary is for the sidecar, which pays the cost once and stays up, and
for running verbs by hand. Hooks keep pointing at `bin/agent-bus` in a
checkout. The README says so where someone setting up hooks will read it.

This weakens the original case for the move. The remaining case — deleting a
web framework from a CLI app, one broker setting, and CI that actually proves
the bus — still holds.

## What was deleted

`app/Http`, `app/Models`, `app/Console`, `database/`, `resources/`, `public/`,
`routes/`, nine `config/` files, `vite.config.js`, `package.json`,
`package-lock.json`, `.npmrc`, `artisan`, `bin/agent-bus-sidecar`,
`tests/Unit/ExampleTest.php`, and the vendored `.grok/skills` tree (236 KB of
Laravel web-app guidance: Blade, Eloquent, migrations, Tailwind — none of it
applicable now).

Laravel Boost went with it. It is a Laravel-application MCP server; its tools
(`database-query`, `database-schema`, `get-absolute-url`, `browser-logs`) have
nothing to operate on here. `.mcp.json`, `boost.json`, `opencode.json`, and
`.grok/config.toml` only configured it.

`CLAUDE.md` and `AGENTS.md` were Boost-generated guidelines for a Laravel web
app — Eloquent, migrations, Blade, Vite, and a pointer to a `.ai/rules`
directory that does not exist. They are rewritten to describe this application:
the two paths, the failure posture, and the rule that hot verbs never boot the
framework.

## Verification

```
Pint                        passed
Pest, no broker             54 passed, 14 skipped
Pest, live broker           68 passed, 0 skipped, 0 failed
Guard, broker down          4 failed (correct — proves the guard bites)
app:build                   compiled, binary runs both paths
Hot path                    66 ms, 0 framework classes, all 6 verbs
Cold path                   150 ms
```

Run against `nats-server v2.14.6` with JetStream on loopback.
