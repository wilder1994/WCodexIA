<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WCodexIA</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #10140f;
            --panel: #171d16;
            --line: #2c3828;
            --text: #e7eee4;
            --muted: #9aab96;
            --accent: #b6d7a8;
            --user: #243024;
            --assistant: #1c241c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 260px 1fr;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.5 "Segoe UI", system-ui, sans-serif;
        }

        aside {
            border-right: 1px solid var(--line);
            padding: 20px 16px;
            background: var(--panel);
        }

        aside h1 {
            margin: 0 0 4px;
            font-size: 18px;
            letter-spacing: 0.02em;
        }

        .meta, .hint, .status { color: var(--muted); font-size: 13px; }

        .meta a {
            color: var(--accent);
            text-decoration: none;
            border-bottom: 1px solid var(--line);
        }

        .new-chat, .session {
            display: block;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: inherit;
            text-decoration: none;
        }

        .new-chat { margin-top: 12px; text-align: center; color: var(--accent); }

        .session-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 6px;
            margin-top: 12px;
            align-items: stretch;
        }

        .session.active, .memory-toggle:has(input:checked) { border-color: var(--accent); }

        .memory-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 0 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--muted);
            font-size: 11px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .memory-toggle input { accent-color: var(--accent); }

        main {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-width: 0;
        }

        header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
        }

        .status strong { color: var(--text); font-weight: 600; }

        .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 6px;
            border-radius: 50%;
            background: #c47a7a;
        }

        .dot.on { background: #8fce7a; }

        .thread {
            flex: 1;
            overflow: auto;
            padding: 24px 20px 12px;
        }

        .bubble {
            max-width: 720px;
            margin: 0 0 12px;
            padding: 12px 14px;
            border-radius: 12px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .bubble.user { margin-left: auto; background: var(--user); }

        .bubble.assistant { background: var(--assistant); border: 1px solid var(--line); }

        .empty { color: var(--muted); }

        .composer {
            padding: 12px 20px 20px;
        }

        textarea {
            width: 100%;
            min-height: 88px;
            resize: vertical;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #0d120d;
            color: var(--text);
            font: inherit;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
        }

        .image-slot {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            color: var(--muted);
            cursor: not-allowed;
        }

        .image-slot input { max-width: 180px; }

        button[type="submit"] {
            margin-left: auto;
            padding: 8px 16px;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: #142012;
            font: inherit;
            font-weight: 650;
            cursor: pointer;
        }

        .error { color: #e7b2b2; font-size: 14px; }

        @media (max-width: 800px) {
            body { grid-template-columns: 1fr; }
            aside { border-right: 0; border-bottom: 1px solid var(--line); }
            main { min-height: auto; }
        }
    </style>
</head>
<body>
    <aside>
        <h1>WCodexIA</h1>
        <p class="meta">Asistente local · <a href="{{ route('agent.index') }}">Agente</a></p>
        <a class="new-chat" href="{{ route('chat.index') }}">Nueva conversación</a>
        <p class="meta">Las tres sesiones más recientes entran al contexto. Marca una para forzarla.</p>
        @forelse ($conversations as $conversation)
            <div class="session-row">
                <a class="session {{ $activeId === $conversation->id ? 'active' : '' }}" href="{{ route('chat.show', $conversation->id) }}">
                    {{ $conversation->title }}
                </a>
                <label class="memory-toggle">
                    <input
                        type="checkbox"
                        name="context_ids[]"
                        value="{{ $conversation->id }}"
                        form="composer"
                        @checked(is_array(old('context_ids')) && in_array($conversation->id, old('context_ids'), true))
                    >
                    Cargar
                </label>
            </div>
        @empty
            <p class="meta">Todavía no hay sesiones guardadas.</p>
        @endforelse
    </aside>
    <main>
        <header>
            <div class="status">
                <span class="dot {{ $modelOnline ? 'on' : '' }}"></span>
                <strong>{{ $model }}</strong>
                <div>{{ $modelOnline ? 'Modelo cargado en este equipo' : 'El modelo todavía no está cargado en este equipo' }}</div>
            </div>
        </header>
        <section class="thread" aria-live="polite">
            @forelse ($messages as $message)
                <article class="bubble {{ $message->role->value }}">{{ $message->content }}</article>
            @empty
                <p class="empty">Escribe un mensaje. La respuesta se genera en este equipo y la sesión queda en MySQL.</p>
            @endforelse
        </section>
        <form id="composer" class="composer" method="post" action="{{ route('chat.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($activeId)
                <input type="hidden" name="conversation_id" value="{{ $activeId }}">
            @endif
            <label class="meta" for="prompt">Mensaje</label>
            <textarea id="prompt" name="prompt" required maxlength="8000" placeholder="Pregunta sobre el diseño o el código">{{ old('prompt') }}</textarea>
            @error('prompt')
                <p class="error">{{ $message }}</p>
            @enderror
            <div class="actions">
                <label class="image-slot">
                    Imagen
                    <input type="file" name="image" accept="image/*" disabled aria-disabled="true" title="La visión todavía no está disponible">
                </label>
                <button type="submit">Enviar</button>
            </div>
            @error('image')
                <p class="error">{{ $message }}</p>
            @enderror
            <p class="hint">Espacio de imagen reservado. No se procesa ningún archivo.</p>
            @error('context_ids')
                <p class="error">{{ $message }}</p>
            @enderror
            @error('context_ids.*')
                <p class="error">{{ $message }}</p>
            @enderror
        </form>
    </main>
</body>
</html>
