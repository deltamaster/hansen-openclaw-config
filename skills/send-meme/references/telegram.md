# Telegram

Use the Telegram **message** / **`sendMessage`** action exposed to the agent:

- Set **`mediaUrl`** to **`MEME_URL`**.
- Optional: short **`content`/`message`** as caption if the schema allows.
- Optional: **`[[reply_to_current]]`** or reply metadata so it reads as a reaction.

CLI (OpenClaw **2026.3.24**): **`--media`** accepts **local path or HTTPS URL**; **`--message`** is optional when sending media-only.

```bash
openclaw message send --channel telegram --target "<chat-or-username>" --media "<MEME_URL>"
# optional caption:
openclaw message send --channel telegram --target "<chat-or-username>" --media "<MEME_URL>" -m "short caption"
```

Optional: **`--force-document`** (Telegram) if the client mangles GIFs.
