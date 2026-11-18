# Trabajo LoRA

Lee reportes del observatorio, entrena un adaptador y publica las métricas en el panel. No modifica Zentra y no cambia el modelo que Ollama está sirviendo (`qwen-coder-2g`).

## Qué hace cada corrida

1. `export_reports.php` lee `observatory_reports` con el bootstrap de Zentra y escribe `/var/lib/wcodexia-lora/dataset.jsonl`. Se queda el id, el tipo y el relato. Fuera quedan nombre, teléfono, usuario, IP, fotos y coordenadas. El relato se recorta a 400 caracteres y se le quitan correos, URLs y números largos.
2. Se descarga de memoria el modelo cargado en Ollama (`ollama stop`), sin detener el servicio.
3. `llama-server` abre el mismo GGUF en `127.0.0.1:11436`, solo para sacar vectores, y se cierra al terminar.
4. `train.py` lee `dataset.jsonl` y, si existe, `external.jsonl`. No los mezcla en disco. Aparta al menos 20 relatos para evaluar, sin repetir ninguno del entrenamiento, y entrena 50 épocas con rango 16, tasa 0,15 y semilla 7. La cabeza lineal parte de cero: los únicos pesos que se mueven son las dos matrices de bajo rango. El GGUF no se reescribe.
5. `POST /api/versions` en el panel (`127.0.0.1:8090`) con muestras, pérdida de entrenamiento, pérdida de evaluación y exactitud (`score`, de 0 a 1). La versión queda pendiente. Aprobar sigue sin desplegar nada.

En un KVM de 8 GB no cabe reescribir los pesos del 3B al lado de Zentra. Por eso el adaptador se entrena sobre los vectores del modelo congelado. Si el dataset no cambió, la corrida no publica otra versión.

## Horario

Timer `wcodexia-lora.timer`: cada 4 horas, en el minuto 20 (00:20, 04:20, 08:20, 12:20, 16:20 y 20:20 UTC), con hasta 15 minutos de retraso aleatorio. `Persistent=true` recupera una corrida perdida si el VPS estaba apagado. El servicio es `wcodexia-lora.service` (oneshot, tope 3,5 GB, 30 minutos).

`wcodexia-lora-ingest.timer` corre cada 4 horas en el minuto 05, antes del entrenamiento. `ingest.py` baja extractos al azar de la API de la Wikipedia en español (`type=wikipedia`) y notas del RSS de BBC Mundo (`type=noticia`), limpia HTML, recorta a 400 caracteres y descarta duplicados por `source_id`. Escribe solo `/var/lib/wcodexia-lora/external.jsonl`.

## Estado al 18 nov 2026

Los relatos de Zentra y los públicos siguen en archivos distintos.

| Archivo | Contenido |
| --- | --- |
| `dataset.jsonl` | Solo el export del observatorio. La última exportación escribió 47 relatos y 40 tipos. |
| `external.jsonl` | 16 relatos públicos: 4 de Wikipedia en español y 12 de BBC Mundo. Ids desde 1.000.000.000, sin pisar los de Zentra. |

`train.py` los junta en memoria. La corrida de la madrugada (timer `08:20` UTC, 03:20 hora de Colombia, más hasta 15 minutos) es la primera que lee los dos juntos. La versión ya publicada, `lora-20261118T0441Z`, salió antes de esa recolección: 27 de entrenamiento, 20 de evaluación, pérdida 0,366634 / 1,757548 y exactitud 0,25. Aprobar sigue sin cambiar `qwen-coder-2g`.

## Dónde está

| Pieza | Ruta |
| --- | --- |
| Código | `/opt/wcodexia-lora` |
| Dataset de Zentra | `/var/lib/wcodexia-lora/dataset.jsonl` |
| Relatos públicos | `/var/lib/wcodexia-lora/external.jsonl` |
| Adaptadores | `/var/lib/wcodexia-lora/adapters` |
| Binario de vectores | `/opt/llama.cpp/llama-server` |
| Log del proceso de vectores | `/var/log/wcodexia-lora-embed.log` |

Corrida manual:

```bash
systemctl start wcodexia-lora.service
```
