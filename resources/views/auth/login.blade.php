<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #10140f; color: #e7eee4; font: 15px/1.4 "Segoe UI", system-ui, sans-serif; }
        form { width: min(360px, calc(100% - 32px)); background: #171d16; border: 1px solid #2c3828; border-radius: 12px; padding: 22px; }
        h1 { margin: 0 0 6px; font-size: 20px; }
        p { margin: 0 0 16px; color: #9aab96; }
        label { display: block; margin: 12px 0 4px; }
        input { width: 100%; box-sizing: border-box; padding: 8px 10px; border-radius: 8px; border: 1px solid #2c3828; background: #10140f; color: inherit; }
        button { margin-top: 16px; border: 0; border-radius: 8px; padding: 8px 14px; background: #245c32; color: #e7eee4; }
        .error { color: #e7b2b2; font-size: 13px; }
    </style>
</head>
<body>
    <form method="post" action="{{ route('login') }}">
        @csrf
        <h1>WCodexIA</h1>
        <p>Entra con tu usuario.</p>
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        <label for="password">Clave</label>
        <input id="password" name="password" type="password" required>
        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror
        <button type="submit">Entrar</button>
    </form>
</body>
</html>
