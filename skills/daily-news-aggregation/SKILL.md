---

## name: daily-news-aggregation
description: >-
  Build a world/headline daily news brief from one FreshRSS RSS URL: GET the feed, read
  response body as XML yourself (no feed-parsing scripts), select 3 deep-dive + 7–10 short
  items, web-fetch article pages with resolve-google-news-url applied to Google News
  wrapper links first, then output only the fixed Markdown template in the user's language.
  Use for scheduled isolated cron runs (payload may override—see below) and any on-demand
  same-format briefs.

# Daily news aggregation

**Scheduled (isolated) runs:** The gateway cron job can inject a **full copy** of the contract. Authoritative payload in this repo: `[scripts/daily-news-cron-message.txt](../../scripts/daily-news-cron-message.txt)`. **That payload wins** if anything disagrees with this skill.

## Pipeline (order)

1. **GET** the RSS feed and **read the XML yourself** — see [references/pipeline.md](references/pipeline.md).
2. Use **memory + profile** to pick **3** deep items and **7–10** short items (dedupe repeats).
3. **Web-fetch** per [references/pipeline.md](references/pipeline.md); **before any fetch** of a Google News wrapper URL, follow `**resolve-google-news-url`** (resolver script → stdout = publisher URL).
4. Write the answer **only** using the contract in [references/output-format.md](references/output-format.md). **Language:** entire brief in the user’s **native language** (infer from memory, profile, or the current message).

If the GET in step 1 fails, say the feed was unavailable and stop.

## Email delivery (optional; Markdown unchanged)

Agents and shell automation find scripts **next to this skill** — not under the repo-global `scripts/` tree:


| Artifact                 | Workspace-relative path                                          |
| ------------------------ | ---------------------------------------------------------------- |
| HTML renderer            | `skills/daily-news-aggregation/scripts/md_to_email_html.py`      |
| Send via Outlook / Graph | `skills/daily-news-aggregation/scripts/send-daily-news-email.sh` |


**Typical on gateway:** `$HOME/.openclaw/workspace/skills/daily-news-aggregation/scripts/…` — same filenames. Requires `python3` + `pip install markdown` and a valid `**~/.outlook-mcp/credentials.json`** access token.

**Example:** `bash ~/.openclaw/workspace/skills/daily-news-aggregation/scripts/send-daily-news-email.sh "you@example.com" "Daily brief" /tmp/news.md`

The repo keeps `scripts/send-daily-news-email.sh` as a **thin forwarder** only for workstations that cloned this layout with `skills/` beside `scripts/`.

## Reference material


| File                                                                 | Contents                                                                          |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| [references/pipeline.md](references/pipeline.md)                     | RSS URL, XML rules, fetch rules, resolver handoff                                 |
| [references/output-format.md](references/output-format.md)           | Mandatory sections, Markdown template, optional email HTML wrapping, ground truth |
| [scripts/md_to_email_html.py](scripts/md_to_email_html.py)           | Markdown brief → branded HTML mail body                                           |
| [scripts/send-daily-news-email.sh](scripts/send-daily-news-email.sh) | Graph sendmail wrapper (Outlook token)                                            |


