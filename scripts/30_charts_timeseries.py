#!/usr/bin/env python3
"""
Generate per-SKU time series charts:
- line: price over time
- markers: 'v' for sale; '*' for all-time low
One chart per figure; no custom colors/styles (per constraints).
"""

from __future__ import annotations
from pathlib import Path
import pandas as pd
import matplotlib.pyplot as plt

HIST = Path("data/history/price_history.csv")
OUT_DIR = Path("charts")


def main() -> None:
    if not HIST.exists():
        raise SystemExit(
            "History file not found. Run 10_merge_history.py and 20_flags.py first."
        )

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    df = pd.read_csv(HIST, dtype={"sku": str}, parse_dates=["date"])
    df = df.sort_values(["sku", "date"])

    for sku, g in df.groupby("sku"):
        if g["price"].notna().sum() < 2:
            continue
        plt.figure()
        plt.plot(
            g["date"],
            g["price"],
            label=f"{sku} {g['name'].dropna().iloc[-1][:40] if g['name'].notna().any() else ''}",
        )
        if "is_sale" in g:
            sale_mask = g["is_sale"] == True
        else:
            sale_mask = pd.Series(False, index=g.index)
        if "is_low" in g:
            low_mask = g["is_low"] == True
        else:
            low_mask = pd.Series(False, index=g.index)
        sales = g[sale_mask]
        lows = g[low_mask]
        if not sales.empty:
            plt.scatter(sales["date"], sales["price"], marker="v")
        if not lows.empty:
            plt.scatter(lows["date"], lows["price"], marker="*")
        plt.title(f"{sku} price history")
        plt.xlabel("date")
        plt.ylabel("USD")
        plt.tight_layout()
        (OUT_DIR / f"{sku}_history.png").unlink(missing_ok=True)
        plt.savefig(OUT_DIR / f"{sku}_history.png")
        plt.close()

    print(f"Wrote charts to {OUT_DIR}/")


if __name__ == "__main__":
    main()
