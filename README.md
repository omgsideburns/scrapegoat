# Scrapegoat – Raspberry Pi price tracker

Scrapegoat automates Raspberry Pi price tracking from Micro Center brand listings.  
It scrapes the public catalog, stores raw snapshots, normalizes them into a history
table, annotates price events, and renders PNG charts for quick visual inspection.

## Data flow

- `scripts/search.py` fetches brand listings and writes dated CSV snapshots to `data/snapshots/`.
- `scripts/10_merge_history.py` parses each snapshot, normalizes rows, and maintains `data/history/price_history.csv` (append-only, unique on `(date, sku)`).
- `scripts/20_flags.py` annotates the history with rolling medians, sale flags, cumulative lows, and discount percentages; it also emits `data/history/price_flags.csv`.
- `scripts/30_charts_timeseries.py` produces per-SKU time-series plots and stores them in `charts/<SKU>_history.png`.
- `scripts/31_chart_heatmap.py` renders `charts/heatmap_model_memory.png`, a model × memory price matrix based on the latest data.

All files are UTF-8 CSV/PNG. Snapshots remain immutable; history grows monotonically.

## Quickstart

```bash
pip install -r requirements.txt

# 1. Scrape a snapshot (Micro Center Raspberry Pi brand listing shown here)
python scripts/search.py "https://www.microcenter.com/search/search_results.aspx?fq=brand:Raspberry+Pi&sortby=match&rpp=96&myStore=false" \
  --out "data/snapshots/$(date +%F)_pi_brand.csv" --pages 1

# 2. Build the normalized history and annotate price events
python scripts/10_merge_history.py
python scripts/20_flags.py

# 3. Generate charts
python scripts/30_charts_timeseries.py
python scripts/31_chart_heatmap.py
```

Pipeline runs are idempotent: re-running generates the same history rows for existing snapshots and simply refreshes flags/charts.

## Repository layout

```
data/
  snapshots/            # raw, dated CSVs (immutable once written)
  history/              # normalized outputs
    price_history.csv   # append-only history, enriched by 20_flags.py
    price_flags.csv     # subset of rows flagged as sales or lows
charts/                 # generated PNG artifacts
scripts/
  search.py             # Micro Center scraper CLI (do not break CLI signature)
  10_merge_history.py   # merge + normalize raw snapshots
  20_flags.py           # rolling medians, sale/lows flags
  30_charts_timeseries.py
  31_chart_heatmap.py
```

## Automation (GitHub Actions)

`.github/workflows/scrape.yml` provisions Python 3.11, runs the full pipeline daily,
and commits updated artifacts (`data/snapshots/*.csv`, `data/history/price_history.csv`,
`charts/*.png`). Set a `workflow_dispatch` run to verify everything works in your fork.

## Development tips

- `python scripts/smoke_test.py` creates a synthetic snapshot and exercises the pipeline end-to-end.
- Keep snapshot filenames in the pattern `YYYY-MM-DD_pi_brand.csv` so merge logic can extract dates.
- The scraper optionally caches listing HTML with `--cache-dir` to aid troubleshooting or to work around Micro Center rate limits.

All dependencies are listed in `requirements.txt` and limited to `requests`, `beautifulsoup4`, `pandas`, and `matplotlib`, per project constraints.

## Issue: Implement price pipeline

- Add requirements.txt
- Implement 10_merge_history.py
- Implement 20_flags.py
- Implement 30_charts_timeseries.py
- Implement 31_chart_heatmap.py
- Add .github/workflows/scrape.yml
- README quickstart
- Smoke test passes on CI

## PR Checklist

- black/ruff clean (if used)
- Re-run pipeline locally; attach generated charts/heatmap_model_memory.png to PR
- No changes to search.py interface
- CI green
