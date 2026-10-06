# Production deployment

Agent Bus runs without Herdr. Odin owns the NATS JetStream broker; Thor owns
the Jev and Pi specialist processes.

## Odin

`nats/compose.yaml` pins the deployed NATS image, binds the client port to
Odin's LAN address, keeps monitoring on loopback, persists JetStream under
`~/.local/share/agent-bus/nats`, and restarts the broker after reboot.

The host-only directory `~/.config/agent-bus/nats` supplies:

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

