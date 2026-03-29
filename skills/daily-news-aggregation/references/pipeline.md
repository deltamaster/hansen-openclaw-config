# Pipeline (detail)

## 1. RSS GET

**URL (this deployment):** `http://127.0.0.1:8080/i/?a=rss&user=admin&nb=100&search=pubdate:PT48H/`

**Read the response body as XML yourself** — scan `<item>` nodes for `title`, `link`, `pubDate`, `description`. **No** Python, **no** shell one-liners, **no** `grep`/`curl | xmllint` pipelines to pre-digest the feed. The only allowed preprocessing is **your own reading** of the XML text.

## 2. Selection

Use **memory + profile** to pick **3** items for deep dive and **7–10** for the short list (dedupe obvious repeats).

## 3. Web-fetch

For each of the **3** deep items, fetch **at least one** `link` from the item (open the URL, read the page). For the **7–10** short items, RSS text is enough unless a headline is unclear — then fetch **that** link only. **No** batch automation beyond sequential fetches.

**Before any web-fetch**, if the `link` is a Google News wrapper (`news.google.com` with `/rss/articles/`, `/articles/`, or `/read/`), follow the **`resolve-google-news-url`** skill (run the resolver script; use stdout as the publisher URL).

## 4. Output

Write the answer **only** in the format defined in [output-format.md](output-format.md).
