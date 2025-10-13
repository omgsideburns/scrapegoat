#!/usr/bin/env python3
"""
Creates a tiny fake snapshot, runs the pipeline, and verifies outputs exist.
Run: python scripts/smoke_test.py
"""

from __future__ import annotations
from pathlib import Path
import csv, datetime as dt, subprocess, sys

ROOT = Path(__file__).resolve().parents[1]
SNAP = ROOT / "data/snapshots"
HIST = ROOT / "data/history/price_history.csv"
def main() -> None:
    SNAP.mkdir(parents=True, exist_ok=True)
    today = dt.date.today().isoformat()
    snap_path = SNAP / f"{today}_pi_brand.csv"

    # minimal snapshot with two rows
    with snap_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["sku", "name", "price", "availability", "stock", "url"])
        w.writerow(
            [
                "999001",
                "Raspberry Pi 4 4GB Board",
                "$54.99",
                "In stock",
                "In Stock",
                "https://example.com/p1",
            ]
        )
        w.writerow(
            [
                "999002",
                "Raspberry Pi 5 8GB Board",
                "$79.99",
                "In-Store Only",
                "Buy In Store",
                "https://example.com/p2",
            ]
        )

    cmds = [
        [sys.executable, "scripts/10_merge_history.py"],
        [sys.executable, "scripts/20_flags.py"],
        [sys.executable, "scripts/25_export_json.py"],
        [sys.executable, "scripts/26_build_markdown.py"],
    ]
    for cmd in cmds:
        subprocess.check_call(cmd, cwd=ROOT)

    assert HIST.exists(), "history not created"
    latest_json = ROOT / "webroot/data/latest.json"
    assert latest_json.exists(), "latest.json not exported"
    sample_history = ROOT / "webroot/data/history/999001.json"
    assert sample_history.exists(), "per-SKU history missing"
    markdown_report = ROOT / "webroot/markdown/raspberry_pi.md"
    assert markdown_report.exists(), "markdown report missing"
    print("Smoke test passed.")


if __name__ == "__main__":
    main()
