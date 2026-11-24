# Production deployment

**Reference only.** Nothing in this repo installs these files. Copy and install
them by hand on the host; the live units on Thor and the broker on Odin are
managed outside this repo.

Agent Bus runs without Herdr. Odin owns the NATS JetStream broker; Thor owns
the Jev and Pi specialist processes.

## Odin

`nats/compose.yaml` pins the deployed NATS image, binds the client port to
`AGENT_BUS_NATS_BIND` (default `127.0.0.1`; set it to the broker host's LAN
address), keeps monitoring on loopback, persists JetStream under
`AGENT_BUS_NATS_DATA` (required), and restarts the broker after reboot.

The host-only directory `AGENT_BUS_NATS_CONFIG` (required) supplies:

- `nats-server.conf` from this directory
- `auth.conf` containing the `agent-bus` user and a bcrypt password hash
- `tls/ca.crt`, `tls/server.crt`, and `tls/server.key`

The plaintext client password and CA certificate are distributed separately to
Thor. Neither belongs in Git.

## Thor

`~/.config/agent-bus/agent-bus.env` is mode `0600` and supplies `NATS_URL`,
`NATS_USER`, `NATS_PASS`, `NATS_TLS_CA`, and `TYPESAFE_API_KEY`. The systemd
units in `systemd/` load that file and run independently of Herdr.

Install the units under `~/.config/systemd/user/`, then run:

```bash
systemctl --user daemon-reload
systemctl --user enable --now agent-bus-judge.service agent-bus-qwen-reviewer.service
```

