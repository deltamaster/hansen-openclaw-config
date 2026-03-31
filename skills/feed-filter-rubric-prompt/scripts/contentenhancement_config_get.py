#!/usr/bin/env python3
"""
GET the Content Enhancement configure page and print a JSON object with _csrf and all form fields.

Environment:
  FRESHRSS_BASE        Base URL (default: http://localhost:8080)
  FRESHRSS_COOKIE_FILE Path to save session cookies (default: freshrss_configure.cookies next to this script).

Leave FRESHRSS_COOKIE unset. GET uses a cookie jar so Set-Cookie from FreshRSS matches _csrf; run
post.py with the same file and FRESHRSS_COOKIE still unset.

Output: UTF-8 JSON on stdout (for editing / feeding to contentenhancement_config_post.py).
"""

from __future__ import annotations

import http.cookiejar
import html as html_module
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

DEFAULT_BASE = os.environ.get("FRESHRSS_BASE", "http://localhost:8080").strip().rstrip("/")
EXTENSION = os.environ.get("FRESHRSS_EXTENSION", "ContentEnhancement").strip()
COOKIE = os.environ.get("FRESHRSS_COOKIE", "").strip()


def cookie_file_path() -> str:
    p = os.environ.get("FRESHRSS_COOKIE_FILE", "").strip()
    if p:
        return p
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), "freshrss_configure.cookies")


def configure_url(base: str) -> str:
    q = urllib.parse.urlencode({"c": "extension", "a": "configure", "e": EXTENSION})
    return f"{base}/i/?{q}"


def _checkbox_checked(html: str, name: str) -> bool:
    pat = rf'<input[^>]*name="{re.escape(name)}"[^>]*value="1"[^>]*\bchecked\b'
    return bool(re.search(pat, html, re.I))


def _input_value(html: str, name: str) -> str:
    m = re.search(rf'<input[^>]*name="{re.escape(name)}"[^>]*value="([^"]*)"', html, re.I)
    return m.group(1) if m else ""


def _csrf_from_json_vars(html: str) -> str | None:
    m = re.search(
        r'<script[^>]+id="jsonVars"[^>]*type="application/json">\s*(\{[\s\S]*?\})\s*</script>',
        html,
        re.I,
    )
    if not m:
        return None
    try:
        j = json.loads(m.group(1))
        ctx = j.get("context")
        if isinstance(ctx, dict):
            c = ctx.get("csrf")
            if isinstance(c, str) and c.strip():
                return c.strip()
    except (json.JSONDecodeError, TypeError):
        return None
    return None


def _csrf_from_manage_form(html: str) -> str | None:
    """CSRF for the configure POST must come from the same form as system_prompt, not the header #post-csrf form."""
    m = re.search(
        r'<form[^>]+action="[^"]*[?&]c=extension[^"]*a=configure[^"]*e=ContentEnhancement[^"]*"[^>]*>',
        html,
        re.I,
    )
    if m:
        end = html.find("</form>", m.end())
        chunk = html[m.start() : end] if end != -1 else html[m.start() : m.start() + 200_000]
        for pat in (
            r'<input[^>]*name="_csrf"[^>]*value="([^"]*)"',
            r'<input[^>]*value="([^"]*)"[^>]*name="_csrf"',
        ):
            cm = re.search(pat, chunk, re.I)
            if cm:
                return cm.group(1).strip()
    ta = re.search(r'<textarea[^>]*name="system_prompt"', html, re.I)
    if not ta:
        return None
    before = html[: ta.start()]
    forms = list(re.finditer(r"<form\b[^>]*>", before, re.I))
    if not forms:
        return None
    form_start = forms[-1].start()
    chunk = html[form_start : ta.start()]
    for pat in (
        r'<input[^>]*name="_csrf"[^>]*value="([^"]*)"',
        r'<input[^>]*value="([^"]*)"[^>]*name="_csrf"',
    ):
        cm = re.search(pat, chunk, re.I)
        if cm:
            return cm.group(1).strip()
    return None


def parse_configure_html(html: str) -> dict[str, object]:
    if "passwordPlain" in html and 'name="username"' in html:
        raise RuntimeError(
            "Response looks like login page; sign in via the browser, then run get again so "
            "freshrss_configure.cookies receives the session cookie."
        )

    csrf = _csrf_from_manage_form(html) or _csrf_from_json_vars(html)
    if not csrf:
        m = re.search(r'name="_csrf"[^>]*value="([^"]*)"', html, re.I)
        if not m:
            m = re.search(r'value="([^"]*)"[^>]*name="_csrf"', html, re.I)
        if not m:
            raise RuntimeError("Could not find _csrf in HTML.")
        csrf = m.group(1).strip()

    sp = re.search(
        r'<textarea[^>]*name="system_prompt"[^>]*>([\s\S]*?)</textarea>',
        html,
        re.I,
    )
    system_prompt = html_module.unescape(sp.group(1).rstrip()) if sp else ""

    mq = _input_value(html, "min_quality")
    try:
        min_quality = int(mq) if mq else 4
    except ValueError:
        min_quality = 4

    return {
        "_csrf": csrf,
        "slider": "1",
        "enabled": _checkbox_checked(html, "enabled"),
        "api_base": _input_value(html, "api_base"),
        "api_key": "",
        "model": _input_value(html, "model"),
        "min_quality": min_quality,
        "prefilter_before_fetch": _checkbox_checked(html, "prefilter_before_fetch"),
        "mark_low_quality_read": _checkbox_checked(html, "mark_low_quality_read"),
        "discard_below_threshold": _checkbox_checked(html, "discard_below_threshold"),
        "apply_freshrss_labels": _checkbox_checked(html, "apply_freshrss_labels"),
        "system_prompt": system_prompt,
    }


def main() -> None:
    url = configure_url(DEFAULT_BASE)
    if COOKIE:
        req = urllib.request.Request(
            url,
            headers={
                "Cookie": COOKIE,
                "User-Agent": "contentenhancement_config_get/1",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                html = resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            print(e.read().decode("utf-8", errors="replace")[:2000], file=sys.stderr)
            sys.exit(e.code)
    else:
        path = cookie_file_path()
        cj = http.cookiejar.MozillaCookieJar(path)
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
        req = urllib.request.Request(
            url,
            headers={"User-Agent": "contentenhancement_config_get/1"},
        )
        try:
            with opener.open(req, timeout=120) as resp:
                html = resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            print(e.read().decode("utf-8", errors="replace")[:2000], file=sys.stderr)
            sys.exit(e.code)
        try:
            cj.save(ignore_discard=True, ignore_expires=True)
        except OSError as e:
            print(f"Could not save cookies to {path}: {e}", file=sys.stderr)
            sys.exit(1)
        print(
            f"Session cookies saved to {path}; run post.py next with the same FRESHRSS_BASE.",
            file=sys.stderr,
        )

    data = parse_configure_html(html)
    json.dump(data, sys.stdout, ensure_ascii=False, indent=2)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
