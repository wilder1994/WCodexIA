<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Versiones · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; background: #10140f; color: #e7eee4; font: 14px/1.4 "Segoe UI", system-ui, sans-serif; }
        header, main { max-width: 960px; margin: 0 auto; padding: 20px 16px; }
        a { color: #b6d7a8; }
        .card { background: #171d16; border: 1px solid #2c3828; border-radius: 12px; padding: 14px; margin: 12px 0; }
        .head { display: flex; justify-content: space-between; gap: 8px; }
        .metrics { display: flex; flex-wrap: wrap; gap: 8px; }
        .metric { background: #10140f; border-radius: 8px; padding: 8px; min-width: 140px; }
        button { border: 0; border-radius: 8px; padding: 8px 12px; color: #e7eee4; }
        .approve { background: #245c32; }
        .reject { background: #6b3030; }
        .badge { border: 1px solid #2c3828; border-radius: 999px; padding: 2px 8px; }
    </style>
</head>
<body>
    <header>
        <p><a href="{{ route('home') }}">Inicio</a> · <a href="{{ route('chat.index') }}">Chat</a></p>
        <h1>Versiones LoRA</h1>
        <p>Aprobar no cambia el modelo que sirve Ollama. La lista se actualiza sola.</p>
        <p id="status">Cargando versiones.</p>
    </header>
    <main id="list"></main>
    <script>
        const list = document.querySelector('#list');
        const statusLine = document.querySelector('#status');
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const labels = {
            pending: 'Pendiente', approved: 'Aprobada', rejected: 'Rechazada',
            train_samples: 'Muestras de entrenamiento', eval_samples: 'Muestras de evaluación',
            train_loss: 'Pérdida de entrenamiento', eval_loss: 'Pérdida de evaluación', score: 'Puntaje'
        };
        const show = (value) => value === null || value === undefined || value === '' ? 'sin dato' : String(value);
        function card(version) {
            const article = document.createElement('article');
            article.className = 'card';
            const head = document.createElement('div');
            head.className = 'head';
            const title = document.createElement('h2');
            title.textContent = version.label || version.id;
            const badge = document.createElement('span');
            badge.className = 'badge';
            badge.textContent = labels[version.status] || version.status;
            head.append(title, badge);
            const metrics = document.createElement('div');
            metrics.className = 'metrics';
            Object.entries(version.metrics || {}).forEach(([key, value]) => {
                const box = document.createElement('div');
                box.className = 'metric';
                const name = document.createElement('div');
                name.textContent = labels[key] || key;
                const strong = document.createElement('strong');
                strong.textContent = show(value);
                box.append(name, strong);
                metrics.append(box);
            });
            const notes = document.createElement('p');
            notes.textContent = version.notes || '';
            const actions = document.createElement('div');
            ['approved', 'rejected'].forEach((decision) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = decision === 'approved' ? 'approve' : 'reject';
                button.textContent = decision === 'approved' ? 'Aprobar' : 'Rechazar';
                button.addEventListener('click', () => decide(version.id, decision));
                actions.append(button);
            });
            article.append(head, metrics, notes, actions);
            return article;
        }
        async function decide(id, decision) {
            statusLine.textContent = 'Guardando la decisión.';
            const response = await fetch(`/versiones/${encodeURIComponent(id)}/decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify({ status: decision })
            });
            if (!response.ok) statusLine.textContent = 'No se pudo guardar la decisión.';
            await load();
        }
        async function load() {
            const response = await fetch('/versiones/datos', { headers: { 'Accept': 'application/json' } });
            const store = await response.json();
            if (store.error) { statusLine.textContent = store.error; return; }
            const versions = store.versions || [];
            list.replaceChildren(...versions.map(card));
            statusLine.textContent = versions.length ? `${versions.length} versión(es).` : 'Todavía no hay versiones.';
        }
        load();
        setInterval(load, 15000);
    </script>
</body>
</html>
