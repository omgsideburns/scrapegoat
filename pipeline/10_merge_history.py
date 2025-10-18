#!/usr/bin/env python3
"""
Merge snapshot CSVs (raw scrapes) into a normalized, append-only history.

Inputs:  data/snapshots/*_pi_brand.csv  (columns: sku,name,price,availability,stock,url)
Output:  data/history/price_history.csv (columns below)
Columns:
  date, sku, name, url, price, stock, availability, model, memory_gb, brand
"""

from __future__ import annotations
import re
from pathlib import Path
import pandas as pd

SNAP_DIR = Path("data/snapshots")
HIST_PATH = Path("data/history/price_history.csv")
HIST_PATH.parent.mkdir(parents=True, exist_ok=True)


def _extract_model(name: str) -> str:
    n = (name or "").lower()
    if "raspberry pi 5" in n:
        return "Pi 5"
    if "raspberry pi 4" in n:
        return "Pi 4"
    if "raspberry pi 3" in n:
        return "Pi 3"
    if "500 plus" in n or "500+" in n:
        return "Pi 500+"
    if "500" in n:
        return "Pi 500"
    if "pico 2" in n:
        return "Pico 2"
    if "pico" in n:
        return "Pico"
    return "Accessory"


def _extract_mem_gb(name: str) -> float | None:
    m = re.search(r"\b(512|1|2|4|8|16)\s*(GB|MB)\b", name or "", re.I)
    if not m:
        return None
    amount = float(m.group(1))
    unit = m.group(2).lower()
    if unit == "mb":
        return round(amount / 1024, 3)
    return amount


def _normalize_price(series: pd.Series) -> pd.Series:
    # Strip anything not digit/dot and cast to float
    return pd.to_numeric(
        series.astype(str).str.replace(r"[^0-9.]", "", regex=True), errors="coerce"
    )


def _load_history() -> pd.DataFrame:
    if HIST_PATH.exists():
        return pd.read_csv(HIST_PATH, dtype={"sku": str})
    cols = [
        "date",
        "sku",
        "name",
        "url",
        "price",
        "stock",
        "availability",
        "model",
        "memory_gb",
        "brand",
    ]
    return pd.DataFrame(columns=cols)


def main() -> None:
    snaps = sorted(SNAP_DIR.glob("*_pi_brand.csv"))
    if not snaps:
        print("No snapshots found in data/snapshots; nothing to merge.")
        return
    frames = []
    for snap in snaps:
        date_part = snap.name.split("_")[0]  # YYYY-MM-DD
        df = pd.read_csv(snap, dtype={"sku": str})
        if df.empty:
            continue

        df["sku"] = (
            df["sku"]
            .fillna("")
            .astype(str)
            .str.strip()
            .replace({"nan": "", "None": "", "none": ""})
        )
        df["name"] = df["name"].fillna("").astype(str)
        # expected columns: sku,name,price,availability,stock,url
        df["date"] = date_part
        df["brand"] = "Raspberry Pi"
        df["model"] = df["name"].map(_extract_model)
        df["memory_gb"] = df["name"].map(_extract_mem_gb)
        df["price"] = _normalize_price(df["price"])
        frames.append(
            df[
                [
                    "date",
                    "sku",
                    "name",
                    "url",
                    "price",
                    "stock",
                    "availability",
                    "model",
                    "memory_gb",
                    "brand",
                ]
            ]
        )

    history = _load_history()
    merged_sources = [history] + frames if not history.empty else frames
    if not merged_sources:
        print("No rows to merge.")
        return

    merged = pd.concat(merged_sources, ignore_index=True)

    # ensure string columns are clean
    for col in ("date", "sku", "name", "url", "stock", "availability", "brand", "model"):
        if col in merged:
            merged[col] = merged[col].fillna("").astype(str).str.strip()

    # De-dupe on (date, sku) for rows that have a SKU
    with_sku = merged[merged["sku"] != ""].drop_duplicates(
        subset=["date", "sku"], keep="first"
    )
    no_sku = merged[merged["sku"] == ""]
    merged = pd.concat([with_sku, no_sku], ignore_index=True)
    merged = merged.sort_values(["sku", "date"], kind="mergesort")
    merged["date"] = merged["date"].replace(pd.NA, "").fillna("").astype(str)

    merged.to_csv(HIST_PATH, index=False)
    print(f"Wrote {HIST_PATH} with {len(merged)} rows")


if __name__ == "__main__":
    main()
