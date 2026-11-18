#!/usr/bin/env python3
"""Ruta del GGUF de qwen-coder-2g dentro del almacén de Ollama. No imprime el modelo."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path("/var/lib/ollama/models")
CANDIDATES = (
    ROOT / "manifests" / "registry.ollama.ai" / "library" / "qwen-coder-2g" / "latest",
    ROOT / "manifests" / "registry.ollama.ai" / "library" / "qwen2.5-coder" / "3b",
)


def main() -> None:
    manifest_path = next((path for path in CANDIDATES if path.is_file()), None)
    if manifest_path is None:
        raise SystemExit("no está el manifiesto del modelo base")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    layers = manifest.get("layers") or []
    best: Path | None = None
    best_size = -1
    for layer in layers:
        digest = str(layer.get("digest", ""))
        if not digest.startswith("sha256:"):
            continue
        blob = ROOT / "blobs" / digest.replace("sha256:", "sha256-")
        if not blob.is_file():
            continue
        size = blob.stat().st_size
        if size > best_size:
            best = blob
            best_size = size
    if best is None or best_size < 1_000_000:
        raise SystemExit("no está el GGUF de qwen-coder-2g")
    print(best)


if __name__ == "__main__":
    main()
