#!/usr/bin/env python3
"""
Add sale/low-price flags to history and emit a filtered flags file.
- is_sale: price <= 95% of 30-row median AND at least $1 off that median
- is_low : price equals new cumulative minimum for the SKU
"""

from __future__ import annotations
from pathlib import Path
import pandas as pd

HIST = Path("data/history/price_history.csv")
OUT = Path("data/history/price_flags.csv")


def main() -> None:
    if not HIST.exists():
        raise SystemExit("History file not found. Run 10_merge_history.py first.")

    df = pd.read_csv(HIST, dtype={"sku": str})
    df["price"] = pd.to_numeric(df["price"], errors="coerce")
    df["date"] = df["date"].astype(str)

    def per_sku(g: pd.DataFrame) -> pd.DataFrame:
        g = g.copy()
        g["date_dt"] = pd.to_datetime(g["date"], errors="coerce")
        g = g.sort_values("date_dt")
        g["rolling_median"] = (
            g.rolling(window="30D", on="date_dt", min_periods=3)["price"].median()
        )
        g["ever_min"] = g["price"].cummin()
        # sale flag
        g["is_sale"] = (
            g["rolling_median"].notna()
            & (g["price"] <= g["rolling_median"] * 0.95)
            & ((g["rolling_median"] - g["price"]) >= 1.0)
        )
        # all-time low
        g["is_low"] = g["price"] <= g["ever_min"]
        # pct off vs median (non-negative)
        denom = g["rolling_median"].where(g["rolling_median"] > 0)
        pct = (1.0 - g["price"] / denom).clip(lower=0)
        pct = pct.where(denom.notna())
        g["pct_off"] = pct.round(3)
        g = g.drop(columns=["date_dt"])
        return g

    df = df.groupby("sku", group_keys=False).apply(per_sku)
    df = df.sort_values(["sku", "date"])
    df.to_csv(HIST, index=False)

    flags = df[(df["is_sale"] == True) | (df["is_low"] == True)].copy()
    flags.to_csv(OUT, index=False)
    print(f"Updated {HIST} and wrote {OUT} with {len(flags)} flagged rows")


if __name__ == "__main__":
    main()
