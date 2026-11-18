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

        html { height: 100%; }

        body {
            margin: 0;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            background: var(--bg);
            color: var(--text);
            font: 13px/1.4 "Segoe UI", system-ui, sans-serif;
        }

        aside {
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
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

        .session-row {
            position: relative;
            display: flex;
            align-items: center;
            gap: 2px;
            margin-top: 6px;
        }

        .session {
            flex: 1;
            min-width: 0;
            margin-top: 0;
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

        .session-row.active .session { border-color: var(--accent); }

        .session-more {
            flex: 0 0 26px;
            width: 26px;
            height: 26px;
            padding: 0;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
        }

        .session-more svg { width: 14px; height: 14px; display: block; margin: 0 auto; }

        .session-more:hover,
        .session-more[aria-expanded="true"] {
            color: var(--text);
            background: #243024;
        }

        .session-menu {
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            z-index: 8;
            width: 148px;
            margin: 0;
            padding: 6px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #1a2219;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
        }

        .session-menu form { margin: 0; }

        main {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            height: 100%;
            overflow: hidden;
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
            min-height: 0;
            overflow: auto;
            padding: 16px 16px 8px;
        }

        .bubble {
            width: fit-content;
            max-width: min(720px, 100%);
            margin: 0 0 8px;
            padding: 8px 10px;
            border-radius: 8px;
            text-align: left;
            text-indent: 0;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .bubble p { margin: 0 0 8px; }

        .bubble p:last-child,
        .bubble ol:last-child,
        .bubble ul:last-child,
        .bubble pre:last-child,
        .bubble h1:last-child,
        .bubble h2:last-child,
        .bubble h3:last-child { margin-bottom: 0; }

        .bubble h1, .bubble h2, .bubble h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 650;
            line-height: 1.3;
        }

        .bubble ol, .bubble ul { margin: 0 0 8px; padding-left: 1.25em; }

        .bubble li { margin: 2px 0; }

        .bubble strong { font-weight: 650; }

        .bubble code {
            font-family: ui-monospace, Consolas, monospace;
            font-size: 12px;
            background: #10160f;
            padding: 1px 4px;
            border-radius: 4px;
        }

        .bubble pre.code {
            margin: 0 0 8px;
            padding: 8px 10px;
            overflow: auto;
            white-space: pre;
            background: #10160f;
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .bubble pre.code code { padding: 0; background: transparent; }

        .bubble.user { margin-left: auto; background: var(--user); }

        .bubble.assistant { margin-right: auto; background: var(--assistant); border: 1px solid var(--line); }

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

        button.session-delete {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 8px;
            width: 100%;
            height: 36px;
            margin: 0;
            padding: 0 10px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: #e06c6c;
            font: 13px/1 "Segoe UI", system-ui, sans-serif;
            text-align: left;
            white-space: nowrap;
            cursor: pointer;
        }

        button.session-delete svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            display: block;
        }

        button.session-delete:hover {
            background: #3a2020;
            color: #e06c6c;
        }

        .error { color: #e7b2b2; font-size: 14px; }

        @media (max-width: 800px) {
            body {
                grid-template-columns: 1fr;
                grid-template-rows: minmax(0, 42vh) minmax(0, 1fr);
            }

            aside {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            main { height: auto; }
        }
    </style>
</head>
<body>
    <aside>
        <h1>WCodexIA</h1>
        <p class="meta">Asistente local · <a href="{{ route('versions.index') }}">Versiones</a> · <a href="{{ route('agent.index') }}">Agente</a></p>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="new-chat" type="submit">Salir</button></form>
        <a class="new-chat" href="{{ route('chat.index') }}">Nueva conversación</a>
        @forelse ($conversations as $conversation)
            <div class="session-row {{ $activeId === $conversation->id ? 'active' : '' }}">
                <a class="session" href="{{ route('chat.show', $conversation->id) }}">{{ $conversation->title }}</a>
                <button class="session-more" type="button" aria-label="Opciones de la conversación" aria-expanded="false" aria-haspopup="menu">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="3" r="1.2" fill="currentColor"/><circle cx="8" cy="8" r="1.2" fill="currentColor"/><circle cx="8" cy="13" r="1.2" fill="currentColor"/></svg>
                </button>
                <div class="session-menu" role="menu" hidden>
                    <form method="post" action="{{ route('chat.destroy', $conversation->id) }}">
                        @csrf
                        @method('DELETE')
                        <button class="session-delete" type="submit" role="menuitem">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4.5 7h15M9.5 7V4.5h5V7M7 7l1 13h8l1-13"/></svg>
                            Eliminar
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <p class="meta">Todavía no hay sesiones guardadas.</p>
        @endforelse
    </aside>
    <main>
        <header>
            <div class="status">
                <span class="dot {{ $modelOnline ? 'on' : '' }}"></span>
                <strong>Wcodex IA</strong>
                <div>{{ $modelOnline ? 'Modelo cargado en este equipo' : 'El modelo todavía no está cargado en este equipo' }}</div>
            </div>
        </header>
        <section class="thread" aria-live="polite">
            @php($messageHtml = app(\App\Domain\Assistant\Services\MessageHtmlRenderer::class))
            @forelse ($messages as $message)
                <article class="bubble {{ $message->role->value }}">@if ($message->hasImage && $activeId && $message->id)<img src="{{ route('chat.image', ['conversation' => $activeId, 'message' => $message->id]) }}" alt="Imagen adjunta">@endif{!! $messageHtml->render($message->content) !!}</article>
            @empty
                <p class="empty">Escribe un mensaje. La respuesta se genera en este servidor y la sesión queda guardada.</p>
            @endforelse
        </section>
        <script>
            history.scrollRestoration = 'manual';
            const pinThread = () => {
                const thread = document.querySelector('.thread');
                if (thread) {
                    thread.scrollTop = thread.scrollHeight;
                }
            };
            pinThread();
            window.addEventListener('load', pinThread);
        </script>
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
            <p class="hint" id="composer-status">Enter envía. Mayús+Enter hace un salto de línea. La imagen se guarda en este equipo. El modelo recibe el texto leído, no los píxeles.</p>
        </form>
        <script>
            const prompt = document.getElementById('prompt');
            const composer = document.getElementById('composer');
            const status = document.getElementById('composer-status');

            prompt.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' || event.shiftKey) {
                    return;
                }

                event.preventDefault();
                composer.requestSubmit();
            });

            const escapeChat = (value) => value
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;');

            const renderChat = (source) => {
                const blocks = [];
                const text = source.replaceAll('\r\n', '\n').replaceAll('\r', '\n').replace(/```[^\n]*\n([\s\S]*?)```/g, (_, code) => {
                    const index = blocks.length;
                    blocks.push('<pre class="code"><code>' + escapeChat(code.replace(/\n$/, '')) + '</code></pre>');
                    return '\n@@B' + index + '@@\n';
                });
                const html = [];
                let paragraph = [];
                let list = '';
                const flushParagraph = () => {
                    if (paragraph.length === 0) return;
                    html.push('<p>' + paragraph.join('<br>') + '</p>');
                    paragraph = [];
                };
                const flushList = () => {
                    if (list === '') return;
                    html.push('</' + list + '>');
                    list = '';
                };
                const inline = (line) => escapeChat(line)
                    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                    .replace(/\*\*(.+?)\*\*|__(.+?)__/g, '<strong>$1$2</strong>');

                for (const line of text.split('\n')) {
                    const trimmed = line.trim();
                    const block = trimmed.match(/^@@B(\d+)@@$/);
                    if (block) {
                        flushParagraph();
                        flushList();
                        html.push(blocks[Number(block[1])]);
                        continue;
                    }
                    if (trimmed === '') {
                        flushParagraph();
                        flushList();
                        continue;
                    }
                    const formatted = inline(line);
                    const heading = formatted.match(/^(#{1,3})\s+(.+)$/);
                    if (heading) {
                        flushParagraph();
                        flushList();
                        const level = heading[1].length;
                        html.push('<h' + level + '>' + heading[2] + '</h' + level + '>');
                        continue;
                    }
                    const ordered = formatted.match(/^\s*\d+\.\s+(.+)$/);
                    if (ordered) {
                        flushParagraph();
                        if (list !== 'ol') {
                            flushList();
                            html.push('<ol>');
                            list = 'ol';
                        }
                        html.push('<li>' + ordered[1] + '</li>');
                        continue;
                    }
                    const bullet = formatted.match(/^\s*[-*]\s+(.+)$/);
                    if (bullet) {
                        flushParagraph();
                        if (list !== 'ul') {
                            flushList();
                            html.push('<ul>');
                            list = 'ul';
                        }
                        html.push('<li>' + bullet[1] + '</li>');
                        continue;
                    }
                    flushList();
                    paragraph.push(formatted);
                }
                flushParagraph();
                flushList();
                return html.join('');
            };

            composer.addEventListener('submit', async (event) => {
                event.preventDefault();
                const text = prompt.value;
                const form = new FormData(composer);
                const button = composer.querySelector('button[type="submit"]');
                const imageInput = document.getElementById('image');
                const previewBox = document.querySelector('.paste-preview');
                const previewImg = previewBox.querySelector('img');
                const imageFile = imageInput.files[0] || null;
                const imageUrl = !previewBox.hidden && previewImg.getAttribute('src') ? previewImg.getAttribute('src') : '';
                prompt.value = '';
                if (imageFile) {
                    imageInput.value = '';
                    previewBox.hidden = true;
                    previewImg.removeAttribute('src');
                    previewUrl = '';
                }
                button.disabled = true;
                prompt.readOnly = true;
                status.textContent = imageFile ? 'Leyendo la imagen…' : 'Generando la respuesta…';

                const thread = document.querySelector('.thread');
                const empty = thread.querySelector('.empty');
                if (empty) {
                    empty.remove();
                }

                const userBubble = document.createElement('article');
                userBubble.className = 'bubble user';
                if (imageUrl !== '') {
                    const sentImage = document.createElement('img');
                    sentImage.src = imageUrl;
                    sentImage.alt = 'Imagen adjunta';
                    userBubble.append(sentImage);
                }
                userBubble.insertAdjacentHTML('beforeend', renderChat(text));
                const reply = document.createElement('article');
                reply.className = 'bubble assistant';
                thread.append(userBubble, reply);
                thread.scrollTop = thread.scrollHeight;

                try {
                    const response = await fetch(composer.action, {
                        method: 'POST',
                        body: form,
                        headers: { 'Accept': 'application/x-ndjson' },
                    });

                    if (!response.ok || !response.body) {
                        throw new Error('El envío no llegó al modelo.');
                    }

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let conversation = '';
                    let replyText = '';

                    while (true) {
                        const chunk = await reader.read();
                        if (chunk.done) {
                            break;
                        }

                        buffer += decoder.decode(chunk.value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop();

                        for (const line of lines) {
                            if (line.trim() === '') {
                                continue;
                            }

                            const payload = JSON.parse(line);
                            if (payload.error) {
                                throw new Error(payload.error);
                            }
                            if (payload.delta) {
                                replyText += payload.delta;
                                reply.innerHTML = renderChat(replyText);
                                thread.scrollTop = thread.scrollHeight;
                                status.textContent = 'Generando la respuesta…';
                            }
                            if (payload.conversation) {
                                conversation = payload.conversation;
                            }
                        }
                    }

                    if (conversation !== '') {
                        history.scrollRestoration = 'manual';
                        window.location.assign('/c/' + conversation);
                    }
                } catch (error) {
                    if (prompt.value === '') {
                        prompt.value = text;
                    }
                    if (imageFile) {
                        showFile(imageFile);
                    }
                    button.disabled = false;
                    prompt.readOnly = false;
                    status.textContent = error instanceof Error ? error.message : 'No se pudo completar la respuesta.';
                }
            });
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

            const closeSessionMenus = () => {
                document.querySelectorAll('.session-menu').forEach((menu) => menu.setAttribute('hidden', ''));
                document.querySelectorAll('.session-more').forEach((button) => button.setAttribute('aria-expanded', 'false'));
            };

            document.querySelectorAll('.session-more').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const menu = button.parentElement.querySelector('.session-menu');
                    const willOpen = menu.hasAttribute('hidden');
                    closeSessionMenus();

                    if (willOpen) {
                        menu.removeAttribute('hidden');
                        button.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            document.addEventListener('click', (event) => {
                if (event.target.closest('.session-menu')) {
                    return;
                }

                closeSessionMenus();
            });
        </script>
    </main>
</body>
</html>
