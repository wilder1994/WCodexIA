<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

final class HostEnvironment
{
    /**
     * artisan serve deja el proceso con la variable real de Windows (Path).
     * Al lanzar un comando desde una petición, Symfony se queda solo con
     * las claves que también están en $_SERVER y pierde Path. Por eso
     * php no se reconoce. Aquí se publica el PATH del host para el
     * servidor y se reconstruye el entorno de cada comando aprobado.
     */
    public static function publishForServer(): void
    {
        foreach (['PATH', 'PATHEXT', 'SYSTEMROOT'] as $name) {
            $value = self::read($name);

            if ($value === null) {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    public function forCommand(): array
    {
        $env = [];

        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        $pathKey = 'PATH';

        foreach (array_keys($env) as $key) {
            if (strcasecmp($key, 'PATH') === 0) {
                $pathKey = $key;
                break;
            }
        }

        $directory = dirname(PHP_BINARY);
        $current = $env[$pathKey] ?? '';

        if ($current === '' || ! str_contains(strtolower($current), strtolower($directory))) {
            $env[$pathKey] = $current === '' ? $directory : $directory.PATH_SEPARATOR.$current;
        }

        return $env;
    }

    private static function read(string $name): ?string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (strcasecmp($name, 'PATH') !== 0) {
            return $value;
        }

        $directory = dirname(PHP_BINARY);

        if (str_contains(strtolower($value), strtolower($directory))) {
            return $value;
        }

        return $directory.PATH_SEPARATOR.$value;
    }
}
