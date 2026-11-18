#!/usr/bin/env python3
"""Afina un adaptador LoRA y publica la versión en el panel.

El modelo base es qwen2.5-coder:3b, servido por Ollama como qwen-coder-2g.
En este VPS de 8 GB un entrenamiento que reescriba esos pesos no cabe junto a
Zentra. El trabajo hace otra cosa, medible y acotada:

1. Lee solo el JSONL público. Los reportes de Zentra no entran.
2. Saca un vector por relato con el GGUF congelado, en un proceso aparte.
3. Entrena solo un adaptador de rango bajo que predice el tipo.
4. Calcula pérdida y exactitud. Publica eso en el panel.
5. No crea ni cambia ningún modelo dentro de Ollama.

Si el dataset no cambió desde la última corrida exitosa, sale sin publicar.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import random
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

EXTERNAL = Path(os.environ.get("LORA_EXTERNAL", "/var/lib/wcodexia-lora/external.jsonl"))
STATE = Path(os.environ.get("LORA_STATE", "/var/lib/wcodexia-lora/state.json"))
ADAPTER_DIR = Path(os.environ.get("LORA_ADAPTERS", "/var/lib/wcodexia-lora/adapters"))
PANEL_URL = os.environ.get("PANEL_URL", "http://127.0.0.1:8090/api/versions").rstrip("/")
VECTOR_URL = os.environ.get("LORA_VECTOR_URL", "http://127.0.0.1:11436/v1/embeddings")
RANK = 16
EPOCHS = 50
LEARNING_RATE = 0.15
SEED = 7
EVAL_MIN = 20


def read_jsonl(path: Path) -> list[dict]:
    if not path.is_file():
        return []
    examples = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        text = str(row.get("text", "")).strip()
        label = str(row.get("type", "")).strip()
        if not text or not label or row.get("id") is None:
            continue
        examples.append({"id": int(row["id"]), "type": label, "text": text})
    return examples


def load_examples() -> list[dict]:
    examples = read_jsonl(EXTERNAL)
    minimum = EVAL_MIN + 2
    if len(examples) < minimum:
        raise SystemExit(
            f"el entrenamiento público quedó en {len(examples)} relatos; "
            f"hace falta al menos {minimum}. Los reportes de Zentra no se usan."
        )
    examples.sort(key=lambda item: item["id"])
    return examples


def dataset_hash(examples: list[dict]) -> str:
    payload = json.dumps(
        [{"id": item["id"], "type": item["type"], "text": item["text"]} for item in examples],
        ensure_ascii=False,
        sort_keys=True,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def read_state() -> dict:
    if not STATE.is_file():
        return {}
    return json.loads(STATE.read_text(encoding="utf-8"))


def write_state(payload: dict) -> None:
    temporary = STATE.with_suffix(".json.tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(STATE)


def split_examples(examples: list[dict]) -> tuple[list[dict], list[dict]]:
    """Separa train y evaluación sin repetir un relato.

    Cada tipo que entra en la evaluación conserva al menos una copia
    en el entrenamiento. Un tipo con un solo relato no se evalúa.
    """
    by_type: dict[str, list[dict]] = {}
    for example in examples:
        by_type.setdefault(example["type"], []).append(example)
    train: list[dict] = []
    evaluate: list[dict] = []
    for group in by_type.values():
        ordered = sorted(group, key=lambda item: item["id"])
        if len(ordered) >= 2:
            evaluate.append(ordered[-1])
            train.extend(ordered[:-1])
        else:
            train.extend(ordered)

    for item in sorted(train, key=lambda item: item["id"], reverse=True):
        if len(evaluate) >= EVAL_MIN or len(train) <= 2:
            break
        if sum(1 for other in train if other["type"] == item["type"]) < 2:
            continue
        train.remove(item)
        evaluate.append(item)
    train.sort(key=lambda item: item["id"])
    evaluate.sort(key=lambda item: item["id"])
    train_ids = {item["id"] for item in train}
    eval_ids = {item["id"] for item in evaluate}
    if train_ids & eval_ids:
        raise SystemExit("train y evaluación se solapan")
    if len(evaluate) < EVAL_MIN:
        raise SystemExit(f"la evaluación quedó en {len(evaluate)}, por debajo de {EVAL_MIN}")
    return train, evaluate


def embed_batch(texts: list[str]) -> list[list[float]]:
    """Pide vectores al proceso local del GGUF. No usa la API de Ollama."""
    request = urllib.request.Request(
        VECTOR_URL,
        data=json.dumps({"input": texts}).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except urllib.error.URLError as error:
        raise SystemExit(f"no hubo vectores del modelo base: {error}") from error
    rows = payload.get("data") or []
    rows.sort(key=lambda item: item.get("index", 0))
    vectors = [row.get("embedding") for row in rows]
    if len(vectors) != len(texts) or any(not vector for vector in vectors):
        raise SystemExit("la respuesta de embeddings no trae un vector por relato")
    return [[float(value) for value in vector] for vector in vectors]


def embed_texts(texts: list[str]) -> list[list[float]]:
    vectors: list[list[float]] = []
    for start in range(0, len(texts), 4):
        vectors.extend(embed_batch(texts[start : start + 4]))
    width = len(vectors[0])
    if any(len(vector) != width for vector in vectors):
        raise SystemExit("los vectores no tienen la misma dimensión")
    return vectors


def normalize(vector: list[float]) -> list[float]:
    norm = math.sqrt(sum(value * value for value in vector))
    if norm == 0:
        return vector
    return [value / norm for value in vector]


def softmax(logits: list[float]) -> list[float]:
    peak = max(logits)
    shifted = [math.exp(value - peak) for value in logits]
    total = sum(shifted)
    return [value / total for value in shifted]


def train_lora(
    train_vectors: list[list[float]],
    train_labels: list[int],
    eval_vectors: list[list[float]],
    eval_labels: list[int],
    n_classes: int,
) -> dict:
    """Adaptador de rango RANK. El peso base de la cabeza queda en cero.

    logits = (B @ A) @ h. Solo A y B reciben gradiente.
    """
    width = len(train_vectors[0])
    rng = random.Random(SEED)
    scale = 0.02
    factor_a = [[rng.uniform(-scale, scale) for _ in range(width)] for _ in range(RANK)]
    factor_b = [[0.0 for _ in range(RANK)] for _ in range(n_classes)]

    def logits_of(vector: list[float]) -> tuple[list[float], list[float]]:
        hidden = [sum(factor_a[row][col] * vector[col] for col in range(width)) for row in range(RANK)]
        logits = [sum(factor_b[row][col] * hidden[col] for col in range(RANK)) for row in range(n_classes)]
        return logits, hidden

    def mean_loss(vectors: list[list[float]], labels: list[int]) -> float:
        if not vectors:
            return 0.0
        total = 0.0
        for vector, label in zip(vectors, labels):
            probs = softmax(logits_of(vector)[0])
            total += -math.log(max(probs[label], 1e-12))
        return total / len(vectors)

    initial_eval = mean_loss(eval_vectors, eval_labels)
    for _ in range(EPOCHS):
        for vector, label in zip(train_vectors, train_labels):
            logits, hidden = logits_of(vector)
            probs = softmax(logits)
            residual = probs[:]
            residual[label] -= 1.0
            old_b = factor_b
            hidden_grad = [
                sum(residual[class_index] * old_b[class_index][rank] for class_index in range(n_classes))
                for rank in range(RANK)
            ]
            factor_b = [
                [
                    old_b[class_index][rank] - LEARNING_RATE * residual[class_index] * hidden[rank]
                    for rank in range(RANK)
                ]
                for class_index in range(n_classes)
            ]
            factor_a = [
                [
                    factor_a[rank][col] - LEARNING_RATE * hidden_grad[rank] * vector[col]
                    for col in range(width)
                ]
                for rank in range(RANK)
            ]

    def accuracy(vectors: list[list[float]], labels: list[int]) -> float:
        if not vectors:
            return 0.0
        correct = 0
        for vector, label in zip(vectors, labels):
            logits = logits_of(vector)[0]
            if logits.index(max(logits)) == label:
                correct += 1
        return correct / len(vectors)

    return {
        "train_loss": mean_loss(train_vectors, train_labels),
        "eval_loss": mean_loss(eval_vectors, eval_labels),
        "initial_eval_loss": initial_eval,
        "score": accuracy(eval_vectors, eval_labels),
        "factor_a": factor_a,
        "factor_b": factor_b,
    }


def publish(version_id: str, metrics: dict, notes: str, adapter_path: Path) -> None:
    body = {
        "id": version_id,
        "label": "LoRA " + version_id.removeprefix("lora-"),
        "base_model": "qwen2.5-coder:3b",
        "adapter": str(adapter_path),
        "metrics": {
            "train_samples": metrics["train_samples"],
            "eval_samples": metrics["eval_samples"],
            "train_loss": round(metrics["train_loss"], 6),
            "eval_loss": round(metrics["eval_loss"], 6),
            "score": round(metrics["score"], 6),
        },
        "notes": notes,
    }
    request = urllib.request.Request(
        PANEL_URL,
        data=json.dumps(body).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            if response.status not in (200, 201):
                raise SystemExit(f"el panel respondió {response.status}")
    except urllib.error.HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")[:240]
        raise SystemExit(f"el panel rechazó la versión: {error.code} {detail}") from error


def self_test() -> None:
    """Dos nubes separables. La pérdida de evaluación tiene que bajar."""
    rng = random.Random(1)
    vectors = []
    labels = []
    for label, sign in ((0, 1.0), (1, -1.0)):
        for _ in range(12):
            vectors.append(normalize([sign + rng.uniform(-0.05, 0.05), rng.uniform(-0.05, 0.05), 0.01]))
            labels.append(label)
    trained = train_lora(vectors[:20], labels[:20], vectors[20:], labels[20:], 2)
    if not math.isfinite(trained["eval_loss"]) or trained["eval_loss"] >= trained["initial_eval_loss"]:
        raise SystemExit("la autoprueba no redujo la pérdida")
    if trained["score"] < 0.5:
        raise SystemExit("la autoprueba no separa las dos clases")
    print("self-test ok", round(trained["initial_eval_loss"], 4), round(trained["eval_loss"], 4), trained["score"])


def main() -> None:
    if "--self-test" in sys.argv:
        self_test()
        return
    examples = load_examples()
    digest = dataset_hash(examples)
    if os.environ.get("LORA_FORCE") != "1" and read_state().get("dataset_sha256") == digest:
        print("dataset sin cambios; no se publica otra versión")
        return
    train, evaluate = split_examples(examples)
    if len(train) < 2 or len(evaluate) < 1:
        raise SystemExit(f"no hay suficientes relatos: train={len(train)} eval={len(evaluate)}")
    train_types = {item["type"] for item in train}
    evaluate = [item for item in evaluate if item["type"] in train_types]
    classes = sorted(train_types)
    index = {label: position for position, label in enumerate(classes)}
    if not evaluate:
        raise SystemExit("el conjunto de evaluación quedó vacío")
    vectors = [normalize(vector) for vector in embed_texts([item["text"] for item in train + evaluate])]
    train_vectors = vectors[: len(train)]
    eval_vectors = vectors[len(train) :]
    trained = train_lora(
        train_vectors,
        [index[item["type"]] for item in train],
        eval_vectors,
        [index[item["type"]] for item in evaluate],
        len(classes),
    )
    if not all(math.isfinite(trained[key]) for key in ("train_loss", "eval_loss", "score")):
        raise SystemExit("la pérdida no es finita; no se publica")
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%MZ")
    version_id = f"lora-{stamp}"
    ADAPTER_DIR.mkdir(parents=True, exist_ok=True)
    adapter_path = ADAPTER_DIR / f"{version_id}.json"
    adapter_path.write_text(
        json.dumps(
            {
                "base_model": "qwen2.5-coder:3b",
                "ollama_model": "qwen-coder-2g",
                "rank": RANK,
                "labels": classes,
                "factor_a": trained["factor_a"],
                "factor_b": trained["factor_b"],
            }
        ),
        encoding="utf-8",
    )
    notes = (
        f"Adaptador LoRA de rango {RANK} sobre vectores del GGUF congelado. "
        f"Evaluación de {len(evaluate)} relatos, sin solaparse con el entrenamiento "
        "y con cada tipo también presente en el entrenamiento. "
        "Ollama sigue sirviendo qwen-coder-2g. "
        f"Pérdida de evaluación inicial {trained['initial_eval_loss']:.4f}, "
        f"final {trained['eval_loss']:.4f}."
    )
    publish(
        version_id,
        {
            "train_samples": len(train),
            "eval_samples": len(evaluate),
            "train_loss": trained["train_loss"],
            "eval_loss": trained["eval_loss"],
            "score": trained["score"],
        },
        notes,
        adapter_path,
    )
    write_state({"dataset_sha256": digest, "version_id": version_id})
    print(
        f"publicado {version_id} train={len(train)} eval={len(evaluate)} "
        f"loss={trained['eval_loss']:.4f} score={trained['score']:.4f}"
    )


if __name__ == "__main__":
    main()
