<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo usuario · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; background: #10140f; color: #e7eee4; font: 15px/1.4 "Segoe UI", system-ui, sans-serif; }
        main { max-width: 460px; margin: 0 auto; padding: 28px 16px; }
        a { color: #b6d7a8; }
        label { display: block; margin: 12px 0 4px; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; box-sizing: border-box; padding: 8px 10px; border-radius: 8px; border: 1px solid #2c3828; background: #171d16; color: inherit; }
        .check { display: flex; gap: 8px; align-items: center; margin-top: 10px; }
        button { margin-top: 16px; border: 0; border-radius: 8px; padding: 8px 14px; background: #245c32; color: inherit; }
        .error { color: #e7b2b2; font-size: 13px; }
    </style>
</head>
<body>
    <main>
        <p><a href="{{ route('users.index') }}">Usuarios</a></p>
        <h1>Nuevo usuario</h1>
        <form method="post" action="{{ route('users.store') }}">
            @csrf
            <label for="name">Nombre</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required>
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required>
            <label for="password">Clave</label>
            <input id="password" name="password" type="password" required>
            <label class="check"><input type="checkbox" name="can_chat" value="1" @checked(old('can_chat', '1') === '1')> Chat</label>
            <label class="check"><input type="checkbox" name="can_versions" value="1" @checked(old('can_versions') === '1')> Versiones LoRA</label>
            @foreach ($errors->all() as $error)
                <p class="error">{{ $error }}</p>
            @endforeach
            <button type="submit">Crear</button>
        </form>
    </main>
</body>
</html>
