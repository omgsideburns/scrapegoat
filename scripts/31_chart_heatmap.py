#!/usr/bin/env python3
"""
Build a model×memory heatmap from the latest prices per SKU.
- Rows: model (Pi 3/4/5/500/500+)
- Cols: memory_gb (2/4/8/16 where present)
- Values: min price among SKUs matching that model/memory
"""

from __future__ import annotations
from pathlib import Path
import numpy as np
import pandas as pd
import matplotlib.pyplot as plt

HIST = Path("data/history/price_history.csv")
OUT_DIR = Path("charts")

FOCUS_MODELS = ["Pi 3", "Pi 4", "Pi 5", "Pi 500", "Pi 500+"]


def main() -> None:
    if not HIST.exists():
        raise SystemExit("History file not found. Run 10_merge_history.py first.")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    df = pd.read_csv(HIST, dtype={"sku": str})
    if df.empty:
        raise SystemExit("History is empty.")

    # latest row per SKU
    df["date_dt"] = pd.to_datetime(df["date"], errors="coerce")
    idx = df.groupby("sku")["date_dt"].idxmax()
    latest = df.loc[idx].copy()
    latest = latest.drop(columns=["date_dt"])
    latest["memory_gb"] = pd.to_numeric(latest["memory_gb"], errors="coerce")
    latest = latest[latest["model"].isin(FOCUS_MODELS)]

    if latest.empty:
        print("No board models with memory to plot.")
        return

    pivot = latest.pivot_table(
        index="model", columns="memory_gb", values="price", aggfunc="min"
    )
    pivot = pivot.reindex(index=FOCUS_MODELS)

    plt.figure()
    im = plt.imshow(pivot.values, aspect="auto")
    plt.xticks(range(len(pivot.columns)), pivot.columns)
    plt.yticks(range(len(pivot.index)), pivot.index)
    plt.title("Price by model vs memory (latest)")
    # write numeric labels
    for i, _row in enumerate(pivot.index):
        for j, _col in enumerate(pivot.columns):
            v = pivot.values[i, j]
            if not (v is None or np.isnan(v)):
                plt.text(j, i, f"${v:.0f}", ha="center", va="center")
    plt.colorbar(im)
    plt.tight_layout()
    out_path = OUT_DIR / "heatmap_model_memory.png"
    out_path.unlink(missing_ok=True)
    plt.savefig(out_path)
    plt.close()
    print(f"Wrote {out_path}")


if __name__ == "__main__":
    main()
