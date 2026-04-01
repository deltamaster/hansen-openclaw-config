---
name: grab-page
description: "Fetch web pages using Playwright with stealth anti-detection. Use when you need to scrape a page that blocks simple HTTP requests, or when you need to capture screenshots. Parameters: URL (required)."
---

# grab-page Skill

Fetch web pages using Playwright with `playwright-stealth` plugin to evade bot detection.

## Setup

Install dependencies in the scripts directory:

```bash
cd ~/.openclaw/workspace/skills/grab-page/scripts
npm install
```

Or install browsers if needed:
```bash
npx playwright install chromium
```

## Usage

```bash
node ~/.openclaw/workspace/skills/grab-page/scripts/grab-page.js <url> [--screenshot] [--html]
```

**Options:**
- `<url>` - The URL to fetch (required)
- `--screenshot` - Take a full-page screenshot (saves to `/tmp/grab-page-screenshot.png`)
- `--html` - Output the raw HTML instead of extracted text

**Examples:**

```bash
# Extract text content
node grab-page.js "https://example.com"

# Get HTML source
node grab-page.js "https://example.com" --html

# Take screenshot
node grab-page.js "https://example.com" --screenshot
```

## When to Use

- Page content is loaded via JavaScript (dynamic SPA)
- Site blocks simple HTTP requests
- Need screenshots of pages
- Need to extract content from anti-bot protected sites

## Output

- **Text mode (default):** Returns extracted text content, scripts/styles removed
- **HTML mode:** Returns full page HTML source
- **Screenshot mode:** Saves screenshot to `/tmp/grab-page-screenshot.png` and prints the path

## Notes

- Uses `playwright-stealth` to mask automation fingerprints
- Headless browser with standard desktop user agent
- 30 second timeout for page load
- Follows redirects automatically
