# Weixin (`openclaw-weixin`)

Weixin does **not** use Telegram’s `mediaUrl` field. Use whatever **Weixin channel send** the gateway exposes (same **`MEME_URL`**):

- Prefer a **media / image / file** send that accepts an **HTTPS URL** or downloaded bytes from that URL.
- If the tool only accepts local paths: fetch **`MEME_URL`** to a temp file, then send as **image/file** per plugin rules.
- If no media tool works: send **`MEME_URL`** alone on its own line as a fallback (WeChat may open in browser).
