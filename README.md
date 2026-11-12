# WCodexIA

Asistente local de desarrollo. El código y las conversaciones se quedan en la máquina: el modelo corre en Ollama y no hay pagos ni llamadas a APIs externas.

## Qué incluye

- Chat web en `/` con historial en MySQL.
- Memoria entre sesiones: hasta 3 sesiones anteriores entran como contexto, no como instrucciones.
- Hueco de imagen visible y deshabilitado. Una subida se rechaza antes de llamar al modelo.
- Modo agente en `/agent`: lee archivos de un directorio indicado, propone un diff y solo escribe o ejecuta un comando después de aprobación.
- API local con token para consultar políticas y pedir completados.
- Políticas de seguridad aplicadas antes y después del modelo.

## Entorno

- PHP 8.2 y Laravel 12.
- MySQL 8 (Laragon). Bases `wcodexia` y `wcodexia_testing`, `utf8mb4_unicode_ci`.
- Ollama en `http://127.0.0.1:11434`.
- Modelo por defecto: `qwen2.5-coder:3b` (Q4_K_M, contexto 32768). Licencia Qwen Research: uso no comercial.

El cliente no elige el proveedor. `WCODEX_ALLOW_EXTERNAL` queda en `false`.

## Arranque

Ollama tiene que estar sirviendo y el modelo descargado:

```bash
ollama pull qwen2.5-coder:3b
```

En el proyecto:

```bash
composer install
copy .env.example .env
php artisan key:generate
```

En `.env` define `WCODEX_API_TOKEN` y confirma MySQL (`DB_DATABASE=wcodexia`, usuario `root`). Crea las dos bases y migra:

```bash
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

- Chat: http://localhost:8000
- Agente: http://localhost:8000/agent
- Salud: http://localhost:8000/up

`.env` no se versiona. `APP_KEY` y `WCODEX_API_TOKEN` son locales.

## Chat y memoria

`DevelopmentAssistant` compone el prompt con la tarea `explain`, las últimas 10 vueltas de la sesión y el bloque de `SessionMemory`. Ese bloque lleva como máximo 3 sesiones (las marcadas con `context_ids[]` primero, luego las más recientes), 6 mensajes y 500 caracteres por mensaje. El texto guardado del usuario es el prompt ya redactado; el contexto de archivos y de memoria se envía al modelo y no se duplica como mensaje de usuario.

El system prompt es fijo: el contenido del usuario, los archivos y la memoria son datos, no instrucciones.

## Agente

El usuario indica el directorio del proyecto, las rutas relativas y la instrucción. `LocalWorkspaceGuard` se construye con esa raíz en cada petición.

Límites de lectura y escritura:

- Máximo 8 archivos y 64 KB por archivo, UTF-8.
- Sin `..`, sin rutas absolutas y sin archivos sensibles (`.env`, credenciales, `.pem`, `.key`, `.p12`, `.pfx`).
- Una ruta fuera del directorio se rechaza. Un edit del modelo sobre un archivo que no se leyó se descarta.
- Un archivo que no existe se trata como contenido vacío para poder proponer uno nuevo.

El modelo debe devolver JSON:

```json
{
  "summary": "qué cambia",
  "edits": [{ "path": "app/Ejemplo.php", "content": "archivo completo" }],
  "command": "php -l app/Ejemplo.php"
}
```

`ProposalParser` acepta ese JSON aunque venga entre cercas Markdown y repara escapes inválidos dentro de strings (por ejemplo `App\Http` o saltos de línea crudos). Como máximo conserva 4 edits. `command` es opcional, de hasta 200 caracteres y sin saltos de línea.

La propuesta queda en sesión. La vista muestra el diff alineado por líneas. **Aprobar escritura** se habilita cuando hay edits válidos y todavía no se aplicaron. **Ejecutar comando** aparece solo si la propuesta trae un comando. Escribir y ejecutar exigen el `proposal_id` de esa sesión. El comando corre con el directorio del proyecto como cwd y un tope de 20 segundos.

## Políticas

Orden de entrada: `external_egress`, `prompt_injection`, `offensive_request`, `secret_redaction`.

Orden de salida: `offensive_request`, `secret_redaction`.

Un `deny` corta el pipeline. La redacción de secretos sustituye el valor por `[REDACTED]` antes del modelo y antes de persistir. La inyección de prompt se niega en español y en inglés. Se niegan pedidos ofensivos (ransomware, keylogger, rootkit, kit de phishing, robo de credenciales); la discusión defensiva sigue permitida.

En la API esos rechazos responden JSON: política 403, workspace 422, conversación desconocida 404, modelo caído 503.

## API

Prefijo `/api/v1`, header `Authorization: Bearer <WCODEX_API_TOKEN>`, límite 30 peticiones por minuto.

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `/api/v1/policies` | Lista de políticas y si están activas |
| POST | `/api/v1/assistant/completions` | Completado del asistente |

Cuerpo del completado:

- `task` (requerido): `explain`, `review`, `generate`, `refactor`, `test`.
- `prompt` (requerido): 1 a 8000 caracteres.
- `conversation_id` (opcional): UUID existente.
- `language` (opcional).
- `paths` (opcional): hasta 8 rutas relativas dentro del workspace de `WCODEX_WORKSPACE` o, si está vacío, la raíz de la aplicación.

El chat y el agente son rutas web con sesión y CSRF. No usan el token de la API.

## Estructura

```
app/Domain/Security      políticas y contrato del workspace
app/Domain/Assistant     chat, memoria, contratos del modelo
app/Domain/Agent         parser, diff, propuesta y comando aprobado
app/Infrastructure       Ollama, guard de archivos, repositorios Eloquent
app/Http                 chat, agente y API v1
config/wcodex.php        token, Ollama, workspace y políticas
```

## Pruebas

`phpunit.xml` usa MySQL `wcodexia_testing`, `WCODEX_API_TOKEN=test-token` y `OLLAMA_MODEL=qwen2.5-coder:7b` solo como valor de configuración: los tests no llaman a Ollama.

```bash
php artisan test
```

La suite cubre políticas, el límite del workspace, el chat (persistencia, memoria, imagen rechazada), la API y el agente (diff antes de escribir, comando bajo aprobación, JSON entre cercas con el botón de aprobar habilitado).
