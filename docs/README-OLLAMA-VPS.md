# Ollama en el VPS, aislado de Zentra

Estado al 18 de noviembre de 2026. Ollama quedó instalado en el KVM 2 de Hostinger (`srv1970897`, `82.25.66.93`) sin la plantilla del panel y sin cambiar el código, el Nginx, las bases ni los servicios de Zentra. El chat público de WCodexIA sí corre en este VPS, en Laravel, y llama a Ollama por `127.0.0.1:11434`.

## Estado actual

| Pieza | Valor |
| --- | --- |
| Binario | `/usr/local/bin/ollama` 0.40.16, modo CPU |
| Usuario | `ollama` (sistema) |
| Modelos | `/var/lib/ollama/models` |
| API directa | `http://127.0.0.1:11434` |
| Proxy | `http://127.0.0.1:11435`, otro proceso Nginx, no el de Zentra |
| Token | `/etc/ollama-isolated/token` (modo 600). No está en este repositorio |
| Modelo a usar | `wcodex-3b` (`qwen2.5:3b`, `num_ctx` 2048). `qwen-coder-2g` sigue instalado para los vectores del entrenamiento |
| Memoria | `MemoryMax=3G`, `MemoryHigh=2600M`. Con 2 GB el modelo de 2,1 GB quedaba frenado y `/api/chat` se cortaba a los 120 s |
| Residencia | `OLLAMA_KEEP_ALIVE=-1`. `ollama ps` lo muestra cargado hasta que se detenga. El chat envía `keep_alive: -1` y no cambia `num_ctx`: otro contexto recarga el modelo |
| Chat | `https://wcodexia.wcodex.cloud/chat`. Streaming NDJSON, tope 600 s, markdown escapado y panel fijo |
| Reinicio | `Restart=always` en `ollama.service` y `ollama-proxy.service` |
| Salud | `ollama-health.timer`, cada 60 segundos |

Una prueba del proxy respondió con el modelo cargado. Sin token, el proxy responde 401. `GET /health` en el proxy responde 200 y no pide token. El chat publicado usa `wcodex-3b`.

## Qué no se tocó

- `/home/wcodex-zentra/htdocs/zentra.wcodex.cloud` (código y `.env`)
- `/etc/nginx/sites-enabled`
- El Nginx maestro de Zentra (PID 1057) y sus workers
- `mysql`, `php8.3-fpm`, `zentra-queue.service`
- DNS y CloudPanel. No hay subdominio público para Ollama

Después de la instalación, lo único con fecha nueva bajo el home de Zentra fue una sesión de PHP y un access log. El sitio `https://zentra.wcodex.cloud/up` respondió 200 y la base `zentra` contestó `SELECT 1`.

## Pasos ejecutados

1. Foto previa: Nginx, MySQL, PHP 8.3 y la cola de Zentra en `active`. Había unos 5,6 GB disponibles y 82 GB libres en disco.
2. Se guardó `/root/ollama-install.sh` y se ejecutó el instalador oficial. Crea el usuario `ollama` y el binario en `/usr/local`. No reinstala el sistema.
3. Se detuvo el unit que deja el instalador y se añadió `/etc/systemd/system/ollama.service.d/isolated.conf` con escucha en `127.0.0.1:11434`, un solo modelo cargado y tope de 2 GB.
4. `ollama pull qwen2.5-coder:3b`.
5. Modelfile en `/var/lib/ollama/Modelfile.qwen3b` y `ollama create qwen-coder-2g`. El parámetro efectivo es `num_ctx 2048`. El contexto máximo de la arquitectura sigue siendo 32768; no se usa.
6. Proxy en `/etc/ollama-isolated/nginx.conf`, unit `ollama-proxy.service`. Escucha solo `127.0.0.1:11435`. El resto de rutas exige `Authorization: Bearer` con el token del servidor.
7. `/usr/local/sbin/ollama-healthcheck` reinicia `ollama` o el proxy si dejan de responder. El timer `ollama-health.timer` lo llama cada minuto.

## Cómo llamarlo

Desde el propio VPS, Zentra puede usar el proxy sin salir a internet:

```bash
curl -sS http://127.0.0.1:11435/api/generate \
  -H "Authorization: Bearer $(cat /etc/ollama-isolated/token)" \
  -H "Content-Type: application/json" \
  -d '{"model":"wcodex-3b","prompt":"di ok","stream":false,"keep_alive":-1,"options":{"num_predict":8}}'
```

El chat publicado llama a `127.0.0.1:11434` desde PHP. No hace falta túnel para el sitio. Para hablar con el proxy desde el PC sigue haciendo falta un túnel, sin DNS:

```bash
ssh -i %USERPROFILE%\.ssh\id_ed25519_decowandy_vps -N -L 11435:127.0.0.1:11435 root@82.25.66.93
```

Con ese túnel, `http://127.0.0.1:11435` en el PC llega al proxy. El token no se copia al `.env` de Zentra ni al de WCodexIA hasta que se autorice ese cambio.

## Comprobación

```bash
systemctl is-active nginx mysql php8.3-fpm zentra-queue.service ollama ollama-proxy
curl -fsS -o /dev/null -w "%{http_code}\n" https://zentra.wcodex.cloud/up
ss -lntp | grep -E "11434|11435"
```

Los dos puertos nuevos tienen que verse en `127.0.0.1` y en ningún `0.0.0.0`.

## Panel y ruta pública (18 nov 2026)

El vhost de `wcodexia.wcodex.cloud` quedó partido. Zentra no se tocó.

| Ruta | Destino |
| --- | --- |
| `https://wcodexia.wcodex.cloud/` | Laravel en `/home/wcodex-wcodexia/wcodexia`. Sin sesión redirige a `/login` |
| `https://wcodexia.wcodex.cloud/ollama/` | Proxy de Ollama, `127.0.0.1:11435` |
| Panel de versiones | Sigue en `127.0.0.1:8090`. Laravel lo muestra en `/versiones` |

El certificado sigue siendo Let's Encrypt. `/.well-known/` se sirve desde el disco del sitio para la renovación. El código del panel está en `supervision-panel/README.md`. El afinado y la recolección pública están en `lora-job/README.md`: no cambian `wcodex-3b` y no escriben dentro de Zentra. Los vectores del clasificador salen del GGUF de `qwen-coder-2g`, que sigue instalado.

## Chat publicado (18 nov 2026)

Laravel está en `/home/wcodex-wcodexia/wcodexia`. El modelo del sitio es `wcodex-3b` (`/var/lib/ollama/Modelfile.wcodex3b`, `FROM qwen2.5:3b`, `num_ctx 2048`). No se hizo prueba de carga con varios usuarios. Si la pregunta pide un dato, el chat lee una página pública, descarta redes y videos, y entrega hasta 1800 caracteres con la línea `Fuente`.

| Comportamiento | Dónde |
| --- | --- |
| El recuadro se vacía al enviar | `resources/views/chat/index.blade.php` |
| La respuesta sale por fragmentos NDJSON y cURL entrega cada línea en cuanto llega | `OllamaProvider`, `ChatController` |
| Tope de generación 600 s. En el vhost de WCodexIA, `fastcgi_read_timeout 600s`, `fastcgi_buffering off` y `gzip off` en PHP | `OLLAMA_TIMEOUT`, Nginx |
| Solo se recuerdan sesiones pedidas en `context_ids[]`. No se añaden otras conversaciones solas | `SessionMemory` |
| Menú de tres puntos y `DELETE /c/{uuid}` | panel y `ChatController::destroy` |
| El panel no se mueve al hacer scroll en el hilo. Si la lista no cabe, el panel tiene scroll propio | CSS del chat |
| Negrita, títulos, listas y código. El HTML del modelo se escapa antes | `MessageHtmlRenderer` |
| El modelo queda residente | `OLLAMA_KEEP_ALIVE=-1`, `MemoryMax=3G`, `MemoryHigh=2600M` |
