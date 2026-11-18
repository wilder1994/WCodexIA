# Panel de supervisión

Interfaz para revisar versiones del modelo de WCodexIA. Vive en el VPS, detrás de `https://wcodexia.wcodex.cloud`. No toca Zentra.

## Qué muestra

Cada versión tiene base, adaptador, fecha, estado y estas métricas:

| Campo | Significado |
| --- | --- |
| `train_samples` | Relatos usados para afinar. Pueden salir de Zentra y de `external.jsonl` |
| `eval_samples` | Relatos apartados para evaluar, sin repetir los de entrenamiento |
| `train_loss` | Pérdida del afinado LoRA |
| `eval_loss` | Pérdida en la evaluación |
| `score` | Puntaje de la evaluación, de 0 a 1 |

`null` se ve como «sin dato». La versión sembrada `qwen-coder-2g` es el modelo base y no tiene métricas. El trabajo de `lora-job/` publica las versiones afinadas con cifras reales.

## API

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `/api/versions` | Listado |
| POST | `/api/versions` | El trabajo LoRA registra una versión. Queda pendiente |
| POST | `/api/versions/{id}/decision` | Cuerpo `{"status":"approved"}` o `{"status":"rejected"}` |

Ejemplo de alta:

```json
{
  "id": "lora-2026-11-18",
  "label": "LoRA 18 nov",
  "base_model": "qwen2.5-coder:3b",
  "adapter": "lora-r16",
  "metrics": {
    "train_samples": 40,
    "eval_samples": 10,
    "train_loss": 0.82,
    "eval_loss": 0.91,
    "score": 0.74
  },
  "notes": "Ventana de reportes del observatorio."
}
```

Aprobar no cambia el modelo que sirve Ollama. Solo guarda la decisión.

## Dónde corre

- Código: `/opt/wcodexia-panel`
- Datos: `/var/lib/wcodexia-panel/versions.json`
- Servicio: `wcodexia-panel.service` en `127.0.0.1:8090`
- Panel: `https://wcodexia.wcodex.cloud/`
- Ollama: `https://wcodexia.wcodex.cloud/ollama/` (el vhost añade el token)

En local, sin instalar nada más:

```bash
python server.py
```

Abre `http://127.0.0.1:8090`.
