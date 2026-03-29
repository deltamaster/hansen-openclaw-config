# OpenClaw deployment log — Hetzner VM

Deployment tracked against [OPENCLAW-HETZNER-ENV.md](./OPENCLAW-HETZNER-ENV.md). Host: **178.104.115.113** (**`de.hansenh.xyz`**, Nuremberg). SSH user: **`openclaw`**.

---

## Summary

| Item | Result |
|------|--------|
| **OpenClaw (CLI)** | `2026.3.24` (`cff6dc9`) — `/usr/bin/openclaw` |
| **Node.js** | `v24.13.0` (NodeSource `node_24.x` apt repo) |
| **npm** | `11.6.2` |
| **Install method** | `sudo npm install -g openclaw@latest` |
| **Onboarding** | Non-interactive; `--auth-choice skip` (no LLM API keys stored in this run) |
| **Gateway** | **Active** — `systemctl --user openclaw-gateway.service` **enabled**, **running** |
| **User lingering** | **yes** (`loginctl` — user services run without an interactive session) |
| **Workspace** | `/home/openclaw/.openclaw/workspace` (default layout under `~/.openclaw/`) |

---

## Timeline (2026-03-28 UTC)

1. **Prerequisites** — Confirmed prior [OPENCLAW-HETZNER-ENV.md](./OPENCLAW-HETZNER-ENV.md) SSH access as `openclaw` with `openclaw-hetzner-ed25519`.

2. **Node.js 24** — Added NodeSource repository and installed `nodejs`:
   - `curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -`
   - `sudo apt-get install -y nodejs`
   - Result: **Node v24.13.0**, npm **11.6.2**.

3. **OpenClaw package** — `sudo npm install -g openclaw@latest` (global install so the `openclaw` user and systemd unit share one CLI).

4. **First onboard attempt** — Initial non-interactive run over SSH disconnected mid-run; no config was left behind (`~/.openclaw` missing). Retried with output captured successfully.

5. **Onboarding (completed)** — Non-interactive onboarding aligned with [CLI automation](https://docs.openclaw.ai/start/wizard-cli-automation) patterns:

   ```bash
   openclaw onboard --non-interactive \
     --accept-risk \
     --mode local \
     --auth-choice skip \
     --workspace /home/openclaw/.openclaw/workspace \
     --gateway-port 18789 \
     --gateway-bind loopback \
     --install-daemon \
     --daemon-runtime node \
     --skip-skills \
     --skip-channels \
     --skip-search \
     --skip-ui
   ```

   Outcomes observed on the host:
   - `~/.openclaw/openclaw.json` updated
   - Workspace and `agents/main/sessions` paths initialized
   - **systemd user unit** installed: `~/.config/systemd/user/openclaw-gateway.service`
   - **Lingering** enabled for `openclaw` (required for user services at boot without login)

6. **Verification** — `systemctl --user status openclaw-gateway.service`: **active (running)**. Gateway WebSocket: `ws://127.0.0.1:18789` (and `[::1]:18789`). Browser control endpoint on loopback: **18791**. Log file (from gateway banner): `/tmp/openclaw/openclaw-2026-03-28.log`.

7. **`openclaw doctor`** — Ran after deployment; reported optional optimizations (e.g. `NODE_COMPILE_CACHE`, `OPENCLAW_NO_RESPAWN` on small VMs) and normal post-install notes (skills/plugins counts, credentials dir when no WhatsApp pairing, etc.).

---

## Network and security notes

Official guidance: keep the Gateway on **loopback** and reach it remotely via **SSH port forwarding** (or Tailscale / VPN), not by publishing OpenClaw ports on the public internet. See **[Remote access](https://docs.openclaw.ai/gateway/remote)** (same content in [中文](https://docs.openclaw.ai/zh-CN/gateway/remote)).

**Principles (from upstream):**

- Gateway WebSocket stays on **`127.0.0.1:18789`** (replace `18789` if you changed `gateway.port`).
- **Do not** rely on iptables DNAT or opening Hetzner ports to “punch through” to loopback — that was an earlier experiment here and has been **removed** from the server.
- Prefer **`ssh -N -L 18789:127.0.0.1:18789 user@host`** so your laptop sees `ws://127.0.0.1:18789` for the remote Gateway. Then `openclaw health`, `openclaw status`, and the Control UI work against the tunnel as documented.
- Optional: **Tailscale Serve** for the Control UI while keeping bind loopback — see [Tailscale](https://docs.openclaw.ai/gateway/tailscale) in the docs.

**Browser control (port 18791)** also listens on loopback only. For remote use, add a **second** local forward (or a second SSH session), e.g. `-L 18791:127.0.0.1:18791`, and open `http://127.0.0.1:18791/` on your machine with token auth per [Browser](https://docs.openclaw.ai/tools/browser) / [Dashboard](https://docs.openclaw.ai/web/dashboard).

**Host firewall:** `iptables-persistent` may still be installed from earlier troubleshooting; **`ufw` was removed** when that package was added. You can use **Hetzner Cloud Firewall** (SSH only from your IP is a good baseline) and/or reinstall **`ufw`** if you want a simple host firewall — avoid duplicating conflicting rules with `iptables-persistent`.

- **Model / API auth:** This deployment used **`--auth-choice skip`**. You still need a real provider and keys for normal agent use. Next steps:
  - Run `openclaw configure` (interactive), or  
  - Re-run `openclaw onboard` with a real `--auth-choice` and provider flags (see [CLI automation](https://docs.openclaw.ai/start/wizard-cli-automation)), or  
  - Set env-based refs with `--secret-input-mode ref` as documented upstream.

**Do not commit** `~/.openclaw/openclaw.json` or credentials if they contain secrets; manage keys via your usual secret store.

---

## Operational commands (on the server as `openclaw`)

```bash
# Gateway service
systemctl --user status openclaw-gateway.service
systemctl --user restart openclaw-gateway.service
journalctl --user -u openclaw-gateway.service -f

# Health
openclaw doctor
```

---

## Remote Gateway access from your PC (recommended)

Matches [Remote access — SSH tunnel](https://docs.openclaw.ai/gateway/remote): forward **18789** to the VM’s loopback Gateway.

**Gateway only (foreground shell on the tunnel):**

```powershell
ssh -i "i:\workspace\hetzner-env\openclaw-hetzner-ed25519" -L 18789:127.0.0.1:18789 openclaw@178.104.115.113
```

**Gateway + browser control** in one connection (both ports forwarded):

```powershell
ssh -i "i:\workspace\hetzner-env\openclaw-hetzner-ed25519" -L 18789:127.0.0.1:18789 -L 18791:127.0.0.1:18791 openclaw@178.104.115.113
```

**Background tunnel** (no remote shell; documented pattern uses `-N`):

```powershell
ssh -N -i "i:\workspace\hetzner-env\openclaw-hetzner-ed25519" -L 18789:127.0.0.1:18789 -L 18791:127.0.0.1:18791 openclaw@178.104.115.113
```

With the tunnel up:

- Control UI / WebSocket: `http://127.0.0.1:18789/` (and `ws://127.0.0.1:18789` for clients) — see [Dashboard](https://docs.openclaw.ai/web/dashboard).
- Browser control UI: `http://127.0.0.1:18791/` — authenticate per docs (gateway token).

To persist CLI defaults pointing at the tunneled URL, see the **`gateway.mode: "remote"`** example in [Remote access](https://docs.openclaw.ai/gateway/remote).

---

## Telegram

Follow **[Telegram (Bot API)](https://docs.openclaw.ai/channels/telegram)** — long polling is default; no `channels login` for Telegram (token in config or env).

### 1) Create a bot token

In Telegram, open **`@BotFather`**, run **`/newbot`**, and save the **bot token**.

### 2) Register the channel on the Gateway (SSH as `openclaw`)

**Option A — CLI (recommended):**

```bash
openclaw channels add --channel telegram --token 'YOUR_BOTFATHER_TOKEN'
```

**Option B — script in this repo** (copied to the server or run from a checkout):

```bash
chmod +x configure-telegram.sh
./configure-telegram.sh 'YOUR_BOTFATHER_TOKEN'
```

(Path locally: [`scripts/configure-telegram.sh`](./scripts/configure-telegram.sh).)

**Option C — env on the host:** set **`TELEGRAM_BOT_TOKEN`** (default account only); config **`channels.telegram.botToken`** overrides when set. See the [Telegram](https://docs.openclaw.ai/channels/telegram) note on resolution order.

### 3) Match the quick-setup defaults (optional)

The docs’ minimal pattern enables the bot, **pairing** for DMs, and **`groups["*"].requireMention: true`** for groups. The script above applies this where supported; otherwise merge into `~/.openclaw/openclaw.json` or use **Control UI → Raw JSON**, for example:

```json5
{
  "channels": {
    "telegram": {
      "enabled": true,
      "botToken": "<from BotFather>",
      "dmPolicy": "pairing",
      "groups": { "*": { "requireMention": true } }
    }
  }
}
```

### 4) Restart Gateway and pair your first DM

```bash
systemctl --user restart openclaw-gateway.service
```

Then DM the bot, and on the server:

```bash
openclaw pairing list telegram
openclaw pairing approve telegram <CODE>
```

Codes expire in about **1 hour**. For groups, add the bot to the group and configure **`channels.telegram.groups`** / **`groupPolicy`** as in the [Telegram](https://docs.openclaw.ai/channels/telegram) guide (privacy mode, mentions, allowlists).

---

## References

- [openclaw/openclaw](https://github.com/openclaw/openclaw) — upstream repo  
- [Remote access](https://docs.openclaw.ai/gateway/remote) — **SSH tunnels, loopback, secure remote Gateway** ([中文](https://docs.openclaw.ai/zh-CN/gateway/remote))  
- [Onboarding (CLI)](https://docs.openclaw.ai/start/wizard) — wizard overview  
- [CLI automation](https://docs.openclaw.ai/start/wizard-cli-automation) — `--non-interactive` examples  
- [Telegram](https://docs.openclaw.ai/channels/telegram) — bot token, pairing, groups  

---

## Changelog

| Date (UTC) | Event |
|------------|--------|
| 2026-03-28 | Node 24 + global `openclaw@latest`, non-interactive onboard with auth skipped, systemd user gateway enabled and verified on loopback. |
| 2026-03-28 | Reverted experimental public-port / iptables DNAT for 18791; docs aligned with [Remote access](https://docs.openclaw.ai/gateway/remote) (SSH tunnel to loopback). |
| 2026-03-28 | Telegram integration steps + [`scripts/configure-telegram.sh`](./scripts/configure-telegram.sh) per [Telegram](https://docs.openclaw.ai/channels/telegram) (requires BotFather token on host). |
