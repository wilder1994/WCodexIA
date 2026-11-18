<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios · WCodexIA</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; background: #10140f; color: #e7eee4; font: 15px/1.4 "Segoe UI", system-ui, sans-serif; }
        main { max-width: 860px; margin: 0 auto; padding: 28px 16px; }
        a { color: #b6d7a8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #2c3828; }
        .error { color: #e7b2b2; }
        button { border: 0; border-radius: 8px; padding: 6px 10px; background: #6b3030; color: inherit; }
    </style>
</head>
<body>
    <main>
        <p><a href="{{ route('home') }}">Inicio</a></p>
        <h1>Usuarios</h1>
        <p><a href="{{ route('users.create') }}">Nuevo usuario</a></p>
        @error('user')
            <p class="error">{{ $message }}</p>
        @enderror
        <table>
            <thead>
                <tr><th>Nombre</th><th>Correo</th><th>Chat</th><th>Versiones</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $account)
                    <tr>
                        <td>{{ $account->name }}</td>
                        <td>{{ $account->email }}</td>
                        <td>{{ $account->allows('chat') ? 'Sí' : 'No' }}</td>
                        <td>{{ $account->allows('versions') ? 'Sí' : 'No' }}</td>
                        <td>
                            <a href="{{ route('users.edit', $account) }}">Editar</a>
                            @unless (auth()->user()->is($account))
                                <form method="post" action="{{ route('users.destroy', $account) }}" style="display:inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit">Borrar</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </main>
</body>
</html>
