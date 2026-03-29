---
name: resolve-google-news-url
description: >-
  Before web-fetch, opening in browser, or citing a link: if the URL is a Google News
  wrapper (news.google.com with /rss/articles/, /articles/, or /read/), run the resolver
  Python script and use stdout as the publisher article URL. Do not fetch the Google URL
  directly—it does not resolve to publisher content via normal HTTP. One URL per invocation.
---

# Resolve Google News URL

Use this skill **immediately before** any **web-fetch**, **browser open**, or **citation** when the URL might be a Google News wrapper. Details below.

## Reference material

| File | Contents |
|------|----------|
| [references/resolver-operation.md](references/resolver-operation.md) | URL patterns, command, stdout/stderr, rules, optional flags, failures |
| [references/scope.md](references/scope.md) | What this skill does not do; relation to **daily-news-aggregation** |
