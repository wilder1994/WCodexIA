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
            grid-template-columns: 220px 1fr;
            background: var(--bg);
            color: var(--text);
            font: 13px/1.4 "Segoe UI", system-ui, sans-serif;
        }

        aside {
            border-right: 1px solid var(--line);
            padding: 12px 10px;
            background: var(--panel);
        }

        aside h1 {
            margin: 0 0 2px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .meta, .hint { color: var(--muted); font-size: 12px; }

        .status {
            color: var(--text);
            font-size: 13px;
            line-height: 1.35;
        }

        .status strong { font-weight: 600; }

        .status div { color: var(--muted); font-size: 12px; }

        .meta a {
            color: var(--accent);
            text-decoration: none;
            border-bottom: 1px solid var(--line);
        }

        .new-chat, .session {
            display: block;
            color: inherit;
            text-decoration: none;
        }

        .new-chat {
            height: 30px;
            margin-top: 10px;
            padding: 0 12px;
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #142012;
            font-size: 13px;
            font-weight: 600;
            line-height: 30px;
            text-align: center;
        }

        .session {
            margin-top: 6px;
            padding: 5px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: transparent;
            font-size: 12px;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .session.active { border-color: var(--accent); }

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
            padding: 8px 16px;
            border-bottom: 1px solid var(--line);
        }

        .status { color: var(--text); }

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
            padding: 16px 16px 8px;
        }

        .bubble {
            max-width: 720px;
            margin: 0 0 8px;
            padding: 8px 10px;
            border-radius: 8px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .bubble.user { margin-left: auto; background: var(--user); }

        .bubble.assistant { background: var(--assistant); border: 1px solid var(--line); }

        .empty { color: var(--muted); }

        .composer {
            padding: 8px 16px 12px;
        }

        .composer-box {
            display: flex;
            flex-direction: column;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #0d120d;
            padding: 4px 4px 2px;
        }

        .paste-preview {
            position: relative;
            width: fit-content;
            margin: 6px 6px 2px;
        }

        .paste-preview img {
            display: block;
            max-width: 220px;
            max-height: 120px;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .clear-image {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 22px;
            height: 22px;
            border: 0;
            border-radius: 50%;
            background: #10140f;
            color: var(--text);
            cursor: pointer;
        }

        .composer-box textarea {
            width: 100%;
            min-height: 40px;
            max-height: 160px;
            resize: none;
            padding: 6px 8px 2px;
            border: 0;
            background: transparent;
            color: var(--text);
            font: inherit;
        }

        .composer-box textarea:focus { outline: none; }

        .composer-bar {
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .icon {
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #d7e4d2;
            cursor: pointer;
        }

        .icon svg { width: 20px; height: 20px; display: block; margin: 0 auto; }

        .icon.on, .icon:hover { color: var(--accent); }

        .icon.is-off { opacity: 0.4; cursor: default; }

        input[name="image"] { display: none; }

        .bubble img {
            display: block;
            max-width: 100%;
            max-height: 240px;
            margin-bottom: 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        button[type="submit"] {
            display: grid;
            place-items: center;
            width: 32px;
            height: 32px;
            margin-left: auto;
            padding: 0;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #d7e4d2;
            cursor: pointer;
        }

        button[type="submit"] svg { width: 20px; height: 20px; display: block; }

        button[type="submit"]:hover { color: var(--accent); }

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
        @forelse ($conversations as $conversation)
            <a class="session {{ $activeId === $conversation->id ? 'active' : '' }}" href="{{ route('chat.show', $conversation->id) }}">
                {{ $conversation->title }}
            </a>
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
                <article class="bubble {{ $message->role->value }}">
                    @if ($message->hasImage && $activeId && $message->id)
                        <img src="{{ route('chat.image', ['conversation' => $activeId, 'message' => $message->id]) }}" alt="Imagen adjunta">
                    @endif
                    {{ $message->content }}
                </article>
            @empty
                <p class="empty">Escribe un mensaje. La respuesta se genera en este equipo y la sesión queda en MySQL.</p>
            @endforelse
        </section>
        <form id="composer" class="composer" method="post" action="{{ route('chat.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($activeId)
                <input type="hidden" name="conversation_id" value="{{ $activeId }}">
            @endif
            <div class="composer-box">
                <div class="paste-preview" hidden>
                    <img alt="Imagen lista para enviar">
                    <button class="clear-image" type="button" aria-label="Quitar imagen">×</button>
                </div>
                <textarea id="prompt" name="prompt" required maxlength="8000" placeholder="Escribe, pega una imagen o dicta" aria-label="Mensaje">{{ old('prompt') }}</textarea>
                <div class="composer-bar">
                    <button class="icon" id="pick-image" type="button" aria-label="Adjuntar imagen">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M5 4.5h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-11a2 2 0 0 1 2-2Zm.6 12.6 3.4-3.5a.9.9 0 0 1 1.3 0l1.5 1.5 2.5-3a.9.9 0 0 1 1.4 0l2.7 3.2V6.8H5.6v10.3ZM8.4 9.2a1.45 1.45 0 1 0 0-2.9 1.45 1.45 0 0 0 0 2.9Z"></path>
                        </svg>
                    </button>
                    <button class="icon" id="dictate" type="button" aria-label="Dictar">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M12 2.2a3.4 3.4 0 0 0-3.4 3.4v5.2a3.4 3.4 0 0 0 6.8 0V5.6A3.4 3.4 0 0 0 12 2.2Zm-5.7 8.2a1 1 0 0 1 2 0 3.7 3.7 0 0 0 7.4 0 1 1 0 1 1 2 0 5.7 5.7 0 0 1-4.7 5.6v1.8h2.1a1 1 0 1 1 0 2H9.9a1 1 0 1 1 0-2h2.1v-1.8a5.7 5.7 0 0 1-4.7-5.6Z"></path>
                        </svg>
                    </button>
                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <button type="submit" aria-label="Enviar">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M3.48 2.4a.75.75 0 0 0-.93.94l2.43 7.91H13.5a.75.75 0 0 1 0 1.5H4.98l-2.43 7.9a.75.75 0 0 0 .93.94 60.5 60.5 0 0 0 18.44-8.99.75.75 0 0 0 0-1.21A60.5 60.5 0 0 0 3.48 2.4Z"></path>
                        </svg>
                    </button>
                </div>
            </div>
            @error('prompt')
                <p class="error">{{ $message }}</p>
            @enderror
            @error('image')
                <p class="error">{{ $message }}</p>
            @enderror
            <p class="hint">La imagen se guarda en este equipo. El modelo recibe el texto leído, no los píxeles.</p>
        </form>
        <script>
            const prompt = document.getElementById('prompt');
            const file = document.getElementById('image');
            const preview = document.querySelector('.paste-preview');
            const previewImage = preview.querySelector('img');
            let previewUrl = '';

            const showFile = (selected) => {
                if (!selected || !selected.type.startsWith('image/')) {
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(selected);
                file.files = transfer.files;

                if (previewUrl !== '') {
                    URL.revokeObjectURL(previewUrl);
                }

                previewUrl = URL.createObjectURL(selected);
                previewImage.src = previewUrl;
                preview.hidden = false;
            };

            document.getElementById('pick-image').addEventListener('click', () => file.click());
            file.addEventListener('change', () => showFile(file.files[0]));
            preview.querySelector('.clear-image').addEventListener('click', () => {
                file.value = '';
                preview.hidden = true;
                previewImage.removeAttribute('src');

                if (previewUrl !== '') {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = '';
                }
            });

            prompt.addEventListener('paste', (event) => {
                const items = event.clipboardData ? event.clipboardData.items : [];

                for (const item of items) {
                    if (!item.type.startsWith('image/')) {
                        continue;
                    }

                    const blob = item.getAsFile();

                    if (blob === null) {
                        continue;
                    }

                    event.preventDefault();
                    const extension = blob.type.split('/')[1] || 'png';
                    showFile(new File([blob], 'pegada.' + extension, { type: blob.type }));
                    break;
                }
            });

            const Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
            const dictate = document.getElementById('dictate');

            if (!Speech) {
                dictate.disabled = true;
                dictate.classList.add('is-off');
                dictate.title = 'Este navegador no ofrece dictado';
            } else {
                const recognition = new Speech();
                recognition.lang = 'es-ES';
                recognition.interimResults = true;
                let base = '';

                dictate.addEventListener('click', () => {
                    if (dictate.classList.contains('on')) {
                        recognition.stop();
                        return;
                    }

                    base = prompt.value.trim();
                    recognition.start();
                });

                recognition.addEventListener('start', () => dictate.classList.add('on'));
                recognition.addEventListener('end', () => dictate.classList.remove('on'));
                recognition.addEventListener('error', () => dictate.classList.remove('on'));
                recognition.addEventListener('result', (event) => {
                    let said = '';

                    for (let index = 0; index < event.results.length; index++) {
                        said += event.results[index][0].transcript;
                    }

                    prompt.value = base === '' ? said.trim() : base + ' ' + said.trim();
                });
            }
        </script>
    </main>
</body>
</html>
