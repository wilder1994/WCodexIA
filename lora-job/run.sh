#!/bin/bash
# Saca vectores del GGUF congelado y publica la versión.
# Lee solo relatos públicos. No exporta ni lee Zentra. No recarga el servicio de Ollama.
set -euo pipefail

export LORA_STATE="${LORA_STATE:-/var/lib/wcodexia-lora/state.json}"
export LORA_ADAPTERS="${LORA_ADAPTERS:-/var/lib/wcodexia-lora/adapters}"
export PANEL_URL="${PANEL_URL:-http://127.0.0.1:8090/api/versions}"
export LORA_VECTOR_URL="${LORA_VECTOR_URL:-http://127.0.0.1:11436/v1/embeddings}"
export OLLAMA_HOST="${OLLAMA_HOST:-127.0.0.1:11434}"

LLAMA_SERVER="${LLAMA_SERVER:-/opt/llama.cpp/llama-server}"
GGUF="${LORA_GGUF:-}"
PORT=11436
SERVER_PID=""

cleanup() {
  if [ -n "${SERVER_PID}" ] && kill -0 "${SERVER_PID}" 2>/dev/null; then
    kill "${SERVER_PID}" 2>/dev/null || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

exec 9>/var/lib/wcodexia-lora/job.lock
if ! flock -n 9; then
  echo "ya hay una corrida"
  exit 0
fi

# El entrenamiento lee solo /var/lib/wcodexia-lora/external.jsonl.
# No exporta ni lee el observatorio de Zentra.
# Si hay clave de Gemini, corrige las etiquetas antes de entrenar.
install -d -o wcodexia-lora -g wcodexia-lora -m 2770 /var/lib/wcodexia-lora/adapters
if [ -f /var/lib/wcodexia-lora/gemini.env ]; then
  runuser -u wcodexia-lora -- /usr/bin/python3 /opt/wcodexia-lora/label_gemini.py || echo "etiquetado Gemini omitido"
fi

if [ ! -x "${LLAMA_SERVER}" ]; then
  echo "falta ${LLAMA_SERVER}" >&2
  exit 1
fi
if [ -z "${GGUF}" ]; then
  GGUF="$(python3 /opt/wcodexia-lora/resolve_gguf.py)"
fi

# Suelta el modelo de la API para no tener dos copias en RAM. El servicio sigue activo.
ollama stop qwen-coder-2g >/dev/null 2>&1 || true
ollama stop wcodex-3b >/dev/null 2>&1 || true

"${LLAMA_SERVER}" \
  -m "${GGUF}" \
  --embeddings \
  --host 127.0.0.1 \
  --port "${PORT}" \
  -c 512 \
  -t 1 \
  -np 1 \
  --pooling mean \
  -ngl 0 \
  >/var/log/wcodexia-lora-embed.log 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 90); do
  if curl -sf -m 2 "http://127.0.0.1:${PORT}/health" >/dev/null; then
    ready=1
    break
  fi
  if ! kill -0 "${SERVER_PID}" 2>/dev/null; then
    echo "el proceso de vectores terminó antes de estar listo" >&2
    exit 1
  fi
  sleep 2
done
if [ "${ready}" -ne 1 ]; then
  echo "el proceso de vectores no respondió a tiempo" >&2
  exit 1
fi

runuser -u wcodexia-lora -- /usr/bin/python3 /opt/wcodexia-lora/train.py
