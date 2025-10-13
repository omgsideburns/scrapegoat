# Scrapegoat – Raspberry Pi price tracker

Scrapegoat automates Raspberry Pi price tracking from Micro Center brand listings.  
It scrapes the public catalog, stores raw snapshots, normalizes them into a history
table, annotates price events, and renders PNG charts for quick visual inspection.

## Data flow

- `scripts/search.py` fetches brand listings and writes dated CSV snapshots to `data/snapshots/`.
- `scripts/10_merge_history.py` parses each snapshot, normalizes rows, and maintains `data/history/price_history.csv` (append-only, unique on `(date, sku)`).
- `scripts/20_flags.py` annotates the history with rolling medians, sale flags, cumulative lows, and discount percentages; it also emits `data/history/price_flags.csv`.
- `scripts/25_export_json.py` publishes JSON bundles under `webroot/data/` for the website (latest listings, board matrix, per-SKU histories).
- `scripts/30_charts_timeseries.py` produces per-SKU time-series plots and stores them in `charts/<SKU>_history.png`.
- `scripts/31_chart_heatmap.py` renders `charts/heatmap_model_memory.png`, a model × memory price matrix based on the latest data.
- `scripts/26_build_markdown.py` assembles Markdown tables (SBC matrix plus power/camera/case/kit accessory tables when present) under `webroot/markdown/` for quick publishing.

All files are UTF-8 CSV/PNG. Snapshots remain immutable; history grows monotonically.

## Quickstart

```bash
pip install -r requirements.txt

# 1. Scrape a snapshot (defaults to the Micro Center Raspberry Pi brand listing)
python scripts/search.py --pages 1

# 2. Build the normalized history and annotate price events
python scripts/10_merge_history.py
python scripts/20_flags.py
python scripts/25_export_json.py
python scripts/26_build_markdown.py

# 3. Generate charts
python scripts/30_charts_timeseries.py
python scripts/31_chart_heatmap.py
```

By default the scraper writes to `data/snapshots/<UTC-date>_pi_brand.csv`. Pass a different listing URL as the first argument (or a custom `--out` path) if you need to scrape another brand or store.

Pipeline runs are idempotent: re-running generates the same history rows for existing snapshots and simply refreshes flags/charts.

## Repository layout

```
data/
  snapshots/            # raw, dated CSVs (immutable once written)
  history/              # normalized outputs
    price_history.csv   # append-only history, enriched by 20_flags.py
    price_flags.csv     # subset of rows flagged as sales or lows
charts/                 # generated PNG artifacts
webroot/
  data/                 # JSON exports consumed by the website
    history/            # per-SKU time series
  markdown/             # Markdown summaries for publishing
  sbc.php               # main Raspberry Pi board table + latest listings
  item.php              # per-SKU detail page (Chart.js)
  site.css              # shared styling
scripts/
  search.py             # Micro Center scraper CLI (do not break CLI signature)
  10_merge_history.py   # merge + normalize raw snapshots
  20_flags.py           # rolling medians, sale/lows flags
  25_export_json.py     # website JSON exports
  26_build_markdown.py  # Markdown tables for site/blog
  30_charts_timeseries.py
  31_chart_heatmap.py
  smoke_test.py         # local integration test
  upload_site.py        # rsync helper (fill in host/user/path)
```

## Automation (GitHub Actions)

`.github/workflows/scrape.yml` provisions Python 3.11, runs the full pipeline daily,
and commits updated artifacts (`data/snapshots/*.csv`, `data/history/*.csv`,
`webroot/data/*.json`, `charts/*.png`). Set a `workflow_dispatch` run to verify
everything works in your fork.

## Website frontend

The `webroot/` directory is a drop-in bundle for any PHP-capable host:

- `sbc.php` renders the Raspberry Pi board matrix and an interactive table of the
  most recent listings using DataTables (via CDN).
- `item.php` shows per-SKU price history plots using Chart.js plus a recent-price table.
- `site.css` keeps styling light and responsive; feel free to tweak for your theme.
- JSON files in `webroot/data/` come from `25_export_json.py` and are cache-friendly.

Serve this directory directly (or symlink it into your site). Regenerated JSON files
are uploaded via `scripts/upload_site.py` or any rsync/SCP workflow you prefer.

### Upload helper

`python scripts/upload_site.py` uses rsync over SSH to mirror the local `webroot/`
folder to a remote path. Edit the placeholder `HOST`, `USER`, `REMOTE_ROOT`, and
`SSH_KEY` values before first use. No secrets are committed to the repository.

## Development tips

- `python scripts/smoke_test.py` creates a synthetic snapshot and exercises the pipeline end-to-end.
- Keep snapshot filenames in the pattern `YYYY-MM-DD_pi_brand.csv` so merge logic can extract dates.
- The scraper optionally caches listing HTML with `--cache-dir` to aid troubleshooting or to work around Micro Center rate limits.
- Dependencies are limited to `requests`, `beautifulsoup4`, `pandas`, and `matplotlib` (see `requirements.txt`).
