# Hetzner VM — OpenClaw deployment notes

Reference for this environment and how to reach it from your workstation.

For **custom OpenClaw patches, model aliases, and server-side config** (rollback and rebuild), see **[OPENCLAW-PATCHES-AND-CONFIG.md](./OPENCLAW-PATCHES-AND-CONFIG.md)**.

## Server

| Item | Value |
|------|--------|
| **Provider** | Hetzner Cloud |
| **Public IPv4** | `178.104.115.113` |
| **DNS (A)** | **`de.hansenh.xyz`** → this host |
| **Hostname (system)** | **`de.hansenh.xyz`** (`hostname -s` → `de`; Hetzner instance name was `ubuntu-8gb-nbg1-1`) |
| **Region (from name)** | Nuremberg (`nbg1`) |
| **OS** | Ubuntu 24.04.3 LTS (Noble) |
| **Kernel** | Linux 6.8.0-90-generic x86_64 |
| **vCPU** | 4 |
| **RAM** | ~7.6 GiB |
| **Root disk** | ~75 GiB on `/` (`/dev/sda1`) |
| **Swap** | None configured |
| **Host firewall** | **`ufw` may be absent** if `iptables-persistent` was installed earlier; prefer **Hetzner Cloud Firewall** (e.g. **SSH 22** only from your IP). Do **not** expose OpenClaw ports publicly — use **SSH tunnels** per [Remote access](https://docs.openclaw.ai/gateway/remote) ([中文](https://docs.openclaw.ai/zh-CN/gateway/remote)). |
| **Deployment user** | `openclaw` (home `/home/openclaw`; use for installs and day-to-day SSH) |

## SSH access

### Primary — `openclaw` (recommended)

Dedicated Ed25519 keypair for this user, stored in this workspace:

| File | Role |
|------|------|
| `openclaw-hetzner-ed25519` | Private key (treat as secret) |
| `openclaw-hetzner-ed25519.pub` | Public key (installed on server as `~openclaw/.ssh/authorized_keys`) |

- **User:** `openclaw`
- **Sudo:** passwordless (`/etc/sudoers.d/99-openclaw`) so you can run `sudo` without a password (account has **no** password; SSH key only).

**Connect (PowerShell / Windows OpenSSH)** — fix key permissions if OpenSSH complains, then:

```powershell
$key = "i:\workspace\hetzner-env\openclaw-hetzner-ed25519"
icacls $key /inheritance:r
icacls $key /grant:r "$($env:USERNAME):(R)"
ssh -i "i:\workspace\hetzner-env\openclaw-hetzner-ed25519" openclaw@178.104.115.113
```

**Connect (Git Bash / WSL / Linux / macOS)**

```bash
chmod 600 path/to/openclaw-hetzner-ed25519
ssh -i path/to/openclaw-hetzner-ed25519 openclaw@178.104.115.113
```

### Bootstrap / emergency — `root`

Hetzner image default; original key only:

- **Private key (local):** `hetzner-private.pem` (`i:\workspace\hetzner-env\hetzner-private.pem`)

```powershell
$key = "i:\workspace\hetzner-env\hetzner-private.pem"
icacls $key /inheritance:r
icacls $key /grant:r "$($env:USERNAME):(R)"
ssh -i "i:\workspace\hetzner-env\hetzner-private.pem" root@178.104.115.113
```

Use `root` only when you must (e.g. recovery). Prefer **`openclaw`** for OpenClaw install and operations.

### Provisioning reference

User was created with `useradd` (non-interactive), added to `sudo`, and the public key was installed server-side. A repeatable script lives at `scripts/provision-openclaw-user.sh` (run as `root` with the `.pub` file present at `/tmp/openclaw-hetzner-ed25519.pub`).

**Security:** Do not commit `hetzner-private.pem`, `openclaw-hetzner-ed25519`, or any other private keys to version control. Keep offline backups of both private keys.

---

## OpenClaw

- **Project:** [openclaw/openclaw](https://github.com/openclaw/openclaw) — personal AI assistant gateway (“lobster” stack).
- **Docs / onboarding:** See the repo README and [Getting started](https://github.com/openclaw/openclaw#getting-started) on GitHub.
- **This environment:** See **[OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md)** for install steps, versions, systemd gateway, loopback ports, and **[Telegram](https://docs.openclaw.ai/channels/telegram)** setup (bot token + pairing).

### Runtime expectations (from upstream)

- **Node.js:** 24 recommended, or **22.16+**
- **Install (global):** `npm install -g openclaw@latest` (or `pnpm add -g openclaw@latest`)
- **Guided setup:** `openclaw onboard --install-daemon`
- **Gateway (example):** `openclaw gateway --port 18789 --verbose` (default control plane port **18789**)

### This VM (deployed)

- **OpenClaw** `2026.3.24` installed globally; **systemd user unit** `openclaw-gateway.service` enabled; gateway listens on **loopback** `127.0.0.1:18789` (WS) and `127.0.0.1:18791` (browser control). Config and workspace under `/home/openclaw/.openclaw/`.
- Onboarding used **`--auth-choice skip`** — add a real model/API via `openclaw configure` or a new non-interactive onboard with provider keys (see deployment log).
- **Remote access:** keep **`gateway.bind`** on **loopback** and use **SSH local forwarding** to **18789** (and **18791** if you need the browser control UI) — see [Remote access](https://docs.openclaw.ai/gateway/remote) and [OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md#remote-gateway-access-from-your-pc-recommended). Do not publish OpenClaw ports on the public internet unless you follow upstream hardening (auth, Tailscale, etc.).

### Follow-ups

1. Configure at least one LLM provider (API key or OAuth) so agents can run meaningful work.
2. Optional: `NODE_COMPILE_CACHE` and `OPENCLAW_NO_RESPAWN=1` on small VMs (`openclaw doctor` suggestions).
3. Harden network exposure before binding the gateway beyond loopback.

---

## Changelog

| Date | Note |
|------|------|
| 2026-03-28 | Initial snapshot after VM creation; SSH verified as `root` with workspace PEM. |
| 2026-03-28 | Added Linux user `openclaw` (sudo, SSH key auth); new Ed25519 keypair `openclaw-hetzner-ed25519` in workspace; prefer `openclaw` over `root` for deployment. |
| 2026-03-28 | OpenClaw deployed (Node 24, `openclaw@latest`, systemd gateway on loopback); details in [OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md). |
| 2026-03-28 | Docs corrected to match [Remote access](https://docs.openclaw.ai/gateway/remote): SSH tunnel to loopback; experimental iptables NAT for 18791 reverted on host. |
| 2026-03-28 | Added [OPENCLAW-PATCHES-AND-CONFIG.md](./OPENCLAW-PATCHES-AND-CONFIG.md) (patches, aliases, rebuild checklist). |
| 2026-03-28 | Public DNS **de.hansenh.xyz** → system hostname **de.hansenh.xyz** (short **de**); mail `mailname` / sendmail aligned. |
