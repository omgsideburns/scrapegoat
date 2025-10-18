#!/usr/bin/env python3
"""
Export history into JSON bundles consumed by the website frontend:
- latest.json: most recent row per SKU
- index.json: SKU/name directory for dropdowns
- sbc_matrix.json: model × memory board matrix
- history/<SKU>.json: per-SKU time series for charts
"""

from __future__ import annotations

import json
import re
from pathlib import Path

import pandas as pd

HIST = Path("data/history/price_history.csv")
OUT = Path("site/data")
HISTORY_OUT = OUT / "history"
BOARD_MODELS = ["Pi 3", "Pi 4", "Pi 5", "Pi 500", "Pi 500+"]


def _slugify_filename(value: str) -> str:
    value = (value or "").strip()
    if not value:
        return "unknown"
    slug = re.sub(r"[^\w.-]", "_", value)
    slug = re.sub(r"_+", "_", slug)
    return slug.strip("_") or "unknown"


def _ensure_dirs() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    HISTORY_OUT.mkdir(parents=True, exist_ok=True)
    for old in HISTORY_OUT.glob("*.json"):
        old.unlink()


def _load_history() -> pd.DataFrame:
    if not HIST.exists():
        raise SystemExit("History file not found. Run 10_merge_history.py first.")
    df = pd.read_csv(HIST, dtype={"sku": str}, parse_dates=["date"])
    if df.empty:
        raise SystemExit("History is empty.")
    return df


def _export_latest(latest: pd.DataFrame) -> None:
    cols = [
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
    latest_out = latest[cols].copy()
    latest_out["price"] = pd.to_numeric(latest_out["price"], errors="coerce").round(2)
    latest_out.to_json(OUT / "latest.json", orient="records", indent=2)

    idx_rows = latest[["sku", "name"]].sort_values("name").reset_index(drop=True)
    idx_rows.to_json(OUT / "index.json", orient="records", indent=2)


def _export_matrix(latest: pd.DataFrame) -> None:
    boards = latest[latest["model"].isin(BOARD_MODELS)].copy()
    boards["memory_gb"] = pd.to_numeric(boards["memory_gb"], errors="coerce")
    boards["price"] = pd.to_numeric(boards["price"], errors="coerce")

    if boards.empty:
        matrix = {"rows": [], "cols": [], "values": []}
    else:
        pivot = boards.pivot_table(
            index="model", columns="memory_gb", values="price", aggfunc="min"
        )
        cols = [c for c in pivot.columns if pd.notna(c)]
        rows = list(pivot.index)
        values = []
        for _, row in pivot.iterrows():
            ordered = row.reindex(cols)
            values.append(
                [
                    None if pd.isna(val) else round(float(val), 2)
                    for val in ordered
                ]
            )
        matrix = {"rows": rows, "cols": cols, "values": values}

    (OUT / "sbc_matrix.json").write_text(
        json.dumps(matrix, indent=2), encoding="utf-8"
    )


def _export_histories(df: pd.DataFrame) -> None:
    for sku, group in df.sort_values("date").groupby("sku"):
        group = group.reset_index(drop=True)
        series = []
        for _, row in group.iterrows():
            price = pd.to_numeric(row.get("price"), errors="coerce")
            if pd.isna(price):
                continue
            dt = row["date"]
            date_str = dt.strftime("%Y-%m-%d") if hasattr(dt, "strftime") else str(dt)
            series.append(
                {
                    "date": date_str,
                    "price": round(float(price), 2),
                    "is_sale": bool(row.get("is_sale", False)),
                    "is_low": bool(row.get("is_low", False)),
                }
            )
        if not series:
            continue
        if "name" in group and group["name"].notna().any():
            name = group["name"].dropna().iloc[-1]
        else:
            name = sku
        payload = {"sku": str(sku), "name": name, "series": series}
        filename = _slugify_filename(str(sku))
        (HISTORY_OUT / f"{filename}.json").write_text(
            json.dumps(payload, indent=2), encoding="utf-8"
        )


def main() -> None:
    _ensure_dirs()
    df = _load_history()
    df = df.sort_values("date").reset_index(drop=True)

    idx = df.groupby("sku")["date"].idxmax()
    latest = df.loc[idx].copy()

    _export_latest(latest)
    _export_matrix(latest)
    _export_histories(df)
    print(f"Exported JSON to {OUT}/")


if __name__ == "__main__":
    main()
