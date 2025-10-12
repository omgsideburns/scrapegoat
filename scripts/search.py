#!/usr/bin/env python3
"""
Scrape Micro Center brand results for Raspberry Pi (or any brand listing URL):
- SKU, Name, Price, Availability (raw), Stock (normalized), URL

Extras:
  --cache-dir DIR   Save fetched listing HTML
  --cache-tiles     Also save each product tile's HTML snippet

Usage:
  python search.py "https://www.microcenter.com/search/search_results.aspx?fq=brand:Raspberry+Pi&sortby=match&rpp=96&myStore=false" \
    --out pi_brand.csv --pages 1 --cache-dir cache --cache-tiles
"""

import argparse, csv, hashlib, json, re, sys, time, urllib.parse
from datetime import datetime
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# ---------- HTTP session with warmup + resilient headers ----------
HEADERS_PRIMARY = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/127.0.0.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
    "Cache-Control": "no-cache",
    "Pragma": "no-cache",
    "Upgrade-Insecure-Requests": "1",
    "Referer": "https://www.microcenter.com/search/search_results.aspx",
    "Connection": "keep-alive",
}
HEADERS_ALT = {
    **HEADERS_PRIMARY,
    "Sec-Fetch-Site": "same-origin",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-User": "?1",
    "Sec-Fetch-Dest": "document",
}
SESSION = requests.Session()
SESSION.headers.update(HEADERS_PRIMARY)


def _attempt_get(url, headers, timeout=30):
    r = SESSION.get(url, headers=headers, timeout=timeout, allow_redirects=True)
    if r.status_code == 403:
        raise requests.HTTPError(f"403 for {url}", response=r)
    r.raise_for_status()
    return r.url, r.text


def get(url):
    """
    Warm up cookies, then try multiple header/URL variants.
    """
    # --- warm up once per run to set cookies/clearance ---
    if not getattr(SESSION, "_warm", False):
        try:
            SESSION.get("https://www.microcenter.com/", timeout=15)
            SESSION.get("https://www.microcenter.com/categories", timeout=15)
            # touch "Shippable Items" store to encourage server-rendered listings
            SESSION.get(
                "https://www.microcenter.com/search/search_results.aspx?storeid=029",
                timeout=15,
            )
            SESSION._warm = True
            time.sleep(0.5)
        except Exception:
            pass

    strategies = []
    strategies.append((url, HEADERS_PRIMARY))
    strategies.append((url, HEADERS_ALT))

    if "fq=brand:Raspberry+Pi" in url:
        u_enc = url.replace("fq=brand:Raspberry+Pi", "fq=brand:Raspberry%20Pi")
        strategies.append((u_enc, HEADERS_ALT))

    if "myStore=false" in url:
        u_nostore = url.replace("&myStore=false", "")
        strategies.append((u_nostore, HEADERS_ALT))

    last_err = None
    for u, h in strategies:
        try:
            return _attempt_get(u, h)
        except Exception as e:
            last_err = e
            time.sleep(0.6)

    if isinstance(last_err, requests.HTTPError) and last_err.response is not None:
        code = last_err.response.status_code
        text = last_err.response.text[:300].replace("\n", " ")
        raise requests.HTTPError(
            f"{code} for {last_err.response.url} :: {text}"
        ) from last_err
    raise last_err or RuntimeError("Unknown fetch error")


# ---------- Helpers ----------
BASE = "https://www.microcenter.com"
SKU_RE = re.compile(r"\bSKU:\s*([0-9]+)\b", re.I)
PRICE_RE = re.compile(r"\$\s*([0-9][0-9,]*(?:\.[0-9]{2})?)")
SKU_CLASS_RE = re.compile(r"\bsku\b", re.I)


def normalize_price(val: str) -> str:
    m = re.search(r"([0-9][0-9,]*(?:\.[0-9]{2})?)", val or "")
    if not m:
        return ""
    amt = m.group(1).replace(",", "")
    try:
        return f"${float(amt):.2f}"
    except ValueError:
        return ""


AVAIL_HINTS = (
    "Usually ships",
    "In stock",
    "SOLD OUT",
    "Sold Out",
    "Out of Stock",
    "In-Store Only",
    "Buy In Store",
    "Pickup Today",
    "In-Store Pickup",
    "Pickup Only",
)


def classify_availability(text: str) -> tuple[str, str]:
    t = (text or "").lower()
    availability_raw = ""
    for h in AVAIL_HINTS:
        if h.lower() in t:
            availability_raw = h
            break
    if (
        "buy in store" in t
        or "in-store only" in t
        or "in store only" in t
        or "pickup today" in t
        or "in-store pickup" in t
        or "pickup only" in t
    ):
        return availability_raw or "Buy In Store", "Buy In Store"
    if "sold out" in t or "out of stock" in t:
        return availability_raw or "Sold Out", "Out of Stock"
    if "usually ships" in t or "in stock" in t:
        return availability_raw or "In stock", "In Stock"
    return availability_raw, ""


def absolutize(href):
    if href.startswith("http"):
        return href
    return urllib.parse.urljoin(BASE, href)


# ---------- Caching ----------
def _ts():
    return datetime.utcnow().strftime("%Y%m%dT%H%M%SZ")


def _short_hash(s: str) -> str:
    return hashlib.sha1((s or "").encode("utf-8")).hexdigest()[:10]


def cache_save_html(cache_dir: Path, kind: str, key: str, html: str):
    """
    Save HTML under cache_dir with a stable-ish filename.
    kind: 'listing' or 'tile'
    key: url (for listing) or 'SKU:<sku>' (for tile)
    """
    cache_dir.mkdir(parents=True, exist_ok=True)
    safe_kind = re.sub(r"[^a-z0-9_-]+", "-", kind.lower())
    base = f"{_ts()}__{safe_kind}__{_short_hash(key)}.html"
    path = cache_dir / base
    path.write_text(html or "", encoding="utf-8")
    return str(path)


# ---------- JSON-LD fallback (ItemList on listing pages) ----------
def parse_listing_jsonld(html: str):
    """
    Return rows from JSON-LD ItemList on listing pages.
    Each row: {sku,name,price,availability,stock,url}
    """
    rows = []
    soup = BeautifulSoup(html, "html.parser")
    for tag in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(tag.string or "")
        except Exception:
            continue

        stack = []
        if isinstance(data, dict):
            stack.append(data)
            for k in ("@graph", "itemListElement"):
                v = data.get(k)
                if isinstance(v, list):
                    stack.extend([d for d in v if isinstance(d, dict)])
        elif isinstance(data, list):
            stack.extend([d for d in data if isinstance(d, dict)])

        for d in stack:
            if d.get("@type") == "ItemList" and isinstance(
                d.get("itemListElement"), list
            ):
                for li in d["itemListElement"]:
                    if not isinstance(li, dict):
                        continue
                    item = li.get("item")
                    if isinstance(item, dict) and item.get("@type") == "Product":
                        name = (item.get("name") or "").strip()
                        url = absolutize((item.get("url") or "").strip())
                        sku = str(item.get("sku") or "").strip()

                        # price from offers if available
                        price = ""
                        offers = item.get("offers")
                        if isinstance(offers, dict):
                            p = offers.get("price") or (
                                offers.get("priceSpecification") or {}
                            ).get("price")
                            if p:
                                try:
                                    price = f"${float(str(p).replace(',', '')):.2f}"
                                except Exception:
                                    price = str(p)

                        # availability normalization
                        availability_raw = ""
                        stock = ""
                        if isinstance(offers, dict):
                            availability_raw = (offers.get("availability") or "").split(
                                "/"
                            )[-1]
                            t = availability_raw.lower()
                            if "outofstock" in t or "out of stock" in t:
                                stock = "Out of Stock"
                            elif "instock" in t or "in stock" in t:
                                stock = "In Stock"

                        rows.append(
                            {
                                "sku": sku,
                                "name": name,
                                "price": price,
                                "availability": availability_raw.replace(
                                    "-", " "
                                ).title()
                                if availability_raw
                                else "",
                                "stock": stock,
                                "url": url,
                            }
                        )
    return rows


# ---------- Parsing tiles ----------
def extract_tile_data(tile):
    txt = tile.get_text(" ", strip=True)

    # SKU
    msku = SKU_RE.search(txt)
    sku = msku.group(1) if msku else ""

    # Name + URL
    name = ""
    prod_link = None
    for a in tile.find_all("a", href=True):
        if "/product/" in a["href"]:
            prod_link = absolutize(a["href"])
            t = a.get_text(" ", strip=True)
            if len(t) > len(name):
                name = t

    # Price
    price = ""
    price_node = tile.find(attrs={"itemprop": "price"})
    if price_node:
        price = normalize_price(price_node.get_text("", strip=True))
    if not price:
        meta_price = tile.find("meta", attrs={"itemprop": "price"})
        if meta_price and meta_price.get("content"):
            price = normalize_price(meta_price["content"])
    if not price:
        price = normalize_price(txt)

    availability, stock = classify_availability(txt)

    return {
        "sku": sku,
        "name": name,
        "price": price,
        "availability": availability,
        "stock": stock,
        "url": prod_link or "",
        "tile_html": str(tile),  # for optional caching
    }


def _locate_tile_from_anchor(anchor):
    """
    Walk anchor ancestors and try to locate the product wrapper element.
    Prefer <li>/<article> with product-ish classes, otherwise fall back to a div
    that actually contains price data to avoid stopping on partial columns.
    """
    best = None
    for depth, parent in enumerate(anchor.parents):
        if depth > 10 or not getattr(parent, "name", None):
            break
        if parent.name in {"html", "body"}:
            break

        classes = " ".join(parent.get("class", [])).lower()
        has_product_hint = any(
            hint in classes
            for hint in (
                "product",
                "result",
                "search-item",
                "listing",
                "tile",
            )
        )

        if parent.name in {"li", "article"} and has_product_hint:
            return parent

        if parent.name in {"div", "section"} and has_product_hint:
            if parent.find(attrs={"itemprop": "price"}) or PRICE_RE.search(
                parent.get_text(" ", strip=True)
            ):
                best = best or parent

        if parent.name in {"ul", "ol"}:
            if best:
                return best
            break

    return best


def find_product_tiles(soup):
    """
    Return a list of product tile elements from the listing soup.
    Tries explicit selectors first, then falls back to anchor-based traversal.
    """
    selectors = [
        "li.product_wrapper",
        "li.product-wrap",
        "li.Product_wrapper",
        "article.product_wrapper",
        "div.product_wrapper",
        "div.product-tile",
        "div.product_tile",
        "div.product-grid__item",
        "div.productGridItem",
    ]
    seen, tiles = set(), []
    for selector in selectors:
        for tile in soup.select(selector):
            if not getattr(tile, "name", None):
                continue
            key = id(tile)
            if key in seen:
                continue
            if not tile.find("a", href=re.compile("/product/")):
                continue
            txt = tile.get_text(" ", strip=True)
            has_price = bool(
                tile.find(attrs={"itemprop": "price"}) or PRICE_RE.search(txt)
            )
            has_sku = "sku:" in txt.lower() or tile.find(class_=SKU_CLASS_RE)
            if not (has_price or has_sku):
                continue
            seen.add(key)
            tiles.append(tile)
    if tiles:
        return tiles

    anchors = [a for a in soup.find_all("a", href=True) if "/product/" in a["href"]]
    for anchor in anchors:
        tile = _locate_tile_from_anchor(anchor)
        if not tile:
            continue
        key = id(tile)
        if key in seen:
            continue
        seen.add(key)
        tiles.append(tile)
    return tiles


# ---------- Scrape ----------
def find_pages(soup, current_url):
    pages = {current_url}
    for a in soup.find_all("a", href=True):
        href = a["href"]
        if "/search/search_results.aspx" in href and (
            "page=" in href or "Page" in a.get_text("", strip=True)
        ):
            pages.add(absolutize(href))
    return sorted(pages, key=lambda u: (len(u), u))


def scrape_listing(
    url,
    follow_pages=False,
    throttle=0.8,
    cache_dir: Path | None = None,
    cache_tiles: bool = False,
):
    seen_urls = set()
    all_rows = []

    final_url, html = get(url)
    if cache_dir:
        saved = cache_save_html(cache_dir, "listing", final_url, html)
        print(f"[cache] listing -> {saved}")
    soup = BeautifulSoup(html, "html.parser")
    page_urls = [final_url]
    if follow_pages:
        page_urls = find_pages(soup, final_url)

    for idx, purl in enumerate(page_urls, start=1):
        if purl in seen_urls:
            continue
        seen_urls.add(purl)

        if purl != final_url:
            _, ph = get(purl)
            if cache_dir:
                saved = cache_save_html(cache_dir, f"listing_p{idx}", purl, ph)
                print(f"[cache] listing page {idx} -> {saved}")
            soup = BeautifulSoup(ph, "html.parser")

        # Collect potential product tiles on the page
        tiles = find_product_tiles(soup)

        page_rows = []
        for tix, tile in enumerate(tiles, start=1):
            row = extract_tile_data(tile)
            if row["name"] and (row["sku"] or row["url"]):
                if cache_dir and cache_tiles:
                    cache_key = f"SKU:{row['sku'] or 'unknown'}__{tix}"
                    cache_save_html(cache_dir, "tile", cache_key, row["tile_html"])
                row.pop("tile_html", None)
                page_rows.append(row)

        # JSON-LD ItemList fallback if no tiles found
        if not page_rows:
            jl_rows = parse_listing_jsonld(str(soup))
            if jl_rows:
                page_rows = jl_rows

        all_rows.extend(page_rows)
        time.sleep(throttle)

    return all_rows


# ---------- CLI ----------
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("url", help="Brand search URL (e.g., Raspberry Pi listing)")
    ap.add_argument("--out", default="items.csv", help="Output CSV path")
    ap.add_argument(
        "--pages",
        default="1",
        help="'1' for first page only, or 'all' to follow pagination",
    )
    ap.add_argument(
        "--cache-dir", help="Directory to save fetched HTML (listing pages)"
    )
    ap.add_argument(
        "--cache-tiles",
        action="store_true",
        help="Also save each product tile's HTML snippet",
    )
    ap.add_argument(
        "--throttle", type=float, default=0.8, help="Delay between page fetches (sec)"
    )
    args = ap.parse_args()

    cache_dir = Path(args.cache_dir) if args.cache_dir else None

    rows = scrape_listing(
        args.url,
        follow_pages=(args.pages.lower() == "all"),
        cache_dir=cache_dir,
        cache_tiles=bool(args.cache_tiles),
        throttle=args.throttle,
    )
    if not rows:
        print(
            "No items found. Try --pages all, increase --throttle, or check cache HTML.",
            file=sys.stderr,
        )
        sys.exit(2)

    # Optional: dedupe by SKU (keep first)
    uniq, seen = [], set()
    for r in rows:
        k = r.get("sku", "")
        if k and k not in seen:
            uniq.append(r)
            seen.add(k)
        elif not k:
            uniq.append(r)  # keep rows without SKU in case URL-only

    out = Path(args.out)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(
            f, fieldnames=["sku", "name", "price", "availability", "stock", "url"]
        )
        w.writeheader()
        for r in uniq:
            w.writerow(r)
    print(f"Wrote {len(uniq)} rows -> {out}")


if __name__ == "__main__":
    main()
