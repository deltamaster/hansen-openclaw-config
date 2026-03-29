---
name: send-meme
description: >-
  Deliver a meme GIF you have already chosen: build MEME_URL (HTTPS under de.hansenh.xyz/meme/),
  send as a separate outbound media message on Telegram or Weixin—native channel tool preferred,
  else one-line openclaw CLI with --channel, --target, --media. Do not run help/exploratory
  commands before send. Use when the exact asset is known; do not use for picking memes (SOUL + catalog).
---

# Send meme (execution only)

This skill is **only** the mechanics of **delivering** a known GIF. **Choosing** which meme fits the moment is instinct from **SOUL** (embedded catalog)—do not open `memes.json` for every reply.

## Speed (mandatory)

- **Do not** run `openclaw message send --help`, `openclaw --help`, or any exploratory shell command before sending.
- **Do not** “check” flags first. See [references/urls-and-rules.md](references/urls-and-rules.md) and the channel file below; execute **one** send action immediately.
- Prefer the **native channel tool** (e.g. Telegram **`sendMessage`** with **`mediaUrl`**) when available—no subprocess.
- If you must use the CLI, run **exactly one** line: `openclaw message send … --media "<MEME_URL>"` — nothing before it.

## Where to read next

| Situation | File |
|-----------|------|
| MEME_URL, target, common rules | [references/urls-and-rules.md](references/urls-and-rules.md) |
| Telegram | [references/telegram.md](references/telegram.md) |
| Weixin (`openclaw-weixin`) | [references/weixin.md](references/weixin.md) |

## Out of scope

- Search or rank memes — **SOUL** + conversation context.
- Update the catalog — edit **`memes.json`** and the **Meme catalog** block in **SOUL** together.
