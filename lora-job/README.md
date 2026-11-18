# Trabajo LoRA

Entrena un adaptador con relatos públicos y publica las métricas en el panel. No lee el observatorio de Zentra y no cambia el modelo que Ollama está sirviendo (`wcodex-3b`).

## Qué hace cada corrida

1. No hay exportación de Zentra. `dataset.jsonl` puede seguir en disco, pero `train.py` no lo abre.
2. Si el archivo público cambió desde la última corrida, se descarga de memoria el modelo del chat y el alias de vectores (`ollama stop wcodex-3b` y `ollama stop qwen-coder-2g`), sin detener el servicio. Si no cambió, la corrida termina y el chat sigue cargado. Gemini solo etiqueta los relatos nuevos.
3. `llama-server` abre el GGUF de `qwen-coder-2g` en `127.0.0.1:11436`, solo para sacar vectores, y se cierra al terminar.
4. `train.py` lee solo `external.jsonl`. Aparta al menos 20 relatos para evaluar, sin repetir ninguno del entrenamiento y sin evaluar un tipo que no quede también en el entrenamiento. Entrena 50 épocas con rango 16, tasa 0,15 y semilla 7. La cabeza lineal parte de cero: los únicos pesos que se mueven son las dos matrices de bajo rango. El GGUF no se reescribe.
5. `POST /api/versions` en el panel (`127.0.0.1:8090`) con muestras, pérdida de entrenamiento, pérdida de evaluación y exactitud (`score`, de 0 a 1). La versión queda pendiente. Aprobar sigue sin desplegar nada.

En un KVM de 8 GB no cabe reescribir los pesos del 3B al lado de Zentra. Por eso el adaptador se entrena sobre los vectores del modelo congelado. Si el dataset no cambió, la corrida no publica otra versión.

## Horario

Timer `wcodexia-lora.timer`: cada 4 horas, en el minuto 20 (00:20, 04:20, 08:20, 12:20, 16:20 y 20:20 UTC), con hasta 15 minutos de retraso aleatorio. `Persistent=true` recupera una corrida perdida si el VPS estaba apagado. El servicio es `wcodexia-lora.service` (oneshot, tope 3,5 GB, 30 minutos).

`wcodexia-lora-ingest.timer` corre cada 4 horas en el minuto 05, antes del entrenamiento. `ingest.py` baja extractos de la Wikipedia en español (`wikipedia`), el RSS de BBC Mundo (`noticia`) y noticias públicas de hurto, riña, amenaza, extorsión, homicidio, secuestro, vandalismo, incendio, desaparición, arma, intrafamiliar, disturbio, invasión, contrabando, ciberataque, atentado, accidente, fraude y desastre. Cada tipo tiene varias búsquedas para no repetir los mismos titulares. Limpia HTML, recorta a 400 caracteres y descarta duplicados por `source_id`. Escribe solo `/var/lib/wcodexia-lora/external.jsonl`.

Si existe `/var/lib/wcodexia-lora/gemini.env` con `GEMINI_API_KEY`, `label_gemini.py` corre al inicio de la corrida y corrige el tipo de esos relatos con Gemini 3.5 Flash-Lite. Gemini 2.5 Flash-Lite ya no acepta esta cuenta. La clave está solo en ese archivo, modo 600, usuario `wcodexia-lora`. No está en el repositorio, no se imprime y no lee Zentra.

## Estado al 18 nov 2026, tarde

Zentra no es fuente de entrenamiento. El archivo del observatorio queda aparte y la corrida no lo lee. `external.jsonl` tenía 606 relatos en 21 tipos: los 19 de suceso de arriba, más `noticia` y `wikipedia`. Esos tipos los corrige Gemini. La corrida de las 20:20 UTC es la primera que entrena con ese archivo.

| Archivo | Contenido |
| --- | --- |
| `dataset.jsonl` | Export viejo del observatorio. No entra en `train.py`. |
| `external.jsonl` | Relatos públicos. Ids desde 1.000.000.000. |
| `gemini.env` | Clave de Gemini. Solo en el servidor. |

## Dónde está

| Pieza | Ruta |
| --- | --- |
| Código | `/opt/wcodexia-lora` |
| Export sin uso | `/var/lib/wcodexia-lora/dataset.jsonl` |
| Relatos públicos | `/var/lib/wcodexia-lora/external.jsonl` |
| Adaptadores | `/var/lib/wcodexia-lora/adapters` |
| Binario de vectores | `/opt/llama.cpp/llama-server` |
| Log del proceso de vectores | `/var/log/wcodexia-lora-embed.log` |

Corrida manual:

```bash
systemctl start wcodexia-lora.service
```
