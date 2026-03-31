---
name: feed-filter-rubric-prompt
description: >-
  Update the system prompt that controls feed filtering and relevance scoring for news items. Use when
  the user wants to change that rubric text only. Flow: GET JSON → edit system_prompt → POST.
---

# Feed filter rubric (`system_prompt`)

## When this applies

The user wants to change the **LLM rubric** that drives filtering / scoring—edit **`system_prompt`** only.

## Run

| Step | Action |
|------|--------|
| 1 | `python3 scripts/contentenhancement_config_get.py` → JSON on stdout |
| 2 | Change **only** **`system_prompt`**. Leave every other key exactly as returned by step 1. |
| 3 | `python3 scripts/contentenhancement_config_post.py < your.json` |

[`scripts/contentenhancement_config_get.py`](scripts/contentenhancement_config_get.py) · [`scripts/contentenhancement_config_post.py`](scripts/contentenhancement_config_post.py)

## Session

Run **get** first: it writes **`freshrss_configure.cookies`** beside the scripts. Run **post** with the same **`FRESHRSS_BASE`**. Optional: **`FRESHRSS_COOKIE_FILE`** to override the cookie path.

Further detail and edge cases: [references/pipeline.md](references/pipeline.md).
