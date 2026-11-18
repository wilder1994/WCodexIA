<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inicio · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; background: #10140f; color: #e7eee4; font: 15px/1.4 "Segoe UI", system-ui, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 32px 16px; }
        h1 { margin: 0 0 8px; }
        p { color: #9aab96; }
        .cards { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
        a.card { display: block; padding: 16px; border-radius: 12px; background: #171d16; border: 1px solid #2c3828; color: inherit; text-decoration: none; }
        a.card strong { display: block; margin-bottom: 4px; }
        button { margin-top: 18px; border: 0; border-radius: 8px; padding: 8px 14px; background: #243024; color: inherit; }
        @media (max-width: 640px) { .cards { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <main>
        <h1>Hola, {{ $user->name }}</h1>
        <p>Superadmin. Desde aquí pruebas el modelo y revisas las versiones.</p>
        <div class="cards">
            <a class="card" href="{{ route('versions.index') }}"><strong>Versiones LoRA</strong>Métricas, aprobar y rechazar.</a>
            <a class="card" href="{{ route('chat.index') }}"><strong>Chat</strong>Habla con el modelo local.</a>
            <a class="card" href="{{ route('users.index') }}"><strong>Usuarios</strong>Crear, editar y borrar cuentas.</a>
        </div>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Salir</button>
        </form>
    </main>
</body>
</html>
