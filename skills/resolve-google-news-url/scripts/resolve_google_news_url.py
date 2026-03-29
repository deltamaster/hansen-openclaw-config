#!/usr/bin/env python3
"""
Resolve Google News RSS redirect URLs (news.google.com/rss/articles/...) to the
publisher URL. Uses Google's batchexecute API (same approach as googlenewsdecoder)
with browser-like headers — plain HTTP redirects do not reach the article host.

Usage (from this skill directory, resolve-google-news-url/):
  python3 scripts/resolve_google_news_url.py 'https://news.google.com/rss/articles/...'
  echo 'https://...' | python3 scripts/resolve_google_news_url.py

Options:
  --curl       After decode, only curl -L (rarely needed)
  --browser    Use Playwright Chromium (needs: pip install playwright && playwright install chromium)
  --no-decode  Skip batchexecute decode; only follow redirects / HTML heuristics
  --timeout S  Per-request cap in seconds (default: 60)

Exit code 0 on success, 1 on failure. Final URL printed to stdout.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from urllib.parse import parse_qs, quote, unquote, urlparse

# Chrome-like UA — many aggregators gate on this.
DEFAULT_UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)
ACCEPT = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"
ACCEPT_LANG = "en-US,en;q=0.9"


def _request(url: str, method: str = "GET", data: bytes | None = None) -> urllib.request.Request:
    h = {
        "User-Agent": DEFAULT_UA,
        "Accept": ACCEPT,
        "Accept-Language": ACCEPT_LANG,
        "Cache-Control": "no-cache",
    }
    if data is not None:
        h["Content-Type"] = "application/x-www-form-urlencoded;charset=UTF-8"
    return urllib.request.Request(url, method=method, headers=h, data=data)


def _google_news_article_token(url: str) -> str | None:
    """Return the encoded token from /rss/articles/, /articles/, or /read/ paths."""
    try:
        u = urlparse(url)
        if (u.hostname or "").lower() != "news.google.com":
            return None
        parts = [p for p in u.path.split("/") if p]
        if len(parts) >= 2 and parts[-2] in ("articles", "read"):
            return parts[-1]
    except Exception:
        pass
    return None


def _extract_n_a_attrs(html: str) -> tuple[str | None, str | None]:
    """data-n-a-sg and data-n-a-ts from Google News article shell HTML."""
    sg = re.search(r'data-n-a-sg="([^"]*)"', html)
    ts = re.search(r'data-n-a-ts="([^"]*)"', html)
    return (sg.group(1) if sg else None, ts.group(1) if ts else None)


def _fetch_text(url: str, timeout: float) -> str:
    req = _request(url)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read().decode("utf-8", errors="replace")


def _decode_google_news_batchexecute(url: str, timeout: float) -> str | None:
    """
    Resolve news.google.com/rss/articles/... (or /read/, /articles/) to publisher URL
    via /_/DotsSplashUi/data/batchexecute. Returns None if decode fails.
    """
    token = _google_news_article_token(url)
    if not token:
        return None

    signature: str | None = None
    timestamp: str | None = None
    used_token = token

    for page_url in (
        f"https://news.google.com/articles/{token}",
        f"https://news.google.com/rss/articles/{token}",
    ):
        try:
            html = _fetch_text(page_url, timeout)
            signature, timestamp = _extract_n_a_attrs(html)
            if signature and timestamp:
                break
        except Exception:
            continue

    if not signature or not timestamp:
        return None

    batchexecute = "https://news.google.com/_/DotsSplashUi/data/batchexecute"
    payload = [
        "Fbv4je",
        f'["garturlreq",[["X","X",["X","X"],null,null,1,1,"US:en",null,1,null,null,null,null,null,0,1],"X","X",1,[1,1,1],1,1,null,0,0,null,0],"{used_token}",{timestamp},"{signature}"]',
    ]
    body = f"f.req={quote(json.dumps([[payload]]))}".encode()
    req = _request(batchexecute, method="POST", data=body)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            text = resp.read().decode("utf-8", errors="replace")
    except Exception:
        return None

    try:
        chunk = text.split("\n\n")[1]
        parsed_data = json.loads(chunk)[:-2]
        decoded_url = json.loads(parsed_data[0][2])[1]
        if isinstance(decoded_url, str) and decoded_url.startswith("http"):
            return decoded_url
    except (json.JSONDecodeError, IndexError, TypeError):
        pass
    return None


def resolve_urllib(url: str, timeout: float = 45.0) -> str:
    """Follow redirects with urllib (default redirect handler)."""
    req = _request(url)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.geturl()


def resolve_curl(url: str, timeout_s: int = 60) -> str:
    """Use curl -L; effective URL after all redirects."""
    try:
        r = subprocess.run(
            [
                "curl",
                "-sL",
                "--max-redirs",
                "20",
                "-o",
                os.devnull,
                "-w",
                "%{url_effective}",
                "-A",
                DEFAULT_UA,
                "-H",
                f"Accept: {ACCEPT}",
                "--connect-timeout",
                str(min(max(10, timeout_s // 3), 30)),
                "-m",
                str(timeout_s),
                url,
            ],
            capture_output=True,
            text=True,
            timeout=timeout_s + 5,
            check=False,
        )
    except FileNotFoundError:
        raise RuntimeError("curl not found; install curl or omit --curl") from None
    out = (r.stdout or "").strip()
    if r.returncode != 0 or not out:
        err = (r.stderr or "").strip()
        hint = ""
        if r.returncode == 28:
            hint = " (timeout — try --timeout 120 or --browser; Google may throttle some networks)"
        raise RuntimeError(
            f"curl failed ({r.returncode}): {err or 'no url_effective'}{hint}"
        )
    return out


def resolve_playwright(url: str, timeout_ms: int = 60000) -> str:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as e:
        raise RuntimeError(
            "Install Playwright: pip install playwright && playwright install chromium"
        ) from e
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        try:
            ctx = browser.new_context(user_agent=DEFAULT_UA, locale="en-US")
            page = ctx.new_page()
            page.goto(url, wait_until="domcontentloaded", timeout=timeout_ms)
            page.wait_for_timeout(800)
            return page.url
        finally:
            browser.close()


def extract_google_url_param(html: str) -> str | None:
    """If page is a Google interstitial, pull decoded target URL from q= / url=."""
    # Full google.com/url?... links
    for m in re.finditer(r'https?://(?:www\.)?google\.com/url\?[^"\'\s<>]+', html, re.I):
        raw = m.group(0).rstrip("\\)")
        q = parse_qs(urlparse(raw).query).get("q", [""])[0]
        if q and q.startswith("http"):
            return unquote(q)
    # Quoted url= inside attributes / JSON
    for m in re.finditer(r'["\']url["\']\s*:\s*["\'](https?://[^"\']+)["\']', html):
        u = unquote(m.group(1))
        if "google.com" not in u and u.startswith("http"):
            return u
    # q=... fragment (encoded publisher URL)
    m = re.search(r'[?&]q=([^&"\'\s<>]+)', html)
    if m:
        cand = unquote(m.group(1))
        if cand.startswith("http") and "google.com" not in urlparse(cand).netloc.lower():
            return cand
    return None


def resolve_with_html_fallback(url: str, timeout: float = 45.0) -> str:
    """GET and follow redirects; if still on Google, try to parse url?q= from body."""
    req = _request(url)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        final = resp.geturl()
        body = resp.read(500_000).decode("utf-8", errors="replace")
    if "news.google.com" in final or "google.com/url" in final:
        extracted = extract_google_url_param(body)
        if extracted and extracted.startswith("http"):
            return extracted
    return final


def resolve_default(url: str, timeout_s: float = 60.0) -> str:
    """urllib → curl on 403/429/URLError → HTML parse if still on Google."""
    tout = float(timeout_s)
    try:
        out = resolve_urllib(url, timeout=tout)
    except urllib.error.HTTPError as e:
        if e.code in (403, 429):
            out = resolve_curl(url, timeout_s=int(tout))
        else:
            raise
    except urllib.error.URLError:
        out = resolve_curl(url, timeout_s=int(tout))

    if "news.google.com" in out or "google.com/url" in out:
        try:
            alt = resolve_with_html_fallback(url, timeout=tout)
            if alt != out and "news.google.com" not in alt and "google.com/url" not in alt:
                return alt
        except Exception:
            pass
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Resolve Google News redirect to publisher URL")
    ap.add_argument("url", nargs="?", help="Redirect URL (or read stdin)")
    ap.add_argument("--curl", action="store_true", help="Use only curl -L (after decode when applicable)")
    ap.add_argument("--browser", action="store_true", help="Use Playwright Chromium")
    ap.add_argument(
        "--no-decode",
        action="store_true",
        help="Skip batchexecute decode (redirect/HTML only)",
    )
    ap.add_argument(
        "--timeout",
        type=int,
        default=60,
        metavar="S",
        help="Max seconds per request (default: 60)",
    )
    args = ap.parse_args()
    raw = (args.url or sys.stdin.read()).strip()
    if not raw:
        print("error: no URL", file=sys.stderr)
        return 1

    try:
        tout = float(args.timeout)
        if not args.no_decode and _google_news_article_token(raw):
            decoded = _decode_google_news_batchexecute(raw, tout)
            if decoded:
                print(decoded)
                return 0

        if args.browser:
            out = resolve_playwright(raw, timeout_ms=max(5000, args.timeout * 1000))
        elif args.curl:
            out = resolve_curl(raw, timeout_s=args.timeout)
        else:
            out = resolve_default(raw, timeout_s=tout)
    except Exception as e:
        print(f"error: {e}", file=sys.stderr)
        return 1

    print(out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
