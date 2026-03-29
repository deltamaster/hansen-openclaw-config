# Applicability, command, rules

## When this applies (check first)

Use whenever you are about to **web-fetch**, **open in browser**, or **cite** an article and the URL matches **all** of:

- Host: **`news.google.com`**
- Path contains one of: **`/rss/articles/`**, **`/articles/`** (article token path), **`/read/`**

If yes → **run the resolver below**, then use the printed URL for fetch/open/citations. If no → proceed without this skill.

---

## What to run (gateway / this deployment)

From this skill’s directory (`resolve-google-news-url/`, as under the agent workspace `skills/` tree):

```bash
python3 scripts/resolve_google_news_url.py '<PASTE_FULL_GOOGLE_NEWS_URL>'
```

- **Stdout** is a **single line**: the publisher article URL (HTTPS). Use that URL for **web-fetch** and for **Markdown links**.
- **Stderr** may contain errors; exit code **0** means success.

---

## Rules

1. **Always** resolve before web-fetch for matching URLs—do not skip to “fetch the Google link” first.
2. **One URL per invocation**; quote the URL so shell metacharacters are safe.
3. If the script **fails** (non-zero exit or empty stdout), say so briefly, optionally retry with `--timeout 120`, and only then fall back to the original Google URL or omit fetch.

---

## Optional flags (only if needed)

| Flag | Use |
|------|-----|
| `--timeout 120` | Slow network or timeouts |
| `--curl` | Prefer curl for redirects (unusual after batchexecute decode) |
| `--browser` | Last resort; needs Playwright on the host |
| `--no-decode` | Skip batchexecute decode (debug only) |

Default path is correct for normal Google News RSS article links.
