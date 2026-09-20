# SPEC: session-owned inbox

Status: DRAFT

**Story:** `send` publishes to `session.{id}.inbox` and presence lists the peer, but nothing delivers unless a host sidecar maps that id onto a Herdr pane. A Pi in a raw terminal is on the bus and still deaf. Delivery must belong to the session process, not a room assignment.

## Locked

- Hot verb `inbox --session=<id>` pulls one envelope from a per-session durable consumer
- Consumer name `agent-bus-inbox-{id}` with colons folded to dashes; filter `session.{id}.inbox`; deliver new; ack explicit
- Exit 0 = one JSON envelope, 2 = empty, 1 = closed
- Clients (Pi, Grok, OpenCode) poll `inbox`. They do not need Herdr, a named session, or a room
- Herdr sidecar stays optional for agents that cannot pull
- You own: `app/Bus/Cli.php`, `tests/Feature/AgentBusCliTest.php`
- Do not: merge, deploy, new harness, start a standing Grok hive

## Done when

- [ ] `vendor/bin/pest --filter=inbox` — missing session exits 1; send then inbox prints the payload with no pane map
- [ ] Live: two heartbeats, `send` a spoken quote, the target `inbox`s it (or the client speaks it) without `herdr agent prompt`

## Out of scope

- Rewriting the sidecar
- Fleet broker on another host (#16 / #21)
- Auto-assigning sessions to Herdr rooms
