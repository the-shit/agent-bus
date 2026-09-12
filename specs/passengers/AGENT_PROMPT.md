Implement the-shit/agent-bus issues #2 and #8 in this clone. Branch `feat/agent-bus-passengers` (already checked out). PHP 8.5, Pest. Do not merge. Do not deploy. Do not `git worktree`.

Read `specs/passengers/spec.md`, README First four, issue #2, issue #8, `bin/agent-bus`.

`bin/agent-bus` today curls a fake HTTP URL and appends `bin/bus_queue.txt`. Replace that. Sidecar stays out of this PR except you must not break `send` flags.

## Do

1. `bin/agent-bus emit|heartbeat|sessions|sessions get` talk to JetStream + KV `sessions` without booting Laravel.
2. Subjects `repo.{owner}.{name}.{event}`. Owner/name from `git remote get-url` on cwd (payload may pass `repo`).
3. KV presence JSON: sessionId, agentType, model, cwd, repo, status, lastSeen. Heartbeat PUTs the full JSON. TTL 90s.
4. Hook scripts in `hooks/` (stdin JSON → emit). Map only the README allowlist. `Notification` matcher `idle_prompt`. Unknown types exit 0. Dead broker exit 0.
5. Pest with a fake NATS. Prove: one `toolCall` from PostToolUse; `phase_changed` not published; heartbeat then expire drops the list.
6. README: how to copy hook JSON into `~/.grok/hooks/`. Do not write Jordan's home.
7. Pint dirty files.

## Do not

- MCP server / Boost tools (issue #9)
- OpenCode plugin
- Homelab NATS
- Tail `events.jsonl`
- `gh pr merge`
- Rewrite the sidecar

## Allowlist

```
app/Bus/
bin/
hooks/
specs/passengers/
tests/Unit/Bus/
tests/Feature/Bus/
composer.json
composer.lock
README.md
docker-compose.yml
```

## PR

```
gh pr create --base master --head feat/agent-bus-passengers --title "feat: hooks emit presence and heartbeat so sessions do not lie"
```

Body: `Closes #2` and `Closes #8`. Last line of your reply is the PR URL. Then idle.
