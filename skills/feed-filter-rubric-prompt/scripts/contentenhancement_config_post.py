#!/usr/bin/env python3
"""
POST Content Enhancement configuration (same body shape as the admin form / example_post_request).

Reads JSON from stdin (typically output of contentenhancement_config_get.py after you edit
system_prompt). Always sends api_key empty to keep the existing key.

Environment:
  FRESHRSS_BASE        Base URL (default: http://localhost:8080)
  FRESHRSS_COOKIE_FILE Path to session cookies (default: freshrss_configure.cookies next to this script).

Leave FRESHRSS_COOKIE unset; loads the same jar get.py wrote so the session matches _csrf in stdin JSON.

Checkbox fields are encoded like the browser: hidden 0 + checkbox 1 when checked, else 0 only.

Do not send slider=1 unless FRESHRSS_POST_SLIDER=1: the UI only adds that field for forms
inside the AJAX slider (extra.js); sending it on a direct POST makes FreshRSS run indexAction
first and breaks configure (404).

Exit 0 on HTTP 2xx or 3xx redirect to a non-error URL (FreshRSS uses 302 after save).

Uses urllib without following redirects: the first response is often 302; following redirects
to c=error yields a misleading HTTP 404.
"""

from __future__ import annotations

import http.cookiejar
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

DEFAULT_BASE = os.environ.get("FRESHRSS_BASE", "http://localhost:8080").strip().rstrip("/")
EXTENSION = os.environ.get("FRESHRSS_EXTENSION", "ContentEnhancement").strip()
COOKIE = os.environ.get("FRESHRSS_COOKIE", "").strip()
POST_SLIDER = os.environ.get("FRESHRSS_POST_SLIDER", "").strip() == "1"


def cookie_file_path() -> str:
    p = os.environ.get("FRESHRSS_COOKIE_FILE", "").strip()
    if p:
        return p
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), "freshrss_configure.cookies")


def configure_url(base: str) -> str:
    q = urllib.parse.urlencode({"c": "extension", "a": "configure", "e": EXTENSION})
    return f"{base}/i/?{q}"


def checkbox_pairs(name: str, checked: bool) -> list[tuple[str, str]]:
    if checked:
        return [(name, "0"), (name, "1")]
    return [(name, "0")]


def build_form_pairs(data: dict[str, object]) -> list[tuple[str, str]]:
    csrf = data.get("_csrf")
    if not csrf or not isinstance(csrf, str):
        raise ValueError("JSON must include string _csrf (from get script output).")

    pairs: list[tuple[str, str]] = [("_csrf", csrf)]
    if POST_SLIDER:
        pairs.insert(0, ("slider", str(data.get("slider", "1"))))

    pairs.extend(checkbox_pairs("enabled", bool(data.get("enabled"))))
    pairs.append(("api_base", str(data.get("api_base", ""))))
    pairs.append(("api_key", ""))
    pairs.append(("model", str(data.get("model", ""))))
    pairs.append(("min_quality", str(int(data.get("min_quality", 4)))))
    pairs.extend(checkbox_pairs("prefilter_before_fetch", bool(data.get("prefilter_before_fetch"))))
    pairs.extend(checkbox_pairs("mark_low_quality_read", bool(data.get("mark_low_quality_read"))))
    pairs.extend(checkbox_pairs("discard_below_threshold", bool(data.get("discard_below_threshold"))))
    pairs.extend(checkbox_pairs("apply_freshrss_labels", bool(data.get("apply_freshrss_labels"))))
    pairs.append(("system_prompt", str(data.get("system_prompt", ""))))

    return pairs


def main() -> None:
    use_jar = not COOKIE
    jar_path = cookie_file_path()
    if use_jar and not os.path.isfile(jar_path):
        print(
            f"No cookie file at {jar_path}. Run contentenhancement_config_get.py first to create it.",
            file=sys.stderr,
        )
        sys.exit(1)

    raw = sys.stdin.read()
    if not raw.strip():
        print("stdin empty; pipe JSON from contentenhancement_config_get.py", file=sys.stderr)
        sys.exit(1)

    try:
        data = json.loads(raw)
    except json.JSONDecodeError as e:
        print(f"Invalid JSON: {e}", file=sys.stderr)
        sys.exit(1)
    if not isinstance(data, dict):
        raise SystemExit("stdin must be a JSON object")

    pairs = build_form_pairs(data)
    body = urllib.parse.urlencode(pairs).encode("utf-8")

    url = configure_url(DEFAULT_BASE)
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "Referer": url,
            "User-Agent": "contentenhancement_config_post/1",
        },
    )
    if not use_jar:
        req.add_header("Cookie", COOKIE)

    if use_jar:
        cj = http.cookiejar.MozillaCookieJar(jar_path)
        cj.load(ignore_discard=True, ignore_expires=True)
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), _NoRedirect())
    else:
        opener = urllib.request.build_opener(_NoRedirect())
    try:
        with opener.open(req, timeout=120) as resp:
            _ = resp.read()
            code = resp.getcode()
    except urllib.error.HTTPError as e:
        if e.code in (301, 302, 303, 307, 308):
            loc = (e.headers.get("Location") or "") if e.headers else ""
            if "c=error" in loc or "/i/?c=error" in loc:
                err = e.read().decode("utf-8", errors="replace")
                if not err.strip():
                    print(
                        "Save rejected (redirect to error, empty body). "
                        "Re-run get, then post the new JSON immediately (same cookie file, do not delete it between get and post).",
                        file=sys.stderr,
                    )
                else:
                    print(err[:4000], file=sys.stderr)
                sys.exit(1)
            print(f"OK (HTTP {e.code} redirect after save)", file=sys.stderr)
            sys.exit(0)
        err = e.read().decode("utf-8", errors="replace")
        print(err[:4000], file=sys.stderr)
        sys.exit(e.code)

    if 200 <= code < 300:
        print("OK", file=sys.stderr)
        sys.exit(0)
    print(f"Unexpected code {code}", file=sys.stderr)
    sys.exit(1)


if __name__ == "__main__":
    main()
