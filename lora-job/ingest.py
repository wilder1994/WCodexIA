#!/usr/bin/env python3
"""Descarga relatos públicos y los suma al entrenamiento.

Lee Wikipedia en español y el RSS de BBC Mundo. No toca Zentra ni el
dataset que exporta el observatorio. Escribe solo external.jsonl.
"""

from __future__ import annotations

import fcntl
import hashlib
import html
import json
import os
import re
import sys
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

EXTERNAL = Path(os.environ.get("LORA_EXTERNAL", "/var/lib/wcodexia-lora/external.jsonl"))
LOCK = Path(os.environ.get("LORA_INGEST_LOCK", "/var/lib/wcodexia-lora/ingest.lock"))
USER_AGENT = "WCodexIA-LoRA/1.0 (https://wcodexia.wcodex.cloud)"
WIKI = "https://es.wikipedia.org/w/api.php"
RSS = "https://feeds.bbci.co.uk/mundo/rss.xml"
ID_BASE = 1_000_000_000
MIN_CHARS = 40
MAX_CHARS = 400
WIKI_TARGET = 12
NEWS_TARGET = 12


def fetch(url: str) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "*/*"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read()


def clean(text: str) -> str:
    text = html.unescape(text)
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"https?://\S+", " ", text, flags=re.I)
    text = re.sub(r"[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}", " ", text, flags=re.I)
    text = re.sub(r"\+?\d[\d\s().\-]{6,}\d", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    if len(text) > MAX_CHARS:
        text = text[:MAX_CHARS].rstrip()
    return text


def stable_id(source_id: str) -> int:
    digest = hashlib.sha256(source_id.encode("utf-8")).hexdigest()
    return ID_BASE + int(digest[:8], 16)


def load_existing() -> tuple[list[dict], set[str], set[int]]:
    if not EXTERNAL.is_file():
        return [], set(), set()
    rows = []
    source_ids: set[str] = set()
    ids: set[int] = set()
    for line in EXTERNAL.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        source_id = str(row.get("source_id", "")).strip()
        if source_id:
            source_ids.add(source_id)
        if row.get("id") is not None:
            ids.add(int(row["id"]))
        rows.append(row)
    return rows, source_ids, ids


def wikipedia_stories() -> list[dict]:
    stories = []
    seen: set[str] = set()
    for _ in range(4):
        if len(stories) >= WIKI_TARGET:
            break
        query = urllib.parse.urlencode(
            {
                "action": "query",
                "format": "json",
                "prop": "extracts",
                "explaintext": "1",
                "exchars": "500",
                "generator": "random",
                "grnnamespace": "0",
                "grnlimit": "15",
            }
        )
        payload = json.loads(fetch(f"{WIKI}?{query}").decode("utf-8"))
        pages = (payload.get("query") or {}).get("pages") or {}
        for page in pages.values():
            source_id = f"eswiki:{page.get('pageid')}"
            if source_id in seen:
                continue
            text = clean(str(page.get("extract") or ""))
            if len(text) < MIN_CHARS:
                continue
            seen.add(source_id)
            stories.append({"source_id": source_id, "type": "wikipedia", "text": text})
            if len(stories) >= WIKI_TARGET:
                break
    return stories


def news_stories() -> list[dict]:
    root = ET.fromstring(fetch(RSS))
    stories = []
    for item in root.findall("./channel/item"):
        title = item.findtext("title") or ""
        description = item.findtext("description") or ""
        text = clean(f"{title}. {description}")
        if len(text) < MIN_CHARS:
            continue
        guid = (item.findtext("guid") or item.findtext("link") or title).strip()
        stories.append({"source_id": f"bbc:{guid}", "type": "noticia", "text": text})
        if len(stories) >= NEWS_TARGET:
            break
    return stories


def remember(story: dict, rows: list[dict], source_ids: set[str], ids: set[int]) -> bool:
    source_id = story["source_id"]
    if source_id in source_ids:
        return False
    row_id = stable_id(source_id)
    if row_id in ids:
        return False
    source_ids.add(source_id)
    ids.add(row_id)
    rows.append(
        {
            "id": row_id,
            "type": story["type"],
            "text": story["text"],
            "source_id": source_id,
        }
    )
    return True


def save(rows: list[dict]) -> None:
    EXTERNAL.parent.mkdir(parents=True, exist_ok=True)
    temporary = EXTERNAL.with_suffix(".jsonl.tmp")
    temporary.write_text(
        "".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows),
        encoding="utf-8",
    )
    os.chmod(temporary, 0o660)
    temporary.replace(EXTERNAL)


def main() -> None:
    LOCK.parent.mkdir(parents=True, exist_ok=True)
    lock_file = LOCK.open("a", encoding="utf-8")
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("ya hay una recolección")
        return

    rows, source_ids, ids = load_existing()
    added = {"wikipedia": 0, "noticia": 0}
    pending = []

    try:
        wiki = wikipedia_stories()
    except Exception as error:
        wiki = []
        pending.append(f"wikipedia: {error}")
    try:
        news = news_stories()
    except Exception as error:
        news = []
        pending.append(f"bbc: {error}")

    for story in wiki + news:
        if remember(story, rows, source_ids, ids):
            added[story["type"]] += 1

    if added["wikipedia"] or added["noticia"]:
        save(rows)

    print(
        f"guardados {added['wikipedia'] + added['noticia']} "
        f"wikipedia={added['wikipedia']} noticia={added['noticia']} "
        f"total={len(rows)} pendientes={'; '.join(pending) if pending else 'ninguna'}"
    )
    if not wiki and not news:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
