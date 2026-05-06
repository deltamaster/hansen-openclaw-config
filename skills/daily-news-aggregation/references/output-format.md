# Output contract

## Rules

- **Exactly 3** numbered deep items (**1. 2. 3.**), each with **four** bullet lines **in this order:** `[What happened]` → `[Context]` → `[Why it matters to you]` → `[Fact vs opinion]`, then one `*Links:`* line. ALL FIELDS ARE MANDATORY AND YOU CAN ABSOLUTELY NOT SKIP. Translate the bracket labels into the user’s language.
- **7–10** bullets under “Also interesting”, each **one or two short sentences** + link + rough age.
- `**Focus today`** line at the top. **Every** story has a Markdown link from the RSS item or fetched page.
- Deep blocks must be **substantive** (not one sentence each). If a page would not load, say so inside **Context** or **Fact vs opinion** and keep the four bullets.

**Incomplete:** wrong count, missing bullet labels, deep content only in “Also interesting”, or no links.

**Do not use:** emoji section headers, “Hey [name]” intros, themed buckets (Tech / Sports / World), or footnotes about saving files — **only** the two `###` sections in the template below.

---

## Markdown template

```markdown
## [Daily brief — YYYY-MM-DD — timezone]

**[Focus today:]** [1–2 sentences]

### [Deep dive — top 3]

**1. [Headline]**

- **[What happened]** …
- **[Context]** …
- **[Why it matters to you]** …
- **[Fact vs opinion]** …
- *Links:* [label](url) · *[Rough age]*

**2. [Headline]**
…
**3. [Headline]**
…

### [Also interesting]

- **[Headline]** — … · *Links:* [label](url) · *[Rough age]*
- …

**[Gaps]** …
```

Omit **Gaps** if nothing to say.

---

## Email rendering (optional path)

The Markdown produced by this contract is the **canonical** body. Scheduled Telegram briefs remain **Markdown only**, unchanged.

For email delivery run **`skills/daily-news-aggregation/scripts/send-daily-news-email.sh`** (from the workspace root — on the gateway that is **`~/.openclaw/workspace/...`**). It reads the Markdown file unchanged and invokes **`skills/daily-news-aggregation/scripts/md_to_email_html.py`**, which emits the branded HTML envelope (narrow column, Focus callout, card-style bullets for deep dives, compact “Also interesting” list). Do not paste custom HTML into the briefing; Markdown plus those scripts preserves a reliable format.


---

## Ground truth

Facts and quotes from the RSS XML or fetched pages only. Health/legal/finance: summarize sources; point to professionals for personal decisions.