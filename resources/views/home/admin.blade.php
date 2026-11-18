<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; background: #10140f; color: #e7eee4; font: 15px/1.4 "Segoe UI", system-ui, sans-serif; }
        main { max-width: 640px; margin: 0 auto; padding: 32px 16px; }
        h1 { margin: 0 0 8px; }
        p { color: #9aab96; }
        button { margin-top: 18px; border: 0; border-radius: 8px; padding: 8px 14px; background: #243024; color: inherit; }
    </style>
</head>
<body>
    <main>
        <h1>Área de administración</h1>
        <p>Hola, {{ $user->name }}. Esta es tu área. Las versiones del modelo y el chat no están en este perfil.</p>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Salir</button>
        </form>
    </main>
</body>
</html>
