# OpenClaw — patches and configuration reference

This document records **customizations applied to the OpenClaw gateway** (Hetzner VM `178.104.115.113`, user `openclaw`) so you can **back out**, **reapply after a rebuild**, or **reconcile after `npm i -g openclaw` upgrades**.

**Secrets:** API keys, bot tokens, and gateway tokens are **not** copied here. They live under `~/.openclaw/` on the server. Rotate any credential that was pasted into chat or logs.

---

## File and path quick reference

| Area | Path on gateway host |
|------|------------------------|
| Main config | `~/.openclaw/openclaw.json` |
| Agent auth (API keys) | `~/.openclaw/agents/main/agent/auth-profiles.json` |
| Global OpenClaw install | `/usr/lib/node_modules/openclaw/` |
| Gateway (user systemd) | `systemctl --user openclaw-gateway.service` — on **178.104.115.113** a drop-in sets **`TimeoutStopSec=120`** (`~/.config/systemd/user/openclaw-gateway.service.d/override.conf`) so deferred restarts (e.g. after `plugins.allow` edits) can shut down without hitting the stock 30s cap when Telegram I/O lags. See [OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md) (operational + recovery) if the unit is **failed** or **inactive**. |
| Workspace | `~/.openclaw/workspace` (per `agents.defaults.workspace`) |
| Plugins (npm / extensions) | `~/.openclaw/extensions/<plugin-id>/` |

Related overview: [OPENCLAW-HETZNER-ENV.md](./OPENCLAW-HETZNER-ENV.md), deploy steps: [OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md).

---

## 1. Model aliases (`openclaw` CLI / `openclaw.json`)

Aliases are stored under **`agents.defaults.models`** (each entry is a model id with optional `"alias"`).

### 1.1 Google Gemini

Configured with a **Google API key** in `auth-profiles.json` (profile `google:default`) and **`plugins.entries.google.enabled: true`** in `openclaw.json`.

**Aliases added (representative commands):**

```bash
openclaw models aliases add gemini-3-flash-preview google/gemini-3-flash-preview
```

**Default primary model** (as set in this environment): `deepseek/deepseek-reasoner` — key `agents.defaults.model.primary`. (Previously Gemini flash-lite; switched for cost.)

**Other Gemini aliases in use:** `gemini` → `google/gemini-3.1-pro-preview`, `gemini-flash-lite` → `google/gemini-3.1-flash-lite-preview`, plus `gemini-3-flash-preview` as above.

**Back out:** remove the corresponding entries from `agents.defaults.models`, or:

```bash
openclaw models aliases remove <alias>
```

(Use `openclaw models aliases list` for current state.)

### 1.2 DeepSeek (official API)

**Provider:** `deepseek` — API key stored in **`auth-profiles.json`** as profile **`deepseek:default`** (`type: api_key`, `provider: deepseek`).

**`openclaw.json`:** `auth.profiles["deepseek:default"]` with `mode: api_key`, and **`plugins.entries.deepseek.enabled: true`**.

**Aliases added:**

```bash
openclaw models aliases add deepseek-v3.2 deepseek/deepseek-chat
openclaw models aliases add deepseek-reasoner deepseek/deepseek-reasoner
```

- **`deepseek/deepseek-chat`** — DeepSeek Chat (V3.2-class in OpenClaw’s catalog).
- **`deepseek/deepseek-reasoner`** — reasoning model.

**Back out:** remove `deepseek:default` from `auth-profiles.json` and from `openclaw.json` `auth.profiles`; set `plugins.entries.deepseek.enabled` to `false` or remove the block; remove the two model entries / run `openclaw models aliases remove` for both aliases.

### 1.3 MiniMax (Token Plan — OAuth)

**Use case:** Use MiniMax billing via **[Token Plan + OpenClaw](https://platform.minimax.io/docs/token-plan/openclaw)** (browser OAuth, no API key on disk). Upstream OpenClaw docs: [MiniMax provider](https://docs.openclaw.ai/providers/minimax).

**On the gateway as `openclaw`:**

1. Enable the bundled MiniMax plugin and restart the gateway (or run [`scripts/enable-minimax-token-plan.sh`](./scripts/enable-minimax-token-plan.sh)).

2. **Add MiniMax only (no full onboarding)** — prefer the **model** section of the config wizard ([`openclaw configure`](https://docs.openclaw.ai/cli/configure)):

```bash
openclaw configure --section model
```

In the prompts, choose **MiniMax** and **CN vs Global OAuth** (or API key). This does **not** rerun gateway install, channels, skills, or the whole quickstart.

3. **Alternative — OAuth from the shell without `configure`** (still skips most of onboarding):

| Region | `openclaw onboard --auth-choice …` |
|--------|--------------------------------------|
| **CN** | `minimax-cn-oauth` |
| **Global** | `minimax-global-oauth` |

```bash
openclaw plugins enable minimax   # skip if already enabled
openclaw gateway restart          # or: systemctl --user restart openclaw-gateway.service
openclaw onboard --auth-choice minimax-cn-oauth \
  --skip-channels --skip-skills --skip-ui --skip-health --skip-search --skip-daemon
# Global: use minimax-global-oauth instead
```

`--skip-daemon` avoids touching systemd gateway install when it is already set up. Adjust flags if you still need a step (see `openclaw onboard --help`).

Older docs mention `minimax-portal` with a region picker; current CLI uses **region-specific** `minimax-*-oauth` choices or **`configure --section model`**.

4. Set the default text model if needed (examples):

```bash
openclaw models set minimax/MiniMax-M2.7
# or: openclaw config set agents.defaults.model.primary minimax/MiniMax-M2.7
```

**Model refs:** `minimax/MiniMax-M2.7`, `minimax/MiniMax-M2.7-highspeed`; image: `minimax/image-01` (see provider doc for `imageGenerationModel`).

**Alternative — API key (Anthropic-compatible):** store **`MINIMAX_API_KEY`** and configure **`models.providers.minimax`** with `baseUrl: https://api.minimax.io/anthropic`, `api: anthropic-messages`, and **`models.mode: merge`** if you keep other providers. Prefer `openclaw configure` → Model/auth → MiniMax. See [MiniMax provider](https://docs.openclaw.ai/providers/minimax).

**Back out (OAuth):** remove MiniMax auth from `auth-profiles.json` / `openclaw.json` per your install; `openclaw plugins disable minimax` if you no longer want the plugin; clear `agents.defaults.model.primary` fallbacks pointing at `minimax/...` if you switch providers.

---

## 2. Telegram channel configuration

Applied via CLI and/or [`scripts/configure-telegram.sh`](./scripts/configure-telegram.sh) (run on the **gateway** as `openclaw`).

**Typical settings in this deployment:**

| Setting | Value / note |
|---------|----------------|
| `channels.telegram.enabled` | `true` |
| `channels.telegram.dmPolicy` | `pairing` |
| `channels.telegram.groups` | `{"*":{"requireMention":true}}` (set with `--strict-json` from a JSON file to avoid quoting bugs) |
| Bot token | `channels.telegram.botToken` **or** env `TELEGRAM_BOT_TOKEN` (see upstream docs) |

**Pairing:** `openclaw pairing list telegram` / `openclaw pairing approve telegram <CODE>`.

### 2.1 Plugin registry entry (required for cron **announce** → Telegram)

Isolated cron jobs with `--announce --channel telegram` use the **outbound** adapter from the Telegram **plugin** registry. If `plugins.allow` lists `telegram` but **`plugins.entries.telegram` is missing**, delivery fails with:

`delivery failed (bestEffort): Outbound not configured for channel: telegram`

**Fix (gateway as `openclaw`):**

```bash
openclaw config set plugins.entries.telegram.enabled true
systemctl --user restart openclaw-gateway.service
```

Confirm with a manual run: `openclaw cron run <job-id>` — `openclaw cron list --json` should show `lastDelivered: true` and `lastDeliveryStatus: "delivered"`.

**Back out (plugin entry):** `openclaw config unset plugins.entries.telegram` (or set `enabled` to `false`) and restart the gateway.

**Back out (channel):** disable the channel or remove the Telegram block per [Telegram](https://docs.openclaw.ai/channels/telegram) docs; revoke/rotate the bot token in BotFather if it was exposed.

---

## 3. Global npm package patches (Telegram `</final` fragments)

**Why:** Telegram sends messages with **`parse_mode: HTML`**. Model output sometimes included **truncated** `</final`-style fragments (no closing `>`). OpenClaw’s built-in stripper only removed **complete** `<final>...</final>` tags. Those fragments leaked into Telegram while the console looked fine.

**Files patched under:** `/usr/lib/node_modules/openclaw/dist/`

| File | Change |
|------|--------|
| `text-runtime-B-kOpuLv.js` | In `stripReasoningTagsFromText`, after the `FINAL_TAG_RE` block, add: `cleaned = cleaned.replace(/<\s*\/?\s*final\b[^<>]*$/gi, "");` so **trailing incomplete** `final` tags are removed. |
| `pi-embedded-BaSvmUpW.js` | Extend `REASONING_TAG_PREFIXES` with `"<final"` and `"</final"` so streaming treats partial `final` tags like partial thinking tags. |

**Automated reapply:** [`scripts/patch-openclaw-telegram-final-tags.sh`](./scripts/patch-openclaw-telegram-final-tags.sh) (requires **sudo**; creates backups next to the patched files).

**Backups created by the script (on first run):**

- `text-runtime-B-kOpuLv.js.bak-telegram-final`
- `pi-embedded-BaSvmUpW.js.bak-telegram-final`

**Rollback (restore upstream copies from backup):**

```bash
sudo cp -a /usr/lib/node_modules/openclaw/dist/text-runtime-B-kOpuLv.js.bak-telegram-final \
  /usr/lib/node_modules/openclaw/dist/text-runtime-B-kOpuLv.js
sudo cp -a /usr/lib/node_modules/openclaw/dist/pi-embedded-BaSvmUpW.js.bak-telegram-final \
  /usr/lib/node_modules/openclaw/dist/pi-embedded-BaSvmUpW.js
systemctl --user restart openclaw-gateway.service
```

**After `npm i -g openclaw@...`:** upstream files are overwritten — **re-run** `scripts/patch-openclaw-telegram-final-tags.sh`. If the script exits with “expected snippet not found”, the bundle was renamed or refactored; inspect the new `dist/` filenames and update the script.

---

## 4. Rebuild / new VM checklist

1. Install Node + global `openclaw` per [OPENCLAW-DEPLOYMENT.md](./OPENCLAW-DEPLOYMENT.md).
2. Restore or recreate **`~/.openclaw/openclaw.json`** and **`auth-profiles.json`** (or re-run onboarding and re-add channels / keys).
3. Re-add **model aliases** (section 1) or merge `agents.defaults.models` from a redacted backup.
4. Re-enable **Telegram** and **plugins** (Google, DeepSeek, MiniMax Token Plan / §1.3) as needed.
5. Run **`patch-openclaw-telegram-final-tags.sh`** (section 3).
6. **`systemctl --user restart openclaw-gateway.service`** (and `loginctl enable-linger openclaw` if applicable).
7. Optional: Homebrew + Himalaya — **removed** on this host (2026-03-28); reinstall with [`scripts/install-homebrew-himalaya-ubuntu.sh`](./scripts/install-homebrew-himalaya-ubuntu.sh) if needed.
8. Optional: [Sendmail localhost MTA](#8-sendmail-localhost-mta-for-himalaya--sendmail) if you use Himalaya / `sendmail` handoff.

---

## 5. Scripts in this repo

| Script | Purpose |
|--------|---------|
| [`scripts/configure-telegram.sh`](./scripts/configure-telegram.sh) | Register Telegram channel + quick defaults + restart gateway |
| [`scripts/patch-openclaw-telegram-final-tags.sh`](./scripts/patch-openclaw-telegram-final-tags.sh) | Reapply Telegram HTML / `final`-tag patches to global `openclaw` |
| [`scripts/install-homebrew-himalaya-ubuntu.sh`](./scripts/install-homebrew-himalaya-ubuntu.sh) | Install [Homebrew on Linux](https://docs.brew.sh/Homebrew-on-Linux) + [Himalaya](https://github.com/soywod/himalaya) (idempotent) |
| [`scripts/configure-sendmail-ubuntu.sh`](./scripts/configure-sendmail-ubuntu.sh) | Fix sendmail FQDN + systemd; localhost MTA for Himalaya ([§8](#8-sendmail-localhost-mta-for-himalaya--sendmail)); env `HOST_FQDN` / `HOST_SHORT` |
| [`scripts/apply-hostname-de-hansenh-xyz.sh`](./scripts/apply-hostname-de-hansenh-xyz.sh) | Set **`de.hansenh.xyz`** as static hostname + hosts + mail (this deployment) |
| [`scripts/set-plugins-allow-weixin.sh`](./scripts/set-plugins-allow-weixin.sh) | Set `plugins.allow` to `["openclaw-weixin"]` (trust Weixin plugin) |
| [`scripts/enable-minimax-token-plan.sh`](./scripts/enable-minimax-token-plan.sh) | Enable MiniMax plugin + restart gateway; then `openclaw configure --section model` or skipped `onboard` (§1.3) |
| [`scripts/provision-openclaw-user.sh`](./scripts/provision-openclaw-user.sh) | Server user provisioning (if used) |
| [`scripts/dump_openclaw_memory_sqlite.py`](./scripts/dump_openclaw_memory_sqlite.py) | Inspect memory DB (optional tooling) |
| [`scripts/deploy-feed-filter-rubric-skill.ps1`](./scripts/deploy-feed-filter-rubric-skill.ps1) | `scp` **feed-filter-rubric-prompt** skill to `~/.openclaw/workspace/skills/` on the gateway ([§11](#11-feed-filter-rubric-prompt-skill-from-this-repo)) |

---

## 7. Homebrew (Linuxbrew) and Himalaya CLI

**Status on gateway (`178.104.115.113`):** **removed** (2026-03-28): `brew uninstall himalaya`, official [uninstall script](https://github.com/Homebrew/install#uninstall-homebrew) piped to `bash`, `sudo rm -rf /home/linuxbrew`, and the **Homebrew block removed from `~/.bashrc`** (user `openclaw`).

**Reinstall:** [`scripts/install-homebrew-himalaya-ubuntu.sh`](./scripts/install-homebrew-himalaya-ubuntu.sh) (still in repo for other hosts or a fresh install).

---

## 8. Sendmail (localhost MTA for Himalaya / `sendmail`)

**Purpose:** Local submission on **`127.0.0.1:25`** and **`127.0.0.1:587`** (default Debian sendmail package), so tools like **Himalaya** can hand off mail via `/usr/sbin/sendmail`.

### What was wrong

- **systemd failed** while the **MTA was already running** (stale `sendmail-mta` after a stop), so `systemctl start sendmail` returned *MTA is already running*.
- Logs showed **`unable to qualify my own domain name`** because the host had no resolvable FQDN (short hostname only).

### What we changed

| Item | Change |
|------|--------|
| **`hostnamectl` / `/etc/hostname`** | Static hostname **`de.hansenh.xyz`** (short name **`de`**). |
| **`/etc/mail/sendmail.mc`** | `define(\`confDOMAIN_NAME',\`de.hansenh.xyz')dnl` after `DOMAIN(\`debian-mta')dnl`, then `make -C /etc/mail`. |
| **`/etc/mailname`** | `de.hansenh.xyz` |
| **`/etc/hosts`** | `127.0.1.1 de.hansenh.xyz de` so `hostname -f` resolves to the public FQDN. |
| **`/etc/cloud/templates/hosts.debian.tmpl`** | Same **127.0.1.1** line for **cloud-init** / `manage_etc_hosts`. |
| **Service** | `systemctl enable --now sendmail` after clearing stuck processes. |

**Reapply / another host:** run [`scripts/configure-sendmail-ubuntu.sh`](./scripts/configure-sendmail-ubuntu.sh) as **root** (defaults: `HOST_FQDN=de.hansenh.xyz`, `HOST_SHORT=de`). For a one-shot hostname + mail sync use [`scripts/apply-hostname-de-hansenh-xyz.sh`](./scripts/apply-hostname-de-hansenh-xyz.sh).

**Internet delivery:** many providers (including **Hetzner**) **block outbound TCP 25** by default. Local delivery and **smarthost relay** (SMTP AUTH to port 587/465 upstream) are unaffected; **direct** delivery to the public internet may require unblocking port 25 in the provider panel or using a relay. This setup validates **local** submission only.

---

## 9. Outlook skill (ClawHub — [jotamed/outlook](https://clawhub.ai/jotamed/outlook))

Replaces raw SMTP to Hotmail with **Microsoft Graph** (read/send mail + calendar). Listed on [ClawHub](https://clawhub.ai/jotamed/outlook); OpenClaw Security notes: [skill page](https://clawhub.ai/jotamed/outlook) (review scripts before use).

### Installed on gateway

| Item | Value |
|------|--------|
| **Install command** | `cd ~/.openclaw/workspace && openclaw skills install outlook` (slug is `outlook`; `jotamed/outlook` is the web path). If ClawHub returns **429**, retry after a minute. |
| **Path** | `~/.openclaw/workspace/skills/outlook/` |
| **Deps (host)** | `jq`, **Azure CLI** (`az`) — installed via apt / Microsoft package so `./scripts/outlook-setup.sh` can run. |
| **OAuth / tokens** | Setup writes **`~/.outlook-mcp/`** (see skill `SKILL.md`). |

### One-time setup (interactive)

On the server as **`openclaw`** (needs browser or device-code flow for Microsoft login):

```bash
cd ~/.openclaw/workspace/skills/outlook
./scripts/outlook-setup.sh
```

Then use the scripts under `scripts/` per **`SKILL.md`** (e.g. `outlook-mail.sh`, `outlook-token.sh refresh`). Restart the gateway after install if the skill list does not refresh: `systemctl --user restart openclaw-gateway.service`.

**Remove:** delete `~/.openclaw/workspace/skills/outlook`, revoke the app registration in [Azure Portal](https://portal.azure.com) if you created one, and remove `~/.outlook-mcp/`.

---

## 10. Weixin channel ([`@tencent-weixin/openclaw-weixin`](https://www.npmjs.com/package/@tencent-weixin/openclaw-weixin))

Tencent’s **微信** plugin for OpenClaw (scan QR to log in). Docs: [npm README](https://www.npmjs.com/package/@tencent-weixin/openclaw-weixin) (also lists backend API notes for custom gateways).

### On this gateway

| Step | Command / note |
|------|----------------|
| **Install** | `openclaw plugins install "@tencent-weixin/openclaw-weixin"` |
| **Enable** | `plugins.entries.openclaw-weixin.enabled` → `true` (set by installer) |
| **Trust list** | `plugins.allow` → `["openclaw-weixin"]` (stops “plugins.allow is empty” warnings); applied via [`scripts/set-plugins-allow-weixin.sh`](./scripts/set-plugins-allow-weixin.sh) |
| **Extension path** | `~/.openclaw/extensions/openclaw-weixin/` |
| **Login (interactive)** | `openclaw channels login --channel openclaw-weixin` — shows **QR in terminal**; scan with WeChat and confirm. Repeat to add more accounts. |
| **Restart** | `systemctl --user restart openclaw-gateway.service` or `openclaw gateway restart` after install/login changes |

**Optional:** isolate memory per WeChat peer: `openclaw config set agents.mode per-channel-per-peer` (see npm README).

**Uninstall:** `openclaw plugins uninstall openclaw-weixin` (or follow the package README); remove `openclaw-weixin` from `plugins.allow` if you set it.

---

## 11. Feed filter rubric prompt skill (from this repo)

Custom skill for updating the **`system_prompt`** that controls feed filtering / relevance rubric (**GET → edit JSON → POST** via `contentenhancement_config_get.py` / `contentenhancement_config_post.py`). Not from ClawHub — **deployed by `scp`** from the workstation. Legacy path **`freshrss-contentenhancement-scoring`** is removed on deploy.

| Item | Value |
|------|--------|
| **Path on gateway** | `~/.openclaw/workspace/skills/feed-filter-rubric-prompt/` |
| **Source in repo** | [`skills/feed-filter-rubric-prompt/`](./skills/feed-filter-rubric-prompt/) |
| **Deploy (Windows)** | [`scripts/deploy-feed-filter-rubric-skill.ps1`](./scripts/deploy-feed-filter-rubric-skill.ps1) — requires `openclaw-hetzner-ed25519` |
| **`FRESHRSS_BASE`** | Default in scripts is `http://localhost:8080`. On the gateway, use **`http://127.0.0.1:8080`** when an SSH tunnel maps local **8080** (or **8081** → **8080**) to FreshRSS; see [`config/freshrss/docker-compose.yml`](./config/freshrss/docker-compose.yml) / tunnel scripts. |

**After deploy:** `systemctl --user restart openclaw-gateway.service` if the skill list does not refresh (same pattern as §9 Outlook).

**Remove:** `rm -rf ~/.openclaw/workspace/skills/feed-filter-rubric-prompt`

---

## 12. Changelog

| Date (UTC) | Change |
|------------|--------|
| 2026-03-28 | Document created: Gemini / DeepSeek aliases and auth layout, Telegram defaults, global `dist/` patches + rollback/reapply, rebuild checklist. |
| 2026-03-28 | Homebrew (Linuxbrew) + Himalaya on gateway; [`scripts/install-homebrew-himalaya-ubuntu.sh`](./scripts/install-homebrew-himalaya-ubuntu.sh); section 7. |
| 2026-03-28 | Sendmail: FQDN + hosts/cloud template + [`scripts/configure-sendmail-ubuntu.sh`](./scripts/configure-sendmail-ubuntu.sh); section 8. |
| 2026-03-28 | Public DNS **`de.hansenh.xyz`** → static hostname, hosts, mailname, sendmail `confDOMAIN_NAME`; [`scripts/apply-hostname-de-hansenh-xyz.sh`](./scripts/apply-hostname-de-hansenh-xyz.sh). |
| 2026-03-28 | ClawHub **Outlook** skill (`openclaw skills install outlook`), `jq` + Azure CLI; §9. |
| 2026-03-28 | **Homebrew + Himalaya** removed from gateway; §7. |
| 2026-03-28 | **Weixin** plugin [`@tencent-weixin/openclaw-weixin`](https://www.npmjs.com/package/@tencent-weixin/openclaw-weixin), `plugins.allow`; §10. |
| 2026-03-29 | **Telegram cron announce:** `plugins.entries.telegram.enabled: true` required for isolated job delivery (`Outbound not configured for channel: telegram` otherwise); gateway restart; §2.1. |
| 2026-03-29 | **Daily news cron:** isolated jobs were ignoring `daily_news_aggregation` SKILL layout (newsletter style, `/tmp` footnote from old prompt). Fix: **embed the full Markdown contract + forbidden patterns in `scripts/daily-news-cron-message.txt`** and `openclaw cron edit … --message "$(cat …)"` — payload is authoritative; remove “save to `/tmp`” from the delivered instruction. |
| 2026-03-29 | **MiniMax Token Plan:** §1.3 OAuth via `minimax-global-oauth` or `minimax-cn-oauth` (not `minimax-portal`); [`scripts/enable-minimax-token-plan.sh`](./scripts/enable-minimax-token-plan.sh); [platform](https://platform.minimax.io/docs/token-plan/openclaw), [OpenClaw MiniMax](https://docs.openclaw.ai/providers/minimax). |
| 2026-03-29 | **MiniMax without full onboarding:** `openclaw configure --section model` or `onboard --auth-choice minimax-*-oauth` with `--skip-channels --skip-skills --skip-ui --skip-health --skip-search --skip-daemon`; §1.3. |
| 2026-03-30 | **feed-filter-rubric-prompt** skill (rename from `freshrss-contentenhancement-scoring`): [`skills/feed-filter-rubric-prompt/`](./skills/feed-filter-rubric-prompt/), deploy [`scripts/deploy-feed-filter-rubric-skill.ps1`](./scripts/deploy-feed-filter-rubric-skill.ps1); §11. |
