# MEME_URL, inputs, common rules

## MEME_URL

Full HTTPS URL, always:

`https://de.hansenh.xyz/` + `<path>`

Example path from the catalog: `meme/kumamo-happy.gif` → **`https://de.hansenh.xyz/meme/kumamo-happy.gif`**

## Inputs (you already have these)

- **`MEME_URL`** — as above.
- **Active channel** — e.g. Telegram vs `openclaw-weixin` (infer from session / inbound envelope).
- **Target** — same peer/thread as the conversation (chat id, `to`, etc.); use the channel’s normal send target.

## Common rules (all channels)

1. **Separate message:** Send the GIF as its **own** outbound message (media send). Do **not** bury the GIF only inside a text bubble; do **not** use Markdown `![...](...)`.
2. **Optional:** Short text reply in one message; **then** send the GIF in a **follow-up** send (or media-only send with caption if the tool merges caption + file in one API call—still treat it as the “GIF message”, not as inline markdown).
3. **One URL:** `MEME_URL` must be the raw HTTPS link above (TLS, correct path).
