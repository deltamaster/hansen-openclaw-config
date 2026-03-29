# Scope and related skills

## What this skill does **not** do

- **RSS ingestion** — do not use this to parse a whole feed; only to rewrite **one article URL** before fetch.
- **Non-Google URLs** — pass through unchanged (do not run the resolver).

## Relation to other skills

- **`daily-news-aggregation`** uses FreshRSS and may hit Google News item links—apply **this skill** for each such `link` before web-fetch, then continue that skill’s template and contract.
