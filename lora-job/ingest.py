#!/usr/bin/env python3
"""Descarga relatos públicos y los suma al entrenamiento.

Lee noticias públicas ya separadas por tipo y extractos de la Wikipedia
en español. No lee Zentra ni el export del observatorio. Escribe solo
external.jsonl. El tipo lo pone la fuente, no un relato de Zentra.
"""

from __future__ import annotations

import fcntl
import hashlib
import html
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

EXTERNAL = Path(os.environ.get("LORA_EXTERNAL", "/var/lib/wcodexia-lora/external.jsonl"))
LOCK = Path(os.environ.get("LORA_INGEST_LOCK", "/var/lib/wcodexia-lora/ingest.lock"))
USER_AGENT = "WCodexIA-LoRA/1.0 (https://wcodexia.wcodex.cloud)"
WIKI = "https://es.wikipedia.org/w/api.php"
RSS = "https://feeds.bbci.co.uk/mundo/rss.xml"
GOOGLE_NEWS = "https://news.google.com/rss/search"
TYPED_QUERIES = (
    ("hurto", "hurto OR robo"),
    ("hurto", "robo a mano armada OR hurto de celular"),
    ("hurto", "fleteo OR cosquilleo"),
    ("riña", "riña OR pelea"),
    ("riña", "riña callejera OR pelea callejera"),
    ("riña", "lesiones personales pelea"),
    ("amenaza", "amenaza de muerte"),
    ("amenaza", "intimidación OR amenaza contra testigo"),
    ("extorsión", "extorsión OR llamada extorsiva"),
    ("extorsión", "cobro de vacuna OR extorsión telefónica"),
    ("homicidio", "homicidio OR asesinado"),
    ("homicidio", "sicariato OR masacre"),
    ("secuestro", "secuestro OR privado de la libertad"),
    ("secuestro", "secuestro extorsivo"),
    ("vandalismo", "vandalismo OR daño en bien ajeno"),
    ("vandalismo", "destrucción de bienes OR saqueo"),
    ("incendio", "incendio estructural OR incendio en vivienda"),
    ("incendio", "incendio de vehículo OR bomberos controlan incendio"),
    ("desaparición", "persona desaparecida OR desaparición"),
    ("desaparición", "familia busca desaparecido"),
    ("arma", "incautación de armas OR porte ilegal de armas"),
    ("arma", "disparos de arma de fuego"),
    ("intrafamiliar", "violencia intrafamiliar OR violencia de pareja"),
    ("intrafamiliar", "agresión intrafamiliar"),
    ("disturbio", "disturbios OR desórdenes públicos"),
    ("disturbio", "bloqueo violento OR enfrentamiento con la policía"),
    ("invasión", "invasión de predio OR ocupación ilegal"),
    ("invasión", "desalojo de invasión"),
    ("contrabando", "contrabando OR mercancía de contrabando"),
    ("contrabando", "incautación de mercancía OR contrabando de licor"),
    ("ciberataque", "ciberataque OR ransomware"),
    ("ciberataque", "ataque informático OR secuestro de datos"),
    ("atentado", "atentado con explosivos OR carro bomba"),
    ("atentado", "artefacto explosivo"),
    ("accidente", "accidente de tránsito OR accidente de transito"),
    ("accidente", "choque bus OR accidente de moto"),
    ("accidente", "volcamiento OR atropello"),
    ("fraude", "estafa OR fraude"),
    ("fraude", "estafa bancaria OR phishing"),
    ("fraude", "estafa digital OR pirámide"),
    ("desastre", "inundación OR deslizamiento OR terremoto"),
    ("desastre", "inundación Colombia"),
    ("desastre", "deslizamiento OR ola invernal"),
)
ID_BASE = 1_000_000_000
MIN_CHARS = 40
MAX_CHARS = 400
WIKI_TARGET = 24
NEWS_TARGET = 30
PER_TYPE = 12


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


def identity(source_id: str) -> str:
    parts = source_id.split(":", 2)
    return parts[-1] if len(parts) == 3 else source_id


def load_existing() -> tuple[list[dict], set[str], set[str], set[int]]:
    if not EXTERNAL.is_file():
        return [], set(), set(), set()
    rows = []
    source_ids: set[str] = set()
    identities: set[str] = set()
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
            identities.add(identity(source_id))
        if row.get("id") is not None:
            ids.add(int(row["id"]))
        rows.append(row)
    return rows, source_ids, identities, ids


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


def feed_stories(url: str, label: str, source_prefix: str, limit: int) -> list[dict]:
    root = ET.fromstring(fetch(url))
    stories = []
    for item in root.findall(".//item"):
        title = item.findtext("title") or ""
        description = item.findtext("description") or ""
        text = clean(f"{title}. {description}")
        if len(text) < MIN_CHARS:
            continue
        guid = (item.findtext("guid") or item.findtext("link") or title).strip()
        stories.append({"source_id": f"{source_prefix}:{guid}", "type": label, "text": text})
        if len(stories) >= limit:
            break
    return stories


def typed_news() -> tuple[list[dict], list[str]]:
    stories = []
    pending = []
    for label, query in TYPED_QUERIES:
        url = GOOGLE_NEWS + "?" + urllib.parse.urlencode(
            {"q": query, "hl": "es-419", "gl": "CO", "ceid": "CO:es-419"}
        )
        try:
            stories.extend(feed_stories(url, label, f"gnews:{label}", PER_TYPE))
        except Exception as error:
            pending.append(f"{label}: {error}")
        time.sleep(3)
    return stories, pending


def news_stories() -> list[dict]:
    return feed_stories(RSS, "noticia", "bbc", NEWS_TARGET)


def remember(story: dict, rows: list[dict], source_ids: set[str], identities: set[str], ids: set[int]) -> bool:
    source_id = story["source_id"]
    marker = identity(source_id)
    if source_id in source_ids or marker in identities:
        return False
    row_id = stable_id(source_id)
    if row_id in ids:
        return False
    source_ids.add(source_id)
    identities.add(marker)
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

    rows, source_ids, identities, ids = load_existing()
    added: dict[str, int] = {label: 0 for label, _query in TYPED_QUERIES}
    added["wikipedia"] = 0
    added["noticia"] = 0
    pending = []
    batches: list[list[dict]] = []

    typed, typed_errors = typed_news()
    pending.extend(typed_errors)
    batches.append(typed)
    try:
        batches.append(wikipedia_stories())
    except Exception as error:
        pending.append(f"wikipedia: {error}")
    try:
        batches.append(news_stories())
    except Exception as error:
        pending.append(f"bbc: {error}")

    for stories in batches:
        for story in stories:
            if remember(story, rows, source_ids, identities, ids):
                added[story["type"]] = added.get(story["type"], 0) + 1

    if any(added.values()):
        save(rows)

    summary = " ".join(f"{label}={count}" for label, count in added.items())
    print(
        f"guardados {sum(added.values())} {summary} "
        f"total={len(rows)} pendientes={'; '.join(pending) if pending else 'ninguna'}"
    )
    if not any(batches):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
