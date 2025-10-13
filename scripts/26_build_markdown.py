#!/usr/bin/env python3
"""
Render Markdown tables summarizing latest Raspberry Pi pricing.
Outputs: webroot/markdown/raspberry_pi.md
"""

from __future__ import annotations

import math
import re
from pathlib import Path
from typing import Iterable, Optional

import pandas as pd

HIST = Path("data/history/price_history.csv")
OUT = Path("webroot/markdown/raspberry_pi.md")
OUT.parent.mkdir(parents=True, exist_ok=True)


BOARD_GROUPS = {
    "Raspberry Pi Boards": [
        "Zero W",
        "Zero 2 W",
        "Pi 3A+",
        "Pi 3B+",
        "Pi 4B",
        "Pi 5",
    ],
}

MEMORY_ORDER = [
    ("512MB", 0.5),
    ("1GB", 1),
    ("2GB", 2),
    ("4GB", 4),
    ("8GB", 8),
    ("16GB", 16),
]

ACCESSORY_KEYWORDS = [
    ("Cameras", ("camera", "imager")),
    ("Cases", ("case", "enclosure", "shell")),
    ("Kits", ("kit", "starter", "bundle", "set")),
    ("Cooling", ("fan", "heat sink", "heatsink", "cooling")),
    ("Displays", ("display", "screen", "monitor", "touchscreen")),
    ("Storage", ("micro sd", "sd card", "storage", "ssd", "flash drive")),
    ("Networking", ("poe", "ethernet", "network", "wifi", "wi-fi", "wireless")),
    ("Audio", ("speaker", "audio", "microphone")),
    ("Robotics", ("robot", "servo", "motor")),
    ("Sensors", ("sensor", "temperature", "humidity", "accelerometer")),
    ("Controllers", ("controller", "gamepad", "joystick")),
]


def _load_history() -> pd.DataFrame:
    if not HIST.exists():
        raise SystemExit("History file not found. Run 10_merge_history.py first.")
    df = pd.read_csv(HIST, dtype={"sku": str}, parse_dates=["date"])
    if df.empty:
        raise SystemExit("History is empty.")
    return df


def _classify_board(name: str) -> Optional[str]:
    n = (name or "").lower()
    patterns = [
        ("Zero 2 W", r"zero\s*2\s*w"),
        ("Zero W", r"zero\s*w"),
        ("Pi 3A+", r"pi\s*3\s*(model\s*)?a\+"),
        ("Pi 3B+", r"pi\s*3\s*(model\s*)?b\+"),
        ("Pi 4B", r"pi\s*4(?!00)\s*(model\s*)?b"),
        ("Pi 5", r"pi\s*5(?!00)"),
        ("Pi 400", r"pi\s*400"),
        ("Pi 500", r"pi\s*500\b"),
        ("Pi 500+", r"pi\s*500\+"),
    ]
    for label, pattern in patterns:
        if re.search(pattern, n):
            return label
    return None


def _availability_status(stock: str, availability: str) -> str:
    combined = f"{stock or ''} {availability or ''}".lower()
    if any(keyword in combined for keyword in ("sold out", "out of stock")):
        return "sold_out"
    if any(
        keyword in combined
        for keyword in (
            "in-store",
            "instore",
            "buy in store",
            "pickup",
            "in store only",
            "store only",
            "call store",
        )
    ):
        return "store_only"
    return "available"


def _format_price_markdown(record: Optional[dict[str, object]]) -> str:
    if not record:
        return "x"
    price = record.get("price")
    if price is None or (isinstance(price, float) and math.isnan(price)):
        return "x"
    price_str = f"${float(price):.2f}"
    status = record.get("status", "available")
    if status == "sold_out":
        return f"~~{price_str}~~"
    if status == "store_only":
        return f"*{price_str}*"
    return price_str


def _price_record_from_row(row: pd.Series) -> Optional[dict[str, object]]:
    price = pd.to_numeric(row.get("price"), errors="coerce")
    if pd.isna(price):
        return None
    return {
        "price": float(price),
        "status": _availability_status(row.get("stock", ""), row.get("availability", "")),
    }


def _memory_from_name(name: str) -> float:
    m = re.search(r"(512|1|2|4|8|16)\s*(GB|MB)", str(name), re.I)
    if not m:
        return math.nan
    value = float(m.group(1))
    unit = m.group(2).lower()
    return value / 1024 if unit == "mb" else value


def _memory_label(val: Optional[float]) -> Optional[str]:
    if val is None:
        return None
    if isinstance(val, float) and math.isnan(val):
        return None
    for label, expected in MEMORY_ORDER:
        if abs(val - expected) < 1e-6:
            return label
    if val < 1:
        return f"{int(val * 1024)}MB"
    if val.is_integer():
        return f"{int(val)}GB"
    return f"{val}GB"


def _build_board_tables(latest: pd.DataFrame) -> tuple[list[str], bool]:
    boards = latest.copy()
    boards["board"] = boards["name"].map(_classify_board)
    boards = boards[boards["board"].notna()]
    if boards.empty:
        return ["No Raspberry Pi boards found.\n"], False

    boards["memory_val"] = pd.to_numeric(boards["memory_gb"], errors="coerce")
    missing = boards["memory_val"].isna()
    if missing.any():
        boards.loc[missing, "memory_val"] = boards.loc[missing, "name"].map(_memory_from_name)
    boards["memory_label"] = boards["memory_val"].map(_memory_label)

    tables: list[str] = []
    include_zero_note = False

    for title, columns in BOARD_GROUPS.items():
        data: dict[tuple[str, str], Optional[dict[str, object]]] = {
            (label, board): None for label, _ in MEMORY_ORDER for board in columns
        }
        status_seen: set[str] = set()
        for _, row in boards.iterrows():
            board = row["board"]
            if board not in columns:
                continue
            mem_label = row["memory_label"]
            if not mem_label:
                continue
            price = pd.to_numeric(row.get("price"), errors="coerce")
            if pd.isna(price):
                continue
            key = (mem_label, board)
            current = data.get(key)
            current_price = None if current is None else current.get("price")
            if current is None or (current_price is not None and price < current_price) or current_price is None:
                status = _availability_status(row.get("stock", ""), row.get("availability", ""))
                data[key] = {
                    "price": float(price),
                    "status": status,
                }
                if status != "available":
                    status_seen.add(status)

        used_columns = [
            col
            for col in columns
            if any(data.get((label, col)) for label, _ in MEMORY_ORDER)
        ]
        if not used_columns:
            continue

        def _decorate(col: str) -> str:
            return f"{col} <sup>1.</sup>" if col.startswith("Zero") else col

        header_cells = ["Memory"] + [_decorate(col) for col in used_columns]
        header_line = "|" + "|".join(header_cells) + "|"
        align = "|:-|" + "|".join("-:" for _ in used_columns) + "|"

        rows = []
        for label, _mem in MEMORY_ORDER:
            cells = [label]
            for board in used_columns:
                record = data.get((label, board))
                cells.append(_format_price_markdown(record))
            rows.append("|" + "|".join(cells) + "|")

        include_zero_note = include_zero_note or any(
            col.startswith("Zero") for col in used_columns
        )

        note_lines: list[str] = []
        if status_seen:
            parts = []
            if "sold_out" in status_seen:
                parts.append("~~price~~ = sold out/out of stock")
            if "store_only" in status_seen:
                parts.append("*price* = in-store only / pickup")
            if parts:
                note_lines.append("Key: " + "; ".join(parts) + ".")

        block = [f"### {title}", "", header_line, align, *rows]
        if note_lines:
            block.append("")
            block.extend(note_lines)
        block.append("")
        tables.append("\n".join(block))

    if not tables:
        return ["No Raspberry Pi board pricing available.\n"], False

    return tables, include_zero_note


def _is_power_product(name: str) -> bool:
    n = (name or "").lower()
    keywords = ("power supply", "psu", "poe", "charger", "injector")
    return any(k in n for k in keywords)


def _classify_accessory(name: str) -> Optional[str]:
    n = (name or "").lower()
    for label, keywords in ACCESSORY_KEYWORDS:
        if any(keyword in n for keyword in keywords):
            return label
    return None


def _extract_power(name: str) -> str:
    n = name or ""
    m = re.search(r"(\d+(?:\.\d+)?)\s*(?:w|watt)", n, re.I)
    return f"{m.group(1)}W" if m else ""


def _extract_connector(name: str) -> str:
    n = (name or "").lower()
    options = [
        ("usb-c", "USB-C"),
        ("usb c", "USB-C"),
        ("usb type-c", "USB-C"),
        ("micro usb", "Micro USB"),
        ("usb micro", "Micro USB"),
        ("usb-a", "USB-A"),
        ("usb a", "USB-A"),
        ("poe", "RJ45 PoE"),
        ("rj45", "RJ45 PoE"),
    ]
    for needle, label in options:
        if needle in n:
            return label
    return ""


def _format_description(text: str, max_parts: int = 3) -> str:
    raw = str(text or "")
    parts = [p.strip() for p in raw.split(";") if p.strip()]
    truncated = False
    if len(parts) > max_parts:
        parts = parts[:max_parts]
        truncated = True
    if not parts:
        parts = [raw.strip()]
    parts = [p.replace("|", "\|") for p in parts if p]
    formatted = "<br>".join(parts)
    if truncated:
        formatted += "<br>…"
    return formatted


def _markdown_escape(value: str) -> str:
    return value.replace("|", "\\|")


def _build_power_table(latest: pd.DataFrame) -> tuple[str, bool]:
    power_df = latest[latest["name"].map(_is_power_product)].copy()
    if power_df.empty:
        return "No power accessories found.\n", False

    rows = ["|Power|Connector|Price|Link|Description|", "|:-|:-|-:|:-:|:-|"]
    for _, row in power_df.sort_values("name").iterrows():
        power = _extract_power(str(row["name"]))
        connector = _extract_connector(str(row["name"]))
        record = _price_record_from_row(row)
        price_text = _format_price_markdown(record)
        url = row.get("url") or ""
        link = f"[Link]({url})" if url else ""
        desc = _format_description(row.get("name") or "")
        rows.append(
            f"|{_markdown_escape(power)}|{_markdown_escape(connector)}|{price_text}|{link}|{desc}|"
        )
    return "\n".join(rows), True


def _build_simple_table(title: str, df: pd.DataFrame) -> Optional[str]:
    if df.empty:
        return None

    lines = [f"### {title}", "", "|Price|Stock|Link|Description|", "|-:|:-|:-:|:-|"]
    for _, row in df.sort_values("price", na_position="last").iterrows():
        record = _price_record_from_row(row)
        price_text = _format_price_markdown(record)
        stock_val = row.get("stock")
        if pd.isna(stock_val) or not stock_val:
            stock_val = row.get("availability")
        stock = "" if pd.isna(stock_val) or stock_val is None else str(stock_val)
        url = row.get("url") or ""
        link = f"[Link]({url})" if url else ""
        desc = _format_description(row.get("name") or "")
        lines.append(f"|{price_text}|{_markdown_escape(stock)}|{link}|{desc}|")
    lines.append("")
    return "\n".join(lines)


def _build_accessory_tables(latest: pd.DataFrame) -> list[str]:
    df = latest.copy()
    df["board"] = df["name"].map(_classify_board)
    df = df[df["board"].isna()].copy()
    df = df[~df["name"].map(_is_power_product)].copy()
    df["category"] = df["name"].map(_classify_accessory)

    if df.empty:
        return []

    # Fallback category for uncategorized accessories
    df["category"] = df["category"].fillna("Other Accessories")

    tables: list[str] = []
    grouped = {category: group for category, group in df.groupby("category")}
    category_order = [label for label, _ in ACCESSORY_KEYWORDS] + ["Other Accessories"]
    for category in category_order:
        group = grouped.get(category)
        if group is None or len(group) < 2:
            continue

        lines = [f"### {category}", "", "|Price|Stock|Link|Description|", "|-:|:-|:-:|:-|"]
        for _, row in group.sort_values("price", na_position="last").iterrows():
            record = _price_record_from_row(row)
            price_text = _format_price_markdown(record)
            stock_val = row.get("stock")
            if pd.isna(stock_val) or not stock_val:
                stock_val = row.get("availability")
            stock = "" if pd.isna(stock_val) or stock_val is None else str(stock_val)
            url = row.get("url") or ""
            link = f"[Link]({url})" if url else ""
            desc = _format_description(row.get("name") or "")
            lines.append(f"|{price_text}|{_markdown_escape(stock)}|{link}|{desc}|")
        lines.append("")
        tables.append("\n".join(lines))

    return tables


def _latest_snapshot(latest: pd.DataFrame) -> str:
    if "date" not in latest:
        return ""
    dates = pd.to_datetime(latest["date"], errors="coerce")
    if dates.notna().any():
        dt = dates.max()
        return dt.strftime("%b %d %Y").replace(" 0", " ")
    return ""


def main() -> None:
    df = _load_history()
    df = df.sort_values("date").reset_index(drop=True)
    idx = df.groupby("sku")["date"].idxmax()
    latest = df.loc[idx].copy()

    as_of = _latest_snapshot(latest)
    board_tables, include_zero_note = _build_board_tables(latest)
    power_table, has_power = _build_power_table(latest)
    pico_df = latest[latest["name"].str.contains("pico", case=False, na=False)].copy()
    pico_table = _build_simple_table("Raspberry Pi Pico", pico_df)
    pi400_df = latest[latest["name"].str.contains(r"pi\s*400|pi\s*500", case=False, na=False)].copy()
    pi400_table = _build_simple_table("Raspberry Pi 400/500 Kits", pi400_df)
    accessory_tables = _build_accessory_tables(latest)

    lines: Iterable[str] = [
        "## Raspberry Pi Devices",
        "",
        f"These are the prices at MicroCenter as of {as_of}" if as_of else "",
        "",
    ]
    for table in board_tables:
        lines.extend(table.split("\n"))
    if include_zero_note:
        lines.append("<sup>1.</sup> Add $1.00 for a Zero with pre-soldered headers.")
        lines.append("")
    if has_power:
        lines.append("### Power Accessories")
        lines.append("")
        lines.extend(power_table.split("\n"))
        lines.append("")
    elif power_table:
        lines.extend(power_table.split("\n"))
        lines.append("")

    if pico_table:
        lines.extend(pico_table.split("\n"))

    if pi400_table:
        lines.extend(pi400_table.split("\n"))

    for table in accessory_tables:
        lines.extend(table.split("\n"))

    content = "\n".join(line for line in lines if line is not None)
    OUT.write_text(content.strip() + "\n", encoding="utf-8")
    print(f"Wrote {OUT}")


if __name__ == "__main__":
    main()
