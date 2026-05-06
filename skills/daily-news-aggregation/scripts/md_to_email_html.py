#!/usr/bin/env python3
"""
Read markdown from path argv[1], write a complete HTML email document to stdout.

Lives in this skill: daily-news-aggregation/scripts/
OpenClaw workspace path (typical):
  ~/.openclaw/workspace/skills/daily-news-aggregation/scripts/md_to_email_html.py <file.md>
"""

from __future__ import annotations

import re
import sys
from typing import Optional

import markdown

# Styles for email clients — keep `{}` escaped if ever embedded in Python f-strings via format()
_STYLE_BLOCK = r"""
body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f1f5f9 !important; }
.md-email { color: #334155; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 16px; line-height: 1.55; }
.md-email a { color: #1d4ed8; text-decoration: underline; font-weight: 500; }
.md-email a:hover { color: #1e40af; }
.md-email h2 {
  margin: 0 0 8px 0;
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
  letter-spacing: -0.02em;
  color: #0f172a;
}
.md-email .focus-box {
  margin: 0 0 24px 0;
  padding: 14px 16px;
  background-color: #eff6ff;
  border-left: 4px solid #2563eb;
  border-radius: 0 8px 8px 0;
  color: #1e293b;
  font-size: 15px;
  line-height: 1.5;
}
.md-email .focus-box strong { font-size: inherit !important; font-weight: 600 !important; color: #1e3a8a; display: inline !important; line-height: inherit !important; margin: 0 !important; }
.md-email h3 {
  margin: 28px 0 14px 0;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #475569;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 8px;
}
.md-email h3:first-of-type { margin-top: 8px; }
.md-email p { margin: 0 0 12px 0; }
.md-email p strong:first-child { color: #0f172a; font-size: 17px; font-weight: 700; line-height: 1.35; display: inline-block; margin-top: 4px; margin-bottom: 2px; }
.md-email ul { margin: 0 0 20px 0; padding-left: 0; list-style: none; }
.md-email ul li {
  margin: 0 0 12px 0;
  padding: 14px 16px 14px 20px;
  background-color: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  border-left: 3px solid #94a3b8;
}
.md-email ul li ul,
.md-email ul li ol {
  margin-top: 8px;
  padding-left: 1.25em;
  list-style: disc;
  background: transparent;
  border: none;
  padding-inline-start: 1.25em;
}
.md-email ul li li { padding: 4px 0; border: none; background: transparent; margin: 0 0 4px 0; border-radius: 0; border-left: none; }
.md-email hr { border: none; border-top: 1px solid #e2e8f0; margin: 28px 0; }
.md-email blockquote { border-left: 4px solid #cbd5e1; margin: 12px 0; padding: 8px 0 8px 16px; color: #475569; font-style: italic; }
.md-email code { background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.88em; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #0f172a; }
.md-email pre { background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; border-radius: 8px; overflow-x: auto; font-size: 0.88em; line-height: 1.45; }
.md-email pre code { background: none; padding: 0; }
.md-email table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 14px; }
.md-email th, .md-email td { border: 1px solid #e2e8f0; padding: 10px 12px; text-align: left; }
.md-email th { background-color: #f1f5f9; font-weight: 600; color: #0f172a; }
.md-email ul.also-list li {
  padding: 10px 14px;
  margin: 0 0 10px 0;
  border-left-width: 2px;
  font-size: 15px;
  line-height: 1.5;
}
"""


def html_escape_plain(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _strip_wrapping_fences(text: str) -> str:
    """If body was saved inside ```markdown fences, unwrap."""
    s = text.strip()
    if s.startswith("```"):
        lines = s.split("\n")
        if len(lines) >= 2 and lines[0].strip().startswith("```"):
            end_idx = None
            for i in range(1, len(lines)):
                if lines[i].strip() == "```":
                    end_idx = i
                    break
            if end_idx is not None:
                return "\n".join(lines[1:end_idx]).strip()
    return text


def _wrap_focus_paragraph(inner: str) -> str:
    """First <p> after h2 wrapped as callout when it resembles Focus today (bold lead-in)."""
    pattern = re.compile(
        r"(<h2[^>]*>.*?</h2>\s*)(<p[^>]*>.*?</p>)",
        re.DOTALL | re.IGNORECASE,
    )
    m = pattern.search(inner)
    if not m:
        return inner
    prefix, fp = m.group(1), m.group(2)
    if "<strong>" not in fp.lower() and "<b>" not in fp.lower():
        return inner
    wrapped = prefix + '<div class="focus-box">' + fp + "</div>"
    return inner[: m.start()] + wrapped + inner[m.end() :]


def _add_also_compact_class(inner: str) -> str:
    """Tighter list styling for second major section's <ul>."""
    h3_iter = list(re.finditer(r"<h3[^>]*>", inner, flags=re.IGNORECASE))
    if len(h3_iter) < 2:
        return inner
    second_h3_start = h3_iter[1].start()
    rest = inner[second_h3_start:]
    mul = re.search(r"<ul\b", rest)
    if not mul:
        return inner
    abs_ul = second_h3_start + mul.start()
    return inner[:abs_ul] + rest[mul.start() :].replace("<ul", '<ul class="also-list"', 1)


def render_email_document(markdown_text: str, title: Optional[str] = None) -> str:
    text = _strip_wrapping_fences(markdown_text)
    inner_raw = markdown.markdown(
        text,
        extensions=[
            "extra",
            "nl2br",
        ],
    )
    inner = _add_also_compact_class(_wrap_focus_paragraph(inner_raw))

    doc_title = ""
    mh = re.search(
        r"<h2([^>]*)>(.*?)</h2>",
        inner_raw,
        flags=re.DOTALL | re.IGNORECASE,
    )
    if mh:
        plain = re.sub(r"<[^>]+>", "", mh.group(2))
        plain = " ".join(plain.split()).strip()
        if plain:
            doc_title = plain
    if not doc_title:
        doc_title = title or "Daily brief"

    style_full = _STYLE_BLOCK.strip()
    title_esc = html_escape_plain(doc_title)

    tmpl = """<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light only">
<title>TITLE_ESC_PLACEHOLDER</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style type="text/css">
STYLE_BLOCK_PLACEHOLDER
@media only screen and (max-width: 620px) {
  .md-shell { width: 100% !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f1f5f9;">
<tr>
<td align="center" style="padding:24px 12px;">
  <!--[if mso]>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" align="center"><tr><td>
  <![endif]-->
  <table role="presentation" class="md-shell" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
    <tr>
      <td style="height:4px;line-height:4px;background-color:#2563eb;font-size:0;">&#160;</td>
    </tr>
    <tr>
      <td style="padding:28px 28px 24px 28px;" class="md-email">
        INNER_HTML_PLACEHOLDER
      </td>
    </tr>
  </table>
  <!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>"""

    out = tmpl.replace("TITLE_ESC_PLACEHOLDER", title_esc)
    out = out.replace("STYLE_BLOCK_PLACEHOLDER", style_full + "\n")
    out = out.replace("INNER_HTML_PLACEHOLDER", inner.strip())
    return out


def main() -> None:
    path = sys.argv[1]
    text = open(path, encoding="utf-8").read()
    sys.stdout.write(render_email_document(text))


if __name__ == "__main__":
    main()
