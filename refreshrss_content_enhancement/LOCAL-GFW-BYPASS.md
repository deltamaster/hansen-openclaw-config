# Local FreshRSS + GFW bypass (SOCKS)

This repo’s **local** stack (`docker-compose.yml` in this folder) sends container **outbound** HTTP(S) through a **SOCKS5 proxy on the host** so ContentEnhancement fetches (curl) can reach sites blocked by the GFW.

## What you must run on the host

1. **SOCKS listener on port 1080** — e.g. SSH dynamic forward to a machine outside the GFW:

   ```powershell
   ssh -D 1080 -N user@your-relay-host
   ```

   Leave that session open while you use FreshRSS. If nothing listens on `1080`, outbound fetches through the proxy will fail.

2. **FreshRSS container** — from this directory:

   ```powershell
   docker compose up -d
   ```

   Recreate after compose/config changes:

   ```powershell
   docker compose up -d --force-recreate
   ```

   Or use `.\run-local.ps1` (pull + up).

## How it is wired

| Piece | Role |
|--------|------|
| `HTTP_PROXY` / `HTTPS_PROXY` / `ALL_PROXY` | `socks5h://host.docker.internal:1080` — DNS goes through the proxy (`socks5h`). |
| `extra_hosts: host.docker.internal:host-gateway` | Lets the container reach the host’s loopback listener from Linux Docker. |
| `../config/freshrss/apache-proxy-passenv.conf` | Apache **PassEnv** so PHP `getenv('HTTP_PROXY')` works under **mod_php**. |

## URLs

- Local dev UI: **http://127.0.0.1:8081/**

## Server-style compose (no bind-mounted extension)

Repo root: `config/freshrss/docker-compose.yml` + merge `docker-compose.proxy.yml` — port **8080**, same proxy pattern.

## Troubleshooting

- **Worked yesterday, broken today:** restart the **SSH tunnel** first, then `docker compose restart` in this folder.
- **DNS still wrong:** keep `socks5h://` (not `socks5://`) unless you intentionally resolve on the host.
- **Still no proxy in PHP:** confirm `apache-proxy-passenv.conf` is mounted (see `docker compose.yml` volumes).
