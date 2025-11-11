# Scrapegoat – Raspberry Pi price data & tooling

Scrapegoat runs daily to collect Raspberry Pi pricing from public Micro Center listings. Every run generates CSV history, Markdown summaries, and JSON feeds that are committed to this repository so anyone can pull the data straight from GitHub — no scraper required.

The project also ships a lightweight PHP bundle so you can drop the live tables into your own site, plus the full collection pipeline if you want to regenerate everything yourself.

The current price table is displayed below.

<!-- pi-table:start -->

## Raspberry Pi Devices

These are the prices at MicroCenter as of Nov 11 2025

### Raspberry Pi Boards

|Memory|Zero W <sup>1.</sup>|Zero 2 W <sup>1.</sup>|Pi 3A+|Pi 4B|Pi 5|
|:-|-:|-:|-:|-:|-:|
|512MB|[*$7.99*](https://www.microcenter.com/product/486575/raspberry-pi-zero-w-microcontroller-development-board)|[*$14.99*](https://www.microcenter.com/product/643085/raspberry-pi-zero-2-w)|[*$24.99*](https://www.microcenter.com/product/514076/raspberry-pi-3-model-a-board)|x|x|
|1GB|x|x|x|[*$34.99*](https://www.microcenter.com/product/665122/raspberry-pi-4-model-b)|x|
|2GB|x|x|x|[*$44.99*](https://www.microcenter.com/product/621439/raspberry-pi-4-model-b)|[*$39.99*](https://www.microcenter.com/product/683269/raspberry-pi-5)|
|4GB|x|x|x|[*$54.99*](https://www.microcenter.com/product/637834/raspberry-pi-4-model-b)|[*$49.99*](https://www.microcenter.com/product/673712/raspberry-pi-5)|
|8GB|x|x|x|[*$74.99*](https://www.microcenter.com/product/622539/raspberry-pi-4-model-b)|[*$64.99*](https://www.microcenter.com/product/673711/raspberry-pi-5)|
|16GB|x|x|x|x|[*$99.99*](https://www.microcenter.com/product/702590/raspberry-pi-5)|

Key: *price* = in-store only / pickup.

<sup>1.</sup> Add $1.00 for a Zero with pre-soldered headers.

<!-- pi-table:end -->

## What lives where

- **Shared data** (`data/`, `site/data/`, `site/markdown/`) – CSV history, daily snapshots, JSON/Markdown feeds that stay versioned in git. These are the files you can hotlink or download for your own analysis.
- **PHP embed package** (`embed/`) – a standalone include that renders the Scrapegoat tables anywhere PHP runs.
- **Site bundle** (`site/`) – the production pages and assets that power <https://xtonyx.org/scrapegoat/>.
- **Data pipeline** (`pipeline/`) – Python scripts that scrape Micro Center, merge history, flag sales, and publish the site/embed artifacts.

## Grab the data

The easiest entry point is the published JSON and Markdown under `site/`:

| Artifact                        | Description                                                         | Raw URL                                                                                        |
| ------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `site/data/latest.json`         | Most recent listing per SKU with price, availability, and metadata. | <https://raw.githubusercontent.com/omgsideburns/scrapegoat/main/site/data/latest.json>         |
| `site/data/sbc_matrix.json`     | Memory × model pricing grid for the main Raspberry Pi boards.       | <https://raw.githubusercontent.com/omgsideburns/scrapegoat/main/site/data/sbc_matrix.json>     |
| `site/data/history/<SKU>.json`  | Per-SKU time series used for charts.                                | replace `<SKU>` as needed                                                                      |
| `site/markdown/raspberry_pi.md` | Markdown tables used on the site/blog.                              | <https://raw.githubusercontent.com/omgsideburns/scrapegoat/main/site/markdown/raspberry_pi.md> |

If you need the raw inputs, `data/snapshots/` stores immutable CSV grabs of the Micro Center brand feed and `data/history/` holds the normalized, annotated history (`price_history.csv`, `price_flags.csv`).

## Drop the tables into your own site

1. Copy the `embed/` directory (and optionally `site/site.css`) into your project.
2. Add the stylesheet or link to the hosted version:
   ```html
   <link rel="stylesheet" href="https://xtonyx.org/scrapegoat/site.css" />
   ```
3. Include the tables:
   ```php
   <?php
   include __DIR__ . '/scrapegoat/embed/include.php';
   ?>
   ```
4. Optionally override `$GLOBALS['scrapegoatEmbedOptions']` (or define `SCRAPEGOAT_EMBED_OPTIONS`) before the include to tweak nav links, footer copy, wrapper classes, etc. See `embed/README.md` for details.

The embed loader fetches `site/markdown/raspberry_pi.md` and `site/data/latest.json` directly from GitHub by default. Set `SCRAPEGOAT_REMOTE_BASE_URL` if you mirror the assets elsewhere.

## Run the pipeline yourself

```bash
pip install -r requirements.txt

# 1. Scrape the Micro Center Raspberry Pi listings
python pipeline/search.py --pages 1

# 2. Build history and publish artifacts
python pipeline/10_merge_history.py
python pipeline/20_flags.py
python pipeline/25_export_json.py
python pipeline/26_build_markdown.py
```

Snapshots are written to `data/snapshots/<UTC-date>_pi_brand.csv`, history grows monotonically in `data/history/price_history.csv`, and exports land in `site/data/` + `site/markdown/`. Re-running is idempotent: existing snapshots are reprocessed and downstream files are refreshed.

## Repository layout

```
data/
  snapshots/            # raw dated CSV pulls (immutable once written)
  history/              # normalized outputs (price_history.csv, price_flags.csv)
embed/                  # drop-in PHP package for third-party sites
pipeline/
  search.py             # Micro Center scraper CLI
  10_merge_history.py   # merge + normalize snapshots
  20_flags.py           # rolling medians, sale/low flags
  25_export_json.py     # JSON feeds for site/embed
  26_build_markdown.py  # Markdown summary tables
  smoke_test.py         # end-to-end integration run
  upload_site.py        # rsync helper for deploying the site bundle
site/
  chrome/               # optional header/footer fragments
  data/                 # published JSON used by the site/embed
  markdown/             # published Markdown report
  tables.php            # main board tables (uses the embed renderer)
  sbc.php, item.php     # interactive table + per-SKU view
  site.css              # shared styles
```

## Automation

`.github/workflows/scrape.yml` provisions Python 3.11, runs the full pipeline daily, and commits refreshed artifacts (`data/snapshots/*.csv`, `data/history/*.csv`, `site/data/*.json`, `site/markdown/*.md`). Trigger `workflow_dispatch` for ad-hoc runs.

## Deploying the site bundle

`python pipeline/upload_site.py` mirrors the local `site/` directory to a remote host via rsync over SSH. Fill in `HOST`, `USER`, `REMOTE_ROOT`, and `SSH_KEY` before first use. No secrets are checked into the repo.

## Development tips

- `python pipeline/smoke_test.py` creates a synthetic snapshot and verifies the pipeline end-to-end.
- Stick to the snapshot filename pattern `YYYY-MM-DD_pi_brand.csv` so merge logic extracts dates correctly.
- The scraper accepts `--cache-dir` to persist raw HTML when debugging or working around rate limits.
- Dependencies are kept light (`requests`, `beautifulsoup4`, `pandas`, `matplotlib`) and listed in `requirements.txt`.
