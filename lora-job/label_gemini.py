#!/usr/bin/env python3
"""Corrige el tipo de los relatos públicos con Gemini 3.5 Flash-Lite.

Lee solo external.jsonl. No abre el export de Zentra ni cambia el modelo
del servidor. La clave se lee de GEMINI_API_KEY o de
/var/lib/wcodexia-lora/gemini.env y no se imprime.
"""

from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path

EXTERNAL = Path(os.environ.get("LORA_EXTERNAL", "/var/lib/wcodexia-lora/external.jsonl"))
KEY_FILE = Path(os.environ.get("GEMINI_ENV", "/var/lib/wcodexia-lora/gemini.env"))
MODEL = os.environ.get("GEMINI_MODEL", "gemini-3.5-flash-lite")
ENDPOINT = f"https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent"
TYPES = (
    "hurto",
    "riña",
    "amenaza",
    "extorsión",
    "homicidio",
    "secuestro",
    "vandalismo",
    "incendio",
    "desaparición",
    "arma",
    "intrafamiliar",
    "disturbio",
    "invasión",
    "contrabando",
    "ciberataque",
    "atentado",
    "accidente",
    "fraude",
    "desastre",
    "noticia",
    "wikipedia",
)
BATCH = 8

PROMPT = """Clasifica cada relato en un solo tipo de esta lista:
hurto, riña, amenaza, extorsión, homicidio, secuestro, vandalismo, incendio, desaparición, arma, intrafamiliar, disturbio, invasión, contrabando, ciberataque, atentado, accidente, fraude, desastre, noticia, wikipedia.

hurto: robo de bienes.
riña: pelea entre personas.
amenaza: intimidación o advertencia de daño, sin cobro.
extorsión: exigir dinero o un favor bajo presión.
homicidio: muerte violenta de una persona.
secuestro: privar de la libertad a una persona.
vandalismo: daño deliberado a bienes.
incendio: fuego en vivienda, local o vehículo.
desaparición: persona de la que no se conoce el paradero.
arma: disparos, porte o incautación de armas.
intrafamiliar: violencia dentro de la familia o la pareja.
disturbio: desorden en el espacio público.
invasión: ocupación ilegal de un predio.
contrabando: ingreso o tenencia ilegal de mercancía.
ciberataque: ataque a sistemas o secuestro de datos.
atentado: ataque deliberado contra un lugar o un grupo.
accidente: choque, caída o hecho no intencional con daños.
fraude: estafa o engaño para obtener dinero.
desastre: inundación, terremoto, deslizamiento o emergencia natural.
noticia: suceso que no cabe en los tipos anteriores.
wikipedia: texto enciclopédico, no es un suceso.

Responde solo un JSON con esta forma: [{"id": 1, "type": "hurto"}]
No agregues tipos nuevos ni texto fuera del JSON.
"""


def load_key() -> str:
    key = os.environ.get("GEMINI_API_KEY", "").strip()
    if key:
        return key
    if not KEY_FILE.is_file():
        return ""
    for line in KEY_FILE.read_text(encoding="utf-8").splitlines():
        if line.startswith("GEMINI_API_KEY="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    return ""


def read_rows() -> list[dict]:
    rows = []
    for line in EXTERNAL.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        if row.get("id") is None or not str(row.get("text", "")).strip():
            continue
        rows.append(row)
    return rows


def ask(key: str, batch: list[dict]) -> list[dict]:
    payload = {
        "contents": [
            {
                "parts": [
                    {
                        "text": PROMPT
                        + "\nRelatos:\n"
                        + json.dumps(
                            [{"id": row["id"], "text": row["text"]} for row in batch],
                            ensure_ascii=False,
                        )
                    }
                ]
            }
        ],
        "generationConfig": {
            "temperature": 0,
            "responseMimeType": "application/json",
        },
    }
    request = urllib.request.Request(
        ENDPOINT,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": key,
        },
        method="POST",
    )
    delay = 2
    for attempt in range(4):
        try:
            with urllib.request.urlopen(request, timeout=40) as response:
                body = json.loads(response.read().decode("utf-8"))
            text = body["candidates"][0]["content"]["parts"][0]["text"]
            parsed = json.loads(text)
            if isinstance(parsed, dict):
                parsed = parsed.get("items") or parsed.get("relatos") or []
            return parsed if isinstance(parsed, list) else []
        except urllib.error.HTTPError as error:
            if error.code not in (429, 500, 503) or attempt == 3:
                raise
            time.sleep(delay)
            delay *= 2
        except (KeyError, json.JSONDecodeError, TimeoutError):
            if attempt == 3:
                raise
            time.sleep(delay)
            delay *= 2
    return []


def save(rows: list[dict]) -> None:
    backup = EXTERNAL.with_suffix(".jsonl.before-gemini")
    if not backup.exists():
        backup.write_text(EXTERNAL.read_text(encoding="utf-8"), encoding="utf-8")
        os.chmod(backup, 0o660)
    temporary = EXTERNAL.with_suffix(".jsonl.tmp")
    temporary.write_text(
        "".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows),
        encoding="utf-8",
    )
    os.chmod(temporary, 0o660)
    temporary.replace(EXTERNAL)


def main() -> None:
    key = load_key()
    if not key:
        raise SystemExit(f"falta la clave en {KEY_FILE}")
    if not EXTERNAL.is_file():
        raise SystemExit(f"no está {EXTERNAL}")

    rows = read_rows()
    allowed = set(TYPES)
    chosen: dict[int, str] = {}
    for start in range(0, len(rows), BATCH):
        batch = rows[start : start + BATCH]
        for item in ask(key, batch):
            if not isinstance(item, dict):
                continue
            label = str(item.get("type", "")).strip().lower()
            if label not in allowed or item.get("id") is None:
                continue
            chosen[int(item["id"])] = label
        time.sleep(1)

    changed = 0
    for row in rows:
        label = chosen.get(int(row["id"]))
        if label and label != row.get("type"):
            row["type"] = label
            changed += 1
    if changed:
        save(rows)

    counts: dict[str, int] = {}
    for row in rows:
        counts[str(row.get("type"))] = counts.get(str(row.get("type")), 0) + 1
    summary = " ".join(f"{label}={count}" for label, count in sorted(counts.items()))
    print(f"revisados={len(rows)} cambiados={changed} {summary}")


if __name__ == "__main__":
    main()
