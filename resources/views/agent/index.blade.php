<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WCodexIA · Agente</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #10140f;
            --panel: #171d16;
            --line: #2c3828;
            --text: #e7eee4;
            --muted: #9aab96;
            --accent: #b6d7a8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.5 "Segoe UI", system-ui, sans-serif;
        }

        header, main { padding: 20px; }

        header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 1px solid var(--line);
        }

        h1 { margin: 0; font-size: 18px; letter-spacing: 0.02em; }

        a { color: var(--accent); text-decoration: none; border-bottom: 1px solid var(--line); }

        .meta, .hint { color: var(--muted); font-size: 13px; }

        form, .proposal {
            max-width: 860px;
            margin-top: 18px;
        }

        label { display: block; margin: 14px 0 6px; color: var(--muted); font-size: 13px; }

        input[type="text"], textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #0d120d;
            color: var(--text);
            font: inherit;
        }

        textarea { min-height: 88px; resize: vertical; }

        .actions { display: flex; gap: 10px; margin-top: 12px; }

        button {
            padding: 8px 16px;
            border: 1px solid var(--accent);
            border-radius: 8px;
            background: transparent;
            color: var(--accent);
            font: inherit;
            cursor: pointer;
        }

        button.solid { background: var(--accent); color: #142012; }

        button:disabled { opacity: 0.4; cursor: not-allowed; }

        pre {
            overflow: auto;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #0d120d;
            white-space: pre-wrap;
        }

        .error { color: #e7b2b2; }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>WCodexIA · Agente</h1>
            <p class="meta">{{ $model }} · nada se escribe ni se ejecuta sin aprobación</p>
        </div>
        <a href="{{ route('chat.index') }}">Chat</a>
    </header>
    <main>
        <form method="post" action="{{ route('agent.propose') }}">
            @csrf
            <label for="project">Directorio del proyecto</label>
            <input id="project" name="project" type="text" required maxlength="500" value="{{ old('project', $project) }}" placeholder="C:\laragon\www\mi-proyecto">
            <label for="paths">Archivos a leer, uno por línea</label>
            <textarea id="paths" name="paths" maxlength="2000" placeholder="src/Service.php">{{ old('paths') }}</textarea>
            <label for="instruction">Qué debe cambiar</label>
            <textarea id="instruction" name="instruction" required maxlength="4000" placeholder="Agrega el tipo de retorno y un test mínimo.">{{ old('instruction') }}</textarea>
            @foreach (['project', 'paths', 'instruction', 'apply', 'command'] as $field)
                @error($field)
                    <p class="error">{{ $message }}</p>
                @enderror
            @endforeach
            <div class="actions">
                <button class="solid" type="submit">Leer y proponer</button>
            </div>
            <p class="hint">El agente lee esos archivos en el directorio indicado. El diff aparece antes de cualquier escritura.</p>
        </form>

        @if (is_array($proposal))
            <section class="proposal">
                <h2>Propuesta</h2>
                <p>{{ $proposal['summary'] ?? '' }}</p>
                @foreach ($proposal['withheld'] ?? [] as $path)
                    <p class="hint">Omitido por secreto o por estar fuera de los archivos leídos: {{ $path }}</p>
                @endforeach
                @forelse ($proposal['diffs'] ?? [] as $diff)
                    <h3>{{ $diff['path'] }}</h3>
                    <pre>{{ $diff['diff'] }}</pre>
                @empty
                    <p class="hint">No hay cambios de archivo para aprobar.</p>
                @endforelse

                @if (($proposal['applied'] ?? false) === true)
                    <p class="hint">Escritura aplicada: {{ implode(', ', $proposal['written'] ?? []) }}</p>
                @endif

                <div class="actions">
                    <form method="post" action="{{ route('agent.apply') }}">
                        @csrf
                        <input type="hidden" name="proposal_id" value="{{ $proposal['id'] }}">
                        <button type="submit" @disabled(($proposal['applied'] ?? false) === true || ($proposal['diffs'] ?? []) === [])>Aprobar escritura</button>
                    </form>
                    @if (is_string($proposal['command'] ?? null))
                        <form method="post" action="{{ route('agent.run') }}">
                            @csrf
                            <input type="hidden" name="proposal_id" value="{{ $proposal['id'] }}">
                            <button type="submit">Ejecutar comando</button>
                        </form>
                    @endif
                </div>
                @if (is_string($proposal['command'] ?? null))
                    <p class="hint">Comando pendiente, se ejecuta en el proyecto solo si lo apruebas:</p>
                    <pre>{{ $proposal['command'] }}</pre>
                @endif
                @if (is_string($proposal['output'] ?? null))
                    <p class="hint">Salida del comando</p>
                    <pre>{{ $proposal['output'] }}</pre>
                @endif
            </section>
        @endif
    </main>
</body>
</html>
