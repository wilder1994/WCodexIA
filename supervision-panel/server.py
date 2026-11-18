#!/usr/bin/env python3
"""Panel de supervisión de versiones del modelo de WCodexIA.

El ciclo previsto, todavía no automatizado, es este:

1. Los reportes del observatorio de Zentra se archivan como datos de entrenamiento.
2. Un trabajo periódico afina el modelo base con LoRA.
3. Ese trabajo evalúa el resultado y lo publica aquí con POST /api/versions.
4. Una persona aprueba o rechaza la versión. Este programa no despliega el modelo.

Escucha solo en localhost. Nginx de wcodexia.wcodex.cloud publica la interfaz.
"""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parent
STATIC = ROOT / "static"
DATA_PATH = Path(os.environ.get("PANEL_DATA", ROOT / "data" / "versions.json"))
HOST = os.environ.get("PANEL_HOST", "127.0.0.1")
PORT = int(os.environ.get("PANEL_PORT", "8090"))

# Contrato que el trabajo periódico debe rellenar. null significa "aún no hay cifra".
METRIC_KEYS = ("train_samples", "eval_samples", "train_loss", "eval_loss", "score")


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def empty_metrics() -> dict:
    return {key: None for key in METRIC_KEYS}


def seed_versions() -> dict:
    """El modelo base instalado en Ollama. No es un resultado de LoRA."""
    return {
        "versions": [
            {
                "id": "qwen-coder-2g",
                "label": "qwen-coder-2g",
                "base_model": "qwen2.5-coder:3b",
                "adapter": None,
                "source": "base",
                "created_at": "2026-11-18T03:06:00+00:00",
                "status": "pending",
                "decided_at": None,
                "metrics": empty_metrics(),
                "notes": "Modelo base en Ollama, contexto 2048. El trabajo LoRA todavía no ha publicado una versión.",
            }
        ]
    }


def load_store() -> dict:
    if not DATA_PATH.exists():
        store = seed_versions()
        save_store(store)
        return store
    return json.loads(DATA_PATH.read_text(encoding="utf-8"))


def save_store(store: dict) -> None:
    DATA_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = DATA_PATH.with_suffix(".json.tmp")
    temporary.write_text(json.dumps(store, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(DATA_PATH)


def find_version(store: dict, version_id: str) -> dict | None:
    for version in store["versions"]:
        if version["id"] == version_id:
            return version
    return None


class PanelHandler(BaseHTTPRequestHandler):
    server_version = "WCodexIAPanel"

    def log_message(self, fmt: str, *args) -> None:
        print(f"{self.address_string()} {fmt % args}", flush=True)

    def do_GET(self) -> None:
        path = urlparse(self.path).path
        if path == "/api/versions":
            self.respond_json(200, load_store())
            return
        if path in ("/", "/index.html"):
            self.respond_file(STATIC / "index.html", "text/html; charset=utf-8")
            return
        if path == "/app.css":
            self.respond_file(STATIC / "app.css", "text/css; charset=utf-8")
            return
        if path == "/app.js":
            self.respond_file(STATIC / "app.js", "text/javascript; charset=utf-8")
            return
        self.respond_json(404, {"error": "no encontrado"})

    def do_POST(self) -> None:
        path = urlparse(self.path).path
        body = self.read_json()
        if body is None:
            return
        if path == "/api/versions":
            self.publish_version(body)
            return
        marker = "/api/versions/"
        if path.startswith(marker) and path.endswith("/decision"):
            version_id = path[len(marker) : -len("/decision")].strip("/")
            self.decide(version_id, body)
            return
        self.respond_json(404, {"error": "no encontrado"})

    def publish_version(self, body: dict) -> None:
        version_id = str(body.get("id", "")).strip()
        label = str(body.get("label", "")).strip()
        if not version_id or not label:
            self.respond_json(422, {"error": "id y label son obligatorios"})
            return
        metrics = empty_metrics()
        incoming = body.get("metrics") or {}
        if not isinstance(incoming, dict):
            self.respond_json(422, {"error": "metrics debe ser un objeto"})
            return
        for key in METRIC_KEYS:
            if key in incoming:
                metrics[key] = incoming[key]
        store = load_store()
        version = {
            "id": version_id,
            "label": label,
            "base_model": body.get("base_model") or "qwen2.5-coder:3b",
            "adapter": body.get("adapter"),
            "source": "lora",
            "created_at": utc_now(),
            "status": "pending",
            "decided_at": None,
            "metrics": metrics,
            "notes": str(body.get("notes") or ""),
        }
        store["versions"] = [item for item in store["versions"] if item["id"] != version_id]
        store["versions"].insert(0, version)
        save_store(store)
        self.respond_json(201, version)

    def decide(self, version_id: str, body: dict) -> None:
        status = body.get("status")
        if status not in ("approved", "rejected"):
            self.respond_json(422, {"error": "status debe ser approved o rejected"})
            return
        store = load_store()
        version = find_version(store, version_id)
        if version is None:
            self.respond_json(404, {"error": "versión desconocida"})
            return
        version["status"] = status
        version["decided_at"] = utc_now()
        save_store(store)
        self.respond_json(200, version)

    def read_json(self) -> dict | None:
        length = int(self.headers.get("Content-Length", "0") or "0")
        raw = self.rfile.read(length) if length else b"{}"
        try:
            parsed = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError:
            self.respond_json(400, {"error": "JSON inválido"})
            return None
        if not isinstance(parsed, dict):
            self.respond_json(400, {"error": "se esperaba un objeto"})
            return None
        return parsed

    def respond_json(self, status: int, payload: dict) -> None:
        encoded = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def respond_file(self, path: Path, content_type: str) -> None:
        if not path.is_file():
            self.respond_json(404, {"error": "archivo ausente"})
            return
        encoded = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


def main() -> None:
    server = ThreadingHTTPServer((HOST, PORT), PanelHandler)
    print(f"panel en http://{HOST}:{PORT}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
