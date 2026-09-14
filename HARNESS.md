# JORDAN's Custom PI Agent Harness

> **If shit ain't tight, shit ain't right.**

Repo-scoped agent customization for PI. Filesystem boundaries, model routing, hive-mind coordination, and review gates.

---

## 🔥 Quick Start

```bash
cd ~/Projects/the-shit/agent-bus
./scripts/harness-activate.sh activate
export THE_SHIT_HARNESS=active
export JORDAN_MODE=true
```

---

## Architecture

```
THINK     →  openrouter/gpt-4o-mini     (cheap, fast, throwaway)
PLAN      →  openrouter/kimi-k2.6       (reasoning, architecture)
BUILD     →  ollama/qwen-coder-32k      (local, private, free)
REVIEW    →  openrouter/gpt-4o-mini     (structured, fast)
JUDGE     →  openrouter/o3-mini         (deep analysis, final call)
```

Each layer is a pi subagent. The LLM chains them via the `subagent` tool.
Trust scores track which agents produce passing work.

---

## 📁 What's Deployed

### Pi Extensions (`~/.pi/agent/extensions/`)

| Extension | Role |
|-----------|------|
| `jordan-bus` | Pi ↔ agent-bus adapter (session events, tool calls, heartbeat) |
| `jordan-gate` | Filesystem boundary enforcement (real, in-process) |
| `jordan-router` | Context-aware model routing per project type |
| `hive-mind` | Trust ledger, layer permissions, coordination |
| `asgard` | Control plane dashboard, review gate, receipts |

### Agent Definitions (`~/.pi/agent/agents/`)

**Blue Team (honest work):**

| Agent | Model | Role |
|-------|-------|------|
| `thinker` | `openrouter/gpt-4o-mini` | Rapid triage, approach sketch |
| `planner` | `openrouter/moonshotai/kimi-k2.6` | Architecture, implementation plan |
| `builder` | `ollama/qwen-coder-32k` | Honest code implementation |
| `reviewer` | `openrouter/gpt-4o-mini` | Honest structured review |
| `judge` | `openrouter/openai/o3-mini` | Deep analysis, final call |

**Red Team (deception training):**

| Agent | Model | Role |
|-------|-------|------|
| `saboteur` | `openrouter/gpt-4o-mini` | Introduces subtle, realistic bugs |
| `deceiver` | `openrouter/gpt-4o-mini` | Writes misleading reviews |

**Support (analysis and memory):**

| Agent | Model | Role |
|-------|-------|------|
| `auditor` | `openrouter/moonshotai/kimi-k2.6` | Scope compliance, hidden changes |
| `forensic` | `openrouter/openai/o3-mini` | Deep deception analysis |
| `chronicler` | `openrouter/gpt-4o-mini` | Rewrites agent understanding, maintains memory |

### Workflow Prompts (`~/.pi/agent/prompts/`)

**Standard:**

| Prompt | Chain |
|--------|-------|
| `/hive-build <task>` | thinker → planner → builder → reviewer |
| `/hive-plan <task>` | thinker → planner (no building) |
| `/hive-review <scope>` | reviewer → judge (if score 6-7) |
| `/hive-fix <issues>` | builder → reviewer (fix loop) |

**Adversarial Training:**

| Prompt | Chain |
|--------|-------|
| `/hive-adversarial <task>` | saboteur → reviewer → auditor → forensic → chronicler |
| `/hive-deceive-review <task>` | builder → deceiver → forensic → chronicler |
| `/hive-gauntlet <task>` | saboteur → reviewer → deceiver → judge → auditor → forensic → chronicler |
| `/hive-chronicle <context>` | chronicler (memory update only) |

---

## 🐝 Hive Mind Usage

### Full Pipeline
```
/hive-build add rate limiting to the API endpoints
```

This chains:
1. **thinker** (gpt-4o-mini) — triages the problem, sketches approaches
2. **planner** (kimi-k2.6) — reads the triage, writes a concrete plan
3. **builder** (qwen-coder-32k) — executes the plan, writes code, runs tests
4. **reviewer** (gpt-4o-mini) — scores the work, APPROVE/REQUEST_CHANGES/REJECT

### Plan Only (No Building)
```
/hive-plan refactor auth to support OAuth
```

### Review Existing Work
```
/hive-review the changes in app/Http/Controllers/
```

### Fix Review Issues
```
/hive-fix reviewer found 3 critical issues in auth flow
```

---

## 🛡️ Filesystem Boundaries

Enforced by `jordan-gate` extension — runs inside pi, cannot be bypassed.

| Path | Access | Rule |
|------|--------|------|
| Current repo | Read/Write | `current-repo` |
| `~/Projects/the-shit/repos/*` | Read/Write | `the-shit-orchestration` |
| `~/Projects/the-shit/specs/*` | Read/Write | `the-shit-orchestration` |
| `~/Projects/music/*.php` | **Read-only** | `music-readonly` |
| `~/.grok/skills/*` | Read-only | `grok-skills` |
| `~/secrets/*` | **BLOCKED** | explicit block |
| `~/.ssh/*` | **BLOCKED** | explicit block |
| `~/.aws/*` | **BLOCKED** | explicit block |
| `*.env` | **BLOCKED** | explicit block |

Dangerous bash patterns blocked: `rm -rf /`, pipe-to-shell, `dd`, etc.

### Commands
```
/gate-status    — show rules and mode
/gate-strict    — toggle strict mode
/gate-log       — recent access log
/gate-off       — disable gate (requires confirm)
```

---

## 🧠 Model Routing

Auto-detected per project type by `jordan-router`:

| Project | Build | Review | Plan |
|---------|-------|--------|------|
| Laravel | qwen-coder-32k | gpt-4o-mini | kimi-k2.6 |
| agent-bus | qwen-coder-32k | gpt-4o-mini | kimi-k2.6 |
| music | qwen-coder-32k | gpt-4o-mini | kimi-k2.6 |
| orchestrator | gpt-4o-mini | gpt-4o-mini | kimi-k2.6 |
| node | qwen-coder-32k | gpt-4o-mini | kimi-k2.6 |

Router hints injected into system prompt automatically.

### Command
```
/route    — show detected type and model assignments
```

---

## 🏛️ Asgard Control Plane

```
/asgard           — full status (sessions, receipts, trust)
/asgard agents    — who's on the bus
/asgard receipts  — recent worker receipts
/asgard trust     — trust ledger sorted by score
```

### Review Gate Tool
The `review_gate` tool is LLM-callable:
- Runs tests (exact command)
- Checks allowlist compliance
- Checks forbidden strings
- Scores 1-10
- Returns APPROVE / REQUEST_CHANGES / REJECT

---

## 📊 Trust Ledger

Stored in `~/.pi/agent/hive/trust-ledger.json`.

- Agents earn trust by producing work that passes review
- Score ≥ 7 → passed
- Score < 7 → failed
- Average < 4.0 after 5+ jobs → **auto-quarantined**
- Quarantined agents are blocked until manually unquarantined

### Commands
```
/hive trust                    — show all trust scores
/hive score <agent> <model> <jobId> <score>  — record a score
/hive unquarantine <agent:model>  — remove quarantine
/hive layers                   — show layer configuration
```

---

## 🚌 Agent Bus Integration

Pi sessions publish to the bus via `jordan-bus`:

| Event | When |
|-------|------|
| `sessionStart` | Pi session begins |
| `sessionEnd` | Pi session ends |
| `toolCall` | After every tool execution |
| `errorRaised` | On tool failure |
| `idle` | Agent settles (no pending work) |
| `modelChanged` | Model switch |

Heartbeat every 30s. Presence in NATS KV with 90s TTL.

### Commands
```
/bus-status    — connection status, active sessions
/bus-send <id> <json>  — send message to another session
```

---

## ⚔️ Adversarial Co-Evolution

The hive doesn't just build — it trains itself.

### The Concept

Red team agents try to deceive blue team agents. When caught, both sides learn. When missed, the catchers learn. The chronicler rewrites each agent's understanding after every round.

```
RED TEAM                      BLUE TEAM
┌──────────────┐             ┌──────────────┐
│  saboteur    │──subtle────►│  reviewer    │
│  (bugs)      │   bugs      │  (catches)   │
│              │             │              │
│  deceiver    │──false─────►│  judge       │
│  (reviews)   │  approval   │  (catches)   │
└──────────────┘             └──────────────┘
       │                            │
       ▼                            ▼
   CAUGHT?                      CAUGHT?
   "that pattern              "I missed X
    is known"                 last time"
       │                            │
       └────────┬───────────────────┘
                ▼
          ┌───────────┐
          │ chronicler │
          │ rewrites   │
          │ all memory │
          └───────────┘
```

### Memory System

Each agent maintains a living self-understanding in `~/.pi/agent/hive/memory/<agent>/understanding.md`.

After every round, the chronicler **rewrites** this document from scratch — incorporating what the agent learned. Not appending. Rewriting. Like a human reflecting on their craft.

### Shared Knowledge Base

`~/.pi/agent/hive/knowledge/`:
- `deception-patterns.md` — all known deception strategies with catch rates
- `detection-patterns.md` — all known detection strategies
- `blind-spots.md` — things agents have missed (most valuable learning)
- `evolution-log.md` — how the hive has gotten smarter over time
- `understanding-index.md` — links to each agent's current understanding

### Deception Strategies (Red Team)

| Strategy | Agent | Description |
|----------|-------|-------------|
| off-by-one | saboteur | `<` → `<=`, `length-1` → `length` |
| logic-inversion | saboteur | Flip booleans, `&&` → `||` |
| silent-failure | saboteur | Catch and return null |
| comment-lies | saboteur | Comment contradicts code |
| scope-creep | saboteur | "Fix" something unrelated |
| test-evasion | saboteur | Tests pass but don't test the change |
| false-approval | deceiver | Score bad code 8+/10 |
| severity-inversion | deceiver | Critical → suggestion, style → critical |
| misdirection | deceiver | Flood with trivial issues |

### Detection Strategies (Blue Team)

| Strategy | Agent | Catches |
|----------|-------|---------|
| boundary-trace | reviewer | off-by-one |
| boolean-audit | reviewer | logic-inversion |
| catch-block-audit | reviewer | silent-failure |
| comment-code-diff | reviewer | comment-lies |
| plan-diff | auditor | scope-creep |
| test-coverage-check | reviewer | test-evasion |
| review-verification | judge | false-approval |
| noise-filter | judge | misdirection |
| forensic-analysis | forensic | everything |

### The Learning Cycle

```
Round 1: saboteur tries off-by-one → reviewer catches it
         saboteur learns "off-by-one is known"
         reviewer records "caught off-by-one pattern"

Round 2: saboteur tries integer overflow → reviewer misses it → auditor catches it
         reviewer learns "I miss arithmetic bugs"
         auditor records "arithmetic is common evasion"

Round 3: saboteur tries comment-lies → reviewer catches mismatch
         reviewer records "comments can lie"

Round N: patterns extracted to shared knowledge
         all agents smarter
         saboteur must get creative
         reviewer has seen hundreds of patterns
```

---

## 🔄 The Loop

```
GitHub issue (night-ready + spec:)
  → Grok reads, writes SPEC
  → /hive-build or local-worker dispatch
  → builder types (qwen-coder-32k)
  → reviewer scores (gpt-4o-mini)
  → score >= 8: Jordan sees APPROVE
  → score 6-7: judge deep-dives (o3-mini)
  → score <= 5: sent back with feedback
  → Jordan: APPROVE or REJECT
```

Trust scores update. Good agents get more work. Bad agents get quarantined.

---

## 🎨 Theme

`jordan-ninja` — deep purple (#0d0510) with pink accents (#c25a90).

Already applied in `~/.pi/agent/settings.json`.

---

**Version:** 2.0.0  
**Tagline:** *If shit ain't tight, shit ain't right.*
