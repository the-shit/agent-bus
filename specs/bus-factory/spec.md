# SPEC: agent-bus conductor + disposable crews

Status: DRAFT

**Story:** This Herdr session looked like a product team shipping the bus (Researcher / Product Manager / Ecosystem Connector). Four idle Pi panes shared the daily clone, the tree was dirty (Spotify + HTTP extras), and `herdr-crew` `spawn.sh --issue N` stamped Asgard issue URLs. A factory is issue → disposable clone → research/build/test → PR. It is not three personalities typing `~/Projects/the-shit/agent-bus`.

## Locked

- This pane is **Conductor** (`w2:tH`). It classifies, spawns, grades receipts. It does not type product code in the daily clone.
- Each typed GitHub issue on `the-shit/agent-bus` gets `herdr-crew`: one `~/Work/die/agent-bus-<n>-crew` clone, three panes (research / build / test), `flock .git/herdr-crew.lock` commits. Children never merge or deploy.
- Spawn from this session is always `--issue N --from /home/jordan/Projects/the-shit/agent-bus`. Never `spawn.sh top N` (that board is Asgard-first).
- Ops issues stay ops. [#16](https://github.com/the-shit/agent-bus/issues/16) and [#21](https://github.com/the-shit/agent-bus/issues/21) are Odin/Tailscale broker work, not a PHP crew.
- GitHub issues remain the inbox. Chat, Pi role tabs, and the dirty daily tree are not a second catalog.
- You own: this Conductor tab, Agent Bus Monitor, `~/.grok/skills/herdr-crew/scripts/spawn.py`, `specs/bus-factory/`.
- Do not: merge, deploy, new harness, Researcher/PM/Ecosystem Connector on the daily clone, Spotify/HTTP as factory work.

## Done when

- [ ] From Conductor: `~/.grok/skills/herdr-crew/scripts/spawn.sh --issue N --from /home/jordan/Projects/the-shit/agent-bus --title "N short"`
- [ ] `~/.grok/skills/herdr-crew/scripts/list.sh` shows `rN` `bN` `tN`
- [ ] Those panes' cwd is `~/Work/die/agent-bus-N-crew` (not the daily clone)
- [ ] The crew prompt names `https://github.com/the-shit/agent-bus/issues/N` (not Asgard)

## Out of scope

- `spawn.sh top 3` from this session
- Role theater on the daily clone
- Spotify bridge / music events as factory work (file on `the-shit/music` if it still matters)
- HTTP/Eloquent extras against the Laravel Zero contract
- Night-shift / local-worker as the primary loop (overflow only, after a SPEC exists)
- Hive standing mesh, a new dashboard for factory status

## Open decisions

- First typed `N`. Open bus issues today are ops (#16, #21). PR 35 (passengers) is already a PR — grade, do not crew. Dirty daily tree is not an issue yet.
