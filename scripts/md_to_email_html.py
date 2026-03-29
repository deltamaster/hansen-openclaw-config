#!/usr/bin/env python3
"""Read markdown from path argv[1], write HTML email document to stdout."""
import sys

import markdown

def main() -> None:
    path = sys.argv[1]
    text = open(path, encoding="utf-8").read()
    inner = markdown.markdown(
        text,
        extensions=[
            "extra",
            "nl2br",
        ],
    )
    doc = f"""<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  line-height: 1.55; color: #1a1a1a; max-width: 640px; margin: 0 auto; padding: 20px 16px; }}
h1 {{ font-size: 1.35rem; border-bottom: 1px solid #e0e0e0; padding-bottom: 0.35em; }}
h2 {{ font-size: 1.15rem; margin-top: 1.25em; }}
h3 {{ font-size: 1.05rem; }}
code, pre {{ background: #f6f8fa; border-radius: 6px; }}
code {{ padding: 0.15em 0.4em; font-size: 0.9em; }}
pre {{ padding: 12px 14px; overflow-x: auto; font-size: 0.88em; line-height: 1.45; }}
pre code {{ background: none; padding: 0; }}
blockquote {{ border-left: 4px solid #d0d7de; margin: 0.8em 0; padding: 0.2em 0 0.2em 14px; color: #444; }}
a {{ color: #0969da; }}
hr {{ border: none; border-top: 1px solid #e0e0e0; margin: 1.5em 0; }}
ul, ol {{ padding-left: 1.35em; }}
table {{ border-collapse: collapse; width: 100%; font-size: 0.95em; }}
th, td {{ border: 1px solid #ddd; padding: 6px 10px; text-align: left; }}
th {{ background: #f6f8fa; }}
</style>
</head><body>
{inner}
</body></html>"""
    sys.stdout.write(doc)


if __name__ == "__main__":
    main()
