#!/usr/bin/env python3
"""
Extract the headline Raspberry Pi table and refresh the README snippet.

Outputs:
- site/snippets/pi_boards.md
- README.md (content between <!-- pi-table:start --> and <!-- pi-table:end -->)
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MARKDOWN_SOURCE = ROOT / "site" / "markdown" / "raspberry_pi.md"
SNIPPET_PATH = ROOT / "site" / "snippets" / "pi_boards.md"
README_PATH = ROOT / "README.md"

START_MARKER = "<!-- pi-table:start -->"
END_MARKER = "<!-- pi-table:end -->"


def extract_snippet(markdown: str) -> str:
    lines = markdown.splitlines()
    snippet_lines: list[str] = []
    capturing = False
    seen_subheading = False

    for line in lines:
        if not capturing:
            if line.strip().startswith("## "):
                capturing = True
            else:
                continue

        if line.startswith("### "):
            if not seen_subheading:
                seen_subheading = True
            else:
                break

        snippet_lines.append(line)

    snippet = "\n".join(snippet_lines).strip()
    if not snippet:
        raise SystemExit("Failed to extract snippet from raspberry_pi.md")
    return snippet + "\n"


def write_snippet(snippet: str) -> None:
    SNIPPET_PATH.parent.mkdir(parents=True, exist_ok=True)
    SNIPPET_PATH.write_text(snippet, encoding="utf-8")


def update_readme(snippet: str) -> None:
    content = README_PATH.read_text(encoding="utf-8")

    try:
        start_idx = content.index(START_MARKER) + len(START_MARKER)
        end_idx = content.index(END_MARKER, start_idx)
    except ValueError as exc:
        raise SystemExit("README markers not found for pi-table snippet") from exc

    snippet_block = "\n\n" + snippet.rstrip() + "\n\n"
    updated = content[:start_idx] + snippet_block + content[end_idx:]

    README_PATH.write_text(updated, encoding="utf-8")


def main() -> None:
    if not MARKDOWN_SOURCE.exists():
        raise SystemExit(f"Markdown source not found: {MARKDOWN_SOURCE}")

    markdown = MARKDOWN_SOURCE.read_text(encoding="utf-8")
    snippet = extract_snippet(markdown)
    write_snippet(snippet)
    update_readme(snippet)


if __name__ == "__main__":
    main()
